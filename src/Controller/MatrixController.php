<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Entity\SavedMatrix;
use App\Repository\SavedMatrixRepository;
use App\Service\Analytics\CsvCell;
use App\Service\Cache\AnalyticsCache;
use App\Service\Matrix\MatrixEngineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/matrices', name: 'app_matrix_')]
#[IsGranted('ROLE_USER')]
class MatrixController extends AbstractController
{
    /** Máximo de linhas e de colunas desenhadas na tela; tamanhos maiores só no CSV (em streaming). */
    public const SCREEN_LIMIT = 250;
    /** Máximo de linhas e de colunas no CSV. */
    public const CSV_LIMIT = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SavedMatrixRepository $savedMatrixRepo,
        private readonly MatrixEngineService $matrixEngine,
        private readonly AnalyticsCache $cache,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(BibliometricProject $project): Response
    {
        $this->denyAccessUnlessGranted('view', $project);

        $dimensions    = $this->matrixEngine->getAvailableDimensions();
        $savedMatrices = $this->savedMatrixRepo->findByProject($project->getId());

        // Group dimensions by category for clean UI rendering
        $categories = [];
        foreach ($dimensions as $dim) {
            $cat = $dim['category'];
            $categories[$cat][] = $dim;
        }

        return $this->render('matrix/index.html.twig', [
            'project'       => $project,
            'dimensions'    => $dimensions,
            'categories'    => $categories,
            'savedMatrices' => $savedMatrices,
        ]);
    }

    #[Route('/generate', name: 'generate', methods: ['GET'])]
    public function generate(BibliometricProject $project, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('view', $project);

        $rowKey       = (string) $request->query->get('row', 'keyword_author');
        $colKey       = (string) $request->query->get('col', 'country');
        $minWeight    = max(1, (int)$request->query->get('minWeight', 1));
        $reqRows      = min(max(5, (int)$request->query->get('maxRows', 30)), self::CSV_LIMIT);
        $reqCols      = min(max(5, (int)$request->query->get('maxCols', 30)), self::CSV_LIMIT);
        $useThesaurus = (bool)$request->query->get('useThesaurus', 1);

        // Na tela o teto é SCREEN_LIMIT: tabelas maiores travam o navegador (o CSV traz o tamanho pedido)
        $maxRows = min($reqRows, self::SCREEN_LIMIT);
        $maxCols = min($reqCols, self::SCREEN_LIMIT);

        // sparse: só as células com valor entram na resposta (a tela trata célula ausente como zero).
        // O tempo de processamento (processingTimeMs) vai junto no cache: é o do cálculo original.
        $compute = fn() => $this->matrixEngine->generateMatrix(
            $project->getId(),
            $rowKey,
            $colKey,
            $minWeight,
            $maxRows,
            $maxCols,
            $useThesaurus,
            true
        );
        // Dimensão fora da lista oficial não vai para o cache (parâmetro livre = entrada nova a cada texto)
        $data = ($this->matrixEngine->getDimension($rowKey) !== null && $this->matrixEngine->getDimension($colKey) !== null)
            ? $this->cache->get($project->getId(), 'matrix.generate', [
                'row' => $rowKey, 'col' => $colKey, 'minWeight' => $minWeight,
                'maxRows' => $maxRows, 'maxCols' => $maxCols, 'useThesaurus' => $useThesaurus,
            ], $compute)
            : $compute();
        $data['screenLimit'] = self::SCREEN_LIMIT;
        $data['limited']     = $maxRows < $reqRows || $maxCols < $reqCols;

        return new JsonResponse($data);
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(BibliometricProject $project, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('edit', $project);

        if (!$this->isCsrfTokenValid('matrix_save_' . $project->getId(), $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'Token CSRF inválido.'], 403);
        }

        $name   = trim($request->request->get('name', ''));
        $rowKey = (string) $request->request->get('rowDimension', 'keyword_author');
        $colKey = (string) $request->request->get('columnDimension', 'country');

        // As dimensões voltam à tela em atributos de HTML: só as da lista oficial são aceitas
        if ($this->matrixEngine->getDimension($rowKey) === null || $this->matrixEngine->getDimension($colKey) === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Dimensão inválida.'], 400);
        }

        if ($name === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Nome da matriz é obrigatório.'], 400);
        }

        $matrix = new SavedMatrix();
        $matrix->setProject($project)
            ->setName($name)
            ->setDescription(trim($request->request->get('description', '')) ?: null)
            ->setRowDimension($rowKey)
            ->setColumnDimension($colKey)
            ->setMinCellWeight((int)$request->request->get('minCellWeight', 1))
            ->setMaxRows((int)$request->request->get('maxRows', 30))
            ->setMaxCols((int)$request->request->get('maxCols', 30))
            ->setUseThesaurus((bool)$request->request->get('useThesaurus', 1));

        $this->em->persist($matrix);
        $this->em->flush();

        return new JsonResponse([
            'ok'   => true,
            'id'   => $matrix->getId(),
            'name' => $matrix->getName(),
        ]);
    }

