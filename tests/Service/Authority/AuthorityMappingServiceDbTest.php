<?php

namespace App\Tests\Service\Authority;

use App\Entity\Keyword;
use App\Service\Authority\AuthorityMappingException;
use App\Service\Authority\AuthorityMappingService;
use App\Service\Authority\AuthorityMergeLogger;
use App\Service\Authority\AuthorityResolver;
use App\Service\Thesaurus\EntityMergeService;
use App\Service\KeywordTreatment\KeywordFuzzyMatcherService;
use App\Service\KeywordTreatment\KeywordTreatmentOptions;
use App\Service\KeywordTreatment\KeywordTreatmentService;
use App\Service\Keyword\KeywordThesaurusMatcherService;
use App\Service\Import\TextNormalizer;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Fase 2 do change tesauro-em-camadas: mesclar agrupa sem apagar. Banco local, tudo em transação desfeita.
 */
class AuthorityMappingServiceDbTest extends LocalDbTestCase
{
    private const TABLES = ['author_identity', 'keyword', 'document_author', 'document_keyword', 'author_name_variant', 'palavra_chave_variacoes_nome'];

    private function svc(?Security $security = null, ?AuthorityMergeLogger $logger = null): AuthorityMappingService
    {
        return new AuthorityMappingService($this->em, new AuthorityResolver($this->conn), $logger ?? new AuthorityMergeLogger($this->em, $security), $security);
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $out = [];
        foreach (self::TABLES as $t) {
            $out[$t] = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM {$t}");
        }

        return $out;
    }

    private function rawAuthorIds(int $id): array
    {
        return array_map('intval', $this->conn->fetchFirstColumn('SELECT id FROM document_author WHERE author_identity_id = ? ORDER BY id', [$id]));
    }

    private function effAuthors(array $linkIds): array
    {
        return array_unique(array_map('intval', $this->conn->fetchFirstColumn('SELECT effective_author_id FROM document_author WHERE id IN (' . implode(',', $linkIds) . ')')));
    }

    private function assertNoDivergence(): void
    {
        $r = new AuthorityResolver($this->conn);
        $this->assertSame(0, $r->process('author', false)['changed'], 'autores divergentes');
        $this->assertSame(0, $r->process('keyword', false)['changed'], 'palavras-chave divergentes');
    }

