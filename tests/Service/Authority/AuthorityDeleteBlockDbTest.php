<?php

namespace App\Tests\Service\Authority;

use App\Controller\AdminAuthorController;
use App\Controller\AdminInstitutionController;
use App\Controller\AdminKeywordController;
use App\Controller\AdminReviewController;
use App\Entity\AuthorIdentity;
use App\Entity\Institution;
use App\Entity\Keyword;
use App\Service\Authority\AuthorityLinkGuard;
use App\Service\Authority\AuthorityMergeLogger;
use App\Service\Thesaurus\EntityMergeService;
use App\Service\Thesaurus\ThesaurusFileService;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Excluir e descartar são recusados quando há documento ligado (nada some, nenhuma linha no diário) e
 * permitidos sem documentos (com o registro no diário). Tudo dentro de uma transação desfeita.
 */
class AuthorityDeleteBlockDbTest extends LocalDbTestCase
{
    private Session $session;

    private function wire(object $controller): object
    {
        $this->session = new Session(new MockArraySessionStorage());
        $request = Request::create('/', 'POST', ['_token' => 'x']);
        $request->setSession($this->session);
        $stack = new RequestStack();
        $stack->push($request);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturn('/destino');
        $container = new Container();
        $container->set('request_stack', $stack);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $controller->setContainer($container);
        $this->request = $request;

        return $controller;
    }

    private Request $request;

    private function flash(string $type): array
    {
        return $this->session->getFlashBag()->peek($type);
    }

    private function logger(): AuthorityMergeLogger
    {
        return new AuthorityMergeLogger($this->em);
    }

    private function keywordController(): AdminKeywordController
    {
        return $this->wire(new AdminKeywordController(
            $this->em, $this->createStub(ThesaurusFileService::class), new EntityMergeService($this->em, $this->logger()), new AuthorityLinkGuard($this->em), $this->logger()
        ));
    }

    private function authorController(): AdminAuthorController
    {
        return $this->wire(new AdminAuthorController(
            $this->em, $this->createStub(ThesaurusFileService::class), new EntityMergeService($this->em, $this->logger()), new AuthorityLinkGuard($this->em), $this->logger()
        ));
    }

    private function institutionController(): AdminInstitutionController
    {
        return $this->wire(new AdminInstitutionController(
            $this->em, $this->createStub(ThesaurusFileService::class), new EntityMergeService($this->em, $this->logger()), new AuthorityLinkGuard($this->em), $this->logger()
        ));
    }

    private function reviewController(): AdminReviewController
    {
        return $this->wire(new AdminReviewController($this->em, new EntityMergeService($this->em, $this->logger()), new AuthorityLinkGuard($this->em), $this->logger()));
    }

    private function withDocs(string $table, string $col): int
    {
        return (int) $this->conn->fetchOne("SELECT {$col} FROM {$table} GROUP BY {$col} ORDER BY COUNT(*) ASC LIMIT 1");
    }

    private function withoutDocs(string $entityTable, string $linkTable, string $col): int
    {
        return (int) $this->conn->fetchOne("SELECT e.id FROM {$entityTable} e LEFT JOIN {$linkTable} l ON l.{$col} = e.id WHERE l.id IS NULL ORDER BY e.id LIMIT 1");
    }

    private function n(string $sql, int $id): int
    {
        return (int) $this->conn->fetchOne($sql, [$id]);
    }

