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

        $countries = $this->em->getRepository(\App\Entity\Country::class)->findBy([], ['commonName' => 'ASC']);
        $natureOptions = ['Pública', 'Privada', 'Empresa', 'Governo', 'Ensino/Pesquisa', 'Internacional'];
        $continentes = ['América do Sul', 'América do Norte', 'Europa', 'Ásia', 'África', 'Oceania'];

        return $this->render('classification/group_form.html.twig', [
            'project'       => $project,
            'group'         => new ClassificationGroup(),
            'mode'          => 'new',
            'countries'     => $countries,
            'natureOptions' => $natureOptions,
            'continentes'   => $continentes,
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

        $countries = $this->em->getRepository(\App\Entity\Country::class)->findBy([], ['commonName' => 'ASC']);
        $natureOptions = ['Pública', 'Privada', 'Empresa', 'Governo', 'Ensino/Pesquisa', 'Internacional'];
        $continentes = ['América do Sul', 'América do Norte', 'Europa', 'Ásia', 'África', 'Oceania'];

        return $this->render('classification/group_form.html.twig', [
            'project'       => $project,
            'group'         => $group,
            'mode'          => 'edit',
            'countries'     => $countries,
            'natureOptions' => $natureOptions,
            'continentes'   => $continentes,
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
            } else {
                $this->addFlash('danger', 'Token de segurança inválido para exclusão.');
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

        // Save min_year setting from the form
        $minYearRaw = $request->request->get('min_year', '');
        $minYear = ($minYearRaw !== '' && $minYearRaw !== null) ? (int) $minYearRaw : null;
        $project->setClassificationMinYear($minYear);
        $this->em->flush();

        try {
            $stats = $this->engine->run($project);
            $multiMsg = $stats['multi'] > 0
                ? sprintf(', %d em múltiplos grupos', $stats['multi'])
                : '';
            $this->addFlash('success', sprintf(
                '✅ Classificação concluída! %d documentos processados — %d classificados, %d ruído, %d sem grupo%s.',
                $stats['total'],
                $stats['total'] - $stats['noise'] - $stats['unclassified'],
                $stats['noise'],
                $stats['unclassified'],
                $multiMsg
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
        $totalDocs = $this->classRepo->countDistinctDocuments($project->getId());

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

        // Build a map of documentId => [group names] for multi-classification display
        $docGroupNames = [];
        foreach ($documents as $dc) {
            $docId = $dc->getDocument()?->getId();
            if ($docId && !isset($docGroupNames[$docId])) {
                $docGroupNames[$docId] = $this->classRepo->findGroupNamesByDocument($docId, $project->getId());
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
            'totalDocs'     => $totalDocs,
            'docGroupNames' => $docGroupNames,
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

        // Fetch all classifications with groups, aggregating groups per document
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT d.id AS doc_id, d.title, d.abstract_text, d.year, d.doi,
                    GROUP_CONCAT(DISTINCT g.name ORDER BY g.position SEPARATOR "|") AS group_names,
                    GROUP_CONCAT(DISTINCT g.type ORDER BY g.position SEPARATOR "|") AS group_types,
                    GROUP_CONCAT(DISTINCT dc.matched_term SEPARATOR "|") AS matched_terms,
                    MAX(dc.manual_override) AS manual_override,
                    MAX(dc.run_at) AS run_at
             FROM document_classification dc
             JOIN document d ON d.id = dc.document_id
             LEFT JOIN classification_group g ON g.id = dc.group_id
             WHERE dc.project_id = ?
             GROUP BY d.id
             ORDER BY group_names, d.year DESC',
            [$project->getId()]
        );

        $csv = "\xEF\xBB\xBF"; // BOM UTF-8
        $csv .= "Título;Ano;DOI;Grupos;Tipos;Termos Correspondidos;Override Manual;Data Classificação\r\n";
        foreach ($rows as $r) {
            $csv .= sprintf(
                '"%s";%s;"%s";"%s";"%s";"%s";%s;"%s"' . "\r\n",
                str_replace('"', '""', $r['title'] ?? ''),
                $r['year'] ?? '',
                $r['doi'] ?? '',
                str_replace('"', '""', $r['group_names'] ?? 'Sem Classificação'),
                $r['group_types'] ?? '',
                str_replace('"', '""', $r['matched_terms'] ?? ''),
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

    // ─── Export VOSviewer Categories ──────────────────────────────────────────

    #[Route('/export-vosviewer', name: 'export_vosviewer', methods: ['GET'])]
    public function exportVosviewer(BibliometricProject $project): Response
    {
        $this->denyAccessUnlessGranted('view', $project);

        // Fetch all documents with their non-noise, non-unclassified group names
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT d.id AS doc_id,
                    GROUP_CONCAT(DISTINCT g.name ORDER BY g.position SEPARATOR ";") AS group_names
             FROM document_classification dc
             JOIN document d ON d.id = dc.document_id
             JOIN classification_group g ON g.id = dc.group_id
             WHERE dc.project_id = ?
               AND g.type = ?
             GROUP BY d.id
             ORDER BY d.id',
            [$project->getId(), ClassificationGroup::TYPE_NORMAL]
        );

        $content = "label\n";
        foreach ($rows as $r) {
            if (!empty($r['group_names'])) {
                $content .= $r['group_names'] . "\n";
            }
        }

        $filename = 'vosviewer-categorias-' . $project->getId() . '.txt';

        return new Response($content, 200, [
            'Content-Type'        => 'text/plain; charset=UTF-8',
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

        $matchFields = $request->request->all('match_fields');
        if (empty($matchFields)) {
            $matchFields = ['title', 'abstract', 'author_keywords', 'indexed_keywords'];
        }
        $group->setMatchFields($matchFields);

        $startYear = $request->request->get('start_year');
        $group->setStartYear($startYear !== null && $startYear !== '' ? (int)$startYear : null);

        $endYear = $request->request->get('end_year');
        $group->setEndYear($endYear !== null && $endYear !== '' ? (int)$endYear : null);

        $natures = $request->request->all('institution_nature');
        $group->setInstitutionNature(!empty($natures) ? array_values($natures) : null);

        $continente = trim($request->request->get('continente', ''));
        $group->setContinente($continente !== '' ? $continente : null);

        $countries = $request->request->all('country_ids');
        $group->setCountryIds(!empty($countries) ? array_values($countries) : null);

        $authorsRaw = trim($request->request->get('authors_filter', ''));
        $authors = array_filter(array_map('trim', explode(',', $authorsRaw)));
        $group->setAuthorsFilter(!empty($authors) ? array_values($authors) : null);

        $qualis = $request->request->all('qualis_filter');
        $group->setQualisFilter(!empty($qualis) ? array_values(array_map('strtoupper', $qualis)) : null);

        $group->setUseThesaurus((bool)$request->request->get('use_thesaurus', 1));

        // Rebuild rules from comma-separated terms
        foreach ($group->getRules() as $r) {
            $this->em->remove($r);
        }
        $group->getRules()->clear();

        $termsRaw = $request->request->get('terms', '');
        $terms    = array_filter(array_map('trim', explode(',', $termsRaw)));
        foreach ($terms as $termStr) {
            $rule = new ClassificationRule();
            $rule->setTerm($termStr);
            $rule->setGroup($group);
            $group->addRule($rule);
        }

        return $group;
    }

    // ─── Export Groups CSV ───────────────────────────────────────────────────

    #[Route('/groups/export-csv', name: 'groups_export_csv', methods: ['GET'])]
    public function exportGroupsCsv(BibliometricProject $project): Response
    {
        $this->denyAccessUnlessGranted('view', $project);

        $groups = $this->groupRepo->findByProject($project->getId());

        $csv = "\xEF\xBB\xBF"; // UTF-8 BOM
        $csv .= "nome;tipo;cor;icone;posicao;termos;campos_match;ano_inicio;ano_fim;qualis;natureza_instituicao;continente;paises;autores;usar_tesauro\r\n";

        foreach ($groups as $g) {
            $terms = [];
            foreach ($g->getRules() as $r) {
                $terms[] = $r->getTerm();
            }
            $termsStr = implode('; ', $terms);

            $matchFieldsStr = implode('; ', $g->getMatchFields() ?? []);
            $qualisStr = implode('; ', $g->getQualisFilter() ?? []);
            $natureStr = implode('; ', $g->getInstitutionNature() ?? []);
            $countriesStr = implode('; ', $g->getCountryIds() ?? []);
            $authorsStr = implode('; ', $g->getAuthorsFilter() ?? []);

            $fields = [
                $g->getName(),
                $g->getType(),
                $g->getColor(),
                $g->getIcon(),
                $g->getPosition(),
                $termsStr,
                $matchFieldsStr,
                $g->getStartYear() ?? '',
                $g->getEndYear() ?? '',
                $qualisStr,
                $natureStr,
                $g->getContinente() ?? '',
                $countriesStr,
                $authorsStr,
                $g->isUseThesaurus() ? '1' : '0',
            ];

            $escaped = array_map(function($val) {
                $v = str_replace('"', '""', (string)$val);
                return '"' . $v . '"';
            }, $fields);

            $csv .= implode(';', $escaped) . "\r\n";
        }

        $filename = 'grupos_classificacao_projeto_' . $project->getId() . '_' . date('Ymd_His') . '.csv';

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }

    // ─── Import Groups CSV ───────────────────────────────────────────────────

    #[Route('/groups/import-csv', name: 'groups_import_csv', methods: ['POST'])]
    public function importGroupsCsv(BibliometricProject $project, Request $request): Response
    {
        $this->denyAccessUnlessGranted('edit', $project);

        if (!$this->isCsrfTokenValid('groups_import_' . $project->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_classification_index', ['id' => $project->getId()]);
        }

        $file = $request->files->get('csv_file');
        if (!$file || !$file->isValid()) {
            $this->addFlash('danger', 'Por favor, selecione um arquivo CSV válido.');
            return $this->redirectToRoute('app_classification_index', ['id' => $project->getId()]);
        }

        $handle = fopen($file->getPathname(), 'r');
        if (!$handle) {
            $this->addFlash('danger', 'Erro ao ler o arquivo enviado.');
            return $this->redirectToRoute('app_classification_index', ['id' => $project->getId()]);
        }

        // Read header
        $header = fgetcsv($handle, 0, ';');
        if (!$header) {
            fclose($handle);
            $this->addFlash('danger', 'O arquivo CSV está vazio ou em formato inválido.');
            return $this->redirectToRoute('app_classification_index', ['id' => $project->getId()]);
        }

        // Strip UTF-8 BOM if present on first header item
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        $importedCount = 0;
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (empty($row) || count($row) < 1 || trim($row[0]) === '') {
                continue;
            }

            $name        = trim($row[0]);
            $type        = trim($row[1] ?? ClassificationGroup::TYPE_NORMAL);
            $color       = trim($row[2] ?? '#4f8ef7');
            $icon        = trim($row[3] ?? 'bi-collection');
            $position    = (int)trim($row[4] ?? 0);
            $termsRaw    = trim($row[5] ?? '');
            $matchRaw    = trim($row[6] ?? '');
            $startYear   = trim($row[7] ?? '');
            $endYear     = trim($row[8] ?? '');
            // Check if column 9 is qualis or nature (backwards compatible)
            $col9        = trim($row[9] ?? '');
            $col10       = trim($row[10] ?? '');
            $col11       = trim($row[11] ?? '');
            $col12       = trim($row[12] ?? '');
            $col13       = trim($row[13] ?? '');
            $col14       = trim($row[14] ?? '');

            if (count($row) >= 15) {
                $qualisRaw   = $col9;
                $natureRaw   = $col10;
                $continente  = $col11;
                $countryRaw  = $col12;
                $authorsRaw  = $col13;
                $useThesaurus= $col14;
            } else {
                $qualisRaw   = '';
                $natureRaw   = $col9;
                $continente  = $col10;
                $countryRaw  = $col11;
                $authorsRaw  = $col12;
                $useThesaurus= $col13;
            }

            $group = new ClassificationGroup();
            $group->setProject($project);
            $group->setName($name);
            $group->setType(in_array($type, [ClassificationGroup::TYPE_NORMAL, ClassificationGroup::TYPE_VALIDATOR, ClassificationGroup::TYPE_NOISE, ClassificationGroup::TYPE_UNCLASSIFIED]) ? $type : ClassificationGroup::TYPE_NORMAL);
            $group->setColor($color ?: '#4f8ef7');
            $group->setIcon($icon ?: 'bi-collection');
            $group->setPosition($position);

            if ($matchRaw !== '') {
                $mFields = array_filter(array_map('trim', explode(';', $matchRaw)));
                $group->setMatchFields(array_values($mFields));
            } else {
                $group->setMatchFields(['title', 'abstract', 'author_keywords', 'indexed_keywords']);
            }

            $group->setStartYear($startYear !== '' ? (int)$startYear : null);
            $group->setEndYear($endYear !== '' ? (int)$endYear : null);

            if ($qualisRaw !== '') {
                $qualis = array_filter(array_map('strtoupper', array_map('trim', explode(';', $qualisRaw))));
                $group->setQualisFilter(array_values($qualis));
            }

            if ($natureRaw !== '') {
                $natures = array_filter(array_map('trim', explode(';', $natureRaw)));
                $group->setInstitutionNature(array_values($natures));
            }

            $group->setContinente($continente !== '' ? $continente : null);

            if ($countryRaw !== '') {
                $countries = array_filter(array_map('trim', explode(';', $countryRaw)));
                $group->setCountryIds(array_values($countries));
            }

            if ($authorsRaw !== '') {
                $authors = array_filter(array_map('trim', explode(';', $authorsRaw)));
                $group->setAuthorsFilter(array_values($authors));
            }

            $group->setUseThesaurus($useThesaurus !== '0');

            // Add terms rules
            if ($termsRaw !== '') {
                // Split by ';' or ','
                $tList = preg_split('/[;,]/', $termsRaw);
                foreach ($tList as $tStr) {
                    $tStr = trim($tStr);
                    if ($tStr !== '') {
                        $rule = new ClassificationRule();
                        $rule->setTerm($tStr);
                        $rule->setGroup($group);
                        $group->addRule($rule);
                    }
                }
            }

            $this->em->persist($group);
            $importedCount++;
        }

        fclose($handle);
        $this->em->flush();

        $this->addFlash('success', sprintf('Sucesso! %d grupo(s) importado(s) a partir do arquivo CSV.', $importedCount));

        return $this->redirectToRoute('app_classification_index', ['id' => $project->getId()]);
    }
}