    public function testMergeAuthorsDeletesNothingKeepsRawIdsAndEffectiveIsTheTarget(): void
    {
        [$a, $b] = $this->pickIds('document_author', 'author_identity_id', 2, 6, 2);
        $linksB = $this->rawAuthorIds($b);
        $before = $this->counts();
        $variantsB = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM author_name_variant WHERE author_identity_id = ?', [$b]);
        $variantsA = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM author_name_variant WHERE author_identity_id = ?', [$a]);
        $user = new class implements \Symfony\Component\Security\Core\User\UserInterface {
            public function getId(): int { return 3; }
            public function getRoles(): array { return ['ROLE_ADMIN']; }
            public function eraseCredentials(): void {}
            public function getUserIdentifier(): string { return 'admin@teste'; }
        };
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $r = $this->svc($security)->merge('author', $b, $a, 'global', 0, 'admin');

        $this->assertFalse($r['noop']);
        $this->assertSame('mapping', $r['mechanism']);
        $this->assertSame($a, $r['target_id']);
        $this->assertSame(count($linksB), $r['affected_links']);
        $this->assertSame($before, $this->counts(), 'nenhuma linha apagada nem criada nas tabelas de dados');
        $this->assertSame($linksB, $this->rawAuthorIds($b), 'o id bruto dos vínculos continua B');
        $this->assertSame([$a], $this->effAuthors($linksB), 'o efetivo é o alvo');
        $this->assertSame($variantsB, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM author_name_variant WHERE author_identity_id = ?', [$b]), 'variações de B ficam com B');
        $this->assertSame($variantsA, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM author_name_variant WHERE author_identity_id = ?', [$a]), 'A não ganha variações duplicadas');
        $this->assertNoDivergence();

        $row = $this->conn->fetchAssociative("SELECT * FROM authority_mapping WHERE entity_type = 'author' AND source_id = ?", [$b]);
        $this->assertSame(['global', 0, $a, 'admin', 3], [$row['scope'], (int) $row['scope_id'], (int) $row['target_id'], $row['origin'], (int) $row['created_by']]);
        $log = $this->logsFor('author', $b)[0];
        $this->assertSame(['screen_admin', 'merge', $a, 3], [$log['origin'], $log['action'], (int) $log['target_id'], (int) $log['user_id']]);
        $this->assertCount(count($linksB), $this->linksOf((int) $log['id']));

        // Repetir é um no-op
        $again = $this->svc()->merge('author', $b, $a, 'global', 0, 'admin');
        $this->assertTrue($again['noop']);
    }

    public function testMergeKeywordsUsesConceptWithoutDeletingAndKeepsVariations(): void
    {
        [$a, $b] = $this->pickIds('document_keyword', 'keyword_id', 2, 6, 2);
        $this->conn->executeStatement('UPDATE keyword SET keyword_concept_id = NULL, thesaurus_concept_id = NULL WHERE id IN (?, ?)', [$a, $b]);
        (new AuthorityResolver($this->conn))->refreshLinks('keyword', "dk.keyword_id IN ({$a}, {$b})");
        $this->conn->insert('palavra_chave_variacoes_nome', ['keyword_id' => $b, 'variation_name' => 'zz var b', 'normalized_name' => 'zz var b', 'variation_type' => 'alternative', 'status' => 1]);
        $linksB = array_map('intval', $this->conn->fetchFirstColumn('SELECT id FROM document_keyword WHERE keyword_id = ? ORDER BY id', [$b]));
        $before = $this->counts();
        $normA = $this->conn->fetchOne('SELECT keyword_normalized FROM keyword WHERE id = ?', [$a]);

        $r = $this->svc()->merge('keyword', $b, $a, 'global', 0, 'admin');

        $this->assertSame('concept', $r['mechanism']);
        $this->assertNull($r['mapping_id']);
        $this->assertSame($before, $this->counts());
        $this->assertSame($a, (int) $this->conn->fetchOne('SELECT keyword_concept_id FROM keyword WHERE id = ?', [$b]));
        $this->assertSame($linksB, array_map('intval', $this->conn->fetchFirstColumn('SELECT id FROM document_keyword WHERE keyword_id = ? ORDER BY id', [$b])));
        $this->assertEqualsCanonicalizing([$a], array_map('intval', $this->conn->fetchFirstColumn('SELECT DISTINCT effective_keyword_id FROM document_keyword WHERE id IN (' . implode(',', $linksB) . ')')));
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM palavra_chave_variacoes_nome WHERE keyword_id = ? AND variation_name = ?', [$b, 'zz var b']), 'a variação fica com B');
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM palavra_chave_variacoes_nome WHERE keyword_id = ? AND variation_name = ?', [$a, 'zz var b']));
        $this->assertSame($normA, $this->conn->fetchOne('SELECT keyword_normalized FROM keyword WHERE id = ?', [$a]), 'a chave normalizada do principal não é reescrita');
        $this->assertNoDivergence();
        $log = $this->logsFor('keyword', $b)[0];
        $this->assertSame(['merge', $a], [$log['action'], (int) $log['target_id']]);
    }

    public function testFinalTargetChainAuthorsAndKeywords(): void
    {
        [$a, $b, $c] = $this->pickIds('document_author', 'author_identity_id', 1, 6, 3);
        $svc = $this->svc();
        $svc->merge('author', $c, $b, 'global', 0, 'admin');
        $r = $svc->merge('author', $b, $a, 'global', 0, 'admin');
        $this->assertSame([$c], $r['retargeted']);
        $this->assertSame($a, (int) $this->conn->fetchOne("SELECT target_id FROM authority_mapping WHERE entity_type = 'author' AND source_id = ?", [$c]), 'C passou a apontar para o alvo final');
        $this->assertSame([$a], $this->effAuthors($this->rawAuthorIds($c)));
        // Agrupar em quem já está agrupado resolve para o alvo final
        $d = (int) $this->pickIds('document_author', 'author_identity_id', 1, 6, 1, [$a, $b, $c])[0];
        $r2 = $svc->merge('author', $d, $b, 'global', 0, 'admin');
        $this->assertSame($a, $r2['target_id']);
        $this->assertNoDivergence();

        [$ka, $kb, $kc] = $this->pickIds('document_keyword', 'keyword_id', 1, 6, 3);
        $this->conn->executeStatement("UPDATE keyword SET keyword_concept_id = NULL, thesaurus_concept_id = NULL WHERE id IN ({$ka}, {$kb}, {$kc})");
        (new AuthorityResolver($this->conn))->refreshLinks('keyword', "dk.keyword_id IN ({$ka}, {$kb}, {$kc})");
        $svc->merge('keyword', $kc, $kb, 'global', 0, 'admin');
        $rk = $svc->merge('keyword', $kb, $ka, 'global', 0, 'admin');
        $this->assertSame([$kc], $rk['dependents']);
        $this->assertSame($ka, (int) $this->conn->fetchOne('SELECT keyword_concept_id FROM keyword WHERE id = ?', [$kc]), 'quem apontava para B passa a apontar para A (sem encadeamento)');
        $this->assertNoDivergence();
    }

    public function testSelfLoopAndAlreadyGroupedElsewhereAreRejectedWithClearMessage(): void
    {
        [$a, $b, $c] = $this->pickIds('document_author', 'author_identity_id', 1, 6, 3);
        $svc = $this->svc();
        try {
            $svc->merge('author', $a, $a, 'global', 0, 'admin');
            $this->fail('auto-mapeamento devia ser recusado');
        } catch (AuthorityMappingException $e) {
            $this->assertStringContainsString('nele mesmo', $e->getMessage());
        }
        $svc->merge('author', $a, $b, 'global', 0, 'admin');
        try {
            $svc->merge('author', $b, $a, 'global', 0, 'admin');
            $this->fail('laço devia ser recusado');
        } catch (AuthorityMappingException $e) {
            $this->assertStringContainsString('laço', $e->getMessage());
        }
        try {
            $svc->merge('author', $a, $c, 'global', 0, 'admin');
            $this->fail('origem já agrupada em outro destino devia ser recusada');
        } catch (AuthorityMappingException $e) {
            $this->assertStringContainsString('já está agrupada', $e->getMessage());
        }
        // Nada mudou: só o primeiro agrupamento existe
        $this->assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM authority_mapping WHERE entity_type = 'author'"));

        [$ka, $kb] = $this->pickIds('document_keyword', 'keyword_id', 1, 6, 2);
        $this->conn->executeStatement("UPDATE keyword SET keyword_concept_id = NULL, thesaurus_concept_id = NULL WHERE id IN ({$ka}, {$kb})");
        $svc->merge('keyword', $ka, $kb, 'global', 0, 'admin');
        $this->expectException(AuthorityMappingException::class);
        $this->expectExceptionMessage('laço');
        $svc->merge('keyword', $kb, $ka, 'global', 0, 'admin');
    }

    public function testUndoRestoresTheEffectiveAndLogsUndo(): void
    {
        [$a, $b] = $this->pickIds('document_author', 'author_identity_id', 2, 6, 2);
        $linksB = $this->rawAuthorIds($b);
        $svc = $this->svc();
        $svc->merge('author', $b, $a, 'global', 0, 'admin');
        $this->assertSame([$a], $this->effAuthors($linksB));

        $u = $svc->undo('author', 'global', 0, $b);

        $this->assertSame(count($linksB), $u['affected_links']);
        $this->assertSame([$b], $this->effAuthors($linksB));
        $this->assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM authority_mapping WHERE entity_type = 'author' AND source_id = ?", [$b]));
        $actions = array_column($this->logsFor('author', $b), 'action');
        $this->assertSame(['merge', 'undo'], $actions);
        $this->assertNoDivergence();
        $this->expectException(AuthorityMappingException::class);
        $svc->undo('author', 'global', 0, $b);
    }

    public function testUndoKeywordConceptReturnsConceptToItselfAndReopensDependents(): void
    {
        [$ka, $kb, $kc] = $this->pickIds('document_keyword', 'keyword_id', 1, 6, 3);
        $this->conn->executeStatement("UPDATE keyword SET keyword_concept_id = NULL, thesaurus_concept_id = NULL WHERE id IN ({$ka}, {$kb}, {$kc})");
        $this->conn->executeStatement('UPDATE keyword SET keyword_concept_id = ? WHERE id = ?', [$kb, $kc]);
        (new AuthorityResolver($this->conn))->refreshLinks('keyword', "dk.keyword_id IN ({$ka}, {$kb}, {$kc})");
        $linksC = array_map('intval', $this->conn->fetchFirstColumn('SELECT id FROM document_keyword WHERE keyword_id = ?', [$kc]));
        $svc = $this->svc();
        $svc->merge('keyword', $kb, $ka, 'global', 0, 'admin');
        $this->assertSame([$ka], array_map('intval', array_unique($this->conn->fetchFirstColumn('SELECT effective_keyword_id FROM document_keyword WHERE id IN (' . implode(',', $linksC) . ')'))));

        $u = $svc->undo('keyword', 'global', 0, $kb);

        $this->assertSame([$kc], $u['reopened']);
        $this->assertSame($kb, (int) $this->conn->fetchOne('SELECT keyword_concept_id FROM keyword WHERE id = ?', [$kb]));
        $this->assertSame($kb, (int) $this->conn->fetchOne('SELECT keyword_concept_id FROM keyword WHERE id = ?', [$kc]), 'o dependente volta a B');
        $this->assertSame([$kb], array_map('intval', array_unique($this->conn->fetchFirstColumn('SELECT effective_keyword_id FROM document_keyword WHERE id IN (' . implode(',', $linksC) . ')'))));
        $this->assertSame(['merge', 'undo'], array_column($this->logsFor('keyword', $kb), 'action'));
        $this->assertNoDivergence();
    }

    public function testKeywordWithThesaurusConceptGroupsByMappingAndUndoReopensDependents(): void
    {
        [$ka, $kb, $kc] = $this->pickIds('document_keyword', 'keyword_id', 1, 6, 3);
        $this->conn->executeStatement("UPDATE keyword SET keyword_concept_id = NULL, thesaurus_concept_id = NULL WHERE id IN ({$ka}, {$kb}, {$kc})");
        $sid = (int) $this->conn->fetchOne("SELECT id FROM thesaurus_scheme ORDER BY id LIMIT 1");
        if (!$sid) {
            $this->conn->executeStatement("INSERT INTO thesaurus_scheme (name, slug, type, created_at, updated_at) VALUES ('Zz', 'zz-fase2', 'keyword', NOW(), NOW())");
            $sid = (int) $this->conn->lastInsertId();
        }
        $this->conn->executeStatement("INSERT INTO thesaurus_concept (scheme_id, preferred_label, normalized_label, status, created_at, updated_at) VALUES (?, 'zz conceito', 'zz conceito', 'active', NOW(), NOW())", [$sid]);
        $tc = (int) $this->conn->lastInsertId();
        // A está num conceito do tesauro (efetivo negativo); C estava agrupada sob B
        $this->conn->executeStatement('UPDATE keyword SET thesaurus_concept_id = ? WHERE id = ?', [$tc, $ka]);
        $this->conn->executeStatement('UPDATE keyword SET keyword_concept_id = ? WHERE id = ?', [$kb, $kc]);
        (new AuthorityResolver($this->conn))->refreshLinks('keyword', "dk.keyword_id IN ({$ka}, {$kb}, {$kc})");
        $linksB = array_map('intval', $this->conn->fetchFirstColumn('SELECT id FROM document_keyword WHERE keyword_id = ?', [$kb]));
        $linksC = array_map('intval', $this->conn->fetchFirstColumn('SELECT id FROM document_keyword WHERE keyword_id = ?', [$kc]));
        $svc = $this->svc();

        $r = $svc->merge('keyword', $kb, $ka, 'global', 0, 'admin');

        $this->assertSame('mapping', $r['mechanism'], 'efetivo negativo não cabe em keyword_concept_id');
        $this->assertSame(-$tc, $r['target_id']);
        foreach ([$linksB, $linksC] as $links) {
            $this->assertSame([-$tc], array_map('intval', array_unique($this->conn->fetchFirstColumn('SELECT effective_keyword_id FROM document_keyword WHERE id IN (' . implode(',', $links) . ')'))));
        }
        $this->assertNoDivergence();

        $svc->undo('keyword', 'global', 0, $kb);
        $this->assertSame([$kb], array_map('intval', array_unique($this->conn->fetchFirstColumn('SELECT effective_keyword_id FROM document_keyword WHERE id IN (' . implode(',', $linksB) . ')'))));
        $this->assertSame([$kb], array_map('intval', array_unique($this->conn->fetchFirstColumn('SELECT effective_keyword_id FROM document_keyword WHERE id IN (' . implode(',', $linksC) . ')'))), 'o dependente reabre junto');
        $this->assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM authority_mapping WHERE entity_type = 'keyword'"));
        $this->assertNoDivergence();
    }

    public function testProjectScopeOnlyChangesThatProject(): void
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT da.author_identity_id id FROM document_author da JOIN document d ON d.id = da.document_id GROUP BY da.author_identity_id HAVING COUNT(DISTINCT d.project_id) >= 2 LIMIT 2'
        );
        if (count($rows) < 2) {
            $this->markTestSkipped('Sem dois autores presentes em dois projetos.');
        }
        [$a, $b] = array_map(fn($r) => (int) $r['id'], $rows);
        $projects = array_map('intval', $this->conn->fetchFirstColumn('SELECT DISTINCT d.project_id FROM document_author da JOIN document d ON d.id = da.document_id WHERE da.author_identity_id = ? ORDER BY 1', [$b]));
        $p1 = $projects[0];
        $this->svc()->merge('author', $b, $a, 'project', $p1, 'project');

        $inP1 = array_unique($this->conn->fetchFirstColumn('SELECT da.effective_author_id FROM document_author da JOIN document d ON d.id = da.document_id WHERE da.author_identity_id = ? AND d.project_id = ?', [$b, $p1]));
        $elsewhere = array_unique($this->conn->fetchFirstColumn('SELECT da.effective_author_id FROM document_author da JOIN document d ON d.id = da.document_id WHERE da.author_identity_id = ? AND d.project_id <> ?', [$b, $p1]));
        $this->assertSame([$a], array_map('intval', $inP1));
        $this->assertSame([$b], array_map('intval', $elsewhere));
        $this->assertNoDivergence();
        $log = $this->logsFor('author', $b)[0];
        $this->assertSame(['screen_project', $p1], [$log['origin'], (int) $log['project_id']]);
    }

