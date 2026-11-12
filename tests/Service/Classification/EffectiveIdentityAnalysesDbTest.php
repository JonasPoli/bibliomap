<?php

namespace App\Tests\Service\Classification;

use App\Entity\BibliometricProject;
use App\Service\Analytics\ReportService;
use App\Service\Authority\AuthorityMappingService;
use App\Service\Authority\AuthorityMergeLogger;
use App\Service\Authority\AuthorityResolver;
use App\Service\Classification\ClassificationEngine;
use App\Service\Classification\ClassificationTermExpander;
use App\Service\Classification\GroupComparisonService;
use App\Tests\Service\Authority\LocalDbTestCase;

/**
 * Fase 2 do change tesauro-em-camadas (2.6): sobreposição de palavras-chave, classificação e listas de autores
 * leem a identidade efetiva depois de um agrupamento global. Banco local, tudo em transação desfeita.
 */
class EffectiveIdentityAnalysesDbTest extends LocalDbTestCase
{
    private const PROJECT = 13;

    private function mapping(): AuthorityMappingService
    {
        return new AuthorityMappingService($this->em, new AuthorityResolver($this->conn), new AuthorityMergeLogger($this->em, null), null);
    }

    private function newKeyword(string $text): int
    {
        $this->conn->insert('keyword', [
            'keyword_original' => $text, 'keyword_normalized' => $text, 'keyword_type' => 'author_keyword',
            'status' => 1, 'keyword_display' => $text,
        ]);

        return (int) $this->conn->lastInsertId();
    }

    private function newAuthor(string $name): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->insert('author_identity', ['preferred_name' => $name, 'normalized_name' => strtolower($name), 'created_at' => $now, 'updated_at' => $now, 'status' => 1]);

