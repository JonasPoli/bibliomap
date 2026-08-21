<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Entity\SavedMatrix;
use App\Repository\SavedMatrixRepository;
use App\Service\Matrix\MatrixEngineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/matrices', name: 'app_matrix_')]
#[IsGranted('ROLE_USER')]
class MatrixController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SavedMatrixRepository $savedMatrixRepo,
        private readonly MatrixEngineService $matrixEngine,
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

        $rowKey       = $request->query->get('row', 'keyword_author');
        $colKey       = $request->query->get('col', 'country');
        $minWeight    = max(1, (int)$request->query->get('minWeight', 1));
        $maxRows      = min(max(5, (int)$request->query->get('maxRows', 30)), 2000);
        $maxCols      = min(max(5, (int)$request->query->get('maxCols', 30)), 2000);
        $useThesaurus = (bool)$request->query->get('useThesaurus', 1);

        $data = $this->matrixEngine->generateMatrix(
            $project->getId(),
            $rowKey,
            $colKey,
            $minWeight,
            $maxRows,
            $maxCols,
            $useThesaurus
        );

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
        $rowKey = $request->request->get('rowDimension', 'keyword_author');
        $colKey = $request->request->get('columnDimension', 'country');

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

        $rowKey       = $request->query->get('row', 'keyword_author');
        $colKey       = $request->query->get('col', 'country');
        $minWeight    = max(1, (int)$request->query->get('minWeight', 1));
        $maxRows      = min(max(5, (int)$request->query->get('maxRows', 30)), 2000);
        $maxCols      = min(max(5, (int)$request->query->get('maxCols', 30)), 2000);
        $useThesaurus = (bool)$request->query->get('useThesaurus', 1);

        $data = $this->matrixEngine->generateMatrix(
            $project->getId(),
            $rowKey,
            $colKey,
            $minWeight,
            $maxRows,
            $maxCols,
            $useThesaurus
        );

        $rows = $data['rows'];
        $cols = $data['cols'];
        $matrix = $data['matrix'];
        $rowTotals = $data['rowTotals'];
        $colTotals = $data['colTotals'];

        $csv = "\xEF\xBB\xBF"; // UTF-8 BOM

        // Header line: RowDim / ColDim ; Col1 ; Col2 ; ... ; Total
        $headerCols = array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $cols);
        $csv .= sprintf('"%s / %s";%s;"Total"' . "\r\n", $data['rowDimension'], $data['colDimension'], implode(';', $headerCols));

        // Data lines
        foreach ($rows as $r) {
            $line = '"' . str_replace('"', '""', $r) . '"';
            foreach ($cols as $c) {
                $line .= ';' . ($matrix[$r][$c] ?? 0);
            }
            $line .= ';' . ($rowTotals[$r] ?? 0);
            $csv .= $line . "\r\n";
        }

        // Total Footer line
        $footer = '"Total Geral"';
        foreach ($cols as $c) {
            $footer .= ';' . ($colTotals[$c] ?? 0);
        }
        $footer .= ';' . $data['totalPairs'];
        $csv .= $footer . "\r\n";

        $filename = 'matriz_' . $rowKey . '_vs_' . $colKey . '_' . date('Ymd') . '.csv';

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }
}
