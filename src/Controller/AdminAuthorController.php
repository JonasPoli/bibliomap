<?php

namespace App\Controller;

use App\Entity\AuthorIdentity;
use App\Entity\AuthorNameVariant;
use App\Entity\AuthorExternalIdentifier;
use App\Service\Import\StringNormalizer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Service\Thesaurus\ThesaurusFileService;
use App\Service\Thesaurus\EntityMergeService;
use App\Service\Authority\AuthorityLinkGuard;
use App\Service\Authority\AuthorityMergeLogger;

#[Route('/admin/authors')]
#[IsGranted('ROLE_ADMIN')]
class AdminAuthorController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThesaurusFileService $thesaurusService,
        private readonly EntityMergeService $mergeService,
        private readonly AuthorityLinkGuard $linkGuard,
        private readonly AuthorityMergeLogger $mergeLogger,
    ) {}

    #[Route('', name: 'app_admin_authors_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = trim($request->query->getString('search', ''));
        $normSearch = StringNormalizer::normalizeString($search, true);
        $status = $request->query->get('status', 'all');
        $page   = max(1, $request->query->getInt('page', 1));
        $limit  = 100;

        // Só a página: 1) ids (a junção das variações e dos identificadores entra apenas com busca),
        // 2) contagem, 3) entidades da página com os identificadores, 4) contagem de variações por GROUP BY.
        $qb = $this->em->createQueryBuilder()->from(AuthorIdentity::class, 'a');

        if ($search !== '') {
            $qb->leftJoin('a.variations', 'v')->leftJoin('a.identifiers', 'id')
               ->andWhere('a.preferredName LIKE :search OR a.normalizedName LIKE :normSearch OR id.identifier LIKE :search OR v.originalName LIKE :search OR v.displayName LIKE :search OR v.normalizedName LIKE :normSearch')
               ->setParameter('search', '%' . $search . '%')
               ->setParameter('normSearch', '%' . $normSearch . '%');
        }

        if ($status === 'active') {
            $qb->andWhere('a.status = 1');
        } elseif ($status === 'inactive') {
            $qb->andWhere('a.status = 0');
        }

        $totalItems = (int) (clone $qb)->select('COUNT(DISTINCT a.id)')->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($totalItems / $limit));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $idsQb = (clone $qb)->select('a.id', 'a.preferredName')
            ->orderBy('a.preferredName', 'ASC')->addOrderBy('a.id', 'ASC')
            ->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        if ($search !== '') {
            $idsQb->distinct();
        }
        $ids = array_map('intval', array_column($idsQb->getQuery()->getScalarResult(), 'id'));

        $authors = [];
        $variationCounts = [];
        if ($ids !== []) {
            $byId = [];
            foreach ($this->em->createQueryBuilder()
                ->select('a', 'ident')->from(AuthorIdentity::class, 'a')
                ->leftJoin('a.identifiers', 'ident')
                ->where('a.id IN (:ids)')->setParameter('ids', $ids)
                ->getQuery()->getResult() as $entity) {
                $byId[$entity->getId()] = $entity;
            }
            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $authors[] = $byId[$id];
                }
            }
            $variationCounts = $this->em->getConnection()->executeQuery(
                'SELECT author_identity_id, COUNT(*) FROM author_name_variant WHERE author_identity_id IN (?) GROUP BY author_identity_id',
                [$ids],
                [ArrayParameterType::INTEGER]
            )->fetchAllKeyValue();
        }

        return $this->render('admin/authors/index.html.twig', [
            'authors' => $authors,
            'variationCounts' => $variationCounts,
            // Documentos ligados a cada autor (uma consulta para a página): com documentos, Excluir fica desabilitado
            'docCounts' => $this->linkGuard->documentCounts('author', $ids),
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

            fputcsv($handle, ['name', 'orcid', 'status', 'variations'], ';', '"', '\\');

            // Em blocos de 2.000 autores (paginação por chave em nome preferido + id): memória constante,
            // sem carregar as grafias e os ORCIDs de todos os autores de uma vez.
            $lastName = null;
            $lastId = 0;
            do {
                $rows = $lastName === null
                    ? $conn->fetchAllAssociative('SELECT id, preferred_name, status FROM author_identity ORDER BY preferred_name ASC, id ASC LIMIT 2000')
                    : $conn->fetchAllAssociative(
                        'SELECT id, preferred_name, status FROM author_identity
                         WHERE preferred_name > ? OR (preferred_name = ? AND id > ?)
                         ORDER BY preferred_name ASC, id ASC LIMIT 2000',
                        [$lastName, $lastName, $lastId]
                    );
                if ($rows === []) {
                    break;
                }
                $ids = array_map(static fn (array $r) => (int) $r['id'], $rows);

                $varsByAuthor = [];
                foreach ($conn->executeQuery(
                    'SELECT author_identity_id, original_name FROM author_name_variant WHERE source != "official" AND author_identity_id IN (?) ORDER BY id',
                    [$ids], [ArrayParameterType::INTEGER]
                )->iterateNumeric() as [$aid, $orig]) {
                    $varsByAuthor[(int) $aid][] = $orig;
                }
                $orcidByAuthor = [];
                foreach ($conn->executeQuery(
                    'SELECT author_identity_id, identifier FROM author_external_identifier WHERE provider = "orcid" AND author_identity_id IN (?) ORDER BY id',
                    [$ids], [ArrayParameterType::INTEGER]
                )->iterateNumeric() as [$aid, $ident]) {
                    $orcidByAuthor[(int) $aid] = $ident;
                }

                foreach ($rows as $auth) {
                    $authId = (int) $auth['id'];
                    fputcsv($handle, [
                        $auth['preferred_name'],
                        $orcidByAuthor[$authId] ?? '',
                        $auth['status'] ? '1' : '0',
                        implode(';', $varsByAuthor[$authId] ?? []),
                    ], ';', '"', '\\');
                }

                $last = end($rows);
                $lastName = $last['preferred_name'];
                $lastId = (int) $last['id'];
            } while (count($rows) === 2000);
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

            // Separador detectado (; , ou tab): o CSV exportado (;) reimporta sem conversão
            $csv = ThesaurusFileService::createCsvReader($file->getRealPath());

            $imported = 0;
            $updated = 0;
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            // Uma transação a cada 500 linhas (antes cada comando gravava e confirmava sozinho)
            $rows = 0;
            $conn->beginTransaction();

            foreach ($csv->getRecords() as $record) {
                $name = trim($record['name'] ?? '');
                if ($name === '') continue;

                if ($rows > 0 && $rows % 500 === 0) {
                    $conn->commit();
                    $conn->beginTransaction();
                }
                $rows++;

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
                if (!$id) {
                    // Cadastros antigos têm normalized_name em outro formato (com pontuação); o nome exportado
                    // é o preferred_name, então a reimportação do próprio CSV casa por ele em vez de duplicar.
                    $id = $conn->fetchOne('SELECT id FROM author_identity WHERE preferred_name IN (?, ?) LIMIT 1', [$name, $displayName]);
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

            $conn->commit();

            $this->addFlash('success', "Importação concluída: {$imported} novos autores criados, {$updated} atualizados.");
        } catch (\Throwable $e) {
            if (isset($conn) && $conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->addFlash('danger', 'Erro ao importar CSV (as linhas de lotes de 500 já confirmados ficam gravadas; o lote em andamento foi desfeito): ' . $e->getMessage());
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
            // Checkbox desmarcado não é enviado: ausente = inativo
            $author->setStatus($request->request->getBoolean('status'));
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
            // Checkbox desmarcado não é enviado: ausente = inativo
            $author->setStatus($request->request->getBoolean('status'));
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

        // O destino da mesclagem é escolhido por busca (app_admin_authors_search): a lista fixa
        // trazia só os 500 primeiros em ordem alfabética de ~150 mil autores.
        return $this->render('admin/authors/edit.html.twig', [
            'author' => $author,
            'orcid' => $orcid,
            'variationsText' => $variationsText,
        ]);
    }

    #[Route('/search', name: 'app_admin_authors_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $q = trim($request->query->getString('q', ''));
        $exclude = $request->query->getInt('exclude');
        if (mb_strlen($q) < 2 && !ctype_digit($q)) {
            return $this->json([]);
        }

        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT a.id, a.preferred_name,
                    (SELECT COUNT(DISTINCT da.document_id) FROM document_author da WHERE da.author_identity_id = a.id) AS docs
             FROM author_identity a
             WHERE a.id <> ? AND (a.id = ? OR a.preferred_name LIKE ? OR a.normalized_name LIKE ?)
             ORDER BY (a.id = ?) DESC, a.preferred_name ASC
             LIMIT 30',
            [$exclude, ctype_digit($q) ? (int) $q : 0, '%' . $q . '%', '%' . StringNormalizer::normalizeString($q, true) . '%', ctype_digit($q) ? (int) $q : 0]
        );

        return $this->json(array_map(fn(array $r) => [
            'id' => (int) $r['id'],
            'label' => sprintf('%s (#%d, %d doc.)', $r['preferred_name'], $r['id'], $r['docs']),
        ], $rows));
    }

    #[Route('/{id}/delete', name: 'app_admin_authors_delete', methods: ['POST'])]
    public function delete(AuthorIdentity $author, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_author_' . $author->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_authors_index');
        }

        $name = $author->getPreferredName();

        // Com documentos ligados não exclui: o ON DELETE CASCADE apagaria os vínculos e o nome original deles
        $docs = $this->linkGuard->documentCount('author', (int) $author->getId());
        if ($docs > 0) {
            $this->addFlash('danger', $this->linkGuard->blockMessage('author', $name, $docs));
            return $this->redirectToRoute('app_admin_authors_index');
        }

        $authorId = (int) $author->getId();
        $this->em->getConnection()->transactional(function () use ($author, $authorId, $name) {
            $this->mergeLogger->begin('screen_admin', 'delete', 'author', $authorId, null, (string) $name, [
                'normalized_name' => $author->getNormalizedName(),
            ]);
            $this->em->remove($author);
            $this->em->flush();
        });

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
        // Diário e troca dos vínculos na mesma transação (o diário copia os vínculos antes de mudarem)
        $conn->transactional(function () use ($conn, $parent, $newAuthor, $varName) {
            $logId = $this->mergeLogger->begin('screen_admin', 'remap', 'author', (int) $parent->getId(), (int) $newAuthor->getId(), (string) $parent->getPreferredName(), [
                'separated_variation' => $varName,
            ]);
            $this->mergeLogger->logLinks($logId, 'document_author', (int) $parent->getId(), null, $varName);
            $conn->executeStatement(
                'UPDATE document_author SET author_identity_id = ? WHERE author_identity_id = ? AND original_name = ?',
                [$newAuthor->getId(), $parent->getId(), $varName]
            );
        });

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

        // Pelo serviço de fusão: remapeia os vínculos e recria grafias e identificadores no destino, numa transação
        $name = $author->getPreferredName();
        try {
            $this->mergeService->mergeAuthors($target->getId(), [$author->getId()], []);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao mesclar autores: ' . $e->getMessage());
            return $this->redirectToRoute('app_admin_authors_index');
        }

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
        $filename = ($format === 'csv') ? 'thesauro_autores.csv' : 'thesauro_autores.the';
        $sql = 'SELECT a.preferred_name AS header, v.original_name AS variation
                FROM author_identity a
                LEFT JOIN author_name_variant v ON v.author_identity_id = a.id
                ORDER BY a.id ASC';

        return $this->thesaurusService->streamExport($this->em->getConnection(), $sql, $format, $filename);
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

            // Só as identidades que o arquivo cita (tabela global grande): por normalized_name, em lotes.
            // Mesma chave de antes (nome preferido normalizado); em duplicata vale a de maior id.
            $authorsMap = [];
            $headers = ThesaurusFileService::normalizedHeaders($entries, static fn (string $h) => StringNormalizer::normalizeString($h, true));
            foreach (array_chunk($headers, 500) as $chunk) {
                $found = $this->em->createQueryBuilder()
                    ->select('a')->from(AuthorIdentity::class, 'a')
                    ->where('a.normalizedName IN (:n)')->setParameter('n', $chunk)
                    ->orderBy('a.id', 'ASC')
                    ->getQuery()->getResult();
                foreach ($found as $a) {
                    $authorsMap[StringNormalizer::normalizeString($a->getPreferredName(), true)] = $a;
                }
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

                    $varName = mb_substr($varName, 0, 500, 'UTF-8');
                    $normVar = mb_substr($normVar, 0, 500, 'UTF-8');

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
