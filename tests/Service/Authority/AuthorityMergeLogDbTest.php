<?php

namespace App\Tests\Service\Authority;

use App\Entity\Keyword;
use App\Service\Authority\AuthorityMergeLogger;
use App\Service\Import\DocumentEnrichmentService;
use App\Service\KeywordTreatment\KeywordFuzzyMatcherService;
use App\Service\KeywordTreatment\KeywordTreatmentOptions;
use App\Service\KeywordTreatment\KeywordTreatmentService;
use App\Service\Keyword\KeywordThesaurusMatcherService;
use App\Service\Import\TextNormalizer;
use App\Service\Normalize\NormalizationService;
use App\Service\Thesaurus\EntityMergeService;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cada caminho que repontua ou apaga vínculos deixa o diário esperado: cabeçalho (origem, usuário, projeto,
 * entidades) e uma linha por vínculo, com texto original e posição. Tudo dentro de uma transação desfeita.
 */
class AuthorityMergeLogDbTest extends LocalDbTestCase
{
    public function testNormalizationMergeAuthorsLogsHeaderAndEveryLink(): void
    {
        [$keep, $discard] = $this->pickIds('document_author', 'author_identity_id', 2, 6, 2);
        $before = $this->authorSnapshot($discard);
        $name = $this->conn->fetchOne('SELECT preferred_name FROM author_identity WHERE id = ?', [$discard]);

        (new NormalizationService($this->em, new AuthorityMergeLogger($this->em)))->mergeAuthors($keep, $discard, 77, ['via' => 'teste']);

        $logs = $this->logsFor('author', $discard);
        $this->assertCount(1, $logs);
        $log = $logs[0];
        $this->assertSame('screen_project', $log['origin']);
        $this->assertSame('merge', $log['action']);
        $this->assertSame($keep, (int) $log['target_id']);
        $this->assertSame(77, (int) $log['project_id']);
        $this->assertNull($log['user_id']);
        $this->assertSame($name, $log['source_label']);
        $payload = json_decode($log['payload'], true);
        $this->assertSame('teste', $payload['via']);
        $this->assertArrayHasKey('variations', $payload);
        $this->assertNotEmpty($before);
        $this->assertSame($this->normalizeRows($before), $this->normalizeRows($this->linksOf((int) $log['id'])));
        // A identidade foi mesclada de verdade e o diário sobrevive à exclusão dela
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM author_identity WHERE id = ?', [$discard]));
    }

    public function testNormalizationMergeKeywordsLogsHeaderAndEveryLink(): void
    {
        [$keep, $discard] = $this->pickIds('document_keyword', 'keyword_id', 2, 6, 2);
        $before = $this->keywordSnapshot($discard);

        (new NormalizationService($this->em, new AuthorityMergeLogger($this->em)))->mergeKeywords($keep, $discard);

        $log = $this->logsFor('keyword', $discard)[0];
        $this->assertSame(['screen_project', 'merge', $keep], [$log['origin'], $log['action'], (int) $log['target_id']]);
        $links = $this->linksOf((int) $log['id']);
        $this->assertSame($this->normalizeRows($before), $this->normalizeRows($links));
        $this->assertNull($links[0]['position']);
    }

    public function testNormalizationBatchLogsEachPairAndChainsToTheFinalTarget(): void
    {
        [$a, $b, $c] = $this->pickIds('document_author', 'author_identity_id', 1, 4, 3);
        $snapB = $this->authorSnapshot($b);
        $snapC = $this->authorSnapshot($c);

        // c -> b e b -> a: o alvo final de c é a
        $n = (new NormalizationService($this->em, new AuthorityMergeLogger($this->em)))
            ->mergeAuthorsBatch([['keepId' => $b, 'discardId' => $c], ['keepId' => $a, 'discardId' => $b]], 5);
        $this->assertSame(2, $n);

        $logC = $this->logsFor('author', $c)[0];
        $logB = $this->logsFor('author', $b)[0];
        $this->assertSame($a, (int) $logC['target_id']);
        $this->assertSame($a, (int) $logB['target_id']);
        $this->assertSame(5, (int) $logC['project_id']);
        $this->assertSame($this->normalizeRows($snapC), $this->normalizeRows($this->linksOf((int) $logC['id'])));
        $this->assertSame($this->normalizeRows($snapB), $this->normalizeRows($this->linksOf((int) $logB['id'])));
    }