    #[Route('/{matrixId}/delete', name: 'delete', methods: ['POST'])]
    public function delete(BibliometricProject $project, int $matrixId, Request $request): Response
    {
        $this->denyAccessUnlessGranted('edit', $project);

        $matrix = $this->savedMatrixRepo->find($matrixId);
        if ($matrix && $matrix->getProject()->getId() === $project->getId()) {
            if ($this->isCsrfTokenValid('matrix_del_' . $matrixId, $request->request->get('_token'))) {
                $this->em->remove($matrix);
                $this->em->flush();
                $this->addFlash('warning', "Matriz \"{$matrix->getName()}\" removida.");
            }
        }

        return $this->redirectToRoute('app_matrix_index', ['id' => $project->getId()]);
    }

    #[Route('/export-csv', name: 'export_csv', methods: ['GET'])]
    public function exportCsv(BibliometricProject $project, Request $request): Response
    {
        $this->denyAccessUnlessGranted('view', $project);

        $rowKey       = (string) $request->query->get('row', 'keyword_author');
        $colKey       = (string) $request->query->get('col', 'country');
        $minWeight    = max(1, (int)$request->query->get('minWeight', 1));
        $maxRows      = min(max(5, (int)$request->query->get('maxRows', 30)), self::CSV_LIMIT);
        $maxCols      = min(max(5, (int)$request->query->get('maxCols', 30)), self::CSV_LIMIT);
        $useThesaurus = (bool)$request->query->get('useThesaurus', 1);

        // sparse: a matriz de 2000 × 2000 não é montada inteira na memória; as linhas saem uma a uma
        $data = $this->matrixEngine->generateMatrix(
            $project->getId(),
            $rowKey,
            $colKey,
            $minWeight,
            $maxRows,
            $maxCols,
            $useThesaurus,
            true
        );

        $filename = 'matriz_' . preg_replace('/[^a-z0-9_]/i', '', $rowKey) . '_vs_' . preg_replace('/[^a-z0-9_]/i', '', $colKey) . '_' . date('Ymd') . '.csv';
        $quote = static fn(string $v): string => '"' . str_replace('"', '""', CsvCell::escape($v)) . '"';

        $response = new StreamedResponse(function () use ($data, $quote): void {
            $rows      = $data['rows'];
            $cols      = $data['cols'];
            $matrix    = $data['matrix'];
            $rowTotals = $data['rowTotals'];
            $colTotals = $data['colTotals'];

            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

            // Header line: RowDim / ColDim ; Col1 ; Col2 ; ... ; Total
            fwrite($out, sprintf('"%s / %s";%s;"Total"' . "\r\n", $data['rowDimension'], $data['colDimension'], implode(';', array_map($quote, $cols))));

            // Data lines
            foreach ($rows as $r) {
                $line = $quote($r);
                $cells = $matrix[$r] ?? [];
                foreach ($cols as $c) {
                    $line .= ';' . ($cells[$c] ?? 0);
                }
                fwrite($out, $line . ';' . ($rowTotals[$r] ?? 0) . "\r\n");
            }

            // Total Footer line
            $footer = '"Total Geral"';
            foreach ($cols as $c) {
                $footer .= ';' . ($colTotals[$c] ?? 0);
            }
            fwrite($out, $footer . ';' . $data['totalPairs'] . "\r\n");
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"$filename\"");

        return $response;
    }
}
