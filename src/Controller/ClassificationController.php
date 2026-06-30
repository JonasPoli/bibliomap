<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Entity\ClassificationGroup;
use App\Entity\ClassificationRule;
use App\Entity\DocumentClassification;
use App\Repository\ClassificationGroupRepository;
use App\Repository\DocumentClassificationRepository;
use App\Service\Classification\ClassificationEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/classification', name: 'app_classification_')]
#[IsGranted('ROLE_USER')]
class ClassificationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface            $em,
        private readonly ClassificationGroupRepository    $groupRepo,
        private readonly DocumentClassificationRepository $classRepo,
        private readonly ClassificationEngine             $engine,
    ) {}

    // ─── Index ────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(BibliometricProject $project): Response
    {
        $this->denyAccessUnlessGranted('view', $project);

        $groups    = $this->groupRepo->findByProject($project->getId());
        $counts    = $this->classRepo->countByGroup($project->getId());
        $hasResult = $this->classRepo->hasResults($project->getId());

        return $this->render('classification/index.html.twig', [
            'project'   => $project,
            'groups'    => $groups,
            'counts'    => $counts,
            'hasResult' => $hasResult,
        ]);
    }

    // ─── Create Group ─────────────────────────────────────────────────────────

    #[Route('/groups/new', name: 'group_new', methods: ['GET', 'POST'])]
    public function groupNew(BibliometricProject $project, Request $request): Response
    {
        $this->denyAccessUnlessGranted('edit', $project);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('clf_group_new_' . $project->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de segurança inválido.');
                return $this->redirectToRoute('app_classification_index', ['id' => $project->getId()]);
            }

            $group = $this->buildGroupFromRequest($request, $project, new ClassificationGroup());
            $this->em->persist($group);
            $this->em->flush();

            $this->addFlash('success', "Grupo \"{$group->getName()}\" criado com sucesso!");
            return $this->redirectToRoute('app_classification_index', ['id' => $project->getId()]);
        }

        return $this->render('classification/group_form.html.twig', [
            'project' => $project,
            'group'   => new ClassificationGroup(),
            'mode'    => 'new',
        ]);
    }

    // ─── Edit Group ───────────────────────────────────────────────────────────

    #[Route('/groups/{groupId}/edit', name: 'group_edit', methods: ['GET', 'POST'])]
    public function groupEdit(BibliometricProject $project, int $groupId, Request $request): Response
    {
        $this->denyAccessUnlessGranted('edit', $project);

        $group = $this->groupRepo->find($groupId);
        if (!$group || $group->getProject()->getId() !== $project->getId()) {
            throw $this->createNotFoundException('Grupo não encontrado.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('clf_group_edit_' . $groupId, $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de segurança inválido.');
                return $this->redirectToRoute('app_classification_index', ['id' => $project->getId()]);
            }

            $this->buildGroupFromRequest($request, $project, $group);
            $this->em->flush();

            $this->addFlash('success', "Grupo \"{$group->getName()}\" atualizado!");
            return $this->redirectToRoute('app_classification_index', ['id' => $project->getId()]);
        }

        return $this->render('classification/group_form.html.twig', [
            'project' => $project,
            'group'   => $group,
            'mode'    => 'edit',
        ]);
    }

    // ─── Delete Group ─────────────────────────────────────────────────────────

    #[Route('/groups/{groupId}/delete', name: 'group_delete', methods: ['POST'])]
    public function groupDelete(BibliometricProject $project, int $groupId, Request $request): Response
    {
        $this->denyAccessUnlessGranted('edit', $project);

        $group = $this->groupRepo->find($groupId);
        if ($group && $group->getProject()->getId() === $project->getId()) {
            if ($this->isCsrfTokenValid('clf_group_del_' . $groupId, $request->request->get('_token'))) {
                $name = $group->getName();
                $this->em->remove($group);
                $this->em->flush();
                $this->addFlash('warning', "Grupo \"$name\" removido.");
            }
        }

        return $this->redirectToRoute('app_classification_index', ['id' => $project->getId()]);
    }

    // ─── Run Classification ───────────────────────────────────────────────────

    #[Route('/run', name: 'run', methods: ['POST'])]
    public function run(BibliometricProject $project, Request $request): Response
    {
        $this->denyAccessUnlessGranted('edit', $project);

        if (!$this->isCsrfTokenValid('clf_run_' . $project->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');
            return $this->redirectToRoute('app_classification_index', ['id' => $project->getId()]);
        }

        try {
            $stats = $this->engine->run($project);
            $this->addFlash('success', sprintf(
                '✅ Classificação concluída! %d documentos processados — %d classificados, %d ruído, %d sem grupo.',
                $stats['total'],
                $stats['total'] - $stats['noise'] - $stats['unclassified'],
                $stats['noise'],
                $stats['unclassified']
            ));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao executar classificação: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_classification_results', ['id' => $project->getId()]);
    }

    // ─── Results ──────────────────────────────────────────────────────────────

    #[Route('/results', name: 'results', methods: ['GET'])]
    public function results(BibliometricProject $project, Request $request): Response
    {
        $this->denyAccessUnlessGranted('view', $project);

        $limit     = 100;
        $page      = max(1, (int) $request->query->get('page', 1));
        $groups    = $this->groupRepo->findByProject($project->getId());
        $counts    = $this->classRepo->countByGroup($project->getId());
        $hasResult = $this->classRepo->hasResults($project->getId());

        $activeGroupId = $request->query->get('group');
        $activeGroup   = null;
        $documents     = [];
        $total         = 0;
        $totalPages    = 1;

        if ($hasResult) {
            if ($activeGroupId === 'unclassified') {
                $total      = $this->classRepo->countByProjectAndGroup($project->getId(), null);
                $totalPages = max(1, (int) ceil($total / $limit));
                $page       = min($page, $totalPages);
                $documents  = $this->classRepo->findByProjectAndGroup($project->getId(), null, $page, $limit);
            } elseif ($activeGroupId !== null) {
                $gid = (int) $activeGroupId;
                foreach ($groups as $g) {
                    if ($g->getId() === $gid) { $activeGroup = $g; break; }
                }
                $total      = $this->classRepo->countByProjectAndGroup($project->getId(), $gid);
                $totalPages = max(1, (int) ceil($total / $limit));
                $page       = min($page, $totalPages);
                $documents  = $this->classRepo->findByProjectAndGroup($project->getId(), $gid, $page, $limit);
            } else {
                // default: first group
                $activeGroup = $groups[0] ?? null;
                if ($activeGroup) {
                    $total      = $this->classRepo->countByProjectAndGroup($project->getId(), $activeGroup->getId());
                    $totalPages = max(1, (int) ceil($total / $limit));
                    $page       = min($page, $totalPages);
                    $documents  = $this->classRepo->findByProjectAndGroup($project->getId(), $activeGroup->getId(), $page, $limit);
                }
            }
        }

        return $this->render('classification/results.html.twig', [
            'project'       => $project,
            'groups'        => $groups,
            'counts'        => $counts,
            'hasResult'     => $hasResult,
            'activeGroup'   => $activeGroup,
            'activeGroupId' => $activeGroupId,
            'documents'     => $documents,
            'page'          => $page,
            'totalPages'    => $totalPages,
            'total'         => $total,
            'limit'         => $limit,
        ]);
    }

    // ─── Move Document ────────────────────────────────────────────────────────

    #[Route('/move', name: 'move', methods: ['POST'])]
    public function move(BibliometricProject $project, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('edit', $project);

        if (!$this->isCsrfTokenValid('clf_move_' . $project->getId(), $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'Token inválido'], 403);
        }

        $docId   = (int) $request->request->get('doc_id');
        $groupId = $request->request->get('group_id');

        $clf = $this->classRepo->findOneBy(['document' => $docId, 'project' => $project->getId()]);
        if (!$clf) {
            return new JsonResponse(['ok' => false, 'error' => 'Classificação não encontrada'], 404);
        }

        if ($groupId === null || $groupId === '' || $groupId === 'unclassified') {
            $clf->setGroup(null);
        } else {
            $group = $this->groupRepo->find((int) $groupId);
            if (!$group || $group->getProject()->getId() !== $project->getId()) {
                return new JsonResponse(['ok' => false, 'error' => 'Grupo inválido'], 400);
            }
            $clf->setGroup($group);
        }

        $clf->setManualOverride(true);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    // ─── Export CSV ───────────────────────────────────────────────────────────

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(BibliometricProject $project): Response
    {
        $this->denyAccessUnlessGranted('view', $project);

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT d.title, d.abstract_text, d.year, d.doi,
                    g.name AS group_name, g.type AS group_type,
                    dc.matched_term, dc.manual_override, dc.run_at
             FROM document_classification dc
             JOIN document d ON d.id = dc.document_id
             LEFT JOIN classification_group g ON g.id = dc.group_id
             WHERE dc.project_id = ?
             ORDER BY g.name, d.year DESC',
            [$project->getId()]
        );

        $csv = "\xEF\xBB\xBF"; // BOM UTF-8
        $csv .= "Título;Ano;DOI;Grupo;Tipo;Termo Correspondido;Override Manual;Data Classificação\r\n";
        foreach ($rows as $r) {
            $csv .= sprintf(
                '"%s";%s;"%s";"%s";"%s";"%s";%s;"%s"' . "\r\n",
                str_replace('"', '""', $r['title'] ?? ''),
                $r['year'] ?? '',
                $r['doi'] ?? '',
                str_replace('"', '""', $r['group_name'] ?? 'Sem Classificação'),
                $r['group_type'] ?? '',
                str_replace('"', '""', $r['matched_term'] ?? ''),
                $r['manual_override'] ? 'Sim' : 'Não',
                $r['run_at'] ?? '',
            );
        }

        $filename = 'classificacao_' . $project->getId() . '_' . date('Ymd') . '.csv';

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function buildGroupFromRequest(Request $request, BibliometricProject $project, ClassificationGroup $group): ClassificationGroup
    {
        $group->setProject($project);
        $group->setName(trim($request->request->get('name', '')));
        $group->setDescription(trim($request->request->get('description', '')) ?: null);
        $group->setType($request->request->get('type', ClassificationGroup::TYPE_NORMAL));
        $group->setColor($request->request->get('color', '#4f8ef7'));
        $group->setIcon($request->request->get('icon', 'bi-collection'));
        $group->setPosition((int) $request->request->get('position', 0));

        // Rebuild rules from comma-separated terms
        foreach ($group->getRules() as $r) {
            $this->em->remove($r);
        }
        $group->getRules()->clear();

        $termsRaw = $request->request->get('terms', '');
        $terms    = array_filter(array_map('trim', explode(',', $termsRaw)));
        $position = 0;
        foreach ($terms as $termStr) {
            $rule = new ClassificationRule();
            $rule->setTerm($termStr);
            $rule->setGroup($group);
            $group->addRule($rule);
        }

        return $group;
    }
}