    public function testNormalizationBatchKeywords(): void
    {
        [$a, $b] = $this->pickIds('document_keyword', 'keyword_id', 1, 4, 2);
        $snapB = $this->keywordSnapshot($b);

        (new NormalizationService($this->em, new AuthorityMergeLogger($this->em)))->mergeKeywordsBatch([['keepId' => $a, 'discardId' => $b]], 9);

        $log = $this->logsFor('keyword', $b)[0];
        $this->assertSame([$a, 9], [(int) $log['target_id'], (int) $log['project_id']]);
        $this->assertSame($this->normalizeRows($snapB), $this->normalizeRows($this->linksOf((int) $log['id'])));
    }

    public function testEntityMergeServiceAuthorsAndKeywordsOriginDefaultsToCommandInCli(): void
    {
        [$keepA, $discardA] = $this->pickIds('document_author', 'author_identity_id', 2, 6, 2);
        [$keepK, $discardK] = $this->pickIds('document_keyword', 'keyword_id', 2, 6, 2);
        $snapA = $this->authorSnapshot($discardA);
        $snapK = $this->keywordSnapshot($discardK);
        $service = new EntityMergeService($this->em, new AuthorityMergeLogger($this->em));

        $service->mergeAuthors($keepA, [$discardA], []);                       // sem requisição web: command
        $service->mergeKeywords($keepK, [$discardK], [], 'screen_admin', 3);   // origem e projeto explícitos

        $la = $this->logsFor('author', $discardA)[0];
        $this->assertSame(['command', 'merge', $keepA], [$la['origin'], $la['action'], (int) $la['target_id']]);
        $this->assertSame($this->normalizeRows($snapA), $this->normalizeRows($this->linksOf((int) $la['id'])));
        $lk = $this->logsFor('keyword', $discardK)[0];
        $this->assertSame(['screen_admin', $keepK, 3], [$lk['origin'], (int) $lk['target_id'], (int) $lk['project_id']]);
        $this->assertSame($this->normalizeRows($snapK), $this->normalizeRows($this->linksOf((int) $lk['id'])));
    }

    public function testLoggedUserComesFromSecurityAndTreatmentNeverHasUser(): void
    {
        $user = new class implements \Symfony\Component\Security\Core\User\UserInterface {
            public function getId(): int { return 3; }
            public function getRoles(): array { return ['ROLE_ADMIN']; }
            public function eraseCredentials(): void {}
            public function getUserIdentifier(): string { return 'admin@teste'; }
        };
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = new AuthorityMergeLogger($this->em, $security);

        $screen = $logger->begin('screen_admin', 'delete', 'keyword', 999999991, null, 'x');
        $treat = $logger->begin('treatment', 'merge', 'keyword', 999999992, 1, 'y');
        $this->assertSame(3, (int) $this->conn->fetchOne('SELECT user_id FROM authority_merge_log WHERE id = ?', [$screen]));
        $this->assertNull($this->conn->fetchOne('SELECT user_id FROM authority_merge_log WHERE id = ?', [$treat]));
    }