    public function testListShowsNamesWhoWhenAndDocumentCountsWithoutNPlusOne(): void
    {
        [$a, $b, $c] = $this->pickIds('document_author', 'author_identity_id', 1, 6, 3);
        $svc = $this->svc();
        $svc->merge('author', $b, $a, 'global', 0, 'admin');
        $svc->merge('author', $c, $a, 'global', 0, 'treatment');

        $nameB = $this->conn->fetchOne('SELECT preferred_name FROM author_identity WHERE id = ?', [$b]);
        $nameA = $this->conn->fetchOne('SELECT preferred_name FROM author_identity WHERE id = ?', [$a]);
        $queries = 0;
        $list = $svc->list('author', 'global', 0, 1, 100);

        $this->assertSame(2, $list['total']);
        $byId = array_column($list['items'], null, 'source_id');
        $this->assertSame([$nameB, $nameA, $a], [$byId[$b]['source_label'], $byId[$b]['target_label'], $byId[$b]['target_id']]);
        $this->assertSame((int) $this->conn->fetchOne('SELECT COUNT(DISTINCT document_id) FROM document_author WHERE author_identity_id = ?', [$b]), $byId[$b]['documents']);
        $this->assertSame('treatment', $byId[$c]['origin']);
        $this->assertNotNull($byId[$b]['created_at']);
        $this->assertSame([$b => ['target_id' => $a, 'target_label' => $nameA]] + [$c => ['target_id' => $a, 'target_label' => $nameA]], $svc->globalGroupings('author', [$b, $c, $a]));
    }

