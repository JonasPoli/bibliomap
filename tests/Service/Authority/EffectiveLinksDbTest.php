<?php

namespace App\Tests\Service\Authority;

use App\Controller\AdminAuthorController;
use App\Controller\AdminKeywordController;
use App\Controller\AdminReviewController;
use App\Entity\BibliometricProject;
use App\Entity\Dataset;
use App\Entity\Keyword;
use App\Entity\User;
use App\Service\Authority\AuthorityLinkGuard;
use App\Service\Authority\AuthorityMergeLogger;
use App\Service\Authority\AuthorityResolver;
use App\Service\Import\DocumentEnrichmentService;
use App\Service\Import\DocumentImportService;
use App\Service\Import\TextNormalizer;
use App\Service\Import\WosImporter;
use App\Service\KeywordTreatment\KeywordFuzzyMatcherService;
use App\Service\KeywordTreatment\KeywordTreatmentOptions;
use App\Service\KeywordTreatment\KeywordTreatmentService;
use App\Service\Keyword\KeywordThesaurusMatcherService;
use App\Service\Normalize\NormalizationService;
use App\Service\ProjectCopyService;
use App\Service\Thesaurus\EntityMergeService;
use App\Service\Thesaurus\ThesaurusFileService;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Tarefa 1.3 do change tesauro-em-camadas: todo caminho que cria, copia, repontua ou apaga vínculos mantém
 * effective_author_id / effective_keyword_id no MESMO comando. Cada teste executa a operação e confere, SEM rodar
 * o rebuild antes, que nenhuma linha diverge do valor calculado (o mesmo que `app:authority:rebuild --check` usa).
 * Tudo dentro de uma transação desfeita.
 */
class EffectiveLinksDbTest extends LocalDbTestCase
{
    private Request $request;

    private function resolver(): AuthorityResolver
    {
        return new AuthorityResolver($this->conn);
    }

    private function logger(): AuthorityMergeLogger
    {
        return new AuthorityMergeLogger($this->em);
    }

    /** Equivalente ao `app:authority:rebuild --check` (tabela inteira) mais as contagens de efetivo velho. */
    private function assertEffectiveConsistent(string $when, bool $withMappings = false): void
    {
        $this->assertSame(0, $this->resolver()->process('author', false)['changed'], "autores divergentes {$when}");
        $this->assertSame(0, $this->resolver()->process('keyword', false)['changed'], "palavras-chave divergentes {$when}");
        if (!$withMappings) {
            $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_author WHERE effective_author_id <> author_identity_id'), "autor efetivo != bruto {$when}");
        }
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_keyword WHERE effective_keyword_id = 0'), "palavra com efetivo 0 {$when}");
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_author WHERE effective_author_id = 0'), "autor com efetivo 0 {$when}");
    }

    private function normalization(): NormalizationService
    {
        return new NormalizationService($this->em, $this->logger());
    }

    /** Projeto descartável, cópia dos dados de um projeto existente (como o harness de importação). */
    private function scratchProject(): int
    {
        $src = (int) $this->conn->fetchOne('SELECT id FROM bibliometric_project ORDER BY id LIMIT 1');
        $p = $this->conn->fetchAssociative('SELECT * FROM bibliometric_project WHERE id = ?', [$src]);
        unset($p['id']);
        $p['title'] = 'ZZ efetivo descartavel';
        $p['slug'] = 'zz-efetivo-' . bin2hex(random_bytes(4));
        $q = [];
        foreach ($p as $k => $v) {
            $q["`$k`"] = $v;
        }
        $this->conn->insert('bibliometric_project', $q);

        return (int) $this->conn->lastInsertId();
    }

    private function wosFile(): string
    {
        $dir = dirname(__DIR__, 3) . '/var/uploads';
        $files = glob($dir . '/*/*.txt') ?: [];
        if (!$files) {
            $this->markTestSkipped('Sem arquivo WoS em var/uploads para a importação.');
        }
        usort($files, fn ($a, $b) => filesize($a) <=> filesize($b));

        return $files[0];
    }

    // ── 1. Importação e enriquecimento ──────────────────────────────────────────