    public function testLoggerFailureFailsTheWholeActionAndRollbackLeavesNothing(): void
    {
        [$keep, $discard] = $this->pickIds('document_author', 'author_identity_id', 2, 6, 2);
        $broken = new class($this->em) extends AuthorityMergeLogger {
            public function logAuthorMerge(string $origin, string $action, int $sourceId, ?int $targetId, ?int $userId = null, ?int $projectId = null, array $extra = []): int
            {
                throw new \RuntimeException('diário indisponível');
            }
        };
        $docsBefore = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_author WHERE author_identity_id = ?', [$discard]);

        try {
            (new NormalizationService($this->em, $broken))->mergeAuthors($keep, $discard);
            $this->fail('A mesclagem devia falhar junto com o diário.');
        } catch (\RuntimeException $e) {
            $this->assertSame('diário indisponível', $e->getMessage());
        }
        // Nada mudou: o vínculo continua no autor descartado
        $this->assertSame($docsBefore, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_author WHERE author_identity_id = ?', [$discard]));
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM author_identity WHERE id = ?', [$discard]));
    }

    public function testTreatmentFusionLogsWithTreatmentOriginAndNoUser(): void
    {
        $normalizer = new TextNormalizer();
        $dirty = 'ZZ  Probe   Treatment ';
        $clean = $normalizer->normalizeKeyword($dirty);
        $this->assertTrue($clean['valid']);

        $this->conn->insert('keyword', ['keyword_original' => 'zz probe treatment', 'keyword_display' => $clean['display'], 'keyword_normalized' => $clean['normalized'], 'keyword_type' => 'author_keyword', 'status' => 1]);
        $canonicalId = (int) $this->conn->lastInsertId();
        // Duplicado sujo (display e forma normalizada fora do padrão): o passo de limpeza o funde no canônico
        $this->conn->insert('keyword', ['keyword_original' => $dirty, 'keyword_display' => $dirty, 'keyword_normalized' => $dirty, 'keyword_type' => 'author_keyword', 'status' => 1]);
        $dupId = (int) $this->conn->lastInsertId();

        $docs = array_map('intval', $this->conn->fetchFirstColumn('SELECT id FROM document ORDER BY id LIMIT 3'));
        foreach ($docs as $d) {
            $this->conn->insert('document_keyword', ['document_id' => $d, 'keyword_id' => $dupId, 'original_term' => $dirty]);
        }

        $fuzzy = new KeywordFuzzyMatcherService();
        $emRef = $this->em;
        $service = new class($this->em, $fuzzy, new KeywordThesaurusMatcherService($this->em, $fuzzy), $dupId, $emRef) extends KeywordTreatmentService {
            public function __construct($em, $f, $m, private readonly int $dupId, private readonly \Doctrine\ORM\EntityManagerInterface $emRef) { parent::__construct($em, $f, $m); }

            protected function fetchBatch(int $lastId, int $limit): array
            {
                return $lastId === 0 ? $this->emRef->getRepository(Keyword::class)->findBy(['id' => $this->dupId]) : [];
            }
        };
        $service->executeJob(new KeywordTreatmentOptions(dryRun: false, limit: 10, processInvalids: true, processExact: false, processThesaurus: false, processFuzzy: false), 'teste');

        $logs = $this->logsFor('keyword', $dupId);
        $this->assertCount(1, $logs);
        $this->assertSame(['treatment', 'merge', $canonicalId], [$logs[0]['origin'], $logs[0]['action'], (int) $logs[0]['target_id']]);
        $this->assertNull($logs[0]['user_id']);
        $links = $this->linksOf((int) $logs[0]['id']);
        $this->assertCount(3, $links);
        $this->assertSame($dirty, $links[0]['original_text']);
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM keyword WHERE id = ?', [$dupId]));
        $this->assertSame(3, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_keyword WHERE keyword_id = ?', [$canonicalId]));
    }

    public function testEnrichmentRemapLogsOneHeaderPerPairAndOneLinkPerChangedLink(): void
    {
        $service = new DocumentEnrichmentService($this->em, new NullLogger(), new AuthorityMergeLogger($this->em));
        $projectId = (int) $this->conn->fetchOne(
            'SELECT d.project_id FROM document d JOIN document_author da ON da.document_id = d.id GROUP BY d.project_id ORDER BY COUNT(*) ASC LIMIT 1'
        );
        // Um vínculo de autor com o id errado: o enriquecimento o devolve ao cadastro do nome original
        $row = $this->conn->fetchAssociative(
            'SELECT da.id, da.document_id, da.author_identity_id, da.original_name, da.position FROM document_author da JOIN document d ON d.id = da.document_id
             WHERE d.project_id = ? AND da.original_name IS NOT NULL AND da.original_name <> "" ORDER BY da.id LIMIT 1',
            [$projectId]
        );
        $wrong = (int) $this->conn->fetchOne(
            'SELECT id FROM author_identity WHERE id <> ? AND id NOT IN (SELECT author_identity_id FROM document_author WHERE document_id = ?) ORDER BY id LIMIT 1',
            [$row['author_identity_id'], $row['document_id']]
        );
        $this->conn->executeStatement('UPDATE document_author SET author_identity_id = ? WHERE id = ?', [$wrong, $row['id']]);

        (new \ReflectionMethod($service, 'remapAuthorsAndKeywords'))->invoke($service, $projectId, $this->conn);

        $now = (int) $this->conn->fetchOne('SELECT author_identity_id FROM document_author WHERE id = ?', [$row['id']]);
        $this->assertNotSame($wrong, $now, 'o vínculo foi devolvido ao cadastro do nome original');
        $logs = $this->logsFor('author', $wrong);
        $this->assertCount(1, $logs);
        $this->assertSame(['enrichment', 'remap', $now, $projectId], [$logs[0]['origin'], $logs[0]['action'], (int) $logs[0]['target_id'], (int) $logs[0]['project_id']]);
        $this->assertNull($logs[0]['user_id']);
        $links = $this->linksOf((int) $logs[0]['id']);
        $this->assertCount(1, $links);
        $this->assertSame([(int) $row['document_id'], 'document_author', $wrong, $row['original_name'], (int) $row['position']], [(int) $links[0]['document_id'], $links[0]['link_table'], (int) $links[0]['old_entity_id'], $links[0]['original_text'], (int) $links[0]['position']]);
    }
}
