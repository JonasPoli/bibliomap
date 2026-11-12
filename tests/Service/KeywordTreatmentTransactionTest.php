<?php

namespace App\Tests\Service;

use App\Entity\Keyword;
use App\Entity\KeywordTreatmentJob;
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
 * Transação por lote, jobs 'running' antigos e contagens no SQL do tratamento de palavras-chave.
 */
class KeywordTreatmentTransactionTest extends TestCase
{
    private int $txDepth = 0;
    /** @var list<int> profundidade da transação em cada chamada da comparação aproximada */
    private array $depthAtMatch = [];
    /** @var list<string> */
    private array $events = [];
    /** @var list<string> */
    private array $sql = [];
    /** @var list<object> */
    private array $persisted = [];

    public function testFuzzyComparisonRunsOutsideTheTransactionAndWritesInsideOne(): void
    {
        $service = $this->service([$this->keyword(1, 'behaviour change'), $this->keyword(2, 'teaching')], lockResult: 1);

        $service->executeJob(new KeywordTreatmentOptions(dryRun: false, limit: 0), 'cli');

        $this->assertSame([0, 0], $this->depthAtMatch, 'a comparação aproximada não pode ficar dentro da transação');
        $this->assertSame(
            ['begin', 'flush', 'commit', 'begin', 'flush', 'commit'],
            $this->events,
            'uma transação para as etapas 1 a 3 e outra, curta, para as gravações da etapa 4'
        );
        $this->assertSame(0, $this->txDepth);
    }

    public function testFailureInsideTheBatchRollsBackAndMarksJobFailed(): void
    {
        $service = $this->service([$this->keyword(1, 'behaviour change')], lockResult: 1, flushFails: true);

        try {
            $service->executeJob(new KeywordTreatmentOptions(dryRun: false, limit: 0), 'cli');
            $this->fail('Esperava a exceção do flush');
        } catch (\RuntimeException $e) {
            $this->assertSame('flush falhou', $e->getMessage());
        }

        $this->assertContains('rollback', $this->events);
        $this->assertSame(0, $this->txDepth);
        $this->assertNotEmpty(array_filter($this->sql, fn($s) => str_contains($s, "SET status = ?") && str_contains($s, 'keyword_treatment_job')));
    }

    public function testStaleRunningJobsBecomeFailedOnlyWhenTheLockIsFree(): void
    {
        $service = $this->service([], lockResult: 1);
        $service->executeJob(new KeywordTreatmentOptions(dryRun: false, limit: 0), 'cli');
        $this->assertNotEmpty(array_filter($this->sql, fn($s) => str_contains($s, "SET status = 'failed'") && str_contains($s, "WHERE status = 'running'")));

        $service = $this->service([], lockResult: 0);
        try {
            $service->executeJob(new KeywordTreatmentOptions(dryRun: false), 'cli');
        } catch (KeywordTreatmentBlockedException) {
        }
        $this->assertEmpty(array_filter($this->sql, fn($s) => str_contains($s, "'failed'")), 'com a trava ocupada outro tratamento pode estar rodando');

        $service = $this->service([], lockResult: 1, importing: 1);
        try {
            $service->executeJob(new KeywordTreatmentOptions(dryRun: false), 'cli');
        } catch (KeywordTreatmentBlockedException) {
        }
        $this->assertEmpty(array_filter($this->sql, fn($s) => str_contains($s, "'failed'")), 'tratamento adiado não mexe em nada');
    }

    public function testAffectedCountsComeFromSqlWithoutDocumentIds(): void
    {
        $service = $this->service([], lockResult: 1);
        $service->executeJob(new KeywordTreatmentOptions(dryRun: false, limit: 0), 'cli');

        $this->assertEmpty(
            array_filter($this->sql, fn($s) => preg_match('/SELECT\s+dk\.document_id\s+FROM document_keyword/i', $s)),
            'a lista de document_id não deve vir para o PHP'
        );
        $this->assertNotEmpty(array_filter($this->sql, fn($s) => str_contains($s, 'COUNT(DISTINCT dk.document_id)')));
    }

