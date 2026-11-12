<?php

namespace App\Tests\Service\Analytics;

use App\Service\Analytics\IndicatorService;
use App\Service\Analytics\ReportService;
use App\Service\Authority\AuthorityResolver;
use App\Tests\Service\Authority\LocalDbTestCase;

/**
 * Requisito "Análises leem a identidade efetiva" (tesauro-em-camadas, tarefa 1.4): com um mapeamento global
 * plantado em authority_mapping e o recálculo do AuthorityResolver, os relatórios agrupam pela identidade
 * efetiva e contam documento distinto. Tudo na transação do LocalDbTestCase (rollback no fim).
 */
class AnaliseLeIdentidadeEfetivaDbTest extends LocalDbTestCase
{
    private AuthorityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->conn->fetchOne('SELECT effective_author_id, effective_keyword_id FROM document_author da, document_keyword dk LIMIT 1');
            $this->conn->fetchOne('SELECT COUNT(*) FROM authority_mapping');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Sem a migration Version20261112170000: ' . $e->getMessage());
        }
        if ((int) $this->conn->fetchOne('SELECT COUNT(*) FROM authority_mapping') > 0) {
            $this->markTestSkipped('authority_mapping não está vazia neste banco.');
        }
        $this->resolver = new AuthorityResolver($this->conn);
        // Parte do estado de hoje (efetivo = bruto), como na produção sem mesclagens
        $this->resolver->process('author', true);
        $this->resolver->process('keyword', true);
    }

    private function map(string $type, int $source, int $target): void
    {
        $this->conn->insert('authority_mapping', [
            'entity_type' => $type, 'scope' => 'global', 'scope_id' => 0, 'source_id' => $source,
            'target_id' => $target, 'origin' => 'test', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testAutoresMapeadosParaAMesmaIdentidadeContamUmaVezPorDocumento(): void
    {
        // Documento com dois autores distintos (A e B) em um projeto; B vira A
        $row = $this->conn->fetchAssociative(
            'SELECT d.project_id, a1.author_identity_id AS a, a2.author_identity_id AS b
             FROM document_author a1
             JOIN document_author a2 ON a2.document_id = a1.document_id AND a2.author_identity_id > a1.author_identity_id
             JOIN document d ON d.id = a1.document_id
             JOIN author_identity i1 ON i1.id = a1.author_identity_id
             JOIN author_identity i2 ON i2.id = a2.author_identity_id AND i2.preferred_name <> i1.preferred_name
             LIMIT 1'
        );
        if (!$row) {
            $this->markTestSkipped('Sem documento com dois autores distintos no banco local.');
        }
        [$project, $a, $b] = [(int) $row['project_id'], (int) $row['a'], (int) $row['b']];

        $docs = fn (array $ids) => (int) $this->conn->fetchOne(
            'SELECT COUNT(DISTINCT da.document_id) FROM document_author da JOIN document d ON d.id = da.document_id
             WHERE d.project_id = ? AND da.author_identity_id IN (' . implode(',', $ids) . ')',
            [$project]
        );
        $somaSeparada = $docs([$a]) + $docs([$b]);
        $esperado = $docs([$a, $b]);
        $this->assertGreaterThan($esperado, $somaSeparada, 'o cenário exige um documento com os dois vínculos');

        $nomeA = (string) $this->conn->fetchOne('SELECT preferred_name FROM author_identity WHERE id = ?', [$a]);
        $nomeB = (string) $this->conn->fetchOne('SELECT preferred_name FROM author_identity WHERE id = ?', [$b]);

        $this->map('author', $b, $a);
        $this->resolver->process('author', true);

        $report = new ReportService($this->conn);
        $lista = [];
        foreach ($report->getAuthorsReport($project, 1000000)['list'] as $r) {
            $lista[(int) $r['id']] = (int) $r['doc_count'];
        }
        $this->assertSame($esperado, $lista[$a] ?? null, 'a identidade efetiva conta o documento uma vez');
        $this->assertArrayNotHasKey($b, $lista, 'o autor mapeado deixa de aparecer sozinho');

        $top = [];
        foreach ((new IndicatorService($this->conn))->topAuthors($project, 1000000) as $r) {
            $top[$r['name']] = (int) $r['doc_count'];
        }
        $this->assertSame($esperado, $top[$nomeA] ?? null);
        $this->assertArrayNotHasKey($nomeB, $top);
    }

    public function testPalavrasChaveMapeadasParaOMesmoConceitoContamUmaVezPorDocumento(): void
    {
        // Documento com duas palavras-chave de conceitos efetivos diferentes (sem tesauro) no mesmo tipo: k2 passa
        // a valer o conceito efetivo de k1. Os conceitos vêm da expressão antiga, independente da coluna.
        $expr = fn (string $a) => AuthorityResolver::keywordRawExpression($a);
        $row = $this->conn->fetchAssociative(
            'SELECT d.project_id, k1.id AS k1, k2.id AS k2, ' . $expr('k1') . ' AS e1, ' . $expr('k2') . ' AS e2
             FROM document_keyword x1
             JOIN document_keyword x2 ON x2.document_id = x1.document_id AND x2.keyword_id > x1.keyword_id
             JOIN document d ON d.id = x1.document_id
             JOIN keyword k1 ON k1.id = x1.keyword_id AND k1.thesaurus_concept_id IS NULL
             JOIN keyword k2 ON k2.id = x2.keyword_id AND k2.thesaurus_concept_id IS NULL AND k2.keyword_type = k1.keyword_type
             WHERE ' . $expr('k1') . ' <> ' . $expr('k2') . '
             LIMIT 1'
        );
        if (!$row) {
            $this->markTestSkipped('Sem documento com duas palavras-chave de conceitos diferentes no banco local.');
        }
        [$project, $k2, $e1, $e2] = [(int) $row['project_id'], (int) $row['k2'], (int) $row['e1'], (int) $row['e2']];

        // Palavras-chave do conceito e1 (antes) mais k2; documentos distintos do projeto
        $docs = fn (string $where) => (int) $this->conn->fetchOne(
            'SELECT COUNT(DISTINCT dk.document_id) FROM document_keyword dk JOIN document d ON d.id = dk.document_id
             JOIN keyword k ON k.id = dk.keyword_id WHERE d.project_id = ? AND (' . $where . ')',
            [$project]
        );
        $esperadoE1 = $docs($expr('k') . ' = ' . $e1 . ' OR k.id = ' . $k2);
        $this->assertGreaterThan($esperadoE1, $docs($expr('k') . ' = ' . $e1) + $docs('k.id = ' . $k2), 'o cenário exige um documento com os dois vínculos');
        $esperadoE2 = $docs($expr('k') . ' = ' . $e2 . ' AND k.id <> ' . $k2);

        $this->map('keyword', $k2, $e1);
        $this->resolver->process('keyword', true);

        $report = new ReportService($this->conn);
        $lista = [];
        foreach ($report->getKeywordsReport($project, 1000000)['list'] as $r) {
            $lista[(int) $r['id']] = (int) $r['freq'];
        }
        $this->assertSame($esperadoE1, $lista[$e1] ?? null, 'a identidade efetiva conta o documento uma vez');
        $this->assertSame($esperadoE2 ?: null, $lista[$e2] ?? null, 'o conceito antigo de k2 perde só o vínculo de k2');
    }
}