    public function testImportWritesEffectiveInTheSameInsertAndEnrichmentKeepsIt(): void
    {
        $file = $this->wosFile();
        $pid = $this->scratchProject();
        $this->conn->insert('dataset', ['project_id' => $pid, 'name' => 'zz', 'source' => 'txt', 'original_filename' => 'zz.txt', 'file_path' => $file, 'file_format' => 'txt', 'status' => 'pending', 'created_at' => '2026-01-01 00:00:00', 'records_count' => 0, 'imported_count' => 0, 'duplicated_count' => 0, 'error_count' => 0]);
        $ds = $this->em->find(Dataset::class, (int) $this->conn->lastInsertId());
        $enrich = new DocumentEnrichmentService($this->em, new NullLogger(), new AuthorityMergeLogger($this->em));
        $import = new DocumentImportService($this->em, new NullLogger(), $enrich);

        $stats = $import->importAll((new WosImporter())->parseStream($file), $ds, null, true);

        $this->assertGreaterThan(0, $stats['imported']);
        $this->assertGreaterThan(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_author da JOIN document d ON d.id = da.document_id WHERE d.project_id = ?', [$pid]));
        $this->assertGreaterThan(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_keyword dk JOIN document d ON d.id = dk.document_id WHERE d.project_id = ?', [$pid]));
        $this->assertEffectiveConsistent('logo depois da importação');

        // Palavra-chave que já tem conceito do tesauro: o vínculo novo nasce com o efetivo negativo
        $this->assertSame(0, (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM document_keyword dk JOIN document d ON d.id = dk.document_id JOIN keyword k ON k.id = dk.keyword_id
             WHERE d.project_id = ? AND k.thesaurus_concept_id IS NOT NULL AND dk.effective_keyword_id <> -k.thesaurus_concept_id',
            [$pid]
        ));

        $enrich->enrichProject($pid);
        $this->assertEffectiveConsistent('logo depois do enriquecimento');
    }

    public function testEnrichmentRemapKeepsEffectiveForAuthorsAndKeywords(): void
    {
        $service = new DocumentEnrichmentService($this->em, new NullLogger(), new AuthorityMergeLogger($this->em));
        $projectId = (int) $this->conn->fetchOne(
            'SELECT d.project_id FROM document d JOIN document_author da ON da.document_id = d.id GROUP BY d.project_id ORDER BY COUNT(*) ASC LIMIT 1'
        );
        $row = $this->conn->fetchAssociative(
            'SELECT da.id, da.document_id, da.author_identity_id FROM document_author da JOIN document d ON d.id = da.document_id
             WHERE d.project_id = ? AND da.original_name IS NOT NULL AND da.original_name <> "" ORDER BY da.id LIMIT 1',
            [$projectId]
        );
        $wrong = (int) $this->conn->fetchOne(
            'SELECT id FROM author_identity WHERE id <> ? AND id NOT IN (SELECT author_identity_id FROM document_author WHERE document_id = ?) ORDER BY id LIMIT 1',
            [$row['author_identity_id'], $row['document_id']]
        );
        // Precondição consistente: o id errado com o efetivo dele
        $this->conn->executeStatement('UPDATE document_author SET author_identity_id = ?, effective_author_id = ? WHERE id = ?', [$wrong, $wrong, $row['id']]);

        $kw = $this->conn->fetchAssociative(
            'SELECT dk.id, dk.document_id, dk.keyword_id, k.keyword_type FROM document_keyword dk JOIN document d ON d.id = dk.document_id JOIN keyword k ON k.id = dk.keyword_id
             WHERE d.project_id = ? AND dk.original_term IS NOT NULL AND dk.original_term <> "" AND k.thesaurus_concept_id IS NULL ORDER BY dk.id LIMIT 1',
            [$projectId]
        );
        $kwWrong = null;
        if ($kw) {
            $kwWrong = (int) $this->conn->fetchOne(
                'SELECT id FROM keyword WHERE id <> ? AND keyword_type = ? AND thesaurus_concept_id IS NULL AND keyword_concept_id = id
                 AND id NOT IN (SELECT keyword_id FROM document_keyword WHERE document_id = ?) ORDER BY id LIMIT 1',
                [$kw['keyword_id'], $kw['keyword_type'], $kw['document_id']]
            );
            $this->conn->executeStatement('UPDATE document_keyword SET keyword_id = ?, effective_keyword_id = ? WHERE id = ?', [$kwWrong, $kwWrong, $kw['id']]);
        }
        $this->assertEffectiveConsistent('antes do remapeamento (precondição)');

        (new \ReflectionMethod($service, 'remapAuthorsAndKeywords'))->invoke($service, $projectId, $this->conn);

        // Agrupa sem trocar o id bruto: o efetivo do vínculo passa a ser o do cadastro do texto original
        $this->assertSame($wrong, (int) $this->conn->fetchOne('SELECT author_identity_id FROM document_author WHERE id = ?', [$row['id']]), 'o id bruto não muda');
        $this->assertSame((int) $row['author_identity_id'], (int) $this->conn->fetchOne('SELECT effective_author_id FROM document_author WHERE id = ?', [$row['id']]), 'o autor foi agrupado');
        if ($kw) {
            $this->assertSame($kwWrong, (int) $this->conn->fetchOne('SELECT keyword_id FROM document_keyword WHERE id = ?', [$kw['id']]), 'o id bruto não muda');
            $this->assertNotSame($kwWrong, (int) $this->conn->fetchOne('SELECT effective_keyword_id FROM document_keyword WHERE id = ?', [$kw['id']]), 'a palavra foi agrupada');
        }
        $this->assertEffectiveConsistent('logo depois do remapeamento', true);
    }

    // ── 2. Cópia de projeto ──────────────────────────────────────────────────────

    public function testProjectCopyCopiesEffectiveColumns(): void
    {
        $sourceId = (int) $this->conn->fetchOne(
            'SELECT d.project_id FROM document d JOIN document_author da ON da.document_id = d.id GROUP BY d.project_id ORDER BY COUNT(*) ASC LIMIT 1'
        );
        $source = $this->em->find(BibliometricProject::class, $sourceId);
        $user = $this->em->getRepository(User::class)->findOneBy([]);
        $uploads = dirname(__DIR__, 3) . '/var/uploads';
        $before = glob($uploads . '/*', GLOB_ONLYDIR) ?: [];

        try {
            $copy = (new ProjectCopyService($this->em, $this->conn, new \Symfony\Component\String\Slugger\AsciiSlugger(), dirname(__DIR__, 3)))->copy($source, $user);
        } finally {
            // A cópia de arquivo acontece fora da transação: remove as pastas novas de var/uploads
            foreach (array_diff(glob($uploads . '/*', GLOB_ONLYDIR) ?: [], $before) as $dir) {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }

        $newId = (int) $copy->getId();
        $this->assertGreaterThan(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_author da JOIN document d ON d.id = da.document_id WHERE d.project_id = ?', [$newId]));
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_author da JOIN document d ON d.id = da.document_id WHERE d.project_id = ? AND da.effective_author_id = 0', [$newId]));
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_keyword dk JOIN document d ON d.id = dk.document_id WHERE d.project_id = ? AND dk.effective_keyword_id = 0', [$newId]));
        $this->assertEffectiveConsistent('logo depois da cópia');
    }

    // ── 3. NormalizationService ──────────────────────────────────────────────────

    public function testNormalizationMergeAuthorsAndKeywordsIndividual(): void
    {
        [$keepA, $discardA] = $this->pickIds('document_author', 'author_identity_id', 2, 6, 2);
        [$keepK, $discardK] = $this->pickIds('document_keyword', 'keyword_id', 2, 6, 2);
        $svc = $this->normalization();

        $svc->mergeAuthors($keepA, $discardA, 77);
        $this->assertEffectiveConsistent('logo depois de mergeAuthors');
        $svc->mergeKeywords($keepK, $discardK);
        $this->assertEffectiveConsistent('logo depois de mergeKeywords');
        $this->assertGreaterThan(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_author WHERE author_identity_id = ? AND effective_author_id = ?', [$keepA, $keepA]));
    }

    public function testNormalizationMergeBatch(): void
    {
        [$a1, $a2, $a3, $a4] = $this->pickIds('document_author', 'author_identity_id', 2, 6, 4);
        [$k1, $k2, $k3, $k4] = $this->pickIds('document_keyword', 'keyword_id', 2, 6, 4);
        $svc = $this->normalization();

        $svc->mergeAuthorsBatch([['keepId' => $a1, 'discardId' => $a2], ['keepId' => $a3, 'discardId' => $a4]], 9);
        $this->assertEffectiveConsistent('logo depois de mergeAuthorsBatch');
        $svc->mergeKeywordsBatch([['keepId' => $k1, 'discardId' => $k2], ['keepId' => $k3, 'discardId' => $k4]], 9);
        $this->assertEffectiveConsistent('logo depois de mergeKeywordsBatch');
    }

    public function testNormalizationMergeDocumentsMovesLinksWithEffective(): void
    {
        $pid = (int) $this->conn->fetchOne('SELECT d.project_id FROM document d JOIN document_author da ON da.document_id = d.id GROUP BY d.project_id ORDER BY COUNT(*) ASC LIMIT 1');
        $ids = $this->conn->fetchFirstColumn(
            'SELECT d.id FROM document d WHERE d.project_id = ? AND EXISTS (SELECT 1 FROM document_author da WHERE da.document_id = d.id)
             AND EXISTS (SELECT 1 FROM document_keyword dk WHERE dk.document_id = d.id) ORDER BY d.id LIMIT 2',
            [$pid]
        );
        $pair = ['keep_id' => $ids[0] ?? 0, 'discard_id' => $ids[1] ?? 0];
        $this->assertCount(2, $ids);
        $keep = (int) $pair['keep_id'];
        $discard = (int) $pair['discard_id'];
        // Autor só do descartado: o vínculo movido leva o efetivo
        $newAuthor = (int) $this->conn->fetchOne('SELECT id FROM author_identity WHERE id NOT IN (SELECT author_identity_id FROM document_author WHERE document_id IN (?, ?)) ORDER BY id LIMIT 1', [$keep, $discard]);
        $this->conn->insert('document_author', ['document_id' => $discard, 'author_identity_id' => $newAuthor, 'position' => 99, 'original_name' => 'zz', 'effective_author_id' => $newAuthor]);
        $this->assertEffectiveConsistent('precondição');

        $this->normalization()->mergeDocuments($keep, $discard);

        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_author WHERE document_id = ? AND author_identity_id = ? AND effective_author_id = ?', [$keep, $newAuthor, $newAuthor]));
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document WHERE id = ?', [$discard]));
        $this->assertEffectiveConsistent('logo depois de mergeDocuments');
    }

    // ── 4. EntityMergeService ────────────────────────────────────────────────────

    public function testEntityMergeServiceAuthorsAndKeywordsIncludingRegroupedKeywords(): void
    {
        [$keepA, $discardA] = $this->pickIds('document_author', 'author_identity_id', 2, 6, 2);
        [$keepK, $discardK, $groupedK] = $this->pickIds('document_keyword', 'keyword_id', 2, 6, 3);
        // $groupedK está agrupado sob $discardK (conceito legado): ao mesclar, passa para o conceito do master
        $this->conn->executeStatement('UPDATE keyword SET keyword_concept_id = ? WHERE id = ?', [$discardK, $groupedK]);
        $this->resolver()->refreshByKeyword($groupedK);
        $this->assertEffectiveConsistent('precondição');
        $service = new EntityMergeService($this->em, $this->logger());

        $service->mergeAuthors($keepA, [$discardA], []);
        $this->assertEffectiveConsistent('logo depois de EntityMergeService::mergeAuthors', true);
        $this->assertGreaterThan(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_author WHERE author_identity_id = ? AND effective_author_id = ?', [$discardA, $keepA]));
        $service->mergeKeywords($keepK, [$discardK], []);
        $this->assertEffectiveConsistent('logo depois de EntityMergeService::mergeKeywords', true);
        $this->assertGreaterThan(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_keyword WHERE keyword_id = ? AND effective_keyword_id = ?', [$groupedK, $keepK]), 'o grupo mudou de conceito e o efetivo acompanhou');
    }

    // ── 5. KeywordTreatmentService ───────────────────────────────────────────────

    public function testTreatmentFusionKeepsEffective(): void
    {
        $normalizer = new TextNormalizer();
        $dirty = 'ZZ  Probe   Effective ';
        $clean = $normalizer->normalizeKeyword($dirty);
        $this->conn->insert('keyword', ['keyword_original' => 'zz probe effective', 'keyword_display' => $clean['display'], 'keyword_normalized' => $clean['normalized'], 'keyword_type' => 'author_keyword', 'status' => 1]);
        $canonicalId = (int) $this->conn->lastInsertId();
        $this->conn->insert('keyword', ['keyword_original' => $dirty, 'keyword_display' => $dirty, 'keyword_normalized' => $dirty, 'keyword_type' => 'author_keyword', 'status' => 1]);
        $dupId = (int) $this->conn->lastInsertId();
        foreach (array_map('intval', $this->conn->fetchFirstColumn('SELECT id FROM document ORDER BY id LIMIT 3')) as $d) {
            $this->conn->insert('document_keyword', ['document_id' => $d, 'keyword_id' => $dupId, 'original_term' => $dirty, 'effective_keyword_id' => $dupId]);
        }
        $this->assertEffectiveConsistent('precondição');

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

        $this->assertSame(3, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_keyword WHERE keyword_id = ? AND effective_keyword_id = ?', [$dupId, $canonicalId]));
        $this->assertEffectiveConsistent('logo depois do agrupamento do tratamento', true);
    }

    // ── 6. Telas administrativas com SQL próprio ─────────────────────────────────

    private function wire(object $controller): object
    {
        $session = new Session(new MockArraySessionStorage());
        $this->request = Request::create('/', 'POST', ['_token' => 'x']);
        $this->request->setSession($session);
        $stack = new RequestStack();
        $stack->push($this->request);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturn('/destino');
        $container = new Container();
        $container->set('request_stack', $stack);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $controller->setContainer($container);

        return $controller;
    }

    public function testAdminSeparateAuthorVariationKeepsEffective(): void
    {
        $varId = (int) $this->conn->fetchOne(
            'SELECT v.id FROM author_name_variant v JOIN document_author da ON da.author_identity_id = v.author_identity_id AND da.original_name COLLATE utf8mb4_unicode_ci = v.original_name COLLATE utf8mb4_unicode_ci
             WHERE NOT EXISTS (SELECT 1 FROM author_identity a WHERE a.normalized_name COLLATE utf8mb4_unicode_ci = v.normalized_name COLLATE utf8mb4_unicode_ci) LIMIT 1'
        );
        $this->assertGreaterThan(0, $varId);
        $c = $this->wire(new AdminAuthorController($this->em, $this->createStub(ThesaurusFileService::class), new EntityMergeService($this->em, $this->logger()), new AuthorityLinkGuard($this->em), $this->logger()));

        $c->separateVariation($varId, $this->request);

        $this->assertEffectiveConsistent('logo depois de separar a variação de autor');
    }

    public function testAdminSeparateKeywordVariationKeepsEffective(): void
    {
        $link = $this->conn->fetchAssociative('SELECT dk.keyword_id, dk.original_term FROM document_keyword dk ORDER BY dk.id LIMIT 1');
        $this->conn->insert('palavra_chave_variacoes_nome', ['keyword_id' => $link['keyword_id'], 'variation_name' => $link['original_term'], 'normalized_name' => 'zz efetivo separado', 'variation_type' => 'alternative', 'status' => 1]);
        $varId = (int) $this->conn->lastInsertId();
        $c = $this->wire(new AdminKeywordController($this->em, $this->createStub(ThesaurusFileService::class), new EntityMergeService($this->em, $this->logger()), new AuthorityLinkGuard($this->em), $this->logger()));

        $c->separateVariation($varId, $this->request);

        $this->assertEffectiveConsistent('logo depois de separar a variação de palavra-chave');
    }

    public function testAdminMoveAuthorToKeywordCreatesKeywordLinksWithEffective(): void
    {
        $authorId = (int) $this->conn->fetchOne('SELECT author_identity_id FROM document_author GROUP BY author_identity_id HAVING COUNT(*) BETWEEN 2 AND 5 ORDER BY author_identity_id LIMIT 1');
        $c = $this->wire(new AdminReviewController($this->em, new EntityMergeService($this->em, $this->logger()), new AuthorityLinkGuard($this->em), $this->logger()));

        $c->moveAuthorToKeyword($authorId, $this->request);

        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM author_identity WHERE id = ?', [$authorId]));
        $this->assertEffectiveConsistent('logo depois de mover autor para palavra-chave');
    }
}