    public function testMemoryMovesSuggestionsAndSuspiciousToTheSurvivor(): void
    {
        $memory = new KeywordTreatmentMemory(
            [['keyword_id' => 10, 'concept_id' => 1], ['keyword_id' => 10, 'concept_id' => 2], ['keyword_id' => 30, 'concept_id' => 3]],
            [['keyword_id' => 10, 'reason' => 'contains_number']]
        );

        $memory->moveKeyword(10, 30);

        $this->assertFalse($memory->hasSuggestion(10, 1));
        $this->assertTrue($memory->hasSuggestion(30, 1));
        $this->assertTrue($memory->hasSuggestion(30, 2));
        $this->assertTrue($memory->hasSuggestion(30, 3), 'o que o destino já tinha continua');
        $this->assertFalse($memory->hasSuspicious(10, 'contains_number'));
        $this->assertTrue($memory->hasSuspicious(30, 'contains_number'));

        $memory->moveKeyword(99, 30); // sem registros: não muda nada
        $this->assertTrue($memory->hasSuggestion(30, 3));
    }

    // ─── Montagem ───────────────────────────────────────────────────────────

    private function keyword(int $id, string $original): Keyword
    {
        $norm = (new TextNormalizer())->normalizeKeyword($original);
        $kw = (new Keyword())
            ->setKeywordOriginal($original)
            ->setKeywordDisplay($norm['display'] ?? $original)
            ->setKeywordNormalized($norm['normalized'] ?? mb_strtolower($original));
        (new \ReflectionProperty($kw, 'id'))->setValue($kw, $id);

        return $kw;
    }

    /** @param Keyword[] $keywords */
    private function service(array $keywords, int $lockResult = 1, int $importing = 0, bool $flushFails = false): KeywordTreatmentService
    {
        $this->txDepth = 0;
        $this->depthAtMatch = [];
        $this->events = [];
        $this->sql = [];
        $this->persisted = [];

        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturnCallback(function (string $sql) use ($lockResult, $importing) {
            $this->sql[] = $sql;

            return match (true) {
                str_contains($sql, 'GET_LOCK') => $lockResult,
                str_contains($sql, 'RELEASE_LOCK') => 1,
                str_contains($sql, 'FROM dataset') => $importing,
                str_contains($sql, 'COUNT(') => 0,
                default => false,
            };
        });
        $conn->method('fetchAllAssociative')->willReturn([]);
        $conn->method('fetchFirstColumn')->willReturn([]);
        $conn->method('iterateKeyValue')->willReturnCallback(fn() => new \ArrayIterator([]));
        $conn->method('executeStatement')->willReturnCallback(function (string $sql) {
            $this->sql[] = $sql;

            return 0;
        });
        $conn->method('beginTransaction')->willReturnCallback(function () {
            $this->txDepth++;
            $this->events[] = 'begin';
        });
        $conn->method('commit')->willReturnCallback(function () {
            $this->txDepth--;
            $this->events[] = 'commit';
        });
        $conn->method('rollBack')->willReturnCallback(function () {
            $this->txDepth--;
            $this->events[] = 'rollback';

            return true;
        });
        $conn->method('isTransactionActive')->willReturnCallback(fn() => $this->txDepth > 0);

        $jobRepo = $this->createStub(EntityRepository::class);
        $jobRepo->method('find')->willReturnCallback(fn() => array_values(array_filter($this->persisted, fn($o) => $o instanceof KeywordTreatmentJob))[0] ?? null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('getRepository')->willReturn($jobRepo);
        $em->method('persist')->willReturnCallback(function (object $o) {
            $this->persisted[] = $o;
        });
        $flushes = 0;
        $em->method('flush')->willReturnCallback(function () use (&$flushes, $flushFails) {
            // o primeiro flush é o do job (fora de transação); os seguintes são os dos lotes
            $flushes++;
            if ($flushFails && $this->txDepth > 0) {
                throw new \RuntimeException('flush falhou');
            }
            if ($this->txDepth > 0) {
                $this->events[] = 'flush';
            }
        });

        $concept = (new ThesaurusConcept())->setPreferredLabel('Behavior change');
        (new \ReflectionProperty($concept, 'id'))->setValue($concept, 1);

        $matcher = $this->createStub(KeywordThesaurusMatcherService::class);
        $matcher->method('matchExact')->willReturn(null);
        $matcher->method('match')->willReturnCallback(function (Keyword $kw) {
            $this->depthAtMatch[] = $this->txDepth;

            return ['conceptId' => 1, 'conceptLabel' => 'Behavior change', 'method' => 'fuzzy', 'score' => 80.0, 'ambiguous' => false];
        });
        $matcher->method('getConceptEntity')->willReturn($concept);
        $matcher->method('recordMatch')->willReturn(new \App\Entity\ThesaurusMatch());

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
