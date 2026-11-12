<?php

namespace App\Tests\Service\Normalize;

use App\Command\RenormalizeAuthorsCommand;
use App\Entity\BibliometricProject;
use App\Entity\User;
use App\Service\Authority\AuthorityMappingException;
use App\Service\Authority\AuthorityMappingService;
use App\Service\Authority\AuthorityMergeLogger;
use App\Service\Authority\AuthorityResolver;
use App\Service\Import\DocumentEnrichmentService;
use App\Service\Normalize\NormalizationService;
use App\Service\ProjectCopyService;
use App\Tests\Service\Authority\LocalDbTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Fase 3 do change tesauro-em-camadas: a tela de normalização do projeto agrupa sem apagar (administrador no escopo
 * global, usuário comum no escopo do projeto), lista e desfaz os agrupamentos do projeto, as sugestões leem a
 * identidade efetiva e a cópia do projeto leva os agrupamentos. Banco local, tudo em transação desfeita.
 */
class NormalizationGroupingDbTest extends LocalDbTestCase
{
    private const TABLES = ['author_identity', 'keyword', 'document_author', 'document_keyword', 'author_name_variant', 'palavra_chave_variacoes_nome', 'document'];

    private function norm(): NormalizationService
    {
        $logger = new AuthorityMergeLogger($this->em);
        $resolver = new AuthorityResolver($this->conn);

        return new NormalizationService($this->em, $logger, $resolver, new AuthorityMappingService($this->em, $resolver, $logger));
    }

    private function mapping(): AuthorityMappingService
    {
        $resolver = new AuthorityResolver($this->conn);

        return new AuthorityMappingService($this->em, $resolver, new AuthorityMergeLogger($this->em));
    }

    private function bigProject(): int
    {
        $id = (int) $this->conn->fetchOne('SELECT project_id FROM document GROUP BY project_id ORDER BY COUNT(*) DESC LIMIT 1');
        if ($id === 0) {
            $this->markTestSkipped('Banco local sem documentos.');
        }

        return $id;
    }

    /** @return list<int> identidades efetivas do projeto com poucos vínculos, de modo que as mesclagens sejam leves */
    private function projectAuthors(int $projectId, int $n): array
    {
        return array_map('intval', $this->conn->fetchFirstColumn(
            'SELECT da.effective_author_id FROM document_author da JOIN document d ON d.id = da.document_id
             WHERE d.project_id = ? GROUP BY da.effective_author_id HAVING COUNT(*) BETWEEN 2 AND 6 ORDER BY da.effective_author_id LIMIT ' . $n,
            [$projectId]
        ));
    }

