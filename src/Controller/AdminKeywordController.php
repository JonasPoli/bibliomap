<?php

namespace App\Controller;

use App\Entity\Keyword;
use App\Entity\KeywordVariation;
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
use App\Service\Authority\AuthorityMappingException;
use App\Service\Authority\AuthorityMappingService;
use App\Service\Authority\AuthorityMergeLogger;
use App\Service\Authority\AuthorityResolver;

#[Route('/admin/keywords')]
#[IsGranted('ROLE_ADMIN')]
class AdminKeywordController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThesaurusFileService $thesaurusService,
        private readonly EntityMergeService $mergeService,
        private readonly AuthorityLinkGuard $linkGuard,
        private readonly AuthorityMergeLogger $mergeLogger,
        private ?AuthorityResolver $authority = null,
        private ?AuthorityMappingService $mappingService = null,
    ) {}

    /** Agrupador de autoridades; criado sob demanda quando o controlador é montado à mão (testes). */
    private function mappings(): AuthorityMappingService
    {
        return $this->mappingService ??= new AuthorityMappingService($this->em, $this->authority(), $this->mergeLogger, null);
    }

    /** Resolvedor do id efetivo; criado sob demanda quando o controlador é montado à mão (testes). */
    private function authority(): AuthorityResolver
    {
        return $this->authority ??= new AuthorityResolver($this->em->getConnection());
    }

    #[Route('', name: 'app_admin_keywords_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = trim($request->query->getString('search', ''));
        $normSearch = StringNormalizer::normalizeString($search, true);
        $status = $request->query->get('status', 'all');
        $type   = $request->query->get('type', 'all');
        $page   = max(1, $request->query->getInt('page', 1));
        $limit  = 100;

        // Só a página: ids primeiro (junção das variações apenas com busca), contagem, entidades e
        // contagem de variações por GROUP BY (sem uma consulta por linha).
        $qb = $this->em->createQueryBuilder()->from(Keyword::class, 'k');

        if ($search !== '') {
            $qb->leftJoin('k.variations', 'v')
               ->andWhere('k.keywordOriginal LIKE :search OR k.keywordDisplay LIKE :search OR k.keywordType LIKE :search OR v.variationName LIKE :search OR v.normalizedName LIKE :normSearch')
               ->setParameter('search', '%' . $search . '%')
               ->setParameter('normSearch', '%' . $normSearch . '%');
        }

        if ($status === 'active') {
            $qb->andWhere('k.status = 1');
        } elseif ($status === 'inactive') {
            $qb->andWhere('k.status = 0');
        }

        if ($type !== 'all' && in_array($type, [Keyword::TYPE_AUTHOR, Keyword::TYPE_INDEXED, Keyword::TYPE_MESH])) {
            $qb->andWhere('k.keywordType = :type')
               ->setParameter('type', $type);
        }

        // Só principais: esconde as palavras que já estão agrupadas em outra (conceito agrupador ou mapeamento global)
        $onlyMain = $request->query->getBoolean('only_main');
        if ($onlyMain) {
            $qb->andWhere('(k.keywordConcept IS NULL OR IDENTITY(k.keywordConcept) = k.id OR k.thesaurusConcept IS NOT NULL)')
               ->andWhere('NOT EXISTS (SELECT 1 FROM App\\Entity\\AuthorityMapping gm WHERE gm.entityType = \'keyword\' AND gm.scope = \'global\' AND gm.sourceId = k.id)');
        }

        $totalItems = (int) (clone $qb)->select('COUNT(DISTINCT k.id)')->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($totalItems / $limit));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $idsQb = (clone $qb)->select('k.id', 'k.keywordOriginal')
            ->orderBy('k.keywordOriginal', 'ASC')->addOrderBy('k.id', 'ASC')
            ->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        if ($search !== '') {
            $idsQb->distinct();
        }
        $ids = array_map('intval', array_column($idsQb->getQuery()->getScalarResult(), 'id'));

        $keywords = [];
        $variationCounts = [];
        if ($ids !== []) {
            $byId = [];
            foreach ($this->em->getRepository(Keyword::class)->findBy(['id' => $ids]) as $entity) {
                $byId[$entity->getId()] = $entity;
            }
            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $keywords[] = $byId[$id];
                }
            }
            $variationCounts = $this->em->getConnection()->executeQuery(
                'SELECT keyword_id, COUNT(*) FROM palavra_chave_variacoes_nome WHERE keyword_id IN (?) GROUP BY keyword_id',
                [$ids],
                [ArrayParameterType::INTEGER]
            )->fetchAllKeyValue();
        }

        return $this->render('admin/keywords/index.html.twig', [
            'keywords' => $keywords,
            'variationCounts' => $variationCounts,
            // Documentos ligados a cada palavra (uma consulta para a página): com documentos, Excluir fica desabilitado
            'docCounts' => $this->linkGuard->documentCounts('keyword', $ids),
            'search' => $search,
            'status' => $status,
            'type' => $type,
            'onlyMain' => $onlyMain,
            // Palavras da página que já estão agrupadas em outra: "agrupada em X"
            'groupings' => $this->mappings()->globalGroupings('keyword', $ids),
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'limit' => $limit,
        ]);
    }

    /** Aba "Agrupamentos": mesclagens globais de palavras-chave, com o botão Desfazer. */
    #[Route('/agrupamentos', name: 'app_admin_keywords_groupings', methods: ['GET'])]
    public function groupings(Request $request): Response
    {
        $list = $this->mappings()->list('keyword', 'global', 0, max(1, $request->query->getInt('page', 1)), AuthorityMappingService::PER_PAGE);

        return $this->render('admin/keywords/groupings.html.twig', [
            'list' => $list,
            'entity' => 'keyword',
            'undoRoute' => 'app_admin_keywords_grouping_undo',
            'backRoute' => 'app_admin_keywords_index',
            'listRoute' => 'app_admin_keywords_groupings',
        ]);
    }

    #[Route('/agrupamentos/{sourceId}/desfazer', name: 'app_admin_keywords_grouping_undo', requirements: ['sourceId' => '\\d+'], methods: ['POST'])]
    public function undoGrouping(int $sourceId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('undo_grouping_keyword_' . $sourceId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_keywords_groupings');
        }
        try {
            $r = $this->mappings()->undo('keyword', 'global', 0, $sourceId);
            $this->addFlash('success', sprintf('Agrupamento desfeito: a palavra-chave #%d volta a contar por conta própria (%d vínculos recalculados).', $sourceId, $r['affected_links']));
        } catch (AuthorityMappingException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_keywords_groupings', ['page' => max(1, $request->query->getInt('page', 1))]);
    }

    #[Route('/export', name: 'app_admin_keywords_export', methods: ['GET'])]
    public function export(): Response
    {
        $conn = $this->em->getConnection();

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function() use ($conn) {
            $handle = fopen('php://output', 'w+');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['term', 'type', 'status', 'variations'], ';', '"', '\\');

            // Em blocos de 2.000 palavras (paginação por chave em termo original + id): memória constante,
            // sem carregar as variações de todas as palavras de uma vez.
            $lastTerm = null;
            $lastId = 0;
            do {
                $rows = $lastTerm === null
                    ? $conn->fetchAllAssociative('SELECT id, keyword_original, keyword_display, keyword_type, status FROM keyword ORDER BY keyword_original ASC, id ASC LIMIT 2000')
                    : $conn->fetchAllAssociative(
                        'SELECT id, keyword_original, keyword_display, keyword_type, status FROM keyword
                         WHERE keyword_original > ? OR (keyword_original = ? AND id > ?)
                         ORDER BY keyword_original ASC, id ASC LIMIT 2000',
                        [$lastTerm, $lastTerm, $lastId]
                    );
                if ($rows === []) {
                    break;
                }
                $ids = array_map(static fn (array $r) => (int) $r['id'], $rows);

                $varsByKw = [];
                foreach ($conn->executeQuery(
                    'SELECT keyword_id, variation_name FROM palavra_chave_variacoes_nome WHERE variation_type != "official" AND keyword_id IN (?) ORDER BY id',
                    [$ids], [ArrayParameterType::INTEGER]
                )->iterateNumeric() as [$kid, $vname]) {
                    $varsByKw[(int) $kid][] = $vname;
                }

                foreach ($rows as $kw) {
                    fputcsv($handle, [
                        $kw['keyword_display'] ?? $kw['keyword_original'],
                        $kw['keyword_type'],
                        $kw['status'] ? '1' : '0',
                        implode(';', $varsByKw[(int) $kw['id']] ?? []),
                    ], ';', '"', '\\');
                }

                $last = end($rows);
                $lastTerm = $last['keyword_original'];
                $lastId = (int) $last['id'];
            } while (count($rows) === 2000);
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="palavras_chave.csv"');

        return $response;
    }

    #[Route('/import', name: 'app_admin_keywords_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_keywords', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        $file = $request->files->get('csv_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor, envie um arquivo CSV.');
            return $this->redirectToRoute('app_admin_keywords_index');
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
                $term = trim($record['term'] ?? '');
                if ($term === '') continue;

                if ($rows > 0 && $rows % 500 === 0) {
                    $conn->commit();
                    $conn->beginTransaction();
                }
                $rows++;

                $type = trim($record['type'] ?? Keyword::TYPE_AUTHOR);
                if (!in_array($type, [Keyword::TYPE_AUTHOR, Keyword::TYPE_INDEXED, Keyword::TYPE_MESH])) {
                    if ($type === 'author') $type = Keyword::TYPE_AUTHOR;
                    elseif ($type === 'indexed') $type = Keyword::TYPE_INDEXED;
                    else $type = Keyword::TYPE_AUTHOR;
                }
                $status = ($record['status'] ?? '1') === '1' ? 1 : 0;
                $variationsStr = trim($record['variations'] ?? '');

                $displayName = StringNormalizer::normalizeString($term);
                $normTerm = StringNormalizer::normalizeString($term, true);

                $keyId = $conn->fetchOne('SELECT id FROM keyword WHERE keyword_normalized = ? AND keyword_type = ?', [$normTerm, $type]);
                if ($keyId) {
                    $updated++;
                    $conn->executeStatement(
                        'UPDATE keyword SET keyword_display = ?, status = ? WHERE id = ?',
                        [$displayName, $status, $keyId]
                    );
                } else {
                    $conn->insert('keyword', [
                        'keyword_original' => $term,
                        'keyword_display' => $displayName,
                        'keyword_normalized' => $normTerm,
                        'keyword_type' => $type,
                        'status' => $status,
                    ]);
                    $keyId = (int)$conn->lastInsertId();
                    $conn->executeStatement('UPDATE keyword SET keyword_concept_id = ? WHERE id = ?', [$keyId, $keyId]);
                    $imported++;
                }

                // Sync variations
                $lines = explode(';', $variationsStr);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $lineNorm = StringNormalizer::normalizeString($line, true);

                    $exists = $conn->fetchOne('SELECT id FROM palavra_chave_variacoes_nome WHERE keyword_id = ? AND normalized_name = ?', [$keyId, $lineNorm]);
                    if (!$exists) {
                        $conn->insert('palavra_chave_variacoes_nome', [
                            'keyword_id' => $keyId,
                            'variation_name' => $line,
                            'normalized_name' => $lineNorm,
                            'variation_type' => 'alternative',
                            'status' => 1,
                        ]);
                    }
                }
            }

            $conn->commit();

            $this->addFlash('success', "Importação concluída: {$imported} novas palavras-chave criadas, {$updated} atualizadas.");
        } catch (\Throwable $e) {
            if (isset($conn) && $conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->addFlash('danger', 'Erro ao importar CSV (as linhas de lotes de 500 já confirmados ficam gravadas; o lote em andamento foi desfeito): ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_keywords_index');
    }

    #[Route('/new', name: 'app_admin_keywords_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $keyword = new Keyword();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_keyword', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_keywords_index');
            }

            $term = (string)$request->request->get('term');
            $displayName = StringNormalizer::normalizeString($term);
            $normTerm = StringNormalizer::normalizeString($term, true);

            $keyword->setKeywordOriginal($term);
            $keyword->setKeywordDisplay($displayName);
            $keyword->setKeywordNormalized($normTerm);
            $keyword->setKeywordType((string)$request->request->get('type', Keyword::TYPE_AUTHOR));
            $keyword->setStatus($request->request->getBoolean('status', true));

            $this->em->persist($keyword);
            $this->em->flush();

            // Set concept to itself initially
            $keyword->setKeywordConcept($keyword);
            $this->em->flush();

            $this->syncVariations($keyword, (string)$request->request->get('variationsText'));

            $this->addFlash('success', "Palavra-chave '{$keyword->getKeywordOriginal()}' criada com sucesso!");
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        return $this->render('admin/keywords/new.html.twig', [
            'keyword' => $keyword,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_keywords_edit', methods: ['GET', 'POST'])]
    public function edit(Keyword $keyword, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_keyword_' . $keyword->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_keywords_index');
            }

            $term = (string)$request->request->get('term');
            $displayName = StringNormalizer::normalizeString($term);
            $normTerm = StringNormalizer::normalizeString($term, true);

            $keyword->setKeywordOriginal($term);
            $keyword->setKeywordDisplay($displayName);
            $keyword->setKeywordNormalized($normTerm);
            $keyword->setKeywordType((string)$request->request->get('type', Keyword::TYPE_AUTHOR));
            $keyword->setStatus($request->request->getBoolean('status', true));

            $this->syncVariations($keyword, (string)$request->request->get('variationsText'));
            $this->em->flush();

            $this->addFlash('success', "Palavra-chave '{$keyword->getTerm()}' atualizada!");
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        $variationNames = [];
        foreach ($keyword->getVariations() as $v) {
            if ($v->getNormalizedName() !== $keyword->getKeywordNormalized()) {
                $variationNames[] = $v->getVariationName();
            }
        }
        $variationsText = implode("\n", $variationNames);

        // O destino da mesclagem é escolhido por busca (app_admin_keywords_search): a lista fixa
        // trazia só os 500 primeiros termos em ordem alfabética.
        return $this->render('admin/keywords/edit.html.twig', [
            'keyword' => $keyword,
            'variationsText' => $variationsText,
        ]);
    }

    #[Route('/search', name: 'app_admin_keywords_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $q = trim($request->query->getString('q', ''));
        $exclude = $request->query->getInt('exclude');
        $type = $request->query->getString('type', '');
        if (mb_strlen($q) < 2 && !ctype_digit($q)) {
            return $this->json([]);
        }

        $id = ctype_digit($q) ? (int) $q : 0;
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT k.id, COALESCE(k.keyword_display, k.keyword_original) AS term,
                    (SELECT COUNT(DISTINCT dk.document_id) FROM document_keyword dk WHERE dk.keyword_id = k.id) AS docs
             FROM keyword k
             WHERE k.id <> ? AND (? = \'\' OR k.keyword_type = ?)
               AND (k.id = ? OR k.keyword_original LIKE ? OR k.keyword_normalized LIKE ?)
             ORDER BY (k.id = ?) DESC, k.keyword_original ASC
             LIMIT 30',
            [$exclude, $type, $type, $id, '%' . $q . '%', '%' . StringNormalizer::normalizeString($q, true) . '%', $id]
        );

        return $this->json(array_map(fn(array $r) => [
            'id' => (int) $r['id'],
            'label' => sprintf('%s (#%d, %d doc.)', $r['term'], $r['id'], $r['docs']),
        ], $rows));
    }

    #[Route('/{id}/delete', name: 'app_admin_keywords_delete', methods: ['POST'])]
    public function delete(Keyword $keyword, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_keyword_' . $keyword->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        $term = $keyword->getKeywordOriginal();

        // Com documentos ligados não exclui: o ON DELETE CASCADE apagaria os vínculos e o termo original deles
        $docs = $this->linkGuard->documentCount('keyword', (int) $keyword->getId());
        if ($docs > 0) {
            $this->addFlash('danger', $this->linkGuard->blockMessage('keyword', $term, $docs));
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        $keywordId = (int) $keyword->getId();
        $this->em->getConnection()->transactional(function () use ($keyword, $keywordId, $term) {
            $this->mergeLogger->begin('screen_admin', 'delete', 'keyword', $keywordId, null, (string) $term, [
                'normalized' => $keyword->getKeywordNormalized(),
                'type'       => $keyword->getKeywordType(),
            ]);
            $this->em->remove($keyword);
            $this->em->flush();
        });

        $this->addFlash('success', "Palavra-chave '{$term}' excluída permanentemente!");
        return $this->redirectToRoute('app_admin_keywords_index');
    }

    #[Route('/variation/{id}/separate', name: 'app_admin_keywords_variation_separate', methods: ['POST'])]
    public function separateVariation(int $id, Request $request): Response
    {
        $variation = $this->em->getRepository(KeywordVariation::class)->find($id);
        if (!$variation) {
            $this->addFlash('danger', 'Variação não encontrada.');
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        if (!$this->isCsrfTokenValid('separate_var_' . $variation->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_keywords_edit', ['id' => $variation->getKeyword()->getId()]);
        }

        $parent = $variation->getKeyword();
        $varName = $variation->getVariationName();
        $varNorm = $variation->getNormalizedName();
        $varDisplay = StringNormalizer::normalizeString($varName);

        $newKeyword = new Keyword();
        $newKeyword->setKeywordOriginal($varName);
        $newKeyword->setKeywordDisplay($varDisplay);
        $newKeyword->setKeywordNormalized($varNorm);
        $newKeyword->setKeywordType($parent->getKeywordType());
        $newKeyword->setStatus(true);
        $this->em->persist($newKeyword);
        $this->em->flush();

        $newKeyword->setKeywordConcept($newKeyword);

        // Add official variation for the new keyword
        $newVar = new KeywordVariation();
        $newVar->setVariationName($varName);
        $newVar->setNormalizedName($varNorm);
        $newVar->setVariationType('official');
        $newVar->setKeyword($newKeyword);
        $this->em->persist($newVar);

        // Remap document_keyword records with original name to point to new keyword
        $conn = $this->em->getConnection();
        // Diário e troca dos vínculos na mesma transação (o diário copia os vínculos antes de mudarem)
        $conn->transactional(function () use ($conn, $parent, $newKeyword, $varName) {
            $logId = $this->mergeLogger->begin('screen_admin', 'remap', 'keyword', (int) $parent->getId(), (int) $newKeyword->getId(), (string) $parent->getKeywordOriginal(), [
                'separated_variation' => $varName,
            ]);
            $this->mergeLogger->logLinks($logId, 'document_keyword', (int) $parent->getId(), null, $varName);
            $conn->executeStatement(
                'UPDATE document_keyword SET keyword_id = ?, effective_keyword_id = 0 WHERE keyword_id = ? AND original_term = ?',
                [$newKeyword->getId(), $parent->getId(), $varName]
            );
            // Efetivo dos vínculos que mudaram de palavra (0 = recalcular), na mesma transação
            $this->authority()->refreshPendingByRaw(AuthorityResolver::ENTITY_KEYWORD, (int) $newKeyword->getId());
        });

        // Remove from old parent
        $parent->removeVariation($variation);
        $this->em->remove($variation);
        $this->em->flush();

        $this->addFlash('success', "Variação '{$varName}' desmembrada com sucesso para uma nova palavra-chave!");
        return $this->redirectToRoute('app_admin_keywords_edit', ['id' => $newKeyword->getId()]);
    }

    #[Route('/{id}/merge', name: 'app_admin_keywords_merge', methods: ['POST'])]
    public function merge(Keyword $keyword, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('merge_keyword_' . $keyword->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        $targetId = (int)$request->request->get('targetId');
        $target = $this->em->getRepository(Keyword::class)->find($targetId);

        if (!$target || $target->getId() === $keyword->getId()) {
            $this->addFlash('danger', 'Palavra-chave de destino inválida.');
            return $this->redirectToRoute('app_admin_keywords_edit', ['id' => $keyword->getId()]);
        }

        // Pelo serviço de fusão: grava o agrupamento global (nada é apagado nem repontuado), numa transação
        $name = $keyword->getKeywordOriginal();
        try {
            $this->mergeService->mergeKeywords($target->getId(), [$keyword->getId()], []);
        } catch (\Throwable $e) {
            $this->addFlash($e instanceof AuthorityMappingException ? 'warning' : 'danger', 'Não foi possível agrupar as palavras-chave: ' . $e->getMessage());
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        $this->addFlash('success', "Palavra-chave '{$name}' agrupada em '{$target->getKeywordOriginal()}'. Nada foi apagado; para voltar atrás, use Desfazer na aba Agrupamentos.");
        return $this->redirectToRoute('app_admin_keywords_index');
    }

    private function syncVariations(Keyword $keyword, string $variationsText): void
    {
        $lines = explode("\n", $variationsText);
        $validVariationNames = [];

        // Main variation
        $validVariationNames[$keyword->getKeywordOriginal()] = 'official';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $validVariationNames[$line] = 'alternative';
            }
        }

        $existingVars = $keyword->getVariations();
        $existingMap = [];
        foreach ($existingVars as $v) {
            $existingMap[$v->getVariationName()] = $v;
        }

        foreach ($validVariationNames as $name => $type) {
            if (!isset($existingMap[$name])) {
                $v = new KeywordVariation();
                $v->setVariationName($name);
                $v->setNormalizedName(StringNormalizer::normalizeString($name, true));
                $v->setVariationType($type);
                $keyword->addVariation($v);
                $this->em->persist($v);
            }
        }

        foreach ($existingMap as $name => $v) {
            if (!isset($validVariationNames[$name])) {
                $keyword->removeVariation($v);
                $this->em->remove($v);
            }
        }
    }

    #[Route('/export-thesaurus', name: 'app_admin_keywords_export_thesaurus', methods: ['GET'])]
    public function exportThesaurus(Request $request): Response
    {
        $format = strtolower($request->query->get('format', 'the'));
        $filename = ($format === 'csv') ? 'thesauro_palavras_chave.csv' : 'thesauro_palavras_chave.the';
        $sql = 'SELECT k.keyword_original AS header, v.variation_name AS variation
                FROM keyword k
                LEFT JOIN palavra_chave_variacoes_nome v ON v.keyword_id = k.id
                ORDER BY k.id ASC';

        return $this->thesaurusService->streamExport($this->em->getConnection(), $sql, $format, $filename);
    }

    #[Route('/import-thesaurus', name: 'app_admin_keywords_import_thesaurus', methods: ['POST'])]
    public function importThesaurus(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_keywords_thesaurus', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        $file = $request->files->get('thesaurus_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor, envie um arquivo .the ou .csv.');
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        try {
            set_time_limit(600);
            $ext = strtolower($file->getClientOriginalExtension());
            $entries = $this->thesaurusService->parseFile($file->getRealPath(), $ext);

            // Só as palavras que o arquivo cita (tabela global grande): por keyword_normalized, em lotes.
            // Mesma chave de antes (termo original normalizado); em duplicata vale a de maior id.
            $keywordsMap = [];
            $headers = ThesaurusFileService::normalizedHeaders($entries, static fn (string $h) => StringNormalizer::normalizeString($h, true));
            foreach (array_chunk($headers, 500) as $chunk) {
                $found = $this->em->createQueryBuilder()
                    ->select('k')->from(Keyword::class, 'k')
                    ->where('k.keywordNormalized IN (:n)')->setParameter('n', $chunk)
                    ->orderBy('k.id', 'ASC')
                    ->getQuery()->getResult();
                foreach ($found as $k) {
                    $keywordsMap[StringNormalizer::normalizeString($k->getKeywordOriginal(), true)] = $k;
                }
            }

            $addedVars = 0;
            $newKeywords = 0;
            $created = [];

            foreach ($entries as $entry) {
                $headerName = trim($entry['header'] ?? '');
                if ($headerName === '') continue;

                $normHeader = StringNormalizer::normalizeString($headerName, true);
                $keyword = $keywordsMap[$normHeader] ?? null;

                if (!$keyword) {
                    $keyword = new Keyword();
                    $keyword->setKeywordOriginal($headerName);
                    $keyword->setKeywordDisplay($headerName);
                    // Sem keyword_normalized a importação de documentos (que busca por normalizado+tipo) nunca casaria
                    $keyword->setKeywordNormalized($normHeader);
                    $keyword->setKeywordType(Keyword::TYPE_AUTHOR);
                    $keyword->setStatus(1);
                    $this->em->persist($keyword);
                    $keywordsMap[$normHeader] = $keyword;
                    $created[] = $keyword;
                    $newKeywords++;
                }

                $existingVars = [];
                foreach ($keyword->getVariations() as $v) {
                    $existingVars[$v->getNormalizedName()] = true;
                }

                foreach ($entry['variations'] as $varName) {
                    $normVar = StringNormalizer::normalizeString($varName, true);
                    if ($normVar === '') continue;

                    $varName = mb_substr($varName, 0, 500, 'UTF-8');
                    $normVar = mb_substr($normVar, 0, 500, 'UTF-8');

                    if (!isset($existingVars[$normVar])) {
                        $v = new KeywordVariation();
                        $v->setVariationName($varName);
                        $v->setNormalizedName($normVar);
                        $v->setVariationType('alternative');
                        $keyword->addVariation($v);
                        $existingVars[$normVar] = true;
                        $addedVars++;
                    }
                }
            }

            $this->em->flush();

            // Conceito inicial = o próprio termo (como em new() e na importação de documentos)
            foreach ($created as $keyword) {
                $keyword->setKeywordConcept($keyword);
            }
            $this->em->flush();

            $this->addFlash('success', "Importação de Tesauro concluída! Novas Palavras-Chave: {$newKeywords}, Novas Variações: {$addedVars}.");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro na importação de tesauro: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_keywords_index');
    }

    #[Route('/merge-preview', name: 'app_admin_keywords_merge_preview', methods: ['POST'])]
    public function mergePreview(Request $request): Response
    {
        $ids = array_map('intval', (array) $request->request->all('ids'));
        $ids = array_values(array_filter($ids));

        if (count($ids) < 2 || count($ids) > 5) {
            $this->addFlash('warning', 'Selecione entre 2 e 5 palavras-chave para mesclar.');
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        $keywords = $this->em->getRepository(Keyword::class)->findBy(['id' => $ids]);
        if (count($keywords) < 2) {
            $this->addFlash('danger', 'Palavras-chave selecionadas não foram encontradas.');
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        $allVariations = [];
        foreach ($keywords as $kw) {
            if ($kw->getKeywordOriginal()) $allVariations[] = $kw->getKeywordOriginal();
            if ($kw->getKeywordDisplay()) $allVariations[] = $kw->getKeywordDisplay();
            foreach ($kw->getVariations() as $var) {
                if ($var->getVariationName()) $allVariations[] = $var->getVariationName();
            }
        }
        $allVariations = array_values(array_unique(array_filter($allVariations)));

        return $this->render('admin/keywords/merge_preview.html.twig', [
            'keywords' => $keywords,
            'allVariations' => $allVariations,
        ]);
    }

    #[Route('/merge-execute', name: 'app_admin_keywords_merge_execute', methods: ['POST'])]
    public function mergeExecute(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('merge_keywords', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        $masterId = (int) $request->request->get('master_id');
        $sourceIds = array_map('intval', (array) $request->request->all('source_ids'));
        $fields = (array) $request->request->all('fields');

        try {
            $master = $this->mergeService->mergeKeywords($masterId, $sourceIds, $fields);
            $this->addFlash('success', "Palavra-chave '{$master->getKeywordOriginal()}' (#{$master->getId()}) agrupada com as selecionadas (nada foi apagado; Desfazer na aba Agrupamentos).");
        } catch (\Throwable $e) {
            $this->addFlash($e instanceof AuthorityMappingException ? 'warning' : 'danger', 'Não foi possível agrupar as palavras-chave: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_keywords_index');
    }
}
