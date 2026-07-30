<?php

namespace App\Controller;

use App\Entity\AuthorIdentity;
use App\Entity\AuthorNameVariant;
use App\Entity\AuthorExternalIdentifier;
use App\Service\Import\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Service\Thesaurus\ThesaurusFileService;
use App\Service\Thesaurus\EntityMergeService;

#[Route('/admin/authors')]
#[IsGranted('ROLE_ADMIN')]
class AdminAuthorController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThesaurusFileService $thesaurusService,
        private readonly EntityMergeService $mergeService,
    ) {}

    #[Route('', name: 'app_admin_authors_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = trim($request->query->getString('search', ''));
        $status = $request->query->get('status', 'all');
        $page   = max(1, $request->query->getInt('page', 1));
        $limit  = 100;

        $qb = $this->em->createQueryBuilder()
            ->select('a')
            ->from(AuthorIdentity::class, 'a')
            ->leftJoin('a.variations', 'v');

        if ($search !== '') {
            $qb->andWhere('a.preferredName LIKE :search OR a.normalizedName LIKE :search OR a.orcid LIKE :search OR v.variationName LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($status === 'active') {
            $qb->andWhere('a.status = 1');
        } elseif ($status === 'inactive') {
            $qb->andWhere('a.status = 0');
        }

        $query = $qb->orderBy('a.preferredName', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery();

        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($query, true);
        $totalItems = count($paginator);
        $totalPages = max(1, (int) ceil($totalItems / $limit));

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        return $this->render('admin/authors/index.html.twig', [
            'authors' => $paginator,
            'search' => $search,
            'status' => $status,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'limit' => $limit,
        ]);
    }

    #[Route('/export', name: 'app_admin_authors_export', methods: ['GET'])]
    public function export(): Response
    {
        $conn = $this->em->getConnection();

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function() use ($conn) {
            $handle = fopen('php://output', 'w+');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['name', 'orcid', 'status', 'variations'], ';');

            // Load variations
            $vars = $conn->fetchAllAssociative('
                SELECT author_identity_id, original_name 
                FROM author_name_variant 
                WHERE source != "official"
            ');
            $varsByAuthor = [];
            foreach ($vars as $v) {
                $varsByAuthor[$v['author_identity_id']][] = $v['original_name'];
            }

            // Load ORCIDs
            $orcids = $conn->fetchAllAssociative('
                SELECT author_identity_id, identifier 
                FROM author_external_identifier 
                WHERE provider = "orcid"
            ');
            $orcidByAuthor = [];
            foreach ($orcids as $o) {
                $orcidByAuthor[$o['author_identity_id']] = $o['identifier'];
            }

            $stmt = $conn->executeQuery('
                SELECT id, preferred_name, status 
                FROM author_identity 
                ORDER BY preferred_name ASC
            ');

            while ($auth = $stmt->fetchAssociative()) {
                $authId = (int)$auth['id'];
                $varNames = $varsByAuthor[$authId] ?? [];
                $orcid = $orcidByAuthor[$authId] ?? '';

                fputcsv($handle, [
                    $auth['preferred_name'],
                    $orcid,
                    $auth['status'] ? '1' : '0',
                    implode(';', $varNames)
                ], ';');
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="autores.csv"');

        return $response;
    }

    #[Route('/import', name: 'app_admin_authors_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_authors', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_authors_index');
        }

        $file = $request->files->get('csv_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor, envie um arquivo CSV.');
            return $this->redirectToRoute('app_admin_authors_index');
        }

        try {
            set_time_limit(1200);
            $conn = $this->em->getConnection();

            $csv = \League\Csv\Reader::createFromPath($file->getRealPath(), 'r');
            $csv->setHeaderOffset(0);

            $imported = 0;
            $updated = 0;
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            foreach ($csv->getRecords() as $record) {
                $name = trim($record['name'] ?? '');
                if ($name === '') continue;

                $orcid = trim($record['orcid'] ?? '') ?: null;
                $status = ($record['status'] ?? '1') === '1' ? 1 : 0;
                $variationsStr = trim($record['variations'] ?? '');

                $displayName = StringNormalizer::normalizeString($name);
                $normName = StringNormalizer::normalizeString($name, true);

                // Find identity by ORCID first, then fallback to normalized name
                $id = null;
                if ($orcid) {
                    $id = $conn->fetchOne('
                        SELECT author_identity_id 
                        FROM author_external_identifier 
                        WHERE provider = "orcid" AND identifier = ?
                        LIMIT 1
                    ', [$orcid]);
                }
                if (!$id) {
                    $id = $conn->fetchOne('SELECT id FROM author_identity WHERE normalized_name = ?', [$normName]);
                }

                if ($id) {
                    $updated++;
                    $conn->executeStatement(
                        'UPDATE author_identity SET preferred_name = ?, status = ?, updated_at = ? WHERE id = ?',
                        [$displayName, $status, $now, $id]
                    );
                } else {
                    $conn->insert('author_identity', [
                        'preferred_name' => $displayName,
                        'normalized_name' => $normName,
                        'status' => $status,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $id = (int)$conn->lastInsertId();
                    $imported++;
                }

                // Sync preferred variant
                $varId = $conn->fetchOne('SELECT id FROM author_name_variant WHERE author_identity_id = ? AND normalized_name = ?', [$id, $normName]);
                if (!$varId) {
                    $conn->insert('author_name_variant', [
                        'author_identity_id' => $id,
                        'original_name' => $name,
                        'display_name' => $displayName,
                        'normalized_name' => $normName,
                        'source' => 'official',
                        'confidence' => 1.0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                // Sync ORCID
                if ($orcid) {
                    $conn->executeStatement('DELETE FROM author_external_identifier WHERE author_identity_id = ? AND provider = "orcid"', [$id]);
                    $conn->insert('author_external_identifier', [
                        'author_identity_id' => $id,
                        'provider' => 'orcid',
                        'identifier' => $orcid,
                        'url' => 'https://orcid.org/' . $orcid
                    ]);
                }

                // Sync variations
                $lines = explode(';', $variationsStr);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $lineNorm = StringNormalizer::normalizeString($line, true);
                    $lineDisplay = StringNormalizer::normalizeString($line);

                    $exists = $conn->fetchOne('SELECT id FROM author_name_variant WHERE author_identity_id = ? AND normalized_name = ?', [$id, $lineNorm]);
                    if (!$exists) {
                        $conn->insert('author_name_variant', [
                            'author_identity_id' => $id,
                            'original_name' => $line,
                            'display_name' => $lineDisplay,
                            'normalized_name' => $lineNorm,
                            'source' => 'import',
                            'confidence' => 1.0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }

            $this->addFlash('success', "Importação concluída: {$imported} novos autores criados, {$updated} atualizados.");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao importar CSV: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_authors_index');
    }

    #[Route('/new', name: 'app_admin_authors_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $author = new AuthorIdentity();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_author', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_authors_index');
            }

            $name = (string)$request->request->get('name');
            $displayName = StringNormalizer::normalizeString($name);
            $normName = StringNormalizer::normalizeString($name, true);

            $author->setPreferredName($displayName);
            $author->setNormalizedName($normName);
            $author->setUpdatedAt(new \DateTimeImmutable());

            $this->em->persist($author);
            $this->em->flush();

            // Set ORCID
            $orcid = trim((string)$request->request->get('orcid'));
            if ($orcid !== '') {
                $ident = new AuthorExternalIdentifier();
                $ident->setAuthorIdentity($author);
                $ident->setProvider('orcid');
                $ident->setIdentifier($orcid);
                $ident->setUrl('https://orcid.org/' . $orcid);
                $this->em->persist($ident);
            }

            $this->syncVariations($author, (string)$request->request->get('variationsText'));

            $this->addFlash('success', "Autor '{$author->getPreferredName()}' criado com sucesso!");
            return $this->redirectToRoute('app_admin_authors_index');
        }

        return $this->render('admin/authors/new.html.twig', [
            'author' => $author,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_authors_edit', methods: ['GET', 'POST'])]
    public function edit(AuthorIdentity $author, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_author_' . $author->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_authors_index');
            }

            $name = (string)$request->request->get('name');
            $displayName = StringNormalizer::normalizeString($name);
            $normName = StringNormalizer::normalizeString($name, true);

            $author->setPreferredName($displayName);
            $author->setNormalizedName($normName);
            $author->setUpdatedAt(new \DateTimeImmutable());

            // Update ORCID
            $orcid = trim((string)$request->request->get('orcid'));
            // Remove existing ORCID
            foreach ($author->getIdentifiers() as $ident) {
                if ($ident->getProvider() === 'orcid') {
                    $this->em->remove($ident);
                }
            }
            if ($orcid !== '') {
                $ident = new AuthorExternalIdentifier();
                $ident->setAuthorIdentity($author);
                $ident->setProvider('orcid');
                $ident->setIdentifier($orcid);
                $ident->setUrl('https://orcid.org/' . $orcid);
                $this->em->persist($ident);
            }

            $this->syncVariations($author, (string)$request->request->get('variationsText'));
            $this->em->flush();

            $this->addFlash('success', "Autor '{$author->getPreferredName()}' atualizado!");
            return $this->redirectToRoute('app_admin_authors_index');
        }

        $variationNames = [];
        foreach ($author->getVariations() as $v) {
            if ($v->getNormalizedName() !== $author->getNormalizedName()) {
                $variationNames[] = $v->getOriginalName();
            }
        }
        $variationsText = implode("\n", $variationNames);

        $orcid = '';
        foreach ($author->getIdentifiers() as $ident) {
            if ($ident->getProvider() === 'orcid') {
                $orcid = $ident->getIdentifier();
            }
        }

        $otherAuthors = $this->em->createQueryBuilder()
            ->select('a')
            ->from(AuthorIdentity::class, 'a')
            ->where('a.id != :id')
            ->setParameter('id', $author->getId())
            ->orderBy('a.preferredName', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        return $this->render('admin/authors/edit.html.twig', [
            'author' => $author,
            'orcid' => $orcid,
            'variationsText' => $variationsText,
            'other_authors' => $otherAuthors,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_authors_delete', methods: ['POST'])]
    public function delete(AuthorIdentity $author, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_author_' . $author->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_authors_index');
        }

        $name = $author->getPreferredName();
        $this->em->remove($author);
        $this->em->flush();

        $this->addFlash('success', "Autor '{$name}' excluído permanentemente!");
        return $this->redirectToRoute('app_admin_authors_index');
    }

    #[Route('/variation/{id}/separate', name: 'app_admin_authors_variation_separate', methods: ['POST'])]
    public function separateVariation(int $id, Request $request): Response
    {
        $variation = $this->em->getRepository(AuthorNameVariant::class)->find($id);
        if (!$variation) {
            $this->addFlash('danger', 'Variação não encontrada.');
            return $this->redirectToRoute('app_admin_authors_index');
        }

        if (!$this->isCsrfTokenValid('separate_var_' . $variation->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_authors_edit', ['id' => $variation->getAuthorIdentity()->getId()]);
        }

        $parent = $variation->getAuthorIdentity();
        $varName = $variation->getOriginalName();
        $varDisplay = $variation->getDisplayName();
        $varNorm = $variation->getNormalizedName();

        $newAuthor = new AuthorIdentity();
        $newAuthor->setPreferredName($varDisplay);
        $newAuthor->setNormalizedName($varNorm);
        $newAuthor->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist($newAuthor);
        $this->em->flush();

        // Add official variation for the new author
        $newVar = new AuthorNameVariant();
        $newVar->setOriginalName($varName);
        $newVar->setDisplayName($varDisplay);
        $newVar->setNormalizedName($varNorm);
        $newVar->setSource('official');
        $newVar->setConfidence(1.0);
        $newVar->setAuthorIdentity($newAuthor);
        $this->em->persist($newVar);

        // Update document_author records with original name to point to new identity
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'UPDATE document_author SET author_identity_id = ? WHERE author_identity_id = ? AND original_name = ?',
            [$newAuthor->getId(), $parent->getId(), $varName]
        );

        // Remove variation from old parent
        $parent->removeVariation($variation);
        $this->em->remove($variation);
        $this->em->flush();

        $this->addFlash('success', "Variação '{$varName}' desmembrada com sucesso para um novo autor!");
        return $this->redirectToRoute('app_admin_authors_edit', ['id' => $newAuthor->getId()]);
    }

    #[Route('/{id}/merge', name: 'app_admin_authors_merge', methods: ['POST'])]
    public function merge(AuthorIdentity $author, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('merge_author_' . $author->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_authors_index');
        }

        $targetId = (int)$request->request->get('targetId');
        $target = $this->em->getRepository(AuthorIdentity::class)->find($targetId);

        if (!$target || $target->getId() === $author->getId()) {
            $this->addFlash('danger', 'Autor de destino inválido.');
            return $this->redirectToRoute('app_admin_authors_edit', ['id' => $author->getId()]);
        }

        $conn = $this->em->getConnection();

        // Remap document_author records
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

        // Move existing variations
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

        // Add author's name as variation of target
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

        // Move identifiers
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
        return $this->redirectToRoute('app_admin_authors_index');
    }

    private function syncVariations(AuthorIdentity $author, string $variationsText): void
    {
        $lines = explode("\n", $variationsText);
        $validVariationNames = [];

        // Main variation
        $validVariationNames[$author->getPreferredName()] = 'official';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $validVariationNames[$line] = 'alternative';
            }
        }

        $existingVars = $author->getVariations();
        $existingMap = [];
        foreach ($existingVars as $v) {
            $existingMap[$v->getOriginalName()] = $v;
        }

        foreach ($validVariationNames as $name => $type) {
            if (!isset($existingMap[$name])) {
                $v = new AuthorNameVariant();
                $v->setOriginalName($name);
                $v->setDisplayName(StringNormalizer::normalizeString($name));
                $v->setNormalizedName(StringNormalizer::normalizeString($name, true));
                $v->setSource($type);
                $v->setConfidence(1.0);
                $author->addVariation($v);
                $this->em->persist($v);
            }
        }

        foreach ($existingMap as $name => $v) {
            if (!isset($validVariationNames[$name])) {
                $author->removeVariation($v);
                $this->em->remove($v);
            }
        }
    }

    #[Route('/export-thesaurus', name: 'app_admin_authors_export_thesaurus', methods: ['GET'])]
    public function exportThesaurus(Request $request): Response
    {
        $format = strtolower($request->query->get('format', 'the'));
        $authors = $this->em->getRepository(AuthorIdentity::class)->findAll();

        $data = [];
        foreach ($authors as $a) {
            $vars = [];
            foreach ($a->getVariations() as $v) {
                $vars[] = $v->getOriginalName();
            }
            $data[] = [
                'header' => $a->getPreferredName(),
                'variations' => $vars
            ];
        }

        if ($format === 'csv') {
            $content = $this->thesaurusService->generateCsvContent($data);
            $mime = 'text/csv; charset=utf-8';
            $filename = 'thesauro_autores.csv';
        } else {
            $content = $this->thesaurusService->generateTheContent($data);
            $mime = 'text/plain; charset=utf-8';
            $filename = 'thesauro_autores.the';
        }

        $response = new Response($content);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    #[Route('/import-thesaurus', name: 'app_admin_authors_import_thesaurus', methods: ['POST'])]
    public function importThesaurus(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_authors_thesaurus', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_authors_index');
        }

        $file = $request->files->get('thesaurus_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor, envie um arquivo .the ou .csv.');
            return $this->redirectToRoute('app_admin_authors_index');
        }

        try {
            set_time_limit(600);
            $ext = strtolower($file->getClientOriginalExtension());
            $entries = $this->thesaurusService->parseFile($file->getRealPath(), $ext);

            $authorsMap = [];
            foreach ($this->em->getRepository(AuthorIdentity::class)->findAll() as $a) {
                $authorsMap[StringNormalizer::normalizeString($a->getPreferredName(), true)] = $a;
            }

            $addedVars = 0;
            $newAuthors = 0;

            foreach ($entries as $entry) {
                $headerName = trim($entry['header'] ?? '');
                if ($headerName === '') continue;

                $normHeader = StringNormalizer::normalizeString($headerName, true);
                $author = $authorsMap[$normHeader] ?? null;

                if (!$author) {
                    $author = new AuthorIdentity();
                    $author->setPreferredName($headerName);
                    $author->setNormalizedName($normHeader);
                    $author->setStatus(1);
                    $this->em->persist($author);
                    $this->em->flush();
                    $authorsMap[$normHeader] = $author;
                    $newAuthors++;
                }

                $existingVars = [];
                foreach ($author->getVariations() as $v) {
                    $existingVars[$v->getNormalizedName()] = true;
                }

                foreach ($entry['variations'] as $varName) {
                    $normVar = StringNormalizer::normalizeString($varName, true);
                    if ($normVar === '') continue;

                    if (!isset($existingVars[$normVar])) {
                        $v = new AuthorNameVariant();
                        $v->setOriginalName($varName);
                        $v->setDisplayName(StringNormalizer::normalizeString($varName));
                        $v->setNormalizedName($normVar);
                        $v->setSource('alternative');
                        $v->setConfidence(1.0);
                        $author->addVariation($v);
                        $existingVars[$normVar] = true;
                        $addedVars++;
                    }
                }
            }

            $this->em->flush();
            $this->addFlash('success', "Importação de Tesauro concluída! Novos Autores: {$newAuthors}, Novas Variações: {$addedVars}.");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro na importação de tesauro: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_authors_index');
    }

    #[Route('/merge-preview', name: 'app_admin_authors_merge_preview', methods: ['POST'])]
    public function mergePreview(Request $request): Response
    {
        $ids = array_map('intval', (array) $request->request->all('ids'));
        $ids = array_values(array_filter($ids));

        if (count($ids) < 2 || count($ids) > 5) {
            $this->addFlash('warning', 'Selecione entre 2 e 5 autores para mesclar.');
            return $this->redirectToRoute('app_admin_authors_index');
        }

        $authors = $this->em->getRepository(AuthorIdentity::class)->findBy(['id' => $ids]);
        if (count($authors) < 2) {
            $this->addFlash('danger', 'Autores selecionados não foram encontrados.');
            return $this->redirectToRoute('app_admin_authors_index');
        }

        $allVariations = [];
        foreach ($authors as $auth) {
            if ($auth->getPreferredName()) $allVariations[] = $auth->getPreferredName();
            foreach ($auth->getVariations() as $var) {
                if ($var->getVariationName()) $allVariations[] = $var->getVariationName();
            }
        }
        $allVariations = array_values(array_unique(array_filter($allVariations)));

        return $this->render('admin/authors/merge_preview.html.twig', [
            'authors' => $authors,
            'allVariations' => $allVariations,
        ]);
    }

    #[Route('/merge-execute', name: 'app_admin_authors_merge_execute', methods: ['POST'])]
    public function mergeExecute(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('merge_authors', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_authors_index');
        }

        $masterId = (int) $request->request->get('master_id');
        $sourceIds = array_map('intval', (array) $request->request->all('source_ids'));
        $fields = (array) $request->request->all('fields');

        try {
            $master = $this->mergeService->mergeAuthors($masterId, $sourceIds, $fields);
            $this->addFlash('success', "Autor '{$master->getPreferredName()}' (#{$master->getId()}) mesclado e consolidado no Tesauro com sucesso!");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao mesclar autores: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_authors_index');
    }
}