        return (int) $this->conn->lastInsertId();
    }

    private function linkKeyword(int $docId, int $kwId, string $term): void
    {
        $this->conn->insert('document_keyword', ['document_id' => $docId, 'keyword_id' => $kwId, 'original_term' => $term, 'effective_keyword_id' => $kwId]);
    }

    private function newGroup(string $name, int $pos): int
    {
        $this->conn->insert('classification_group', [
            'project_id' => self::PROJECT, 'name' => $name, 'type' => 'normal', 'position' => $pos,
            'created_at' => date('Y-m-d H:i:s'), 'match_fields' => json_encode(['author_keywords']), 'use_thesaurus' => 0,
        ]);

        return (int) $this->conn->lastInsertId();
    }

    public function testGroupedKeywordsAndAuthorsInOverlapClassificationAndAuthorLists(): void
    {
        $project = $this->em->find(BibliometricProject::class, self::PROJECT);
        if ($project === null) {
            $this->markTestSkipped('Projeto 13 não existe no banco local.');
        }
        $minYear = $project->getClassificationMinYear() ?? 0;
        $docs = array_map('intval', $this->conn->fetchFirstColumn(
            'SELECT id FROM document WHERE project_id = ? AND year >= ? ORDER BY id LIMIT 4',
            [self::PROJECT, max($minYear, 1900)]
        ));
        if (count($docs) < 4) {
            $this->markTestSkipped('Projeto 13 sem 4 documentos elegíveis.');
        }
        [$d1, $d2, $d3, $d4] = $docs;
        $u = uniqid('zz');

        // Palavras: A nos dois primeiros documentos, B nos dois últimos (grupos diferentes)
        $ka = $this->newKeyword("{$u} alfa");
        $kb = $this->newKeyword("{$u} beta");
        $this->linkKeyword($d1, $ka, "{$u} alfa");
        $this->linkKeyword($d2, $ka, "{$u} alfa");
        $this->linkKeyword($d3, $kb, "{$u} beta");
        $this->linkKeyword($d4, $kb, "{$u} beta");

        // Autores: os dois no primeiro documento (posições depois dos existentes)
        $aa = $this->newAuthor("{$u} Autora Principal");
        $ab = $this->newAuthor("{$u} Autora Variante");
        foreach ([[$aa, 900], [$ab, 901]] as [$id, $pos]) {
            $this->conn->insert('document_author', ['position' => $pos, 'original_name' => 'x', 'document_id' => $d1, 'author_identity_id' => $id, 'effective_author_id' => $id]);
        }

        // Grupos: G1 (d1, d2) e G2 (d3, d4) para a sobreposição; G3 casa pelo nome do agrupador (B)
        $g1 = $this->newGroup("{$u} G1", 901);
        $g2 = $this->newGroup("{$u} G2", 902);
        $g3 = $this->newGroup("{$u} G3", 903);
        $this->conn->insert('classification_rule', ['group_id' => $g3, 'term' => "{$u} beta", 'created_at' => date('Y-m-d H:i:s')]);
        foreach ([[$d1, $g1], [$d2, $g1], [$d3, $g2], [$d4, $g2]] as [$d, $g]) {
            $this->conn->insert('document_classification', ['document_id' => $d, 'group_id' => $g, 'project_id' => self::PROJECT, 'run_at' => date('Y-m-d H:i:s'), 'manual_override' => 0]);
        }
        $this->em->clear();

        $cmp = new GroupComparisonService($this->em);
        $overlap = fn() => $cmp->getSharedKeywordOverlap(self::PROJECT, [$g1, $g2], false)['pairwiseJaccard'][0];
        $engine = fn() => new ClassificationEngine(
            $this->em,
            $this->em->getRepository(\App\Entity\ClassificationGroup::class),
            $this->em->getRepository(\App\Entity\DocumentClassification::class),
            new ClassificationTermExpander($this->conn),
        );
        $matchesOf = function (int $doc, int $group) use ($engine): array {
            $project = $this->em->find(BibliometricProject::class, self::PROJECT);
            $r = $engine()->calculate($project);
            $out = [];
            foreach ($r['assignments'][$doc] ?? [] as $a) {
                if ($a['group']?->getId() === $group) {
                    $out[] = $a['term'];
                }
            }

            return $out;
        };
        $authorsOf = fn() => $this->conn->fetchOne('SELECT ' . ReportService::effectiveAuthorNamesSql('d.id') . ' FROM document d WHERE d.id = ?', [$d1]);
        $authorsBatch = fn() => $this->conn->fetchOne(
            'SELECT GROUP_CONCAT(a.preferred_name ORDER BY da.position, da.id SEPARATOR \'; \') FROM document_author da
             JOIN author_identity a ON a.id = da.effective_author_id
             WHERE da.document_id = ? AND ' . ReportService::firstEffectiveAuthorFilter('da'),
            [$d1]
        );

        // Antes do agrupamento: palavras distintas, sem casamento do grupo 3 em d1, os dois autores listados
        $this->assertSame(0, $overlap()['intersection']);
        $this->assertSame([], $matchesOf($d1, $g3));
        $this->assertStringContainsString("{$u} Autora Principal; {$u} Autora Variante", (string) $authorsOf());

        $this->mapping()->merge('keyword', $ka, $kb, 'global', 0, 'admin', 1);
        $this->mapping()->merge('author', $ab, $aa, 'global', 0, 'admin', 1);
        $this->em->clear();

        // (a) o par conta como uma palavra: a interseção passa a 1, com o rótulo do agrupador
        $o = $overlap();
        $this->assertSame(1, $o['intersection']);
        $this->assertSame(["{$u} beta"], $o['topShared']);

        // (b) o documento da palavra agrupada casa pelo nome do agrupador; d3/d4 casavam e continuam casando
        $this->assertSame(["{$u} beta"], $matchesOf($d1, $g3));
        $this->assertSame(["{$u} beta"], $matchesOf($d3, $g3));

        // (c) o autor efetivo aparece uma vez, na posição do primeiro vínculo
        foreach ([$authorsOf(), $authorsBatch()] as $list) {
            $this->assertSame(1, substr_count((string) $list, "{$u} Autora"), 'o mesmo autor efetivo uma vez');
            $this->assertStringEndsWith("{$u} Autora Principal", (string) $list);
        }
    }
}