    /** @return list<int> palavras-chave efetivas positivas, de autor, fora do tesauro */
    private function projectKeywords(int $projectId, int $n): array
    {
        return array_map('intval', $this->conn->fetchFirstColumn(
            "SELECT dk.effective_keyword_id FROM document_keyword dk JOIN document d ON d.id = dk.document_id
             JOIN keyword k ON k.id = dk.effective_keyword_id
             WHERE d.project_id = ? AND dk.effective_keyword_id > 0 AND k.thesaurus_concept_id IS NULL AND k.keyword_type = 'author_keyword'
             GROUP BY dk.effective_keyword_id HAVING COUNT(*) BETWEEN 2 AND 6 ORDER BY dk.effective_keyword_id LIMIT " . $n,
            [$projectId]
        ));
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

    private function mappingRow(string $entity, string $scope, int $scopeId, int $source): ?array
    {
        $r = $this->conn->fetchAssociative(
            'SELECT * FROM authority_mapping WHERE entity_type = ? AND scope = ? AND scope_id = ? AND source_id = ?',
            [$entity, $scope, $scopeId, $source]
        );

        return $r ?: null;
    }

    private function effectiveAuthorsOf(int $rawAuthor, int $projectId): array
    {
        return array_map('intval', $this->conn->fetchFirstColumn(
            'SELECT DISTINCT da.effective_author_id FROM document_author da JOIN document d ON d.id = da.document_id WHERE d.project_id = ? AND da.author_identity_id = ?',
            [$projectId, $rawAuthor]
        ));
    }

    // ── papel → escopo ───────────────────────────────────────────────────────────

    public function testCommonUserGroupsInProjectScopeAndAdminInGlobalScopeNothingIsDeleted(): void
    {
        $p = $this->bigProject();
        [$a, $b, $c, $d] = $this->projectAuthors($p, 4);
        $before = $this->counts();
        $linksB = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_author WHERE author_identity_id = ?', [$b]);
        $rawIds = $this->conn->fetchFirstColumn('SELECT id FROM document_author WHERE author_identity_id IN (?, ?) ORDER BY id', [$b, $d]);
        $svc = $this->norm();

        // Usuário comum: escopo do projeto
        $this->assertTrue($svc->mergeAuthors($a, $b, $p, [], false));
        $row = $this->mappingRow('author', 'project', $p, $b);
        $this->assertNotNull($row);
        $this->assertSame([$a, 'project'], [(int) $row['target_id'], $row['origin']]);
        $this->assertNull($this->mappingRow('author', 'global', 0, $b), 'o usuário comum não grava no global');
        $this->assertSame([$a], $this->effectiveAuthorsOf($b, $p));

        // Administrador: escopo global (scope_id 0)
        $this->assertTrue($svc->mergeAuthors($c, $d, $p, [], true));
        $row = $this->mappingRow('author', 'global', 0, $d);
        $this->assertNotNull($row);
        $this->assertSame([$c, 'admin'], [(int) $row['target_id'], $row['origin']]);
        $this->assertNull($this->mappingRow('author', 'project', $p, $d));

        // Nada foi apagado nem repontuado
        $this->assertSame($before, $this->counts());
        $this->assertSame($linksB, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_author WHERE author_identity_id = ?', [$b]));
        $this->assertSame($rawIds, $this->conn->fetchFirstColumn('SELECT id FROM document_author WHERE author_identity_id IN (?, ?) ORDER BY id', [$b, $d]));
        $this->assertSame(0, (new AuthorityResolver($this->conn))->process('author', false)['changed'], 'o efetivo está em dia');
    }

    public function testKeywordGroupingByRoleAndBatchNothingDeleted(): void
    {
        $p = $this->bigProject();
        [$a, $b, $c, $d] = $this->projectKeywords($p, 4);
        $before = $this->counts();
        $svc = $this->norm();

        $this->assertSame(1, $svc->mergeKeywordsBatch([['keepId' => $a, 'discardId' => $b]], $p, false));
        $this->assertSame($a, (int) $this->mappingRow('keyword', 'project', $p, $b)['target_id']);

        $this->assertTrue($svc->mergeKeywords($c, $d, $p, [], true));
        // Global de palavra-chave: conceito agrupador (keyword_concept_id), sem linha de projeto
        $this->assertNull($this->mappingRow('keyword', 'project', $p, $d));
        $this->assertSame($c, (int) $this->conn->fetchOne('SELECT keyword_concept_id FROM keyword WHERE id = ?', [$d]));

        $this->assertSame($before, $this->counts());
        $this->assertSame(0, (new AuthorityResolver($this->conn))->process('keyword', false)['changed']);
    }

    public function testBatchSkipsRefusedPairsAndReportsThemWithoutAbortingTheRest(): void
    {
        $p = $this->bigProject();
        [$a, $b, $c, $d] = $this->projectAuthors($p, 4);
        $svc = $this->norm();
        $svc->mergeAuthors($a, $b, $p);

        // (b em a) repetido = nada a fazer; (a em b) formaria um laço = recusado; (d em c) segue
        $n = $svc->mergeAuthorsBatch([['keepId' => $a, 'discardId' => $b], ['keepId' => $b, 'discardId' => $a], ['keepId' => $c, 'discardId' => $d]], $p);
        $this->assertSame(1, $n);
        $this->assertCount(1, $svc->lastRefusals());
        $this->assertStringContainsString('laço', $svc->lastRefusals()[0]);
        $this->assertNotNull($this->mappingRow('author', 'project', $p, $d));
    }

    public function testLoopAndSelfMappingAreRefusedWithAPortugueseMessage(): void
    {
        $p = $this->bigProject();
        [$a, $b] = $this->projectAuthors($p, 2);
        $svc = $this->norm();
        $svc->mergeAuthors($a, $b, $p);

        try {
            $svc->mergeAuthors($b, $a, $p);
            $this->fail('o laço deveria ser recusado');
        } catch (AuthorityMappingException $e) {
            $this->assertStringContainsString('laço', $e->getMessage());
        }
        $this->assertNull($this->mappingRow('author', 'project', $p, $a));

        $this->expectException(AuthorityMappingException::class);
        $svc->mergeAuthors($a, $a, $p);
    }

    // ── lista e desfazer ──────────────────────────────────────────────────────────

    public function testUndoProjectGroupingRestoresTheEffectiveIdentity(): void
    {
        $p = $this->bigProject();
        [$a, $b] = $this->projectAuthors($p, 2);
        $svc = $this->norm();
        $svc->mergeAuthors($a, $b, $p);

        $list = $svc->projectGroupings('author', $p);
        $this->assertSame(1, $list['total']);
        $this->assertSame($b, $list['items'][0]['source_id']);
        $mappingId = (int) $list['items'][0]['mapping_id'];

        $svc->undoProjectGrouping($p, $mappingId);
        $this->assertNull($this->mappingRow('author', 'project', $p, $b));
        $this->assertSame([$b], $this->effectiveAuthorsOf($b, $p), 'volta a contar por conta própria');
        $this->assertSame(0, $svc->projectGroupings('author', $p)['total']);

        // Desfazer de novo: já foi desfeito
        $this->expectException(AuthorityMappingException::class);
        $svc->undoProjectGrouping($p, $mappingId);
    }

    public function testUserCannotUndoGroupingOfAnotherProjectOrGlobal(): void
    {
        $p = $this->bigProject();
        $other = (int) $this->conn->fetchOne('SELECT project_id FROM document WHERE project_id <> ? GROUP BY project_id LIMIT 1', [$p]);
        if ($other === 0) {
            $this->markTestSkipped('Só há um projeto com documentos.');
        }
        [$a, $b, $c, $d] = $this->projectAuthors($p, 4);
        $svc = $this->norm();
        $svc->mergeAuthors($a, $b, $p);                 // do projeto p
        $svc->mergeAuthors($c, $d, $p, [], true);       // global
        $projectMapping = (int) $this->mappingRow('author', 'project', $p, $b)['id'];
        $globalMapping = (int) $this->mappingRow('author', 'global', 0, $d)['id'];

        foreach ([[$other, $projectMapping], [$p, $globalMapping], [$other, $globalMapping]] as [$project, $mappingId]) {
            try {
                $svc->undoProjectGrouping($project, $mappingId);
                $this->fail('deveria recusar o desfazer fora do escopo do projeto');
            } catch (AuthorityMappingException $e) {
                $this->assertStringContainsString('não pertence a este projeto', $e->getMessage());
            }
        }
        $this->assertNotNull($this->mappingRow('author', 'project', $p, $b), 'o agrupamento do projeto segue intacto');
        $this->assertNotNull($this->mappingRow('author', 'global', 0, $d), 'o global segue intacto');
    }

    public function testGlobalGroupingsInUseListsOnlyGlobalOnesThatTouchTheProject(): void
    {
        $p = $this->bigProject();
        [$a, $b, $c, $d] = $this->projectAuthors($p, 4);
        $svc = $this->norm();
        $svc->mergeAuthors($a, $b, $p);                 // projeto: não entra na lista de globais
        $svc->mergeAuthors($c, $d, $p, [], true);       // global

        $in = $svc->globalGroupingsInUse('author', $p);
        $ids = array_column($in['items'], 'source_id');
        $this->assertContains($d, $ids);
        $this->assertNotContains($b, $ids);
        $row = $in['items'][array_search($d, $ids, true)];
        $this->assertSame($c, $row['target_id']);
        $this->assertGreaterThan(0, $row['documents']);

        $kw = $svc->globalGroupingsInUse('keyword', $p);
        $this->assertArrayHasKey('items', $kw);
    }

    // ── sugestões sobre a identidade efetiva ──────────────────────────────────────

    public function testSuggestionsNoLongerListAGroupedPair(): void
    {
        $p = $this->bigProject();
        $svc = $this->norm();

        $authors = $svc->findSimilarAuthors($p);
        $this->assertNotEmpty($authors);
        $pair = $authors[0];
        $keep = (int) $pair['keep']['id'];
        $discard = (int) $pair['discard']['id'];
        $svc->mergeAuthors($keep, $discard, $p);
        $after = $svc->findSimilarAuthors($p);
        foreach ($after as $s) {
            $this->assertNotSame($discard, (int) $s['keep']['id']);
            $this->assertNotSame($discard, (int) $s['discard']['id'], 'a identidade agrupada deixa de aparecer');
        }
        $this->assertLessThan(count($authors), count($after));

        $kws = $svc->findSimilarKeywords($p, 'author');
        $this->assertNotEmpty($kws);
        $kpair = $kws[0];
        $kKeep = (int) $kpair['keep']['id'];
        $kDiscard = (int) $kpair['discard']['id'];
        $svc->mergeKeywords($kKeep, $kDiscard, $p);
        foreach ($svc->findSimilarKeywords($p, 'author') as $s) {
            $this->assertNotSame($kDiscard, (int) $s['keep']['id']);
            $this->assertNotSame($kDiscard, (int) $s['discard']['id']);
        }
        // O mantido passa a contar os documentos do agrupado (sem contar duas vezes o documento que tinha os dois)
        $all = array_column($svc->getAllKeywords($p, 'author'), null, 'id');
        $this->assertArrayNotHasKey($kDiscard, $all);
    }

    public function testSuggestionsWithoutGroupingsMatchTheRawIdentities(): void
    {
        // Sem agrupamentos do projeto, o efetivo é o bruto (autor) e a identidade já resolvida (palavra-chave)
        $p = $this->bigProject();
        $this->assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM authority_mapping WHERE scope = 'project' AND scope_id = ?", [$p]));
        $this->assertSame(0, (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM document_author da JOIN document d ON d.id = da.document_id WHERE d.project_id = ? AND da.effective_author_id <> da.author_identity_id',
            [$p]
        ));
        $svc = $this->norm();
        $ids = array_map(fn ($s) => (int) $s['keep']['id'], $svc->findSimilarAuthors($p));
        $raw = array_map('intval', $this->conn->fetchFirstColumn(
            'SELECT DISTINCT da.author_identity_id FROM document_author da JOIN document d ON d.id = da.document_id WHERE d.project_id = ?',
            [$p]
        ));
        $this->assertSame([], array_diff($ids, $raw));
    }

    // ── importação de CSV ─────────────────────────────────────────────────────────

    public function testCsvImportGroupsByRoleAndDeletesNothing(): void
    {
        $p = $this->bigProject();
        $svc = $this->norm();

        // Um par de autores com nomes que normalizam diferente
        $pair = null;
        foreach ($svc->findSimilarAuthors($p) as $s) {
            if (DocumentEnrichmentService::normalize($s['keep']['name']) !== DocumentEnrichmentService::normalize($s['discard']['name'])) {
                $pair = $s;
                break;
            }
        }
        $this->assertNotNull($pair);
        $before = $this->counts();
        $keep = (int) $pair['keep']['id'];
        $discard = (int) $pair['discard']['id'];
        $rows = [['preferred_name' => $pair['keep']['name'], 'variant_name' => $pair['discard']['name']]];

        $this->assertSame(1, $svc->importAuthorNormalization($p, $rows, false));
        $this->assertSame($keep, (int) $this->mappingRow('author', 'project', $p, $discard)['target_id']);
        $this->assertNull($this->mappingRow('author', 'global', 0, $discard));
        $this->assertSame($before, $this->counts());

        // Repetir o mesmo arquivo: já agrupado, nada a fazer e nada recusado
        $this->assertSame(0, $svc->importAuthorNormalization($p, $rows, false));

        // Palavras-chave, por administrador: global (conceito agrupador)
        $kpair = null;
        foreach ($svc->findSimilarKeywords($p, 'author') as $s) {
            if (DocumentEnrichmentService::normalize($s['keep']['term']) !== DocumentEnrichmentService::normalize($s['discard']['term'])) {
                $kpair = $s;
                break;
            }
        }
        $this->assertNotNull($kpair);
        $kRows = [['preferred_keyword' => $kpair['keep']['term'], 'variant_keyword' => $kpair['discard']['term'], 'keyword_type' => 'author_keyword']];
        $this->assertSame(1, $svc->importKeywordNormalization($p, $kRows, true));
        $this->assertNull($this->mappingRow('keyword', 'project', $p, (int) $kpair['discard']['id']));
        $this->assertSame((int) $kpair['keep']['id'], (int) $this->conn->fetchOne('SELECT keyword_concept_id FROM keyword WHERE id = ?', [(int) $kpair['discard']['id']]));
    }

    public function testCsvImportByCommonUserDoesNotRegisterNameVariations(): void
    {
        $p = $this->bigProject();
        $svc = $this->norm();
        $a = $this->projectAuthors($p, 1)[0];
        $name = (string) $this->conn->fetchOne('SELECT preferred_name FROM author_identity WHERE id = ?', [$a]);
        $variants = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM author_name_variant');

        $n = $svc->importAuthorNormalization($p, [['preferred_name' => $name, 'variant_name' => 'Zzzinexistente, Qq']], false);
        $this->assertSame(0, $n);
        $this->assertSame($variants, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM author_name_variant'));
        $this->assertCount(1, $svc->lastRefusals());
    }

    // ── cópia do projeto ──────────────────────────────────────────────────────────

    public function testProjectCopyBringsTheProjectGroupings(): void
    {
        $sourceId = (int) $this->conn->fetchOne(
            'SELECT d.project_id FROM document d JOIN document_author da ON da.document_id = d.id GROUP BY d.project_id ORDER BY COUNT(*) ASC LIMIT 1'
        );
        $source = $this->em->find(BibliometricProject::class, $sourceId);
        $user = $this->em->getRepository(User::class)->findOneBy([]);
        $authors = $this->projectAuthors($sourceId, 2);
        $keywords = $this->projectKeywords($sourceId, 2);
        if (count($authors) < 2) {
            $this->markTestSkipped('Projeto pequeno demais para o teste.');
        }
        $svc = $this->norm();
        $svc->mergeAuthors($authors[0], $authors[1], $sourceId);
        if (count($keywords) === 2) {
            $svc->mergeKeywords($keywords[0], $keywords[1], $sourceId);
        }
        $mappings = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM authority_mapping WHERE scope = 'project' AND scope_id = ?", [$sourceId]);
        $this->assertGreaterThanOrEqual(1, $mappings);

        $uploads = dirname(__DIR__, 3) . '/var/uploads';
        $beforeDirs = glob($uploads . '/*', GLOB_ONLYDIR) ?: [];
        try {
            $copy = (new ProjectCopyService($this->em, $this->conn, new \Symfony\Component\String\Slugger\AsciiSlugger(), dirname(__DIR__, 3)))->copy($source, $user);
        } finally {
            // A cópia de arquivo acontece fora da transação: remove as pastas novas de var/uploads
            foreach (array_diff(glob($uploads . '/*', GLOB_ONLYDIR) ?: [], $beforeDirs) as $dir) {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }
        $newId = (int) $copy->getId();
        $this->assertNotSame($sourceId, $newId);

        $cols = 'entity_type, source_id, target_id, origin, created_by, created_at, note';
        $orig = $this->conn->fetchAllAssociative("SELECT {$cols} FROM authority_mapping WHERE scope = 'project' AND scope_id = ? ORDER BY entity_type, source_id", [$sourceId]);
        $new = $this->conn->fetchAllAssociative("SELECT {$cols} FROM authority_mapping WHERE scope = 'project' AND scope_id = ? ORDER BY entity_type, source_id", [$newId]);
        $this->assertCount($mappings, $new);
        $this->assertSame($orig, $new, 'a cópia leva os agrupamentos do projeto, com o scope_id novo');

        // O efetivo da cópia sai igual ao do original
        foreach (['author' => 'document_author da', 'keyword' => 'document_keyword da'] as $entity => $table) {
            $col = $entity === 'author' ? 'effective_author_id' : 'effective_keyword_id';
            $q = "SELECT da.{$col} AS e, COUNT(*) AS n FROM {$table} JOIN document d ON d.id = da.document_id WHERE d.project_id = ? GROUP BY da.{$col} ORDER BY da.{$col}";
            $this->assertSame($this->conn->fetchAllAssociative($q, [$sourceId]), $this->conn->fetchAllAssociative($q, [$newId]), "efetivo de {$entity} igual ao do original");
        }
        $this->assertSame(0, (new AuthorityResolver($this->conn))->process('author', false)['changed']);
        $this->assertSame(0, (new AuthorityResolver($this->conn))->process('keyword', false)['changed']);
    }

    // ── comando app:authors:renormalize ───────────────────────────────────────────

    public function testRenormalizeCommandGroupsDuplicatesInsteadOfDeleting(): void
    {
        $now = date('Y-m-d H:i:s');
        $ids = [];
        foreach ([['Zzzcomando, Ab', 'zzzcomando ab'], ['ZZZCOMANDO, AB', 'zzzcomando, ab']] as [$name, $key]) {
            $this->conn->insert('author_identity', ['preferred_name' => $name, 'normalized_name' => $key, 'created_at' => $now, 'updated_at' => $now]);
            $ids[] = (int) $this->conn->lastInsertId();
        }
        $before = $this->counts();

        $logger = new AuthorityMergeLogger($this->em);
        $mapping = new AuthorityMappingService($this->em, new AuthorityResolver($this->conn), $logger);
        $tester = new CommandTester(new RenormalizeAuthorsCommand($this->conn, $mapping));
        $tester->execute(['--execute' => true]);

        $this->assertSame($before['author_identity'], (int) $this->conn->fetchOne('SELECT COUNT(*) FROM author_identity'), 'nenhuma identidade apagada');
        $row = $this->mappingRow('author', 'global', 0, $ids[1]);
        $this->assertNotNull($row, 'o duplicado foi agrupado no escopo global');
        $this->assertSame([$ids[0], 'command'], [(int) $row['target_id'], $row['origin']]);
        $this->assertStringContainsString('agrupados', $tester->getDisplay());
    }
}