    public function testListIncludesKeywordConceptGroupings(): void
    {
        [$ka, $kb] = $this->pickIds('document_keyword', 'keyword_id', 1, 6, 2);
        $this->conn->executeStatement("UPDATE keyword SET keyword_concept_id = NULL, thesaurus_concept_id = NULL WHERE id IN ({$ka}, {$kb})");
        $this->svc()->merge('keyword', $kb, $ka, 'global', 0, 'admin');

        $found = null;
        for ($page = 1; $page <= 5 && !$found; $page++) {
            $list = $this->svc()->list('keyword', 'global', 0, $page, 100);
            foreach ($list['items'] as $it) {
                if ($it['source_id'] === $kb) {
                    $found = $it;
                }
            }
            if ($page >= $list['pages']) {
                break;
            }
        }
        $this->assertNotNull($found, 'o agrupamento por conceito aparece na lista');
        $this->assertSame(['concept', $ka], [$found['kind'], $found['target_id']]);
        $this->assertSame('admin', $found['origin'], 'quem e quando vêm do diário');
    }

    public function testFailureInsideMergeRollsEverythingBack(): void
    {
        [$a, $b] = $this->pickIds('document_author', 'author_identity_id', 2, 6, 2);
        $broken = new class($this->em) extends AuthorityMergeLogger {
            public function logAuthorMerge(string $origin, string $action, int $sourceId, ?int $targetId, ?int $userId = null, ?int $projectId = null, array $extra = []): int
            {
                throw new \RuntimeException('diário indisponível');
            }
        };
        try {
            $this->svc(null, $broken)->merge('author', $b, $a, 'global', 0, 'admin');
            $this->fail('devia falhar junto com o diário');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM authority_mapping WHERE entity_type = 'author'"));
        $this->assertSame([$b], $this->effAuthors($this->rawAuthorIds($b)));
    }

    public function testEntityMergeServiceBatchAppliesAllOrNothing(): void
    {
        [$a, $b, $c] = $this->pickIds('document_author', 'author_identity_id', 1, 6, 3);
        $before = $this->counts();
        $service = new EntityMergeService($this->em, new AuthorityMergeLogger($this->em));
        $service->mergeAuthors($a, [$b, $c], []);
        $this->assertSame($before, $this->counts());
        $this->assertSame(2, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM authority_mapping WHERE entity_type = 'author' AND target_id = ?", [$a]));

        // Lote com um par recusado: A já está agrupado em X; B→A (alvo final X) é gravado e X→A forma laço: o lote inteiro é desfeito
        [$x, $d] = $this->pickIds('document_author', 'author_identity_id', 1, 6, 2, [$a, $b, $c]);
        $service->mergeAuthors($x, [$a], []);
        $rowsBefore = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM authority_mapping WHERE entity_type = 'author'");
        try {
            $service->mergeAuthors($a, [$d, $x], []);
            $this->fail('laço devia ser recusado');
        } catch (AuthorityMappingException $e) {
            $this->assertStringContainsString('laço', $e->getMessage());
        }
        $this->assertSame($rowsBefore, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM authority_mapping WHERE entity_type = 'author'"), 'o par válido do lote também foi desfeito');
        $this->assertSame([$d], $this->effAuthors($this->rawAuthorIds($d)));
    }

    public function testTreatmentCollisionGroupsWithoutDeletingAndSecondRunIsSilent(): void
    {
        $normalizer = new TextNormalizer();
        $dirty = 'ZZ  Probe   Fase2 ';
        $clean = $normalizer->normalizeKeyword($dirty);
        $this->conn->insert('keyword', ['keyword_original' => 'zz probe fase2', 'keyword_display' => $clean['display'], 'keyword_normalized' => $clean['normalized'], 'keyword_type' => 'author_keyword', 'status' => 1]);
        $canonicalId = (int) $this->conn->lastInsertId();
        $this->conn->insert('keyword', ['keyword_original' => $dirty, 'keyword_display' => $dirty, 'keyword_normalized' => $dirty, 'keyword_type' => 'author_keyword', 'status' => 1]);
        $dupId = (int) $this->conn->lastInsertId();
        $docs = array_map('intval', $this->conn->fetchFirstColumn('SELECT id FROM document ORDER BY id LIMIT 3'));
        foreach ($docs as $d) {
            $this->conn->insert('document_keyword', ['document_id' => $d, 'keyword_id' => $dupId, 'original_term' => $dirty, 'effective_keyword_id' => $dupId]);
        }
        $before = $this->counts();

        $fuzzy = new KeywordFuzzyMatcherService();
        $emRef = $this->em;
        $service = new class($this->em, $fuzzy, new KeywordThesaurusMatcherService($this->em, $fuzzy), $dupId, $emRef) extends KeywordTreatmentService {
            public function __construct($em, $f, $m, private readonly int $dupId, private readonly \Doctrine\ORM\EntityManagerInterface $emRef) { parent::__construct($em, $f, $m); }

            protected function fetchBatch(int $lastId, int $limit): array
            {
                return $lastId === 0 ? $this->emRef->getRepository(Keyword::class)->findBy(['id' => $this->dupId]) : [];
            }
        };
        $opts = new KeywordTreatmentOptions(dryRun: false, limit: 10, processInvalids: true, processExact: false, processThesaurus: false, processFuzzy: false);
        $job1 = $service->executeJob($opts, 'teste');

        $this->assertSame($before, $this->counts(), 'nada apagado nem repontuado');
        $this->assertSame($canonicalId, (int) $this->conn->fetchOne('SELECT keyword_concept_id FROM keyword WHERE id = ?', [$dupId]));
        $this->assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM keyword_treatment_log WHERE job_id = ? AND action = 'merged'", [$job1->getId()]));
        $this->assertSame($dirty, $this->conn->fetchOne('SELECT keyword_normalized FROM keyword WHERE id = ?', [$dupId]), 'a forma normalizada do termo agrupado fica');

        // Segunda rodada: já agrupado, não conta nem registra de novo
        $this->em->clear();
        $job2 = $service->executeJob($opts, 'teste');
        $this->assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM keyword_treatment_log WHERE job_id = ? AND action = 'merged'", [$job2->getId()]));
        $this->assertSame(1, count($this->logsFor('keyword', $dupId)), 'um só registro no diário');
    }
}
