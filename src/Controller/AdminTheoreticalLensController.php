<?php

namespace App\Controller;

use App\Entity\TheoreticalLens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/theoretical-lenses', name: 'app_admin_theoretical_lenses_')]
#[IsGranted('ROLE_ADMIN')]
class AdminTheoreticalLensController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    // ─── List / Index ──────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = trim($request->query->getString('search', ''));
        $fieldFilter = trim($request->query->getString('field', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $qb = $this->em->createQueryBuilder()
            ->select('t')
            ->from(TheoreticalLens::class, 't');

        if ($search !== '') {
            $qb->andWhere('t.name LIKE :search OR t.description LIKE :search OR t.category LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($fieldFilter !== '') {
            $qb->andWhere('t.researchField = :field')
               ->setParameter('field', $fieldFilter);
        }

        // Clone query builder for total count
        $countQb = clone $qb;
        $countQb->select('COUNT(t.id)');
        $totalItems = (int) $countQb->getQuery()->getSingleScalarResult();

        // Paginate results
        $qb->orderBy('t.id', 'DESC')
           ->setFirstResult($offset)
           ->setMaxResults($limit);

        $lenses = $qb->getQuery()->getResult();
        $totalPages = (int) ceil($totalItems / $limit);

        // Fetch all unique fields for filter dropdown
        $fields = $this->em->createQueryBuilder()
            ->select('DISTINCT t.researchField')
            ->from(TheoreticalLens::class, 't')
            ->orderBy('t.researchField', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return $this->render('admin/theoretical_lenses/index.html.twig', [
            'lenses' => $lenses,
            'search' => $search,
            'fieldFilter' => $fieldFilter,
            'fields' => array_filter($fields),
            'page' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
        ]);
    }

    // ─── Create New ───────────────────────────────────────────────────────────

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $lens = new TheoreticalLens();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_lens_new', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de segurança inválido.');
                return $this->redirectToRoute('app_admin_theoretical_lenses_index');
            }

            $name = trim($request->request->get('name', ''));
            $researchField = trim($request->request->get('researchField', ''));
            $category = trim($request->request->get('category', ''));
            $description = trim($request->request->get('description', ''));
            $icon = trim($request->request->get('icon', 'bi-mortarboard'));
            $color = trim($request->request->get('color', '#4f8ef7'));

            // Parse comma-separated strings to arrays
            $termsString = trim($request->request->get('terms', ''));
            $terms = array_filter(array_map('trim', explode(',', $termsString)));
            $terms = array_map('strtolower', $terms);

            $citationsString = trim($request->request->get('citationFormats', ''));
            $citations = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $citationsString))));

            if ($name === '' || $researchField === '' || $category === '' || $description === '') {
                $this->addFlash('danger', 'Nome, Área de Pesquisa, Categoria e Descrição são campos obrigatórios.');
            } else {
                $lens->setName($name);
                $lens->setResearchField($researchField);
                $lens->setCategory($category);
                $lens->setDescription($description);
                $lens->setIcon($icon);
                $lens->setColor($color);
                $lens->setTerms($terms);
                $lens->setCitationFormats($citations);

                $this->em->persist($lens);
                $this->em->flush();

                $this->addFlash('success', sprintf('Lente teórica de <strong>%s</strong> criada com sucesso!', $name));
                return $this->redirectToRoute('app_admin_theoretical_lenses_index');
            }
        }

        return $this->render('admin/theoretical_lenses/new.html.twig', [
            'lens' => $lens,
        ]);
    }

    // ─── Edit Existing ────────────────────────────────────────────────────────

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(TheoreticalLens $lens, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_lens_edit_' . $lens->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de segurança inválido.');
                return $this->redirectToRoute('app_admin_theoretical_lenses_index');
            }

            $name = trim($request->request->get('name', ''));
            $researchField = trim($request->request->get('researchField', ''));
            $category = trim($request->request->get('category', ''));
            $description = trim($request->request->get('description', ''));
            $icon = trim($request->request->get('icon', 'bi-mortarboard'));
            $color = trim($request->request->get('color', '#4f8ef7'));

            // Parse terms
            $termsString = trim($request->request->get('terms', ''));
            $terms = array_filter(array_map('trim', explode(',', $termsString)));
            $terms = array_map('strtolower', $terms);

            // Parse citations
            $citationsString = trim($request->request->get('citationFormats', ''));
            $citations = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $citationsString))));

            if ($name === '' || $researchField === '' || $category === '' || $description === '') {
                $this->addFlash('danger', 'Nome, Área de Pesquisa, Categoria e Descrição são campos obrigatórios.');
            } else {
                $lens->setName($name);
                $lens->setResearchField($researchField);
                $lens->setCategory($category);
                $lens->setDescription($description);
                $lens->setIcon($icon);
                $lens->setColor($color);
                $lens->setTerms($terms);
                $lens->setCitationFormats($citations);

                $this->em->flush();

                $this->addFlash('success', sprintf('Lente teórica de <strong>%s</strong> atualizada com sucesso!', $name));
                return $this->redirectToRoute('app_admin_theoretical_lenses_index');
            }
        }

        return $this->render('admin/theoretical_lenses/edit.html.twig', [
            'lens' => $lens,
        ]);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(TheoreticalLens $lens, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_lens_delete_' . $lens->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');
            return $this->redirectToRoute('app_admin_theoretical_lenses_index');
        }

        $name = $lens->getName();
        $this->em->remove($lens);
        $this->em->flush();

        $this->addFlash('warning', sprintf('Lente teórica de <strong>%s</strong> removida com sucesso.', $name));
        return $this->redirectToRoute('app_admin_theoretical_lenses_index');
    }
}
