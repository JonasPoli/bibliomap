<?php

namespace App\Controller;

use App\Entity\Keyword;
use App\Entity\KeywordVariation;
use App\Service\Import\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/keywords')]
#[IsGranted('ROLE_ADMIN')]
class AdminKeywordController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    #[Route('', name: 'app_admin_keywords_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = $request->query->getString('search', '');
        $status = $request->query->get('status', 'all');
        $type   = $request->query->get('type', 'all');

        $qb = $this->em->createQueryBuilder()
            ->select('k')
            ->from(Keyword::class, 'k');

        if ($search !== '') {
            $qb->andWhere('k.keywordOriginal LIKE :search OR k.keywordDisplay LIKE :search')
               ->setParameter('search', '%' . $search . '%');
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

        $keywords = $qb->orderBy('k.keywordOriginal', 'ASC')
            ->setMaxResults(250)
            ->getQuery()
            ->getResult();

        return $this->render('admin/keywords/index.html.twig', [
            'keywords' => $keywords,
            'search' => $search,
            'status' => $status,
            'type' => $type,
        ]);
    }

    #[Route('/export', name: 'app_admin_keywords_export', methods: ['GET'])]
    public function export(): Response
    {
        $conn = $this->em->getConnection();

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function() use ($conn) {
            $handle = fopen('php://output', 'w+');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['term', 'type', 'status', 'variations'], ';');

            // Load variations
            $vars = $conn->fetchAllAssociative('
                SELECT keyword_id, variation_name AS original_name 
                FROM palavra_chave_variacoes_nome 
                WHERE variation_type != "official"
            ');
            $varsByKw = [];
            foreach ($vars as $v) {
                $varsByKw[$v['keyword_id']][] = $v['original_name'];
            }

            $stmt = $conn->executeQuery('
                SELECT id, keyword_original, keyword_display, keyword_type, status 
                FROM keyword 
                ORDER BY keyword_original ASC
            ');

            while ($kw = $stmt->fetchAssociative()) {
                $kwId = (int)$kw['id'];
                $varNames = $varsByKw[$kwId] ?? [];

                fputcsv($handle, [
                    $kw['keyword_display'] ?? $kw['keyword_original'],
                    $kw['keyword_type'],
                    $kw['status'] ? '1' : '0',
                    implode(';', $varNames)
                ], ';');
            }
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

            $csv = \League\Csv\Reader::createFromPath($file->getRealPath(), 'r');
            $csv->setHeaderOffset(0);

            $imported = 0;
            $updated = 0;
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            foreach ($csv->getRecords() as $record) {
                $term = trim($record['term'] ?? '');
                if ($term === '') continue;

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

                    $exists = $conn->fetchOne('SELECT id FROM keyword_variation WHERE keyword_id = ? AND normalized_name = ?', [$keyId, $lineNorm]);
                    if (!$exists) {
                        $conn->insert('keyword_variation', [
                            'keyword_id' => $keyId,
                            'variation_name' => $line,
                            'normalized_name' => $lineNorm,
                            'variation_type' => 'alternative',
                            'status' => 1,
                        ]);
                    }
                }
            }

            $this->addFlash('success', "Importação concluída: {$imported} novas palavras-chave criadas, {$updated} atualizadas.");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao importar CSV: ' . $e->getMessage());
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

        $otherKeywords = $this->em->createQueryBuilder()
            ->select('k')
            ->from(Keyword::class, 'k')
            ->where('k.id != :id AND k.keywordType = :type')
            ->setParameter('id', $keyword->getId())
            ->setParameter('type', $keyword->getKeywordType())
            ->orderBy('k.keywordOriginal', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        return $this->render('admin/keywords/edit.html.twig', [
            'keyword' => $keyword,
            'variationsText' => $variationsText,
            'other_keywords' => $otherKeywords,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_keywords_delete', methods: ['POST'])]
    public function delete(Keyword $keyword, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_keyword_' . $keyword->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_keywords_index');
        }

        $term = $keyword->getKeywordOriginal();
        $this->em->remove($keyword);
        $this->em->flush();

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
        $conn->executeStatement(
            'UPDATE document_keyword SET keyword_id = ? WHERE keyword_id = ? AND original_term = ?',
            [$newKeyword->getId(), $parent->getId(), $varName]
        );

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

        $conn = $this->em->getConnection();

        // Remap document_keyword records
        $conn->executeStatement(
            'DELETE dk1 FROM document_keyword dk1
             JOIN document_keyword dk2 ON dk1.document_id = dk2.document_id
             WHERE dk1.keyword_id = ? AND dk2.keyword_id = ?',
            [$keyword->getId(), $target->getId()]
        );
        $conn->executeStatement(
            'UPDATE document_keyword SET keyword_id = ? WHERE keyword_id = ?',
            [$target->getId(), $keyword->getId()]
        );

        // Remap concept_id in keyword table
        $conn->executeStatement(
            'UPDATE keyword SET keyword_concept_id = ? WHERE keyword_concept_id = ?',
            [$target->getId(), $keyword->getId()]
        );

        // Move existing variations
        foreach ($keyword->getVariations() as $v) {
            $exists = false;
            foreach ($target->getVariations() as $tv) {
                if ($tv->getNormalizedName() === $v->getNormalizedName()) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $v->setKeyword($target);
                $target->addVariation($v);
            } else {
                $this->em->remove($v);
            }
        }

        // Add keyword's term as variation of target
        $name = $keyword->getKeywordOriginal();
        $norm = $keyword->getKeywordNormalized();
        $exists = false;
        foreach ($target->getVariations() as $tv) {
            if ($tv->getNormalizedName() === $norm) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $v = new KeywordVariation();
            $v->setVariationName($name);
            $v->setNormalizedName($norm);
            $v->setVariationType('alternative');
            $v->setKeyword($target);
            $this->em->persist($v);
        }

        $this->em->remove($keyword);
        $this->em->flush();

        $this->addFlash('success', "Palavra-chave '{$name}' mesclada em '{$target->getKeywordOriginal()}' com sucesso!");
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
}
