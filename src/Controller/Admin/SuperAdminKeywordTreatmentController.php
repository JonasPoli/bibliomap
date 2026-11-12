<?php

namespace App\Controller\Admin;

use App\Entity\Keyword;
use App\Entity\KeywordTreatmentJob;
use App\Entity\KeywordTreatmentLog;
use App\Entity\ThesaurusMatch;
use App\Service\Import\TextNormalizer;
use App\Service\DatabaseBackup\DatabaseBackupService;
use App\Service\KeywordTreatment\KeywordTreatmentBlockedException;
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
        private readonly KeywordTreatmentService $service,
        private readonly DatabaseBackupService $backups
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

        // Último backup completo do banco (só nome, data e tamanho dos arquivos)
        $lastBackup = $this->backups->findLatestBackup();

        return $this->render('admin/super_keywords_treatment/index.html.twig', [
            'diagnosis' => $diagnosis,
            'jobs' => $jobs,
            'pending_reviews_count' => $pendingReviewsCount,
            'backup_configured' => $this->backups->isConfigured(),
            'last_backup' => $lastBackup,
            'last_backup_size' => $lastBackup ? DatabaseBackupService::formatBytes($lastBackup['size']) : null,
            'backup_today' => $this->backups->hasBackupForDay(new DateTimeImmutable('today')),
        ]);
    }

    #[Route('/run', name: 'app_super_admin_keyword_treatment_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('kw_treatment_run', (string)$request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_super_admin_keyword_treatment_index');
        }

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
        } catch (KeywordTreatmentBlockedException $e) {
            // Outro tratamento em andamento ou importação em curso: nada foi gravado
            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToRoute('app_super_admin_keyword_treatment_index');
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

        // Tudo por SQL, sem hidratar um log por linha: o relatório de um tratamento real tem dezenas de
        // milhares de registros. As listas da tela são limitadas; o CSV (Exportar) traz o job completo.
        $conn = $this->em->getConnection();
        $jobId = $job->getId();
        $logsCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM keyword_treatment_log WHERE job_id = ?', [$jobId]);

        // Agrupamentos: os 50 primeiros conceitos (na ordem em que aparecem) com até 200 termos cada
        $topGroupings = [];
        $groupedTotals = [];
        $rows = $conn->executeQuery(
            "SELECT tc.preferred_label AS tc_label, nk.keyword_display AS nk_label, k.keyword_original
             FROM keyword_treatment_log l
             JOIN keyword k ON k.id = l.keyword_id
             LEFT JOIN thesaurus_concept tc ON tc.id = l.new_thesaurus_concept_id
             LEFT JOIN keyword nk ON nk.id = l.new_concept_id
             WHERE l.job_id = ? AND l.action IN ('exact_label_matched', 'exact_concept_matched', 'fuzzy_auto_matched')
             ORDER BY l.id ASC",
            [$jobId]
        );
        foreach ($rows->iterateAssociative() as $row) {
            $conceptName = $row['tc_label'] ?? $row['nk_label'] ?? 'N/A';
            if (!isset($topGroupings[$conceptName])) {
                if (count($topGroupings) >= 50) {
                    continue;
                }
                $topGroupings[$conceptName] = [];
                $groupedTotals[$conceptName] = 0;
            }
            $groupedTotals[$conceptName]++;
            if (count($topGroupings[$conceptName]) < 200) {
                $topGroupings[$conceptName][] = $row['keyword_original'];
            }
        }

        $limit = 500;
        $invalidTotal = (int) $conn->fetchOne("SELECT COUNT(*) FROM keyword_treatment_log WHERE job_id = ? AND action = 'invalid'", [$jobId]);
        $invalidTerms = $conn->fetchAllAssociative(
            "SELECT k.keyword_original AS term, l.reason FROM keyword_treatment_log l JOIN keyword k ON k.id = l.keyword_id
             WHERE l.job_id = ? AND l.action = 'invalid' ORDER BY l.id ASC LIMIT $limit",
            [$jobId]
        );

        $fuzzyTotal = (int) $conn->fetchOne("SELECT COUNT(*) FROM keyword_treatment_log WHERE job_id = ? AND action = 'fuzzy_review_required'", [$jobId]);
        $fuzzySuggestions = [];
        foreach ($conn->fetchAllAssociative(
            "SELECT k.keyword_display, tc.preferred_label AS tc_label, nk.keyword_display AS nk_label, l.reason, l.score
             FROM keyword_treatment_log l
             JOIN keyword k ON k.id = l.keyword_id
             LEFT JOIN thesaurus_concept tc ON tc.id = l.new_thesaurus_concept_id
             LEFT JOIN keyword nk ON nk.id = l.new_concept_id
             WHERE l.job_id = ? AND l.action = 'fuzzy_review_required' ORDER BY l.id ASC LIMIT $limit",
            [$jobId]
        ) as $row) {
            $fuzzySuggestions[] = [
                'display' => $row['keyword_display'],
                'concept' => $row['tc_label'] ?? $row['nk_label'] ?? $row['reason'],
                'score' => $row['score'] !== null ? (float) $row['score'] : 0.0,
            ];
        }

        return $this->render('admin/super_keywords_treatment/report.html.twig', [
            'job' => $job,
            'logs_count' => $logsCount,
            'top_groupings' => $topGroupings,
            'grouped_totals' => $groupedTotals,
            'invalid_terms' => $invalidTerms,
            'invalid_total' => $invalidTotal,
            'fuzzy_suggestions' => $fuzzySuggestions,
            'fuzzy_total' => $fuzzyTotal,
            'list_limit' => $limit,
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
            
            fputcsv($handle, ['Keyword ID', 'Original Term', 'Action', 'Old Display', 'New Display', 'Old Concept', 'New Concept', 'Score', 'Reason', 'Status'], ';', '"', '');

            // Cursor por id, em blocos de 5.000, com conceitos e palavra na mesma consulta
            $conn = $this->em->getConnection();
            $lastId = 0;
            do {
                $rows = $conn->fetchAllAssociative(
                    'SELECT l.id, l.keyword_id, k.keyword_original, l.action, l.old_display, l.new_display,
                            otc.preferred_label AS old_tc, ok.keyword_display AS old_k,
                            ntc.preferred_label AS new_tc, nk.keyword_display AS new_k,
                            l.score, l.reason, l.status
                     FROM keyword_treatment_log l
                     JOIN keyword k ON k.id = l.keyword_id
                     LEFT JOIN thesaurus_concept otc ON otc.id = l.old_thesaurus_concept_id
                     LEFT JOIN keyword ok ON ok.id = l.old_concept_id
                     LEFT JOIN thesaurus_concept ntc ON ntc.id = l.new_thesaurus_concept_id
                     LEFT JOIN keyword nk ON nk.id = l.new_concept_id
                     WHERE l.job_id = ? AND l.id > ? ORDER BY l.id ASC LIMIT 5000',
                    [$job->getId(), $lastId]
                );
                foreach ($rows as $r) {
                    fputcsv($handle, [
                        $r['keyword_id'],
                        $r['keyword_original'],
                        $r['action'],
                        $r['old_display'],
                        $r['new_display'],
                        $r['old_tc'] ?? $r['old_k'] ?? '',
                        $r['new_tc'] ?? $r['new_k'] ?? '',
                        $r['score'] !== null ? (float) $r['score'] : null,
                        $r['reason'],
                        $r['status'],
                    ], ';', '"', '');
                    $lastId = (int) $r['id'];
                }
            } while (count($rows) === 5000);

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
    public function review(Request $request): Response
    {
        $perPage = 100;
        $total = $this->em->getRepository(KeywordTreatmentLog::class)->count([
            'action' => 'fuzzy_review_required',
            'status' => 'pending',
        ]);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $request->query->getInt('page', 1)), $pages);

        $logs = $this->em->createQueryBuilder()
            ->select('l', 'k', 'tc', 'nc')->from(KeywordTreatmentLog::class, 'l')
            ->join('l.keyword', 'k')
            ->leftJoin('l.newThesaurusConcept', 'tc')
            ->leftJoin('l.newConcept', 'nc')
            ->where('l.action = :a')->andWhere('l.status = :s')
            ->setParameter('a', 'fuzzy_review_required')->setParameter('s', 'pending')
            ->orderBy('l.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();

        return $this->render('admin/super_keywords_treatment/review.html.twig', [
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ]);
    }

    #[Route('/review/action', name: 'app_super_admin_keyword_treatment_review_action', methods: ['POST'])]
    public function reviewAction(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('kw_treatment_review', (string)$request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_super_admin_keyword_treatment_review');
        }

        $logId = (int)$request->request->get('log_id');
        $decision = $request->request->get('decision'); // approve, reject, edit

        $log = $this->em->getRepository(KeywordTreatmentLog::class)->find($logId);
        if (!$log) {
            throw $this->createNotFoundException('Log de sugestão não encontrado.');
        }
        if ($log->getStatus() !== 'pending' || $log->getAction() !== 'fuzzy_review_required') {
            $this->addFlash('info', 'Esta sugestão já foi resolvida.');
            return $this->redirectToRoute('app_super_admin_keyword_treatment_review');
        }

        $kw = $log->getKeyword();

        if ($decision === 'approve') {
            // O serviço grava o conceito sugerido em newThesaurusConcept; newConcept é legado
            $concept = $log->getNewThesaurusConcept();
            $legacyConcept = $log->getNewConcept();
            if ($concept) {
                $kw->setThesaurusConcept($concept);
                $this->closePendingMatches($kw->getId(), $concept->getId(), 'accepted');
            } elseif ($legacyConcept) {
                $kw->setKeywordConcept($legacyConcept);
            }
            if ($concept || $legacyConcept) {
                $log->setStatus('applied');
                $log->setAction('manual_approved');
                $this->addFlash('success', 'Associação aprovada e aplicada.');
            } else {
                $this->addFlash('warning', 'Sugestão sem conceito registrado: nada foi aplicado. Recuse-a ou edite o termo.');
            }
        } elseif ($decision === 'reject') {
            if ($log->getNewThesaurusConcept()) {
                $this->closePendingMatches($kw->getId(), $log->getNewThesaurusConcept()->getId(), 'rejected');
            }
            $log->setStatus('rejected');
            $log->setAction('manual_rejected');
            $this->addFlash('warning', 'Sugestão de associação rejeitada.');
        } elseif ($decision === 'edit') {
            $newDisplay = trim($request->request->get('new_display', ''));
            if ($newDisplay !== '') {
                // Mesma forma de comparação usada na importação (sem acentos e pontuação)
                $newNorm = (new TextNormalizer())->normalizeForComparison($newDisplay);
                $kw->setKeywordDisplay($newDisplay);
                $kw->setKeywordNormalized($newNorm);
                $log->setNewDisplay($newDisplay);
                $log->setNewNormalized($newNorm);
                $log->setStatus('applied');
                $log->setAction('cleaned');
                $this->addFlash('success', 'Palavra-chave editada e salva.');
            }
        }

        $this->em->flush();

        return $this->redirectToRoute('app_super_admin_keyword_treatment_review');
    }

    /** Fecha a sugestão pendente correspondente em thesaurus_match (auditoria). */
    private function closePendingMatches(int $keywordId, int $conceptId, string $status): void
    {
        $matches = $this->em->getRepository(ThesaurusMatch::class)->findBy([
            'keyword' => $keywordId,
            'concept' => $conceptId,
            'status' => 'pending',
        ]);
        foreach ($matches as $match) {
            $match->setStatus($status);
        }
    }
}
