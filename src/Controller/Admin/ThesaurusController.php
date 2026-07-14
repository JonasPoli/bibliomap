<?php

namespace App\Controller\Admin;

use App\Entity\ThesaurusScheme;
use App\Entity\ThesaurusConcept;
use App\Entity\ThesaurusLabel;
use App\Entity\ThesaurusRelation;
use App\Entity\ThesaurusMatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use DateTimeImmutable;

#[Route('/admin/thesaurus')]
#[IsGranted('ROLE_ADMIN')]
class ThesaurusController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'app_admin_thesaurus_index', methods: ['GET'])]
    public function index(): Response
    {
        $schemes = $this->em->getRepository(ThesaurusScheme::class)->findAll();
        
        // If empty, seed default schemes automatically for convenience
        if (empty($schemes)) {
            $types = ['keyword' => 'Palavras-chave', 'institution' => 'Instituições', 'place' => 'Lugares/Países', 'author' => 'Autores'];
            foreach ($types as $type => $name) {
                $scheme = new ThesaurusScheme();
                $scheme->setName("Tesauro de $name");
                $scheme->setSlug($type);
                $scheme->setType($type);
                $this->em->persist($scheme);
            }
            $this->em->flush();
            $schemes = $this->em->getRepository(ThesaurusScheme::class)->findAll();
        }

        $pendingMatchesCount = $this->em->getRepository(ThesaurusMatch::class)->count(['status' => 'pending']);

        return $this->render('admin/thesaurus/index.html.twig', [
            'schemes' => $schemes,
            'pending_matches_count' => $pendingMatchesCount,
        ]);
    }

    #[Route('/scheme/{id}', name: 'app_admin_thesaurus_scheme_show', methods: ['GET'])]
    public function showScheme(int $id): Response
    {
        $scheme = $this->em->getRepository(ThesaurusScheme::class)->find($id);
        if (!$scheme) {
            throw $this->createNotFoundException('Esquema não encontrado.');
        }

        $concepts = $this->em->getRepository(ThesaurusConcept::class)->findBy(['scheme' => $scheme]);

        $conceptLabels = [];
        if (!empty($concepts)) {
            $labels = $this->em->getRepository(ThesaurusLabel::class)->findBy(['concept' => $concepts]);
            foreach ($labels as $l) {
                $conceptLabels[$l->getConcept()->getId()][] = $l;
            }
        }

        return $this->render('admin/thesaurus/scheme_show.html.twig', [
            'scheme' => $scheme,
            'concepts' => $concepts,
            'conceptLabels' => $conceptLabels,
        ]);
    }

    #[Route('/concept/new/{schemeId}', name: 'app_admin_thesaurus_concept_new', methods: ['POST'])]
    public function newConcept(int $schemeId, Request $request): Response
    {
        $scheme = $this->em->getRepository(ThesaurusScheme::class)->find($schemeId);
        if (!$scheme) {
            throw $this->createNotFoundException('Esquema não encontrado.');
        }

        $preferredLabel = trim($request->request->get('preferredLabel', ''));
        if ($preferredLabel === '') {
            $this->addFlash('danger', 'O rótulo preferido não pode ser vazio.');
            return $this->redirectToRoute('app_admin_thesaurus_scheme_show', ['id' => $schemeId]);
        }

        $normalizer = new \App\Service\Import\TextNormalizer();
        $conceptNorm = $normalizer->normalizeForComparison($preferredLabel);

        // Check if duplicate concept
        $existingConcept = $this->em->getRepository(ThesaurusConcept::class)->findOneBy([
            'scheme' => $scheme,
            'normalizedLabel' => $conceptNorm
        ]);

        if ($existingConcept) {
            $this->addFlash('warning', 'Este conceito já está cadastrado neste esquema.');
            return $this->redirectToRoute('app_admin_thesaurus_scheme_show', ['id' => $schemeId]);
        }

        $concept = new ThesaurusConcept();
        $concept->setScheme($scheme);
        $concept->setPreferredLabel($preferredLabel);
        $concept->setNormalizedLabel($conceptNorm);
        
        $this->em->persist($concept);

        // Save preferred label
        $prefLabel = new ThesaurusLabel();
        $prefLabel->setConcept($concept);
        $prefLabel->setLabel($preferredLabel);
        $prefLabel->setNormalizedLabel($conceptNorm);
        $prefLabel->setType('preferred');
        $this->em->persist($prefLabel);

        // Save synonyms (alternative labels)
        $synonymsText = $request->request->get('synonyms', '');
        $lines = explode("\n", $synonymsText);
        $processedSynonyms = [];
        foreach ($lines as $line) {
            $synonym = trim($line);
            if ($synonym === '') {
                continue;
            }
            // Skip if exactly preferred label or already processed in this request
            if ($synonym === $preferredLabel || in_array($synonym, $processedSynonyms)) {
                continue;
            }
            $processedSynonyms[] = $synonym;

            $synNorm = $normalizer->normalizeForComparison($synonym);
            // Check duplicate in database
            $existingLabel = $this->em->getRepository(ThesaurusLabel::class)->findOneBy([
                'concept' => $concept,
                'label' => $synonym
            ]);
            if (!$existingLabel) {
                $altLabel = new ThesaurusLabel();
                $altLabel->setConcept($concept);
                $altLabel->setLabel($synonym);
                $altLabel->setNormalizedLabel($synNorm);
                $altLabel->setType('alternative');
                $altLabel->setLanguage('en');
                $this->em->persist($altLabel);
            }
        }

        $this->em->flush();
        $this->applyThesaurusMappings();
        $this->addFlash('success', 'Conceito e sinônimos criados com sucesso.');
        return $this->redirectToRoute('app_admin_thesaurus_scheme_show', ['id' => $schemeId]);
    }

    #[Route('/concept/{id}/edit', name: 'app_admin_thesaurus_concept_edit', methods: ['GET', 'POST'])]
    public function editConcept(int $id, Request $request): Response
    {
        $concept = $this->em->getRepository(ThesaurusConcept::class)->find($id);
        if (!$concept) {
            throw $this->createNotFoundException('Conceito não encontrado.');
        }

        $normalizer = new \App\Service\Import\TextNormalizer();

        // Get existing labels
        $labels = $this->em->getRepository(ThesaurusLabel::class)->findBy(['concept' => $concept]);
        $altLabels = [];
        $prefLabelObj = null;
        foreach ($labels as $l) {
            if ($l->getType() === 'preferred') {
                $prefLabelObj = $l;
            } else {
                $altLabels[] = $l;
            }
        }

        if ($request->isMethod('POST')) {
            $preferredLabel = trim($request->request->get('preferredLabel', ''));
            if ($preferredLabel === '') {
                $this->addFlash('danger', 'O rótulo preferido não pode ser vazio.');
                return $this->redirectToRoute('app_admin_thesaurus_concept_edit', ['id' => $id]);
            }

            $conceptNorm = $normalizer->normalizeForComparison($preferredLabel);

            // Update Concept preferred label
            $concept->setPreferredLabel($preferredLabel);
            $concept->setNormalizedLabel($conceptNorm);
            $concept->setUpdatedAt(new DateTimeImmutable());

            // Update or create preferred label object
            if ($prefLabelObj) {
                $prefLabelObj->setLabel($preferredLabel);
                $prefLabelObj->setNormalizedLabel($conceptNorm);
                $prefLabelObj->setUpdatedAt(new DateTimeImmutable());
            } else {
                $prefLabelObj = new ThesaurusLabel();
                $prefLabelObj->setConcept($concept);
                $prefLabelObj->setLabel($preferredLabel);
                $prefLabelObj->setNormalizedLabel($conceptNorm);
                $prefLabelObj->setType('preferred');
                $this->em->persist($prefLabelObj);
            }

            // Remove existing alternative labels to rebuild
            foreach ($altLabels as $l) {
                $this->em->remove($l);
            }

            // Add new alternative labels
            $synonymsText = $request->request->get('synonyms', '');
            $lines = explode("\n", $synonymsText);
            $processedSynonyms = [];
            foreach ($lines as $line) {
                $synonym = trim($line);
                if ($synonym === '') {
                    continue;
                }
                // Skip if exactly preferred label or already processed in this request
                if ($synonym === $preferredLabel || in_array($synonym, $processedSynonyms)) {
                    continue;
                }
                $processedSynonyms[] = $synonym;

                $synNorm = $normalizer->normalizeForComparison($synonym);
                $altLabel = new ThesaurusLabel();
                $altLabel->setConcept($concept);
                $altLabel->setLabel($synonym);
                $altLabel->setNormalizedLabel($synNorm);
                $altLabel->setType('alternative');
                $altLabel->setLanguage('en');
                $this->em->persist($altLabel);
            }

            $this->em->flush();
            $this->applyThesaurusMappings($concept->getId());
            $this->addFlash('success', 'Conceito e sinônimos atualizados com sucesso.');
            return $this->redirectToRoute('app_admin_thesaurus_scheme_show', ['id' => $concept->getScheme()->getId()]);
        }

        // Get alternative labels as string for textarea
        $synonymsString = implode("\n", array_map(fn($l) => $l->getLabel(), $altLabels));

        return $this->render('admin/thesaurus/concept_edit.html.twig', [
            'concept' => $concept,
            'synonyms' => $synonymsString,
        ]);
    }

    #[Route('/concept/{id}/delete', name: 'app_admin_thesaurus_concept_delete', methods: ['POST'])]
    public function deleteConcept(int $id, Request $request): Response
    {
        $concept = $this->em->getRepository(ThesaurusConcept::class)->find($id);
        if (!$concept) {
            throw $this->createNotFoundException('Conceito não encontrado.');
        }

        $submittedToken = $request->request->get('_token');
        if ($this->isCsrfTokenValid('delete-concept-' . $concept->getId(), $submittedToken)) {
            $schemeId = $concept->getScheme()->getId();
            $this->em->remove($concept);
            $this->em->flush();
            $this->addFlash('success', 'Conceito excluído com sucesso.');
            return $this->redirectToRoute('app_admin_thesaurus_scheme_show', ['id' => $schemeId]);
        }

        $this->addFlash('danger', 'Token CSRF inválido.');
        return $this->redirectToRoute('app_admin_thesaurus_scheme_show', ['id' => $concept->getScheme()->getId()]);
    }

    #[Route('/matches', name: 'app_admin_thesaurus_matches', methods: ['GET'])]
    public function matches(): Response
    {
        $matches = $this->em->getRepository(ThesaurusMatch::class)->findBy([], ['createdAt' => 'DESC']);
        return $this->render('admin/thesaurus/matches.html.twig', [
            'matches' => $matches,
        ]);
    }

    #[Route('/export', name: 'app_admin_thesaurus_export', methods: ['GET'])]
    public function export(): StreamedResponse
    {
        $response = new StreamedResponse(function () {
            $handle = fopen('php://output', 'w+');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM
            
            fputcsv($handle, ['Scheme', 'Concept', 'Label', 'Type', 'Language'], ';');

            $labels = $this->em->getRepository(ThesaurusLabel::class)->findAll();
            foreach ($labels as $lbl) {
                fputcsv($handle, [
                    $lbl->getConcept()->getScheme()->getSlug(),
                    $lbl->getConcept()->getPreferredLabel(),
                    $lbl->getLabel(),
                    $lbl->getType(),
                    $lbl->getLanguage()
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'tesauro_bibliomap.csv'
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/template', name: 'app_admin_thesaurus_template', methods: ['GET'])]
    public function template(): StreamedResponse
    {
        $response = new StreamedResponse(function () {
            $handle = fopen('php://output', 'w+');
            fwrite($handle, "\xEF\xBB\xBF");
            
            fputcsv($handle, ['Scheme', 'Concept', 'Label', 'Type', 'Language'], ';');
            fputcsv($handle, ['keyword', 'Artificial Intelligence', 'Artificial Intelligence', 'preferred', 'en'], ';');
            fputcsv($handle, ['keyword', 'Artificial Intelligence', 'AI', 'alternative', 'en'], ';');
            fputcsv($handle, ['keyword', 'Artificial Intelligence', 'Inteligência Artificial', 'alternative', 'pt'], ';');
            fputcsv($handle, ['institution', 'University of São Paulo', 'University of São Paulo', 'preferred', 'en'], ';');
            fputcsv($handle, ['institution', 'University of São Paulo', 'USP', 'alternative', 'pt'], ';');
            fputcsv($handle, ['place', 'Brazil', 'Brazil', 'preferred', 'en'], ';');
            fputcsv($handle, ['place', 'Brazil', 'Brasil', 'alternative', 'pt'], ';');

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'modelo_tesauro.csv'
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/import', name: 'app_admin_thesaurus_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $file = $request->files->get('csv_file');
        if (!$file) {
            $this->addFlash('danger', 'Nenhum arquivo enviado.');
            return $this->redirectToRoute('app_admin_thesaurus_index');
        }

        $normalizer = new \App\Service\Import\TextNormalizer();
        $path = $file->getRealPath();
        if (($handle = fopen($path, 'r')) !== false) {
            // Check BOM
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            // Headers
            $headers = fgetcsv($handle, 1000, ';');
            if (!$headers || count($headers) < 3) {
                $this->addFlash('danger', 'Formato de CSV inválido (verifique o separador ponto e vírgula).');
                fclose($handle);
                return $this->redirectToRoute('app_admin_thesaurus_index');
            }

            $conceptCache = [];
            $schemeCache = [];
            $imported = 0;
            $skipped = 0;
            $errors = 0;
            $lineNum = 1;
            $conceptsCreated = 0;
            $labelsCreated = 0;

            // Seed schemes if not cached
            $schemes = $this->em->getRepository(ThesaurusScheme::class)->findAll();
            foreach ($schemes as $s) {
                $schemeCache[$s->getSlug()] = $s;
            }

            while (($data = fgetcsv($handle, 1000, ';')) !== false) {
                $lineNum++;
                if (count($data) < 3) {
                    $errors++;
                    continue;
                }

                $schemeSlug = trim($data[0]);
                $conceptName = trim($data[1]);
                $labelText = trim($data[2]);
                $labelType = isset($data[3]) ? trim($data[3]) : '';
                $lang = count($data) > 4 ? trim($data[4]) : 'en';

                if ($schemeSlug === '' || $conceptName === '' || $labelText === '') {
                    $skipped++;
                    continue;
                }

                // Default type: preferred if label equals concept, else alternative
                if ($labelType === '' || $labelType === null) {
                    $labelType = (strtolower($labelText) === strtolower($conceptName)) ? 'preferred' : 'alternative';
                }

                // Normalize
                $conceptNorm = $normalizer->normalizeForComparison($conceptName);
                $labelNorm = $normalizer->normalizeForComparison($labelText);

                // Find/create Scheme
                if (!isset($schemeCache[$schemeSlug])) {
                    $scheme = new ThesaurusScheme();
                    $scheme->setName("Tesauro de " . ucfirst($schemeSlug));
                    $scheme->setSlug($schemeSlug);
                    $scheme->setType($schemeSlug);
                    $this->em->persist($scheme);
                    $this->em->flush();
                    $schemeCache[$schemeSlug] = $scheme;
                }
                $scheme = $schemeCache[$schemeSlug];

                // Find/create Concept
                $conceptKey = $schemeSlug . '_' . $conceptNorm;
                if (!isset($conceptCache[$conceptKey])) {
                    $concept = $this->em->getRepository(ThesaurusConcept::class)->findOneBy([
                        'scheme' => $scheme,
                        'normalizedLabel' => $conceptNorm
                    ]);
                    if (!$concept) {
                        $concept = new ThesaurusConcept();
                        $concept->setScheme($scheme);
                        $concept->setPreferredLabel($conceptName);
                        $concept->setNormalizedLabel($conceptNorm);
                        $this->em->persist($concept);
                        $this->em->flush();
                        $conceptsCreated++;

                        // Ensure preferred label exists
                        $prefLabel = new ThesaurusLabel();
                        $prefLabel->setConcept($concept);
                        $prefLabel->setLabel($conceptName);
                        $prefLabel->setNormalizedLabel($conceptNorm);
                        $prefLabel->setType('preferred');
                        $this->em->persist($prefLabel);
                        $labelsCreated++;
                    }
                    $conceptCache[$conceptKey] = $concept;
                }
                $concept = $conceptCache[$conceptKey];

                // Find/create Label (skip if duplicate)
                $existingLabel = $this->em->getRepository(ThesaurusLabel::class)->findOneBy([
                    'concept' => $concept,
                    'normalizedLabel' => $labelNorm
                ]);
                if (!$existingLabel) {
                    $label = new ThesaurusLabel();
                    $label->setConcept($concept);
                    $label->setLabel($labelText);
                    $label->setNormalizedLabel($labelNorm);
                    $label->setType($labelType);
                    $label->setLanguage($lang);
                    $this->em->persist($label);
                    $labelsCreated++;
                    $imported++;
                } else {
                    $skipped++;
                }
            }

            fclose($handle);
            $this->em->flush();
            $this->applyThesaurusMappings();

            $this->addFlash('success', sprintf(
                'Importação concluída: %d labels importados, %d concepts criados, %d ignorados, %d erros (de %d linhas).',
                $imported, $conceptsCreated, $skipped, $errors, $lineNum - 1
            ));
        } else {
            $this->addFlash('danger', 'Falha ao ler o arquivo CSV.');
        }

        return $this->redirectToRoute('app_admin_thesaurus_index');
    }

    private function applyThesaurusMappings(?int $conceptId = null): void
    {
        $conn = $this->em->getConnection();
        
        if ($conceptId !== null) {
            // Reset keywords that were matched to this concept but are no longer in the thesaurus_label
            $conn->executeStatement("
                UPDATE keyword k
                LEFT JOIN thesaurus_label tl ON k.keyword_normalized = tl.normalized_label AND tl.concept_id = :conceptId
                SET k.thesaurus_concept_id = NULL
                WHERE k.thesaurus_concept_id = :conceptId AND tl.id IS NULL
            ", ['conceptId' => $conceptId]);
        }

        // Apply mappings to all keywords where thesaurus_concept_id is NULL
        $conn->executeStatement("
            UPDATE keyword k
            JOIN thesaurus_label tl ON k.keyword_normalized = tl.normalized_label
            SET k.thesaurus_concept_id = tl.concept_id
            WHERE k.thesaurus_concept_id IS NULL
        ");
    }
}