    public function testKeywordDeleteIsRefusedWithDocumentsAndAllowedWithout(): void
    {
        $id = $this->withDocs('document_keyword', 'keyword_id');
        $docs = $this->n('SELECT COUNT(DISTINCT document_id) FROM document_keyword WHERE keyword_id = ?', $id);
        $this->assertGreaterThan(0, $docs);
        $logs = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM authority_merge_log');

        $this->keywordController()->delete($this->em->find(Keyword::class, $id), $this->request);

        $this->assertStringContainsString("está ligada a {$docs} documento", $this->flash('danger')[0]);
        $this->assertStringContainsString('não pode ser excluída', $this->flash('danger')[0]);
        $this->assertSame(1, $this->n('SELECT COUNT(*) FROM keyword WHERE id = ?', $id));
        $this->assertSame($docs, $this->n('SELECT COUNT(DISTINCT document_id) FROM document_keyword WHERE keyword_id = ?', $id));
        $this->assertSame($logs, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM authority_merge_log'));

        $free = $this->withoutDocs('keyword', 'document_keyword', 'keyword_id');
        $this->keywordController()->delete($this->em->find(Keyword::class, $free), $this->request);
        $this->assertSame(0, $this->n('SELECT COUNT(*) FROM keyword WHERE id = ?', $free));
        $log = $this->logsFor('keyword', $free)[0];
        $this->assertSame(['screen_admin', 'delete'], [$log['origin'], $log['action']]);
    }

    public function testAuthorDeleteIsRefusedWithDocumentsAndAllowedWithout(): void
    {
        $id = $this->withDocs('document_author', 'author_identity_id');
        $docs = $this->n('SELECT COUNT(DISTINCT document_id) FROM document_author WHERE author_identity_id = ?', $id);

        $this->authorController()->delete($this->em->find(AuthorIdentity::class, $id), $this->request);

        $this->assertStringContainsString("está ligado a {$docs} documento", $this->flash('danger')[0]);
        $this->assertStringContainsString('não pode ser excluído', $this->flash('danger')[0]);
        $this->assertSame(1, $this->n('SELECT COUNT(*) FROM author_identity WHERE id = ?', $id));
        $this->assertSame($docs, $this->n('SELECT COUNT(DISTINCT document_id) FROM document_author WHERE author_identity_id = ?', $id));
        $this->assertSame([], $this->logsFor('author', $id));

        $free = $this->withoutDocs('author_identity', 'document_author', 'author_identity_id');
        $this->authorController()->delete($this->em->find(AuthorIdentity::class, $free), $this->request);
        $this->assertSame(0, $this->n('SELECT COUNT(*) FROM author_identity WHERE id = ?', $free));
        $this->assertSame('delete', $this->logsFor('author', $free)[0]['action']);
    }

    public function testInstitutionDeleteIsRefusedWithDocumentsAndAllowedWithout(): void
    {
        $id = $this->withDocs('documento_instituicoes', 'institution_id');
        $docs = $this->n('SELECT COUNT(DISTINCT document_id) FROM documento_instituicoes WHERE institution_id = ?', $id);

        $this->institutionController()->delete($this->em->find(Institution::class, $id), $this->request);

        $this->assertStringContainsString("está ligada a {$docs} documento", $this->flash('danger')[0]);
        $this->assertSame(1, $this->n('SELECT COUNT(*) FROM instituicoes_ensino WHERE id = ?', $id));
        $this->assertSame($docs, $this->n('SELECT COUNT(DISTINCT document_id) FROM documento_instituicoes WHERE institution_id = ?', $id));

        $free = $this->withoutDocs('instituicoes_ensino', 'documento_instituicoes', 'institution_id');
        $this->institutionController()->delete($this->em->find(Institution::class, $free), $this->request);
        $this->assertSame(0, $this->n('SELECT COUNT(*) FROM instituicoes_ensino WHERE id = ?', $free));
        $this->assertSame('delete', $this->logsFor('institution', $free)[0]['action']);
    }

    public function testReviewDiscardKeywordAuthorAndMoveToInstitution(): void
    {
        $kw = $this->withDocs('document_keyword', 'keyword_id');
        $au = $this->withDocs('document_author', 'author_identity_id');
        $authorLinks = $this->n('SELECT COUNT(*) FROM document_author WHERE author_identity_id = ?', $au);
        $logs = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM authority_merge_log');
        $instBefore = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM instituicoes_ensino');

        $c = $this->reviewController();
        $c->discardKeyword($kw, $this->request);
        $c->discardAuthor($au, $this->request);
        $c->moveAuthorToInstitution($au, $this->request);

        $danger = $this->flash('danger');
        $this->assertCount(3, $danger);
        $this->assertStringContainsString('não pode ser descartada', $danger[0]);
        $this->assertStringContainsString('não pode ser descartado', $danger[1]);
        $this->assertStringContainsString('não pode ser movido', $danger[2]);
        $this->assertSame(1, $this->n('SELECT COUNT(*) FROM keyword WHERE id = ?', $kw));
        $this->assertSame(1, $this->n('SELECT COUNT(*) FROM author_identity WHERE id = ?', $au));
        $this->assertSame($authorLinks, $this->n('SELECT COUNT(*) FROM document_author WHERE author_identity_id = ?', $au));
        $this->assertSame($logs, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM authority_merge_log'));
        $this->assertSame($instBefore, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM instituicoes_ensino'), 'nenhuma instituição criada pela recusa');

        // Sem documentos: continua permitido, com o registro no diário
        $freeKw = $this->withoutDocs('keyword', 'document_keyword', 'keyword_id');
        $freeAu = $this->withoutDocs('author_identity', 'document_author', 'author_identity_id');
        $freeAu2 = (int) $this->conn->fetchOne('SELECT e.id FROM author_identity e LEFT JOIN document_author l ON l.author_identity_id = e.id WHERE l.id IS NULL AND e.id > ? ORDER BY e.id LIMIT 1', [$freeAu]);
        $c->discardKeyword($freeKw, $this->request);
        $c->discardAuthor($freeAu, $this->request);
        $c->moveAuthorToInstitution($freeAu2, $this->request);
        $this->assertSame(0, $this->n('SELECT COUNT(*) FROM keyword WHERE id = ?', $freeKw));
        $this->assertSame(0, $this->n('SELECT COUNT(*) FROM author_identity WHERE id = ?', $freeAu));
        $this->assertSame(0, $this->n('SELECT COUNT(*) FROM author_identity WHERE id = ?', $freeAu2));
        $this->assertSame('discard', $this->logsFor('keyword', $freeKw)[0]['action']);
        $this->assertSame('discard', $this->logsFor('author', $freeAu)[0]['action']);
        $this->assertSame('remap', $this->logsFor('author', $freeAu2)[0]['action']);
    }

    public function testGuardCountsManyIdsInOneQueryAndMatchesSingleCounts(): void
    {
        $guard = new AuthorityLinkGuard($this->em);
        $ids = array_map('intval', $this->conn->fetchFirstColumn('SELECT keyword_id FROM document_keyword GROUP BY keyword_id ORDER BY keyword_id LIMIT 8'));
        $ids[] = $this->withoutDocs('keyword', 'document_keyword', 'keyword_id');

        $counts = $guard->documentCounts('keyword', $ids);

        foreach ($ids as $id) {
            $this->assertSame($guard->documentCount('keyword', $id), $counts[$id] ?? 0);
        }
        $this->assertSame([], $guard->documentCounts('author', []));
        $this->assertSame("O autor 'Fulano' está ligado a 1 documento; não pode ser excluído.", $guard->blockMessage('author', 'Fulano', 1));
        $this->assertSame("A palavra-chave 'x' está ligada a 12 documentos; não pode ser descartada.", $guard->blockMessage('keyword', 'x', 12, 'descartada'));
    }
}
