<?php

namespace App\Controller;

use App\Entity\Categories;
use App\Entity\Collections;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Form\CollectionsFormType;
use App\Repository\CategoriesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class CollectionsController extends AbstractController
{
    #[Route('/collections', name: 'app_collections')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // One group per category, plus the uncategorised ones.
        $groups = [];
        $uncategorised = [];
        foreach ($this->latestFirst($user->getCollectionsOwned()->toArray()) as $collection) {
            $category = $collection->getCategory();
            if (!$category) {
                $uncategorised[] = $collection;
                continue;
            }
            $groups[$category->getId()] ??= ['category' => $category, 'collections' => []];
            $groups[$category->getId()]['collections'][] = $collection;
        }
        usort($groups, fn ($a, $b) => strcmp($a['category']->getName(), $b['category']->getName()));

        return $this->render('collections/index.html.twig', [
            'groups' => $groups,
            'uncategorised' => $uncategorised,
        ]);
    }

    #[Route('/collections/category/{slug}', name: 'app_collections_category')]
    #[IsGranted('ROLE_USER')]
    public function category(string $slug, CategoriesRepository $categoriesRepository): Response
    {
        $category = array_find($categoriesRepository->findAll(), fn (Categories $c) => $c->getSlug() === $slug)
            ?? throw $this->createNotFoundException();

        /** @var User $user */
        $user = $this->getUser();
        $collections = $this->latestFirst(array_filter(
            $user->getCollectionsOwned()->toArray(),
            fn (Collections $c) => $c->getCategory() === $category,
        ));

        return $this->render('collections/category.html.twig', [
            'category' => $category,
            'collections' => $collections,
        ]);
    }

    #[Route('/collections/{id}', name: 'app_collections_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(int $id, UserInterface $user): Response
    {
        return $this->render('collections/show.html.twig', [
            'collection_id' => $id,
            'user' => $user,
        ]);
    }

    #[Route('/collections/new', name: 'app_collections_new')]
    #[IsGranted('ROLE_USER')]
    public function newCollection(Request $request, EntityManagerInterface $em): Response
    {
        $collection = new Collections();
        $collection->setOwner($this->getUser());

        $form = $this->createForm(CollectionsFormType::class, $collection);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($collection);
            $em->flush();
        }

        return $this->render('collections/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/collections/{id}/edit', name: 'app_collections_edit')]
    #[IsGranted('ROLE_USER')]
    public function editCollection(int $id, UserInterface $userInterface): Response
    {
        /** @var User $user */
        $user = $userInterface;
        return $this->render('collections/edit.html.twig', [
            'collection_id' => $id,
            'user' => $user,
        ]);
    }

    /**
     * @param Collections[] $collections
     * @return Collections[] most recently updated first
     */
    private function latestFirst(array $collections): array
    {
        usort($collections, fn (Collections $a, Collections $b) => [$b->getUpdatedAt(), $b->getId()] <=> [$a->getUpdatedAt(), $a->getId()]);

        return $collections;
    }
}
