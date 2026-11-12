<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Entity\ClassificationGroup;
use App\Entity\ClassificationRule;
use App\Entity\DocumentClassification;
use App\Repository\ClassificationGroupRepository;
use App\Repository\DocumentClassificationRepository;
use App\Service\Classification\ClassificationEngine;
use App\Service\Analytics\CsvCell;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        private readonly LoggerInterface                  $logger,
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
            'project'         => $project,
            'groups'          => $groups,
            'counts'          => $counts,
            'hasResult'       => $hasResult,
            'expansionNotice' => $this->expansionNotice($project, $groups, $hasResult),
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
        $continentes = $this->continentOptions();

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
        $continentes = $this->continentOptions();

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
            if (($stats['rejected_unassigned'] ?? 0) > 0) {
                $multiMsg .= sprintf(
                    ' (%d reprovados no ano mínimo/validador foram para "Sem Classificação": o projeto não tem grupo Ruído)',
                    $stats['rejected_unassigned']
                );
            }
            $this->addFlash('success', sprintf(
                '✅ Classificação concluída! %d documentos processados — %d classificados, %d ruído, %d sem grupo%s.',
                $stats['total'],
                $stats['total'] - $stats['noise'] - $stats['unclassified'],
                $stats['noise'],
                $stats['unclassified'],
                $multiMsg
            ));
        } catch (\Throwable $e) {
            // O detalhe técnico vai para o log; a tela mostra uma mensagem genérica
            $this->logger->error('Falha ao executar a classificação do projeto {project}', [
                'project'   => $project->getId(),
                'exception' => $e,
            ]);
            $this->addFlash('danger', 'Não foi possível executar a classificação. Tente novamente; se o erro continuar, avise o administrador.');
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
                $documents  = $this->classRepo->findPageForDisplay($project->getId(), null, $page, $limit);
            } elseif ($activeGroupId !== null) {
                $gid = (int) $activeGroupId;
                foreach ($groups as $g) {
                    if ($g->getId() === $gid) { $activeGroup = $g; break; }
                }
                $total      = $this->classRepo->countByProjectAndGroup($project->getId(), $gid);
                $totalPages = max(1, (int) ceil($total / $limit));
                $page       = min($page, $totalPages);
                $documents  = $this->classRepo->findPageForDisplay($project->getId(), $gid, $page, $limit);
            } else {
                // default: first group
                $activeGroup = $groups[0] ?? null;
                if ($activeGroup) {
                    $total      = $this->classRepo->countByProjectAndGroup($project->getId(), $activeGroup->getId());
                    $totalPages = max(1, (int) ceil($total / $limit));
                    $page       = min($page, $totalPages);
                    $documents  = $this->classRepo->findPageForDisplay($project->getId(), $activeGroup->getId(), $page, $limit);
                }
            }
        }

        // documentId => [nomes dos grupos] para os selos de multiclassificação (uma consulta para a página)
        $docGroupNames = $this->classRepo->findGroupNamesByDocuments(
            array_map(fn(array $dc) => $dc['document']['id'], $documents),
            $project->getId()
        );

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
            'expansionNotice' => $this->expansionNotice($project, $groups, $hasResult),
        ]);
    }

    /**
     * Aviso de resultado calculado com uma expansão pelo tesauro anterior à atual: o projeto tem resultados,
     * a versão gravada é NULL ou menor que ClassificationEngine::EXPANSION_VERSION e há grupo (validador,
     * ruído ou temático) com o tesauro ligado. Traz quantos documentos movidos à mão a reexecução apagaria.
     *
     * @param ClassificationGroup[] $groups
     * @return array{manual: int}|null
     */
    private function expansionNotice(BibliometricProject $project, array $groups, bool $hasResult): ?array
    {
        if (!$hasResult || ($project->getClassificationExpansionVersion() ?? 0) >= ClassificationEngine::EXPANSION_VERSION) {
            return null;
        }
        $usesThesaurus = false;
        foreach ($groups as $g) {
            if ($g->getType() !== ClassificationGroup::TYPE_UNCLASSIFIED && $g->isUseThesaurus()) {
                $usesThesaurus = true;
                break;
            }
        }
        if (!$usesThesaurus) {
            return null;
        }

        $manual = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(DISTINCT document_id) FROM document_classification WHERE project_id = ? AND manual_override = 1',
            [$project->getId()]
        );

        return ['manual' => $manual];
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
        $fromRaw = $request->request->get('from_group_id');

        // Linha de origem: a do grupo que está aberto na tela (o documento pode estar em vários grupos)
        $criteria = ['document' => $docId, 'project' => $project->getId()];
        if ($fromRaw !== null && $fromRaw !== '') {
            $criteria['group'] = $fromRaw === 'unclassified' ? null : (int) $fromRaw;
        }
        $clf = $this->classRepo->findOneBy($criteria);
        if (!$clf) {
            return new JsonResponse(['ok' => false, 'error' => 'Classificação não encontrada'], 404);
        }

        if ($groupId === null || $groupId === '' || $groupId === 'unclassified') {
            // "Sem grupo" = o grupo "Sem Classificação" do motor (NULL só se ele não existir)
            $target = $this->groupRepo->findOneBy(['project' => $project->getId(), 'type' => ClassificationGroup::TYPE_UNCLASSIFIED]);
        } else {
            $target = $this->groupRepo->find((int) $groupId);
            if (!$target || $target->getProject()->getId() !== $project->getId()) {
                return new JsonResponse(['ok' => false, 'error' => 'Grupo inválido'], 400);
            }
        }

        $targetId = $target?->getId();
        if ($clf->getGroup()?->getId() !== $targetId) {
            $existing = $this->classRepo->findOneBy([
                'document' => $docId,
                'project'  => $project->getId(),
                'group'    => $targetId,
            ]);
            if ($existing) {
                // Já está no destino: só remove do grupo de origem (evita violar uniq_doc_group_classification)
                $existing->setManualOverride(true);
                $this->em->remove($clf);
            } else {
                $clf->setGroup($target);
                $clf->setManualOverride(true);
            }
            $this->em->flush();
        }

        return new JsonResponse(['ok' => true]);
    }

    // ─── Export CSV ───────────────────────────────────────────────────────────

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(BibliometricProject $project): Response
    {
        $this->denyAccessUnlessGranted('view', $project);

        $pid      = $project->getId();
        $conn     = $this->em->getConnection();
        $filename = 'classificacao_' . $pid . '_' . date('Ymd') . '.csv';

        // Linha a linha, direto para a saída: o projeto inteiro nunca fica na memória (e o resumo,
        // que não vai no CSV, nem é lido)
        $response = new StreamedResponse(function () use ($conn, $pid): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
            fwrite($out, "Título;Ano;DOI;Grupos;Tipos;Termos Correspondidos;Override Manual;Data Classificação\r\n");

            $rows = $conn->iterateAssociative(
                'SELECT d.id AS doc_id, d.title, d.year, d.doi,
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
                 ORDER BY group_names, d.year DESC, d.id',
                [$pid]
            );
            foreach ($rows as $r) {
                fwrite($out, sprintf(
                    '"%s";%s;"%s";"%s";"%s";"%s";%s;"%s"' . "\r\n",
                    str_replace('"', '""', CsvCell::escape($r['title'])),
                    $r['year'] ?? '',
                    str_replace('"', '""', CsvCell::escape($r['doi'])),
                    str_replace('"', '""', CsvCell::escape($r['group_names'] ?? 'Sem Classificação')),
                    $r['group_types'] ?? '',
                    str_replace('"', '""', CsvCell::escape($r['matched_terms'])),
                    $r['manual_override'] ? 'Sim' : 'Não',
                    $r['run_at'] ?? '',
                ));
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"$filename\"");

        return $response;
    }

    // ─── Export VOSviewer Categories ──────────────────────────────────────────

    #[Route('/export-vosviewer', name: 'export_vosviewer', methods: ['GET'])]
    public function exportVosviewer(BibliometricProject $project): Response
    {
        $this->denyAccessUnlessGranted('view', $project);

        // Uma linha por documento do projeto, na ordem de importação (id), para alinhar com o
        // corpus. Sem grupo temático → nome do grupo em que ficou (Ruído / Sem Classificação).
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT d.id AS doc_id,
                    GROUP_CONCAT(DISTINCT CASE WHEN g.type = ? THEN g.name END ORDER BY g.position SEPARATOR ";") AS group_names,
                    GROUP_CONCAT(DISTINCT CASE WHEN g.type <> ? THEN g.name END ORDER BY g.position SEPARATOR ";") AS other_names
             FROM document d
             LEFT JOIN document_classification dc ON dc.document_id = d.id AND dc.project_id = d.project_id
             LEFT JOIN classification_group g ON g.id = dc.group_id
             WHERE d.project_id = ?
             GROUP BY d.id
             ORDER BY d.id',
            [ClassificationGroup::TYPE_NORMAL, ClassificationGroup::TYPE_NORMAL, $project->getId()]
        );

        $content = "label\n";
        foreach ($rows as $r) {
            $content .= ($r['group_names'] ?: ($r['other_names'] ?: 'Sem Classificação')) . "\n";
        }

        $filename = 'vosviewer-categorias-' . $project->getId() . '.txt';

        return new Response($content, 200, [
            'Content-Type'        => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public const DEFAULT_COLOR = '#4f8ef7';
    public const DEFAULT_ICON  = 'bi-collection';

    /** Cor do grupo: só #RRGGBB; qualquer outra coisa (inclusive HTML/CSS injetado) volta ao padrão. */
    public static function sanitizeColor(string $color): string
    {
        $color = trim($color);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : self::DEFAULT_COLOR;
    }

    /** Ícone do grupo: só uma classe do Bootstrap Icons ("bi-" + letras minúsculas, números e hífen). */
    public static function sanitizeIcon(string $icon): string
    {
        $icon = trim($icon);

        return preg_match('/^bi-[a-z0-9-]+$/', $icon) === 1 ? $icon : self::DEFAULT_ICON;
    }

    /** Continentes reais do cadastro de países (inclui "América Central" e "Europa/Ásia"). */
    private function continentOptions(): array
    {
        return $this->em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT continente FROM paises WHERE continente IS NOT NULL AND continente <> \'\' ORDER BY continente'
        );
    }

    /**
     * Separa o filtro de autores. Separador oficial: ";" ou quebra de linha, para que
     * "Silva, J" fique inteiro. Entrada antiga só com vírgulas continua aceita, mas
     * iniciais soltas (ex.: "J", "ME", "J.R.") voltam a se juntar ao sobrenome anterior.
     *
     * @return string[]
     */
    public static function parseAuthorsFilter(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        if (preg_match('/[;\r\n]/', $raw)) {
            $parts = preg_split('/[;\r\n]+/', $raw);
        } else {
            $parts = [];
            foreach (explode(',', $raw) as $piece) {
                $piece = trim($piece);
                // Iniciais em maiúsculas (até 3 letras, com ou sem pontos) pertencem ao nome anterior
                if ($parts !== [] && preg_match('/^(?:\p{Lu}\.?\s*){1,3}$/u', $piece)) {
                    $parts[count($parts) - 1] .= ', ' . $piece;
                } else {
                    $parts[] = $piece;
                }
            }
        }

        return array_values(array_filter(array_map('trim', $parts), fn($a) => $a !== ''));
    }

    private function buildGroupFromRequest(Request $request, BibliometricProject $project, ClassificationGroup $group): ClassificationGroup
    {
        $group->setProject($project);
        $group->setName(trim($request->request->get('name', '')));
        $group->setDescription(trim($request->request->get('description', '')) ?: null);
        $group->setType($request->request->get('type', ClassificationGroup::TYPE_NORMAL));
        $group->setColor(self::sanitizeColor((string) $request->request->get('color', '')));
        $group->setIcon(self::sanitizeIcon((string) $request->request->get('icon', '')));
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

        $authors = self::parseAuthorsFilter((string) $request->request->get('authors_filter', ''));
        $group->setAuthorsFilter(!empty($authors) ? $authors : null);

        $qualis = $request->request->all('qualis_filter');
        $group->setQualisFilter(!empty($qualis) ? array_values(array_map('strtoupper', $qualis)) : null);

        // Checkbox desmarcado não é enviado: ausente = desligado (o formulário também manda hidden 0)
        $group->setUseThesaurus($request->request->getBoolean('use_thesaurus'));

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
                // Texto que começa com = + - @ vira fórmula no Excel: recebe apóstrofo (desfeito na importação)
                $v = str_replace('"', '""', CsvCell::escape($val));
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

        // Grupos existentes por nome: reimportar o CSV atualiza em vez de duplicar
        $existingByName = [];
        foreach ($this->groupRepo->findByProject($project->getId()) as $g) {
            $existingByName[mb_strtolower(trim($g->getName()))] = $g;
        }
        $validTypes = [ClassificationGroup::TYPE_NORMAL, ClassificationGroup::TYPE_VALIDATOR, ClassificationGroup::TYPE_NOISE, ClassificationGroup::TYPE_UNCLASSIFIED];

        $importedCount = 0;
        $updatedCount  = 0;
        $invalidTypes  = [];
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            // Desfaz o apóstrofo que a exportação põe antes de = + - @ (proteção contra fórmula)
            $row = array_map(fn($cell) => CsvCell::unescape((string) $cell), $row);
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

            $key   = mb_strtolower($name);
            $group = $existingByName[$key] ?? null;
            if ($group) {
                // Atualiza: as regras (termos) são substituídas pelas do arquivo
                foreach ($group->getRules() as $r) {
                    $this->em->remove($r);
                }
                $group->getRules()->clear();
                $updatedCount++;
            } else {
                $group = new ClassificationGroup();
                $group->setProject($project);
                $existingByName[$key] = $group;
                $importedCount++;
            }
            $group->setName($name);
            if ($type === '') {
                $type = ClassificationGroup::TYPE_NORMAL;
            }
            if (!in_array($type, $validTypes, true)) {
                $invalidTypes[] = sprintf('"%s" (tipo "%s")', $name, $type);
                $type = ClassificationGroup::TYPE_NORMAL;
            }
            $group->setType($type);
            $group->setColor(self::sanitizeColor($color));
            $group->setIcon(self::sanitizeIcon($icon));
            $group->setPosition($position);

            if ($matchRaw !== '') {
                $mFields = array_filter(array_map('trim', explode(';', $matchRaw)));
                $group->setMatchFields(array_values($mFields));
            } else {
                $group->setMatchFields(['title', 'abstract', 'author_keywords', 'indexed_keywords']);
            }

            $group->setStartYear($startYear !== '' ? (int)$startYear : null);
            $group->setEndYear($endYear !== '' ? (int)$endYear : null);

            // Coluna vazia limpa o filtro (importante ao atualizar um grupo existente)
            $qualis = array_filter(array_map('strtoupper', array_map('trim', explode(';', $qualisRaw))));
            $group->setQualisFilter($qualis ? array_values($qualis) : null);

            $natures = array_filter(array_map('trim', explode(';', $natureRaw)));
            $group->setInstitutionNature($natures ? array_values($natures) : null);

            $group->setContinente($continente !== '' ? $continente : null);

            $countries = array_filter(array_map('trim', explode(';', $countryRaw)));
            $group->setCountryIds($countries ? array_values($countries) : null);

            $authors = array_filter(array_map('trim', explode(';', $authorsRaw)));
            $group->setAuthorsFilter($authors ? array_values($authors) : null);

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
        }

        fclose($handle);
        $this->em->flush();

        $this->addFlash('success', sprintf('Sucesso! %d grupo(s) criado(s) e %d atualizado(s) (mesmo nome) a partir do arquivo CSV.', $importedCount, $updatedCount));
        if ($invalidTypes) {
            $this->addFlash('warning', sprintf(
                'Tipo inválido em %d linha(s), importada(s) como grupo normal: %s. Tipos aceitos: %s.',
                count($invalidTypes),
                implode(', ', array_slice($invalidTypes, 0, 10)),
                implode(', ', $validTypes)
            ));
        }

        return $this->redirectToRoute('app_classification_index', ['id' => $project->getId()]);
    }
}
