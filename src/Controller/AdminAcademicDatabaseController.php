<?php

namespace App\Controller;

use App\Entity\AcademicDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/academic-databases', name: 'app_admin_academic_databases_')]
#[IsGranted('ROLE_ADMIN')]
class AdminAcademicDatabaseController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    // ─── List / Index ──────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = trim($request->query->getString('search', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $qb = $this->em->createQueryBuilder()
            ->select('db')
            ->from(AcademicDatabase::class, 'db');

        if ($search !== '') {
            $qb->andWhere('db.name LIKE :search OR db.acronym LIKE :search OR db.description LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Clone query builder for total count
        $countQb = clone $qb;
        $countQb->select('COUNT(db.id)');
        $totalItems = (int) $countQb->getQuery()->getSingleScalarResult();

        // Paginate results
        $qb->orderBy('db.name', 'ASC')
           ->setFirstResult($offset)
           ->setMaxResults($limit);

        $databases = $qb->getQuery()->getResult();
        $totalPages = (int) ceil($totalItems / $limit);

        return $this->render('admin/academic_databases/index.html.twig', [
            'databases' => $databases,
            'search' => $search,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
        ]);
    }

    // ─── Create New ───────────────────────────────────────────────────────────

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $database = new AcademicDatabase();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_database_new', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de segurança inválido.');
                return $this->redirectToRoute('app_admin_academic_databases_index');
            }

            $name = trim($request->request->get('name', ''));
            $acronym = strtolower(trim($request->request->get('acronym', '')));
            $url = trim($request->request->get('url', ''));
            $logo = trim($request->request->get('logo', ''));
            $description = trim($request->request->get('description', ''));
            $importInstructions = trim($request->request->get('importInstructions', ''));

            // Parse file formats (comma separated)
            $formatsString = trim($request->request->get('fileFormats', ''));
            $fileFormats = array_filter(array_map('trim', explode(',', $formatsString)));
            $fileFormats = array_map('strtolower', $fileFormats);

            // Parse signature columns (newline separated)
            $sigString = trim($request->request->get('signatureColumns', ''));
            $signatureColumns = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $sigString))));

            if ($name === '' || $acronym === '') {
                $this->addFlash('danger', 'Nome e Sigla (Chave) são campos obrigatórios.');
            } else {
                // Check if acronym already exists
                $existing = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => $acronym]);
                if ($existing) {
                    $this->addFlash('danger', sprintf('Já existe uma base de dados com a sigla/chave "%s".', $acronym));
                } else {
                    $database->setName($name);
                    $database->setAcronym($acronym);
                    $database->setUrl($url !== '' ? $url : null);
                    $database->setLogo($logo !== '' ? $logo : null);
                    $database->setFileFormats($fileFormats);
                    $database->setSignatureColumns($signatureColumns);
                    $database->setDescription($description !== '' ? $description : null);
                    $database->setImportInstructions($importInstructions !== '' ? $importInstructions : null);

                    $this->em->persist($database);
                    $this->em->flush();

                    $this->addFlash('success', sprintf('Base de dados <strong>%s</strong> criada com sucesso!', $name));
                    return $this->redirectToRoute('app_admin_academic_databases_index');
                }
            }
        }

        return $this->render('admin/academic_databases/new.html.twig', [
            'database' => $database,
        ]);
    }

    // ─── Edit Existing ────────────────────────────────────────────────────────

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(AcademicDatabase $database, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_database_edit_' . $database->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de segurança inválido.');
                return $this->redirectToRoute('app_admin_academic_databases_index');
            }

            $name = trim($request->request->get('name', ''));
            $acronym = strtolower(trim($request->request->get('acronym', '')));
            $url = trim($request->request->get('url', ''));
            $logo = trim($request->request->get('logo', ''));
            $description = trim($request->request->get('description', ''));
            $importInstructions = trim($request->request->get('importInstructions', ''));

            // Parse file formats
            $formatsString = trim($request->request->get('fileFormats', ''));
            $fileFormats = array_filter(array_map('trim', explode(',', $formatsString)));
            $fileFormats = array_map('strtolower', $fileFormats);

            // Parse signature columns
            $sigString = trim($request->request->get('signatureColumns', ''));
            $signatureColumns = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $sigString))));

            if ($name === '' || $acronym === '') {
                $this->addFlash('danger', 'Nome e Sigla (Chave) são campos obrigatórios.');
            } else {
                // Check unique acronym
                if ($acronym !== $database->getAcronym()) {
                    $existing = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => $acronym]);
                    if ($existing) {
                        $this->addFlash('danger', sprintf('Já existe outra base de dados com a sigla/chave "%s".', $acronym));
                        return $this->render('admin/academic_databases/edit.html.twig', [
                            'database' => $database,
                        ]);
                    }
                }

                $database->setName($name);
                $database->setAcronym($acronym);
                $database->setUrl($url !== '' ? $url : null);
                $database->setLogo($logo !== '' ? $logo : null);
                $database->setFileFormats($fileFormats);
                $database->setSignatureColumns($signatureColumns);
                $database->setDescription($description !== '' ? $description : null);
                $database->setImportInstructions($importInstructions !== '' ? $importInstructions : null);

                $this->em->flush();

                $this->addFlash('success', sprintf('Base de dados <strong>%s</strong> atualizada com sucesso!', $name));
                return $this->redirectToRoute('app_admin_academic_databases_index');
            }
        }

        return $this->render('admin/academic_databases/edit.html.twig', [
            'database' => $database,
        ]);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(AcademicDatabase $database, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_database_delete_' . $database->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');
            return $this->redirectToRoute('app_admin_academic_databases_index');
        }

        $name = $database->getName();
        $this->em->remove($database);
        $this->em->flush();

        $this->addFlash('warning', sprintf('Base de dados <strong>%s</strong> removida com sucesso.', $name));
        return $this->redirectToRoute('app_admin_academic_databases_index');
    }
}
