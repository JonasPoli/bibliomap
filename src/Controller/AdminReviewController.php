<?php

namespace App\Controller;

use App\Entity\AuthorIdentity;
use App\Entity\AuthorNameVariant;
use App\Entity\AuthorExternalIdentifier;
use App\Entity\Keyword;
use App\Entity\KeywordVariation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/review')]
#[IsGranted('ROLE_ADMIN')]
class AdminReviewController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    #[Route('', name: 'app_admin_review_index', methods: ['GET'])]
    public function index(): Response
    {
        $conn = $this->em->getConnection();

        // 1. Fetch unresolved authors
        $unresolvedAuthorsRaw = $conn->fetchAllAssociative('
            SELECT id, preferred_name, normalized_name, status, review_reasons
            FROM author_identity
            WHERE status = 0
            ORDER BY preferred_name ASC
            LIMIT 100
        ');

        $unresolvedAuthors = [];
        foreach ($unresolvedAuthorsRaw as $row) {
            $id = (int)$row['id'];
            
            // Get variations original names
            $origNames = $conn->fetchFirstColumn(
                'SELECT original_name FROM author_name_variant WHERE author_identity_id = ?',
                [$id]
            );

            // Find suggestions with status = 1 and same normalized name
            $suggestion = $conn->fetchAssociative('
                SELECT id, preferred_name 
                FROM author_identity 
                WHERE status = 1 AND normalized_name = ?
                LIMIT 1
            ', [$row['normalized_name']]);

            $unresolvedAuthors[] = [
                'id' => $id,
                'preferred_name' => $row['preferred_name'],
                'normalized_name' => $row['normalized_name'],
                'original_names' => $origNames,
                'review_reasons' => $row['review_reasons'],
                'suggestion' => $suggestion ? $suggestion : null,
            ];
        }

        // 2. Fetch unresolved keywords
        $unresolvedKeywordsRaw = $conn->fetchAllAssociative('
            SELECT id, keyword_original, keyword_display, keyword_normalized, keyword_type, status, review_reasons
            FROM keyword
            WHERE status = 0
            ORDER BY keyword_original ASC
            LIMIT 100
        ');

        $unresolvedKeywords = [];
        foreach ($unresolvedKeywordsRaw as $row) {
            $id = (int)$row['id'];

            // Find suggestions with status = 1 and same normalized term
            $suggestion = $conn->fetchAssociative('
                SELECT id, keyword_display, keyword_type 
                FROM keyword 
                WHERE status = 1 AND keyword_normalized = ? AND keyword_type = ?
                LIMIT 1
            ', [$row['keyword_normalized'], $row['keyword_type']]);

            $unresolvedKeywords[] = [
                'id' => $id,
                'keyword_original' => $row['keyword_original'],
                'keyword_display' => $row['keyword_display'] ?? $row['keyword_original'],
                'keyword_normalized' => $row['keyword_normalized'],
                'keyword_type' => $row['keyword_type'],
                'review_reasons' => $row['review_reasons'],
                'suggestion' => $suggestion ? $suggestion : null,
            ];
        }

        // Get count of total unresolved items
        $totalUnresolvedAuthors = (int)$conn->fetchOne('SELECT COUNT(id) FROM author_identity WHERE status = 0');
        $totalUnresolvedKeywords = (int)$conn->fetchOne('SELECT COUNT(id) FROM keyword WHERE status = 0');

        return $this->render('admin/review/index.html.twig', [
            'unresolved_authors' => $unresolvedAuthors,
            'unresolved_keywords' => $unresolvedKeywords,
            'total_unresolved_authors' => $totalUnresolvedAuthors,
            'total_unresolved_keywords' => $totalUnresolvedKeywords,
        ]);
    }

    // ── Author Actions ───────────────────────────────────────────────────────

    #[Route('/author/{id}/accept', name: 'app_admin_review_author_accept', methods: ['POST'])]
    public function acceptAuthor(int $id, Request $request): Response
    {
        $author = $this->em->getRepository(AuthorIdentity::class)->find($id);
        if (!$author) {
            $this->addFlash('danger', 'Autor não encontrado.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        if (!$this->isCsrfTokenValid('accept_author_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        // Set status to 1 (resolved)
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE author_identity SET status = 1 WHERE id = ?', [$id]);

        $this->addFlash('success', "Autor '{$author->getPreferredName()}' aceito com sucesso!");
        return $this->redirectToRoute('app_admin_review_index');
    }

    #[Route('/author/{id}/edit', name: 'app_admin_review_author_edit', methods: ['POST'])]
    public function editAuthor(int $id, Request $request): Response
    {
        $author = $this->em->getRepository(AuthorIdentity::class)->find($id);
        if (!$author) {
            $this->addFlash('danger', 'Autor não encontrado.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        if (!$this->isCsrfTokenValid('edit_author_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        $newName = trim((string)$request->request->get('preferred_name', ''));
        if ($newName !== '') {
            $author->setPreferredName($newName);
            $author->setUpdatedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', "Nome preferido atualizado para '{$newName}'!");
        }

        return $this->redirectToRoute('app_admin_review_index');
    }

    #[Route('/author/{id}/merge', name: 'app_admin_review_author_merge', methods: ['POST'])]
    public function mergeAuthor(int $id, Request $request): Response
    {
        $author = $this->em->getRepository(AuthorIdentity::class)->find($id);
        if (!$author) {
            $this->addFlash('danger', 'Autor não encontrado.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        if (!$this->isCsrfTokenValid('merge_author_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        $targetId = (int)$request->request->get('target_id');
        $target = $this->em->getRepository(AuthorIdentity::class)->find($targetId);

        if (!$target || $target->getId() === $author->getId()) {
            $this->addFlash('danger', 'Autor de destino inválido.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        $conn = $this->em->getConnection();

        // 1. Remap document links
        $conn->executeStatement(
            'DELETE da1 FROM document_author da1
             JOIN document_author da2 ON da1.document_id = da2.document_id
             WHERE da1.author_identity_id = ? AND da2.author_identity_id = ?',
            [$author->getId(), $target->getId()]
        );
        $conn->executeStatement(
            'UPDATE document_author SET author_identity_id = ? WHERE author_identity_id = ?',
            [$target->getId(), $author->getId()]
        );

        // 2. Move existing variations
        foreach ($author->getVariations() as $v) {
            $exists = false;
            foreach ($target->getVariations() as $tv) {
                if ($tv->getNormalizedName() === $v->getNormalizedName()) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $v->setAuthorIdentity($target);
                $target->addVariation($v);
            } else {
                $this->em->remove($v);
            }
        }

        // Add author's name as variant of target
        $name = $author->getPreferredName();
        $norm = $author->getNormalizedName();
        $exists = false;
        foreach ($target->getVariations() as $tv) {
            if ($tv->getNormalizedName() === $norm) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $v = new AuthorNameVariant();
            $v->setOriginalName($name);
            $v->setDisplayName($name);
            $v->setNormalizedName($norm);
            $v->setSource('alternative');
            $v->setConfidence(1.0);
            $v->setAuthorIdentity($target);
            $this->em->persist($v);
        }

        // 3. Move identifiers
        foreach ($author->getIdentifiers() as $ident) {
            $exists = false;
            foreach ($target->getIdentifiers() as $tident) {
                if ($tident->getProvider() === $ident->getProvider() && $tident->getIdentifier() === $ident->getIdentifier()) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $ident->setAuthorIdentity($target);
                $target->addIdentifier($ident);
            } else {
                $this->em->remove($ident);
            }
        }

        $this->em->remove($author);
        $this->em->flush();

        $this->addFlash('success', "Autor '{$name}' mesclado em '{$target->getPreferredName()}' com sucesso!");
        return $this->redirectToRoute('app_admin_review_index');
    }

    #[Route('/author/{id}/ignore', name: 'app_admin_review_author_ignore', methods: ['POST'])]
    public function ignoreAuthor(int $id, Request $request): Response
    {
        return $this->acceptAuthor($id, $request);
    }

    #[Route('/author/{id}/move-to-keyword', name: 'app_admin_review_author_move_to_keyword', methods: ['POST'])]
    public function moveAuthorToKeyword(int $id, Request $request): Response
    {
        $author = $this->em->getRepository(AuthorIdentity::class)->find($id);
        if (!$author) {
            $this->addFlash('danger', 'Autor não encontrado.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        if (!$this->isCsrfTokenValid('move_to_keyword_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        $conn = $this->em->getConnection();

        $displayName = $author->getPreferredName();
        $normalized  = $author->getNormalizedName();

        // 1. Find or create keyword
        $kwId = $conn->fetchOne('SELECT id FROM keyword WHERE keyword_normalized = ? AND keyword_type = "author_keyword"', [$normalized]);
        if (!$kwId) {
            $conn->insert('keyword', [
                'keyword_original'   => $displayName,
                'keyword_display'    => $displayName,
                'keyword_normalized' => $normalized,
                'keyword_type'       => 'author_keyword',
                'status'             => 1,
            ]);
            $kwId = (int)$conn->lastInsertId();
            $conn->executeStatement('UPDATE keyword SET keyword_concept_id = ? WHERE id = ?', [$kwId, $kwId]);
        }

        // 2. Revinculo all document_author links to document_keyword
        $docAuthors = $conn->fetchAllAssociative(
            'SELECT document_id, original_name FROM document_author WHERE author_identity_id = ?',
            [$author->getId()]
        );
        foreach ($docAuthors as $da) {
            // Check if document already has this keyword
            $exists = $conn->fetchOne(
                'SELECT id FROM document_keyword WHERE document_id = ? AND keyword_id = ?',
                [$da['document_id'], $kwId]
            );
            if (!$exists) {
                $conn->insert('document_keyword', [
                    'document_id'   => $da['document_id'],
                    'keyword_id'    => $kwId,
                    'original_term' => $da['original_name'],
                ]);
            }
        }

        // 3. Delete the old author identity
        $this->em->remove($author);
        $this->em->flush();

        $this->addFlash('success', "Autor '{$displayName}' movido para Palavras-chave com sucesso!");
        return $this->redirectToRoute('app_admin_review_index');
    }

    #[Route('/author/{id}/move-to-institution', name: 'app_admin_review_author_move_to_institution', methods: ['POST'])]
    public function moveAuthorToInstitution(int $id, Request $request): Response
    {
        $author = $this->em->getRepository(AuthorIdentity::class)->find($id);
        if (!$author) {
            $this->addFlash('danger', 'Autor não encontrado.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        if (!$this->isCsrfTokenValid('move_to_institution_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        $conn = $this->em->getConnection();
        $name = $author->getPreferredName();

        // 1. Find or create institution
        $instId = $conn->fetchOne('SELECT id FROM instituicoes_ensino WHERE official_name = ?', [$name]);
        if (!$instId) {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $conn->insert('instituicoes_ensino', [
                'official_name' => $name,
                'created_at'    => $now,
                'updated_at'    => $now,
                'status'        => 1,
            ]);
            $instId = (int)$conn->lastInsertId();
        }

        // 2. Revinculo all document_author links to document_institution
        $docAuthors = $conn->fetchAllAssociative(
            'SELECT document_id FROM document_author WHERE author_identity_id = ?',
            [$author->getId()]
        );
        foreach ($docAuthors as $da) {
            // Check if document already has this institution linked
            $exists = $conn->fetchOne(
                'SELECT id FROM documento_instituicoes WHERE document_id = ? AND institution_id = ?',
                [$da['document_id'], $instId]
            );
            if (!$exists) {
                $conn->insert('documento_instituicoes', [
                    'document_id'    => $da['document_id'],
                    'institution_id' => $instId,
                    'link_type'      => 'author_affiliation',
                ]);
            }
        }

        // 3. Delete the old author identity
        $this->em->remove($author);
        $this->em->flush();

        $this->addFlash('success', "Autor '{$name}' movido para Instituições com sucesso!");
        return $this->redirectToRoute('app_admin_review_index');
    }

    #[Route('/author/{id}/discard', name: 'app_admin_review_author_discard', methods: ['POST'])]
    public function discardAuthor(int $id, Request $request): Response
    {
        $author = $this->em->getRepository(AuthorIdentity::class)->find($id);
        if (!$author) {
            $this->addFlash('danger', 'Autor não encontrado.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        if (!$this->isCsrfTokenValid('discard_author_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        $name = $author->getPreferredName();
        $this->em->remove($author);
        $this->em->flush();

        $this->addFlash('warning', "Autor '{$name}' descartado com sucesso.");
        return $this->redirectToRoute('app_admin_review_index');
    }

    // ── Keyword Actions ──────────────────────────────────────────────────────

    #[Route('/keyword/{id}/accept', name: 'app_admin_review_keyword_accept', methods: ['POST'])]
    public function acceptKeyword(int $id, Request $request): Response
    {
        $keyword = $this->em->getRepository(Keyword::class)->find($id);
        if (!$keyword) {
            $this->addFlash('danger', 'Palavra-chave não encontrada.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        if (!$this->isCsrfTokenValid('accept_keyword_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE keyword SET status = 1 WHERE id = ?', [$id]);

        $this->addFlash('success', "Palavra-chave '{$keyword->getTerm()}' aceita!");
        return $this->redirectToRoute('app_admin_review_index');
    }

    #[Route('/keyword/{id}/edit', name: 'app_admin_review_keyword_edit', methods: ['POST'])]
    public function editKeyword(int $id, Request $request): Response
    {
        $keyword = $this->em->getRepository(Keyword::class)->find($id);
        if (!$keyword) {
            $this->addFlash('danger', 'Palavra-chave não encontrada.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        if (!$this->isCsrfTokenValid('edit_keyword_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        $newTerm = trim((string)$request->request->get('keyword_display', ''));
        if ($newTerm !== '') {
            $keyword->setKeywordDisplay($newTerm);
            $this->em->flush();
            $this->addFlash('success', "Termo limpo atualizado para '{$newTerm}'!");
        }

        return $this->redirectToRoute('app_admin_review_index');
    }

    #[Route('/keyword/{id}/merge', name: 'app_admin_review_keyword_merge', methods: ['POST'])]
    public function mergeKeyword(int $id, Request $request): Response
    {
        $keyword = $this->em->getRepository(Keyword::class)->find($id);
        if (!$keyword) {
            $this->addFlash('danger', 'Palavra-chave não encontrada.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        if (!$this->isCsrfTokenValid('merge_keyword_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        $targetId = (int)$request->request->get('target_id');
        $target = $this->em->getRepository(Keyword::class)->find($targetId);

        if (!$target || $target->getId() === $keyword->getId()) {
            $this->addFlash('danger', 'Palavra-chave de destino inválida.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        $conn = $this->em->getConnection();

        // 1. Remap document links
        $conn->executeStatement(
            'DELETE dk1 FROM document_keyword dk1
             JOIN document_keyword dk2 ON dk1.document_id = dk2.document_id
             WHERE dk1.keyword_id = ? AND dk2.keyword_id = ?',
            [$keyword->getId(), $target->getId()]
        );
        $conn->executeStatement(
            'UPDATE document_keyword SET keyword_id = ? WHERE keyword_id = ?',
            [$target->getId(), $keyword->getId()]
        );

        // 2. Remap concept_id in keyword table
        $conn->executeStatement(
            'UPDATE keyword SET keyword_concept_id = ? WHERE keyword_concept_id = ?',
            [$target->getId(), $keyword->getId()]
        );

        // 3. Move variations
        foreach ($keyword->getVariations() as $v) {
            $exists = false;
            foreach ($target->getVariations() as $tv) {
                if ($tv->getNormalizedName() === $v->getNormalizedName()) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $v->setKeyword($target);
                $target->addVariation($v);
            } else {
                $this->em->remove($v);
            }
        }

        // Add keyword's term as variant of target
        $name = $keyword->getKeywordOriginal();
        $norm = $keyword->getKeywordNormalized();
        $exists = false;
        foreach ($target->getVariations() as $tv) {
            if ($tv->getNormalizedName() === $norm) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $v = new KeywordVariation();
            $v->setVariationName($name);
            $v->setNormalizedName($norm);
            $v->setVariationType('alternative');
            $v->setKeyword($target);
            $this->em->persist($v);
        }

        $this->em->remove($keyword);
        $this->em->flush();

        $this->addFlash('success', "Palavra-chave '{$name}' mesclada em '{$target->getTerm()}'!");
        return $this->redirectToRoute('app_admin_review_index');
    }

    #[Route('/keyword/{id}/ignore', name: 'app_admin_review_keyword_ignore', methods: ['POST'])]
    public function ignoreKeyword(int $id, Request $request): Response
    {
        return $this->acceptKeyword($id, $request);
    }

    #[Route('/keyword/{id}/discard', name: 'app_admin_review_keyword_discard', methods: ['POST'])]
    public function discardKeyword(int $id, Request $request): Response
    {
        $keyword = $this->em->getRepository(Keyword::class)->find($id);
        if (!$keyword) {
            $this->addFlash('danger', 'Palavra-chave não encontrada.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        if (!$this->isCsrfTokenValid('discard_keyword_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_review_index');
        }

        $term = $keyword->getKeywordOriginal();
        $this->em->remove($keyword);
        $this->em->flush();

        $this->addFlash('warning', "Palavra-chave '{$term}' descartada com sucesso.");
        return $this->redirectToRoute('app_admin_review_index');
    }
}
