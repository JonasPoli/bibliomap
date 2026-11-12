<?php

namespace App\Tests\Service\Authority;

use App\Command\AuthorityRebuildCommand;
use App\Service\Authority\AuthorityResolver;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Resolução do id efetivo (projeto > usuário dono > global > bruto), idempotência e --check no banco local.
 * Tudo dentro da transação do LocalDbTestCase (rollback no fim). Pula sem a migration Version20261112170000.
 */
class AuthorityResolverDbTest extends LocalDbTestCase
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
    }

    private function map(string $type, string $scope, int $scopeId, int $source, int $target): void
    {
        $this->conn->insert('authority_mapping', [
            'entity_type' => $type, 'scope' => $scope, 'scope_id' => $scopeId, 'source_id' => $source,
            'target_id' => $target, 'origin' => 'test', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Vínculo de autor cujo autor aparece em dois projetos de donos quaisquer: [linha A, linha B]. */
    private function authorInTwoProjects(): array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT da.author_identity_id a FROM document_author da JOIN document d ON d.id = da.document_id
             GROUP BY da.author_identity_id HAVING COUNT(DISTINCT d.project_id) >= 2 LIMIT 1'
        );
        if (!$row) {
            $this->markTestSkipped('Sem autor em dois projetos no banco local.');
        }
        $rows = $this->conn->fetchAllAssociative(
            'SELECT da.id, d.project_id, p.user_id FROM document_author da JOIN document d ON d.id = da.document_id
             JOIN bibliometric_project p ON p.id = d.project_id WHERE da.author_identity_id = ? ORDER BY d.project_id, da.id',
            [$row['a']]
        );
        $first = $rows[0];
        $other = null;
        foreach ($rows as $r) {
            if ((int) $r['project_id'] !== (int) $first['project_id']) {
                $other = $r;
                break;
            }
        }

        return [(int) $row['a'], $first, $other];
    }

    private function effAuthor(int $id): int
    {
        return (int) $this->conn->fetchOne('SELECT effective_author_id FROM document_author WHERE id = ?', [$id]);
    }

    public function testSemMapeamentoOEfetivoEOQueExistiaAntes(): void
    {
        $this->resolver->process('author', true);
        $this->resolver->process('keyword', true);

        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document_author WHERE effective_author_id <> author_identity_id'));
        $this->assertSame(0, (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM document_keyword dk JOIN keyword k ON k.id = dk.keyword_id
             WHERE dk.effective_keyword_id <> IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id)'
        ));
        $this->assertSame(
            'IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id)',
            AuthorityResolver::keywordRawExpression('k')
        );
    }

    public function testProjetoVenceUsuarioVenceGlobal(): void
    {
        [$author, $first, $other] = $this->authorInTwoProjects();
        $project = (int) $first['project_id'];
        $user = (int) $first['user_id'];
        [$g, $u, $p] = [900000001, 900000002, 900000003];

        $this->resolver->process('author', true);
        $this->assertSame($author, $this->effAuthor((int) $first['id']));

        $this->map('author', 'global', 0, $author, $g);
        $this->resolver->process('author', true);
        $this->assertSame($g, $this->effAuthor((int) $first['id']));
        $this->assertSame($g, $this->effAuthor((int) $other['id']), 'o global vale nos demais projetos');

        $this->map('author', 'user', $user, $author, $u);
        $this->resolver->process('author', true);
        $this->assertSame($u, $this->effAuthor((int) $first['id']));

        $this->map('author', 'project', $project, $author, $p);
        $this->resolver->process('author', true);
        $this->assertSame($p, $this->effAuthor((int) $first['id']));
        $this->assertNotSame($p, $this->effAuthor((int) $other['id']), 'o mapeamento do projeto não vaza para outro projeto');

        // Desfazer: apagar a linha volta ao nível seguinte, sem tocar no vínculo bruto
        $this->conn->executeStatement("DELETE FROM authority_mapping WHERE scope = 'project'");
        $this->resolver->process('author', true);
        $this->assertSame($u, $this->effAuthor((int) $first['id']));
        $this->assertSame($author, (int) $this->conn->fetchOne('SELECT author_identity_id FROM document_author WHERE id = ?', [$first['id']]));
    }

    public function testMapeamentoDePalavraChaveUsaOFormatoDoEfetivo(): void
    {
        $row = $this->conn->fetchAssociative(
            'SELECT dk.id, dk.keyword_id, d.project_id FROM document_keyword dk JOIN document d ON d.id = dk.document_id LIMIT 1'
        );
        if (!$row) {
            $this->markTestSkipped('Sem palavras-chave.');
        }
        $this->map('keyword', 'global', 0, (int) $row['keyword_id'], -424242);
        $this->resolver->process('keyword', true, (int) $row['project_id']);
        $this->assertSame(-424242, (int) $this->conn->fetchOne('SELECT effective_keyword_id FROM document_keyword WHERE id = ?', [$row['id']]));

        // Mapeamento de autor não afeta palavra-chave com o mesmo id
        $this->conn->executeStatement("DELETE FROM authority_mapping");
        $this->map('author', 'global', 0, (int) $row['keyword_id'], 7);
        $this->resolver->process('keyword', true, (int) $row['project_id']);
        $this->assertNotSame(7, (int) $this->conn->fetchOne('SELECT effective_keyword_id FROM document_keyword WHERE id = ?', [$row['id']]));
    }

    public function testIdempotenteEFaixasPequenasDaoOMesmoResultado(): void
    {
        $this->resolver->process('author', true);
        $this->conn->executeStatement('UPDATE document_author SET effective_author_id = 0 WHERE id % 7 = 0');
        $a = $this->resolver->process('author', true, null, 1000);
        $this->assertGreaterThan(0, $a['changed']);
        $b = $this->resolver->process('author', true, null, 1000);
        $this->assertSame(0, $b['changed']);
        $this->assertSame(0, $this->resolver->process('author', false)['changed']);
    }

    public function testCheckApontaDivergenciaPlantadaESaiComUm(): void
    {
        $this->resolver->process('author', true);
        $this->resolver->process('keyword', true);
        $id = (int) $this->conn->fetchOne('SELECT id FROM document_keyword LIMIT 1');
        if ($id === 0) {
            $this->markTestSkipped('Sem palavras-chave.');
        }
        $tester = new CommandTester(new AuthorityRebuildCommand($this->resolver));

        $this->assertSame(0, $tester->execute(['--check' => true]));

        $this->conn->executeStatement('UPDATE document_keyword SET effective_keyword_id = 123456789 WHERE id = ?', [$id]);
        $this->assertSame(1, $tester->execute(['--check' => true]));
        $this->assertStringContainsString('1 divergência', $tester->getDisplay());
        // --check e --dry-run não gravam
        $this->assertSame(123456789, (int) $this->conn->fetchOne('SELECT effective_keyword_id FROM document_keyword WHERE id = ?', [$id]));
        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $this->assertSame(123456789, (int) $this->conn->fetchOne('SELECT effective_keyword_id FROM document_keyword WHERE id = ?', [$id]));

        $this->assertSame(0, $tester->execute([]));
        $this->assertNotSame(123456789, (int) $this->conn->fetchOne('SELECT effective_keyword_id FROM document_keyword WHERE id = ?', [$id]));
        $this->assertSame(0, $tester->execute(['--check' => true]));
    }
}
