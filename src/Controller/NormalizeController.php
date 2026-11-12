<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Service\Authority\AuthorityMappingException;
use App\Service\Normalize\NormalizationService;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use League\Csv\EscapeFormula;
use Psr\Log\LoggerInterface;
use League\Csv\Writer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/normalize')]
#[IsGranted('ROLE_USER')]
class NormalizeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NormalizationService $normalizationService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Mensagem para o usuário: só as recusas previstas (token, tipo, IDs de outro projeto) aparecem como estão;
     * qualquer outra exceção vai para o log e a tela recebe uma mensagem genérica.
     */
    private function safeMessage(\Throwable $e, string $generic): string
    {
        if ($e instanceof \InvalidArgumentException || $e instanceof AuthorityMappingException || $e instanceof \Symfony\Component\Security\Core\Exception\AccessDeniedException) {
            return $e->getMessage() !== '' ? $e->getMessage() : $generic;
        }
        $this->logger->error($generic . ' ' . $e->getMessage(), ['exception' => $e]);

        return $generic;
    }

    #[Route('', name: 'app_normalize_index', methods: ['GET'])]
    public function index(int $id, Request $request): Response
    {
        $project = $this->getProject($id);

        $tab    = $request->query->get('tab', 'authors');
        $kwType = $request->query->get('kwType', 'author');
        $kwView = $request->query->get('kwView', 'suggestions'); // 'suggestions' | 'all'
        $search = $request->query->get('q', '');

        $suggestions  = [];
        $allKeywords  = [];
        $groupings    = null;

        if ($tab === 'authors') {
            $suggestions = $this->normalizationService->findSimilarAuthors($project->getId());
        } elseif ($tab === 'keywords') {
            if ($kwView === 'all') {
                $allKeywords = $this->normalizationService->getAllKeywords($project->getId(), $kwType, $search);
            } else {
                $suggestions = $this->normalizationService->findSimilarKeywords($project->getId(), $kwType);
            }
        } elseif ($tab === 'duplicates') {
            $suggestions = $this->normalizationService->findPotentialDuplicates($project->getId());
        } elseif ($tab === 'groupings') {
            // Agrupamentos do projeto (com Desfazer) e, só para leitura, os globais que afetam documentos dele
            $groupings = [];
            foreach (['author', 'keyword'] as $entity) {
                $groupings[$entity] = [
                    'project' => $this->normalizationService->projectGroupings($entity, $project->getId(), max(1, $request->query->getInt($entity . 'Page', 1))),
                    'global'  => $this->normalizationService->globalGroupingsInUse($entity, $project->getId()),
                ];
            }
        }

        return $this->render('normalize/index.html.twig', [
            'project'     => $project,
            'tab'         => $tab,
            'kwType'      => $kwType,
            'kwView'      => $kwView,
            'search'      => $search,
            'suggestions' => $suggestions,
            'allKeywords' => $allKeywords,
            'groupings'   => $groupings,
            'isAdmin'     => $this->isGranted('ROLE_ADMIN'),
        ]);
    }

    #[Route('/merge', name: 'app_normalize_merge', methods: ['POST'])]
    public function merge(int $id, Request $request): Response
    {
        $project = $this->getProject($id);
        
        $type      = $request->request->get('type');
        $keepId    = (int) $request->request->get('keepId');
        $discardId = (int) $request->request->get('discardId');

        $isAjax    = $request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json';

        try {
            if (!$this->isCsrfTokenValid('normalize_merge', $request->request->get('_token'))) {
                throw new \InvalidArgumentException('Token CSRF inválido. Recarregue a página.');
            }
            $this->assertBelongsToProject((string) $type, [$keepId, $discardId], $project->getId());

            // Administrador agrupa no global; usuário comum, só no projeto dele. Nada é apagado.
            $asAdmin = $this->isGranted('ROLE_ADMIN');
            $applied = true;
            if ($type === 'author') {
                $applied = $this->normalizationService->mergeAuthors($keepId, $discardId, $project->getId(), [], $asAdmin);
            } elseif ($type === 'keyword') {
                $applied = $this->normalizationService->mergeKeywords($keepId, $discardId, $project->getId(), [], $asAdmin);
            } elseif ($type === 'document') {
                $this->normalizationService->mergeDocuments($keepId, $discardId);
            } else {
                throw new \InvalidArgumentException('Tipo de mesclagem inválido.');
            }

            $message = match (true) {
                $type === 'document' => 'Mesclagem realizada com sucesso!',
                !$applied            => 'Este par já estava agrupado.',
                $asAdmin             => 'Agrupamento gravado para todos os projetos. Nada foi apagado; dá para desfazer na administração.',
                default              => 'Agrupamento gravado neste projeto. Nada foi apagado; dá para desfazer em "Agrupamentos do projeto".',
            };

            if ($isAjax) {
                return $this->json(['success' => true, 'message' => $message]);
            }

            $this->addFlash('success', $message);

        } catch (\Throwable $e) {
            $errMessage = $e instanceof AuthorityMappingException
                ? 'Agrupamento recusado: ' . $e->getMessage()
                : 'Erro ao realizar mesclagem: ' . $this->safeMessage($e, 'não foi possível concluir. Tente de novo ou fale com o administrador.');
            
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => $errMessage], 400);
            }

            $this->addFlash('danger', $errMessage);
        }

        return $this->redirectToRoute('app_normalize_index', [
            'id'     => $project->getId(),
            'tab'    => $request->request->get('tab', 'authors'),
            'kwType' => $request->request->get('kwType', 'author'),
        ]);
    }

    #[Route('/merge-batch', name: 'app_normalize_merge_batch', methods: ['POST'])]
    public function mergeBatch(int $id, Request $request): Response
    {
        $project = $this->getProject($id);

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Payload inválido.'], 400);
        }

        $type  = $data['type']  ?? '';
        $pairs = $data['pairs'] ?? [];

        if (!is_array($pairs) || empty($pairs)) {
            return $this->json(['success' => false, 'message' => 'Nenhum par fornecido.'], 400);
        }

        try {
            if (!$this->isCsrfTokenValid('normalize_merge', $data['_token'] ?? null)) {
                throw new \InvalidArgumentException('Token CSRF inválido. Recarregue a página.');
            }
            $ids = [];
            foreach ($pairs as $pair) {
                $ids[] = (int) ($pair['keepId'] ?? 0);
                $ids[] = (int) ($pair['discardId'] ?? 0);
            }
            $this->assertBelongsToProject((string) $type, $ids, $project->getId());

            $asAdmin = $this->isGranted('ROLE_ADMIN');
            $merged = match ($type) {
                'keyword' => $this->normalizationService->mergeKeywordsBatch($pairs, $project->getId(), $asAdmin),
                'author'  => $this->normalizationService->mergeAuthorsBatch($pairs, $project->getId(), $asAdmin),
                default   => throw new \InvalidArgumentException("Tipo '{$type}' não suportado em lote."),
            };
            $refusals = $this->normalizationService->lastRefusals();
            $message = "{$merged} item(s) agrupados" . ($asAdmin ? ' (para todos os projetos)' : ' neste projeto') . '. Nada foi apagado.';
            if ($refusals !== []) {
                $message .= ' ' . count($refusals) . ' recusado(s): ' . implode(' ', array_slice($refusals, 0, 3));
            }

            return $this->json([
                'success' => true,
                'merged'  => $merged,
                'refused' => count($refusals),
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'message' => $this->safeMessage($e, 'Erro ao mesclar em lote. Tente de novo ou fale com o administrador.')], 500);
        }
    }

    // ── Export & Import Normalization Routes ──────────────────────────────────

    #[Route('/export/authors', name: 'app_normalize_export_authors', methods: ['GET'])]
    public function exportAuthors(int $id): Response
    {
        $project = $this->getProject($id);
        $rules = $this->normalizationService->exportAuthorNormalization($project->getId());

        $csv = Writer::createFromString('');
        // Célula que começa com = + - @ vira texto no Excel; a importação desfaz o apóstrofo
        $csv->addFormatter((new EscapeFormula("'"))->escapeRecord(...));
        $csv->insertOne(['preferred_name', 'variant_name', 'source', 'created_at']);

        foreach ($rules as $r) {
            $csv->insertOne([
                $r['preferred_name'] ?? '',
                $r['variant_name'] ?? '',
                $r['source'] ?? 'alternative',
                $r['created_at'] ?? '',
            ]);
        }

        $response = new Response($csv->toString());
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="normalizacao_autores_projeto_%d.csv"', $project->getId()));

        return $response;
    }

    #[Route('/import/authors', name: 'app_normalize_import_authors', methods: ['POST'])]
    public function importAuthors(int $id, Request $request): Response
    {
        $project = $this->getProject($id);

        if (!$this->isCsrfTokenValid('import_author_normalization', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_normalize_index', ['id' => $project->getId(), 'tab' => 'authors']);
        }

        $file = $request->files->get('csv_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor, envie um arquivo CSV.');
            return $this->redirectToRoute('app_normalize_index', ['id' => $project->getId(), 'tab' => 'authors']);
        }

        try {
            $csv = Reader::createFromPath($file->getRealPath(), 'r');
            $csv->setHeaderOffset(0);

            $records = iterator_to_array($csv->getRecords());
            $applied = $this->normalizationService->importAuthorNormalization($project->getId(), $records, $this->isGranted('ROLE_ADMIN'));

            $this->addFlash('success', "Importação de normatização de autores concluída! Processados: {$applied} par(es). Nada foi apagado.");
            $this->flashRefusals();
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao importar normatização de autores: ' . $this->safeMessage($e, 'o arquivo não pôde ser processado. Confira as colunas e a codificação (UTF-8).'));
        }

        return $this->redirectToRoute('app_normalize_index', ['id' => $project->getId(), 'tab' => 'authors']);
    }

    #[Route('/export/keywords', name: 'app_normalize_export_keywords', methods: ['GET'])]
    public function exportKeywords(int $id): Response
    {
        $project = $this->getProject($id);
        $rules = $this->normalizationService->exportKeywordNormalization($project->getId());

        $csv = Writer::createFromString('');
        // Célula que começa com = + - @ vira texto no Excel; a importação desfaz o apóstrofo
        $csv->addFormatter((new EscapeFormula("'"))->escapeRecord(...));
        $csv->insertOne(['preferred_keyword', 'variant_keyword', 'keyword_type', 'status']);

        foreach ($rules as $r) {
            $csv->insertOne([
                $r['preferred_keyword'] ?? '',
                $r['variant_keyword'] ?? '',
                $r['keyword_type'] ?? 'author_keyword',
                $r['status'] ?? 1,
            ]);
        }

        $response = new Response($csv->toString());
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="normalizacao_palavras_chave_projeto_%d.csv"', $project->getId()));

        return $response;
    }

    #[Route('/import/keywords', name: 'app_normalize_import_keywords', methods: ['POST'])]
    public function importKeywords(int $id, Request $request): Response
    {
        $project = $this->getProject($id);

        if (!$this->isCsrfTokenValid('import_keyword_normalization', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_normalize_index', ['id' => $project->getId(), 'tab' => 'keywords']);
        }

        $file = $request->files->get('csv_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor, envie um arquivo CSV.');
            return $this->redirectToRoute('app_normalize_index', ['id' => $project->getId(), 'tab' => 'keywords']);
        }

        try {
            $csv = Reader::createFromPath($file->getRealPath(), 'r');
            $csv->setHeaderOffset(0);

            $records = iterator_to_array($csv->getRecords());
            $applied = $this->normalizationService->importKeywordNormalization($project->getId(), $records, $this->isGranted('ROLE_ADMIN'));

            $this->addFlash('success', "Importação de normatização de palavras-chave concluída! Processados: {$applied} par(es). Nada foi apagado.");
            $this->flashRefusals();
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao importar normatização de palavras-chave: ' . $this->safeMessage($e, 'o arquivo não pôde ser processado. Confira as colunas e a codificação (UTF-8).'));
        }

        return $this->redirectToRoute('app_normalize_index', ['id' => $project->getId(), 'tab' => 'keywords']);
    }

    /** Desfaz um agrupamento do PROJETO (a linha precisa ser do escopo deste projeto). */
    #[Route('/groupings/{mappingId}/undo', name: 'app_normalize_undo_grouping', requirements: ['mappingId' => '\d+'], methods: ['POST'])]
    public function undoGrouping(int $id, int $mappingId, Request $request): Response
    {
        $project = $this->getProject($id);

        try {
            if (!$this->isCsrfTokenValid('normalize_undo_' . $mappingId, $request->request->get('_token'))) {
                throw new \InvalidArgumentException('Token CSRF inválido. Recarregue a página.');
            }
            $this->normalizationService->undoProjectGrouping($project->getId(), $mappingId);
            $this->addFlash('success', 'Agrupamento desfeito: a identidade volta a contar por conta própria neste projeto.');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Não foi possível desfazer: ' . $this->safeMessage($e, 'tente de novo ou fale com o administrador.'));
        }

        return $this->redirectToRoute('app_normalize_index', ['id' => $project->getId(), 'tab' => 'groupings']);
    }

    /** Mostra, como aviso, os pares que o serviço recusou na importação (laço, já agrupado, fora do projeto). */
    private function flashRefusals(): void
    {
        $refusals = $this->normalizationService->lastRefusals();
        if ($refusals !== []) {
            $this->addFlash('warning', count($refusals) . ' par(es) não foram agrupados: ' . implode(' ', array_slice($refusals, 0, 5)));
        }
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Impede mesclar registros de outros projetos enviando IDs arbitrários:
     * documentos precisam ser do projeto; autores e palavras-chave precisam estar
     * vinculados a algum documento do projeto.
     */
    private function assertBelongsToProject(string $type, array $ids, int $projectId): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids || in_array(0, $ids, true)) {
            throw new \InvalidArgumentException('IDs inválidos para mesclagem.');
        }

        $sql = match ($type) {
            'document' => 'SELECT COUNT(*) FROM document WHERE project_id = ? AND id IN (?)',
            // As telas oferecem a identidade EFETIVA do vínculo (a que já considera os agrupamentos)
            'author'   => 'SELECT COUNT(DISTINCT da.effective_author_id) FROM document_author da
                           JOIN document d ON d.id = da.document_id AND d.project_id = ?
                           WHERE da.effective_author_id IN (?)',
            'keyword'  => 'SELECT COUNT(DISTINCT dk.effective_keyword_id) FROM document_keyword dk
                           JOIN document d ON d.id = dk.document_id AND d.project_id = ?
                           WHERE dk.effective_keyword_id IN (?)',
            default    => throw new \InvalidArgumentException('Tipo de mesclagem inválido.'),
        };

        $found = (int) $this->em->getConnection()->fetchOne(
            $sql,
            [$projectId, $ids],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        if ($found !== count($ids)) {
            throw $this->createAccessDeniedException('Registro fora deste projeto.');
        }
    }

    /** Normalizar altera cadastros e documentos: só o dono (ProjectVoter 'edit'). */
    private function getProject(int $id): BibliometricProject
    {
        $project = $this->em->getRepository(BibliometricProject::class)->find($id);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('edit', $project);
        return $project;
    }
}
