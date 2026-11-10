<?php

namespace App\Tests\Service;

use App\Entity\Keyword;
use App\Entity\KeywordTreatmentJob;
use App\Entity\KeywordTreatmentLog;
use App\Entity\ThesaurusConcept;
use App\Service\Import\TextNormalizer;
use App\Service\Keyword\KeywordThesaurusMatcherService;
use App\Service\KeywordTreatment\KeywordFuzzyMatcherService;
use App\Service\KeywordTreatment\KeywordTreatmentBlockedException;
use App\Service\KeywordTreatment\KeywordTreatmentMemory;
use App\Service\KeywordTreatment\KeywordTreatmentOptions;
use App\Service\KeywordTreatment\KeywordTreatmentService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tratamento repetível (memória de sugestões, invalidações e alertas) e exclusivo
 * (trava e importação em andamento), com Connection e EntityManager simulados.
 */
class KeywordTreatmentRepeatableTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];
    /** @var list<string> */
    private array $sqlSeen = [];
    private int $recordMatchCalls = 0;

    // ─── Um tratamento por vez ───────────────────────────────────────────────

    public function testLockHeldByAnotherTreatmentCreatesNoJob(): void
    {
        $service = $this->service([], lockResult: 0);

        try {
            $service->executeJob(new KeywordTreatmentOptions(dryRun: false), 'cli');
            $this->fail('Esperava KeywordTreatmentBlockedException');
        } catch (KeywordTreatmentBlockedException $e) {
            $this->assertSame('Outro tratamento de palavras-chave está em andamento.', $e->getMessage());
        }
        $this->assertSame([], $this->jobs());
    }

    public function testImportInProgressBlocksExecuteModeAndReleasesLock(): void
    {
        $service = $this->service([], importing: 1);

        try {
            $service->executeJob(new KeywordTreatmentOptions(dryRun: false), 'cli');
            $this->fail('Esperava KeywordTreatmentBlockedException');
        } catch (KeywordTreatmentBlockedException $e) {
            $this->assertSame('Importação em andamento: tratamento adiado.', $e->getMessage());
        }
        $this->assertSame([], $this->jobs());
        $this->assertContains('SELECT RELEASE_LOCK(?)', $this->sqlSeen);
    }

    public function testDryRunIsNotBlockedByImport(): void
    {
        $service = $this->service([], importing: 1);

        $job = $service->executeJob(new KeywordTreatmentOptions(dryRun: true), 'cli');

        $this->assertSame('completed', $job->getStatus());
        $this->assertCount(1, $this->jobs());
        $this->assertEmpty(array_filter($this->sqlSeen, fn(string $sql) => str_contains($sql, 'FROM dataset')));
        $this->assertContains('SELECT RELEASE_LOCK(?)', $this->sqlSeen);
    }

    // ─── Sugestões (etapa 4) ────────────────────────────────────────────────

    public function testPendingSuggestionIsNotRecordedAgain(): void
    {
        $kw = $this->keyword(10, 'behaviour change');
        $service = $this->service([$kw], suggestionRows: [['keyword_id' => 10, 'concept_id' => 1]], fuzzy: [10 => 89.0]);

        $job = $service->executeJob($this->fuzzyOnly(), 'cli');

        $this->assertSame(0, $job->getFuzzyReviewCount());
        $this->assertSame([], $this->logs('fuzzy_review_required'));
        $this->assertSame(0, $this->recordMatchCalls);
    }

    public function testRejectedSuggestionDoesNotComeBack(): void
    {
        $kw = $this->keyword(11, 'education');
        // manual_rejected: a recusa troca a ação do log; a consulta da memória traz esse par
        $service = $this->service([$kw], suggestionRows: [['keyword_id' => 11, 'concept_id' => 1]], fuzzy: [11 => 80.0]);

        $job = $service->executeJob($this->fuzzyOnly(), 'cli');

        $this->assertSame(0, $job->getFuzzyReviewCount());
        $this->assertSame([], $this->logs('fuzzy_review_required'));
        $this->assertSame(0, $this->recordMatchCalls);
    }

    public function testNewSuggestionIsRecordedOnce(): void
    {
        $kw = $this->keyword(12, 'behaviour change');
        // Sugestão para outro conceito não conta como a mesma
        $service = $this->service([$kw], suggestionRows: [['keyword_id' => 12, 'concept_id' => 99]], fuzzy: [12 => 89.0]);

        $job = $service->executeJob($this->fuzzyOnly(), 'cli');

        $this->assertSame(1, $job->getFuzzyReviewCount());
        $this->assertCount(1, $this->logs('fuzzy_review_required'));
        $this->assertSame('pending', $this->logs('fuzzy_review_required')[0]->getStatus());
        $this->assertSame(1, $this->recordMatchCalls);
    }

    public function testMemoryQueriesPendingAndRejectedSuggestionsAndAppliedSuspicious(): void
    {
        $conn = $this->createStub(Connection::class);
        $queries = [];
        $conn->method('fetchAllAssociative')->willReturnCallback(function (string $sql) use (&$queries) {
            $queries[] = preg_replace('/\s+/', ' ', $sql);
            return str_contains($sql, 'suspicious')
                ? [['keyword_id' => '20', 'reason' => 'contains_number']]
                : [['keyword_id' => '10', 'concept_id' => '1'], ['keyword_id' => '11', 'concept_id' => null]];
        });

        $memory = KeywordTreatmentMemory::load($conn);

        $this->assertStringContainsString("(action = 'fuzzy_review_required' AND status = 'pending') OR action = 'manual_rejected'", $queries[0]);
        $this->assertStringContainsString("action = 'suspicious' AND status = 'applied'", $queries[1]);
        $this->assertTrue($memory->hasSuggestion(10, 1));
        $this->assertFalse($memory->hasSuggestion(11, 1));
        $this->assertTrue($memory->hasSuspicious(20, 'contains_number'));
        $this->assertFalse($memory->hasSuspicious(20, 'looks_like_author'));

        $memory->moveKeyword(20, 30);
        $this->assertFalse($memory->hasSuspicious(20, 'contains_number'));
        $this->assertTrue($memory->hasSuspicious(30, 'contains_number'));
    }

    // ─── Invalidação e alerta (etapa 1) ─────────────────────────────────────

    public function testTermAcceptedInReviewQueueStaysActive(): void
    {
        $original = str_repeat('a', 210);
        $reason = (new TextNormalizer())->normalizeKeyword($original)['reason'];
        $this->assertSame('too_long', $reason);

        // Invalidado antes (review_reasons gravado) e aceito na Fila de Revisão (status 1)
        $kw = $this->keyword(30, $original)->setReviewReasons($reason)->setStatus(true);
        $service = $this->service([$kw]);

        $job = $service->executeJob($this->stage1Only(), 'cli');

        $this->assertTrue($kw->isStatus());
        $this->assertSame(0, $job->getInvalidCount());
        $this->assertSame([], $this->logs('invalid'));
    }

    public function testInvalidWithDifferentReasonIsTreatedAgain(): void
    {
        $kw = $this->keyword(31, str_repeat('a', 210))->setReviewReasons('purely_numeric')->setStatus(true);
        $service = $this->service([$kw]);

        $job = $service->executeJob($this->stage1Only(), 'cli');

        $this->assertFalse($kw->isStatus());
        $this->assertSame('too_long', $kw->getReviewReasons());
        $this->assertSame(1, $job->getInvalidCount());
        $this->assertCount(1, $this->logs('invalid'));
    }

    public function testSuspiciousAlreadyRecordedIsNotCountedAgain(): void
    {
        $kw = $this->keyword(20, 'COVID-19');
        $service = $this->service([$kw], suspiciousRows: [['keyword_id' => 20, 'reason' => 'contains_number']]);

        $job = $service->executeJob($this->stage1Only(), 'cli');

        $this->assertSame(0, $job->getSuspiciousCount());
        $this->assertSame([], $this->logs('suspicious'));
    }

    public function testFirstSuspiciousIsRecorded(): void
    {
        $kw = $this->keyword(21, 'COVID-19');
        $service = $this->service([$kw]);

        $job = $service->executeJob($this->stage1Only(), 'cli');

        $this->assertSame(1, $job->getSuspiciousCount());
        $this->assertCount(1, $this->logs('suspicious'));
        $this->assertSame('applied', $this->logs('suspicious')[0]->getStatus());
    }

    // ─── Montagem ───────────────────────────────────────────────────────────

    private function fuzzyOnly(): KeywordTreatmentOptions
    {
        return new KeywordTreatmentOptions(dryRun: false, processInvalids: false, processExact: false, processThesaurus: false, processFuzzy: true);
    }

    private function stage1Only(): KeywordTreatmentOptions
    {
        return new KeywordTreatmentOptions(dryRun: false, processInvalids: true, processExact: false, processThesaurus: false, processFuzzy: false);
    }

    private function keyword(int $id, string $original): Keyword
    {
        $norm = (new TextNormalizer())->normalizeKeyword($original);
        $kw = (new Keyword())
            ->setKeywordOriginal($original)
            ->setKeywordDisplay($norm['display'] ?? $original)
            ->setKeywordNormalized($norm['normalized'] ?? mb_strtolower($original));
        $this->setId($kw, $id);

        return $kw;
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }

    /** @return list<KeywordTreatmentJob> */
    private function jobs(): array
    {
        return array_values(array_filter($this->persisted, fn($o) => $o instanceof KeywordTreatmentJob));
    }

    /** @return list<KeywordTreatmentLog> */
    private function logs(string $action): array
    {
        return array_values(array_filter(
            $this->persisted,
            fn($o) => $o instanceof KeywordTreatmentLog && $o->getAction() === $action
        ));
    }

    /**
     * @param Keyword[] $keywords
     * @param list<array{keyword_id: int, concept_id: int}> $suggestionRows
     * @param list<array{keyword_id: int, reason: string}> $suspiciousRows
     * @param array<int, float> $fuzzy score de revisão por ID da palavra-chave (conceito 1)
     */
    private function service(
        array $keywords,
        int $lockResult = 1,
        int $importing = 0,
        array $suggestionRows = [],
        array $suspiciousRows = [],
        array $fuzzy = [],
    ): KeywordTreatmentService {
        $this->persisted = [];
        $this->sqlSeen = [];
        $this->recordMatchCalls = 0;

        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturnCallback(function (string $sql) use ($lockResult, $importing) {
            $this->sqlSeen[] = $sql;
            return match (true) {
                str_contains($sql, 'GET_LOCK') => $lockResult,
                str_contains($sql, 'RELEASE_LOCK') => 1,
                str_contains($sql, 'FROM dataset') => $importing,
                str_contains($sql, 'COUNT(*)') => 0,
                default => false,
            };
        });
        $conn->method('fetchAllAssociative')->willReturnCallback(function (string $sql) use ($suggestionRows, $suspiciousRows) {
            $this->sqlSeen[] = $sql;
            return match (true) {
                str_contains($sql, 'fuzzy_review_required') => $suggestionRows,
                str_contains($sql, "action = 'suspicious'") => $suspiciousRows,
                default => [],
            };
        });
        $conn->method('fetchFirstColumn')->willReturn([]);
        $conn->method('executeStatement')->willReturn(0);

        $jobRepo = $this->createStub(EntityRepository::class);
        $jobRepo->method('find')->willReturnCallback(fn() => $this->jobs()[0] ?? null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('getRepository')->willReturn($jobRepo);
        $em->method('persist')->willReturnCallback(function (object $o) {
            $this->persisted[] = $o;
        });

        $concept = (new ThesaurusConcept())->setPreferredLabel('Behavior change');
        $this->setId($concept, 1);

        $matcher = $this->createStub(KeywordThesaurusMatcherService::class);
        $matcher->method('match')->willReturnCallback(fn(Keyword $kw) => isset($fuzzy[$kw->getId()])
            ? ['conceptId' => 1, 'conceptLabel' => 'Behavior change', 'method' => 'fuzzy', 'score' => $fuzzy[$kw->getId()], 'ambiguous' => false]
            : ['conceptId' => null, 'conceptLabel' => null, 'method' => 'no_match', 'score' => 0.0, 'ambiguous' => false]);
        $matcher->method('getConceptEntity')->willReturn($concept);
        $matcher->method('recordMatch')->willReturnCallback(function () {
            $this->recordMatchCalls++;
            return new \App\Entity\ThesaurusMatch();
        });

        return new class($em, new KeywordFuzzyMatcherService(), $matcher, $keywords) extends KeywordTreatmentService {
            private bool $served = false;

            /** @param Keyword[] $batch */
            public function __construct(EntityManagerInterface $em, KeywordFuzzyMatcherService $f, KeywordThesaurusMatcherService $m, private readonly array $batch)
            {
                parent::__construct($em, $f, $m);
            }

            protected function fetchBatch(int $lastId, int $limit): array
            {
                if ($this->served) {
                    return [];
                }
                $this->served = true;

                return $this->batch;
            }
        };
    }
}
