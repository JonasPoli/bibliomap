<?php

namespace App\Controller\Admin;

use App\Entity\Keyword;
use App\Entity\KeywordTreatmentJob;
use App\Entity\KeywordTreatmentLog;
use App\Service\KeywordTreatment\KeywordTreatmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use DateTimeImmutable;

#[Route('/admin/super/keywords/treatment')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class SuperAdminKeywordTreatmentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KeywordTreatmentService $service
    ) {}

    #[Route('', name: 'app_super_admin_keyword_treatment_index', methods: ['GET'])]
    public function index(): Response
    {
        $diagnosis = $this->service->getDiagnosis();
        
        $jobs = $this->em->getRepository(KeywordTreatmentJob::class)->findBy([], ['createdAt' => 'DESC'], 10);
        
        // Count pending fuzzy review items
        $pendingReviewsCount = $this->em->getRepository(KeywordTreatmentLog::class)->count([
            'action' => 'fuzzy_review_required',
            'status' => 'pending'
        ]);

        return $this->render('admin/super_keywords_treatment/index.html.twig', [
            'diagnosis' => $diagnosis,
            'jobs' => $jobs,
            'pending_reviews_count' => $pendingReviewsCount,
        ]);
    }

    #[Route('/run', name: 'app_super_admin_keyword_treatment_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $options = \App\Service\KeywordTreatment\KeywordTreatmentOptions::fromRequest($request->request->all());

        try {
            $user = $this->getUser();
            $userName = $user ? $user->getUserIdentifier() : 'super_admin';
            
            $job = $this->service->executeJob($options, $userName);
            
            $this->addFlash('success', sprintf(
                'Job de tratamento executado com sucesso em modo %s.',
                $options->dryRun ? 'SIMULAÇÃO (Dry-run)' : 'REAL (Correção)'
            ));

            return $this->redirectToRoute('app_super_admin_keyword_treatment_report', ['id' => $job->getId()]);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao executar tratamento: ' . $e->getMessage());
            return $this->redirectToRoute('app_super_admin_keyword_treatment_index');
        }
    }

    #[Route('/report/{id}', name: 'app_super_admin_keyword_treatment_report', methods: ['GET'])]
    public function report(int $id): Response
    {
        $job = $this->em->getRepository(KeywordTreatmentJob::class)->find($id);
        if (!$job) {
            throw $this->createNotFoundException('Job não encontrado.');
        }

        $logs = $this->em->getRepository(KeywordTreatmentLog::class)->findBy(['job' => $job], ['id' => 'ASC']);

        // Group actions by types
        $topGroupings = [];
        $invalidTerms = [];
        $fuzzySuggestions = [];

        foreach ($logs as $log) {
            if ($log->getAction() === 'exact_grouped' || $log->getAction() === 'fuzzy_auto_matched') {
                $conceptName = $log->getNewConcept() ? $log->getNewConcept()->getKeywordDisplay() : 'N/A';
                $topGroupings[$conceptName][] = $log->getKeyword()->getKeywordOriginal();
            }
            if ($log->getAction() === 'invalid') {
                $invalidTerms[] = $log;
            }
            if ($log->getAction() === 'fuzzy_review_required') {
                $fuzzySuggestions[] = $log;
            }
        }

        // Limit top groupings to 50 for the template
        $topGroupings = array_slice($topGroupings, 0, 50, true);

        return $this->render('admin/super_keywords_treatment/report.html.twig', [
            'job' => $job,
            'logs_count' => count($logs),
            'top_groupings' => $topGroupings,
            'invalid_terms' => $invalidTerms,
            'fuzzy_suggestions' => $fuzzySuggestions,
        ]);
    }

    #[Route('/report/{id}/export', name: 'app_super_admin_keyword_treatment_report_export', methods: ['GET'])]
    public function export(int $id): StreamedResponse
    {
        $job = $this->em->getRepository(KeywordTreatmentJob::class)->find($id);
        if (!$job) {
            throw $this->createNotFoundException('Job não encontrado.');
        }

        $response = new StreamedResponse(function () use ($job) {
            $handle = fopen('php://output', 'w+');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM
            
            fputcsv($handle, ['Keyword ID', 'Original Term', 'Action', 'Old Display', 'New Display', 'Old Concept', 'New Concept', 'Score', 'Reason', 'Status'], ';');

            $logs = $this->em->getRepository(KeywordTreatmentLog::class)->findBy(['job' => $job]);
            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->getKeyword()->getId(),
                    $log->getKeyword()->getKeywordOriginal(),
                    $log->getAction(),
                    $log->getOldDisplay(),
                    $log->getNewDisplay(),
                    $log->getOldConcept() ? $log->getOldConcept()->getKeywordDisplay() : '',
                    $log->getNewConcept() ? $log->getNewConcept()->getKeywordDisplay() : '',
                    $log->getScore(),
                    $log->getReason(),
                    $log->getStatus()
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('relatorio_tratamento_%d.csv', $job->getId())
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/review', name: 'app_super_admin_keyword_treatment_review', methods: ['GET'])]
    public function review(): Response
    {
        $logs = $this->em->getRepository(KeywordTreatmentLog::class)->findBy([
            'action' => 'fuzzy_review_required',
            'status' => 'pending'
        ], ['id' => 'ASC']);

        return $this->render('admin/super_keywords_treatment/review.html.twig', [
            'logs' => $logs,
        ]);
    }

    #[Route('/review/action', name: 'app_super_admin_keyword_treatment_review_action', methods: ['POST'])]
    public function reviewAction(Request $request): Response
    {
        $logId = (int)$request->request->get('log_id');
        $decision = $request->request->get('decision'); // approve, reject, edit

        $log = $this->em->getRepository(KeywordTreatmentLog::class)->find($logId);
        if (!$log) {
            throw $this->createNotFoundException('Log de sugestão não encontrado.');
        }

        $kw = $log->getKeyword();

        if ($decision === 'approve') {
            $concept = $log->getNewConcept();
            if ($concept) {
                $kw->setKeywordConcept($concept);
                $log->setStatus('applied');
                $log->setAction('manual_approved');
                $this->addFlash('success', 'Associação aprovada e aplicada.');
            }
        } elseif ($decision === 'reject') {
            $log->setStatus('rejected');
            $log->setAction('manual_rejected');
            $this->addFlash('warning', 'Sugestão de associação rejeitada.');
        } elseif ($decision === 'edit') {
            $newDisplay = trim($request->request->get('new_display', ''));
            if ($newDisplay !== '') {
                $kw->setKeywordDisplay($newDisplay);
                $kw->setKeywordNormalized(strtolower($newDisplay));
                $log->setNewDisplay($newDisplay);
                $log->setNewNormalized(strtolower($newDisplay));
                $log->setStatus('applied');
                $log->setAction('cleaned');
                $this->addFlash('success', 'Palavra-chave editada e salva.');
            }
        }

        $this->em->flush();

        return $this->redirectToRoute('app_super_admin_keyword_treatment_review');
    }
}
