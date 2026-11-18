<?php

namespace App\Controller;

use App\Entity\Categories;
use App\Entity\Collections;
use App\Entity\Items;
use App\Form\ItemsFormType;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Form\CollectionsFormType;
use App\Repository\CategoriesRepository;
use App\Service\CollectionGrouper;
use App\Repository\CollectionsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class CollectionsController extends AbstractController
{
    #[Route('/collections', name: 'app_collections')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request, CollectionGrouper $grouper): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $all = $this->latestFirst($user->getCollectionsOwned()->toArray());
        $category = array_values(array_filter((array) ($request->query->all()['category'] ?? []), 'is_string'));
        $collections = $grouper->filter($all, $category);
        $q = trim($request->query->getString('q'));
        if ($q !== '') {
            $collections = array_values(array_filter($collections, static fn (Collections $c) => false !== mb_stripos($c->getName() . ' ' . $c->getDescription(), $q)));
        }

        return $this->render('collections/index.html.twig', [
            'q' => $q,
            'collections' => $collections,
            'categories' => $grouper->categories($all),
            'category' => $category,
            'view' => $request->query->getString('view') === 'grid' ? 'grid' : 'grouped',
        ] + $grouper->group($collections));
    }

    #[Route('/browse', name: 'app_browse')]
    #[IsGranted('ROLE_USER')]
    public function browse(Request $request, CollectionsRepository $repository): Response
    {
        $q = trim($request->query->getString('q'));
        $search = $repository->createQueryBuilder('c')
            ->join('c.owner', 'o')
            ->andWhere('c.public = true')
            ->andWhere('c.owner != :me')
            ->setParameter('me', $this->getUser())
            ->orderBy('c.updatedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC');
        if ($q !== '') {
            // Match the name, description or owner; escape LIKE wildcards typed by the user.
            $search->andWhere("(c.name LIKE :q ESCAPE '!' OR c.description LIKE :q ESCAPE '!' OR o.username LIKE :q ESCAPE '!')")
                ->setParameter('q', '%' . addcslashes($q, '!%_') . '%');
        }
        $collections = $search->getQuery()->getResult();
        if ($q !== '') {
            // While searching, only the results are shown.
            return $this->render('collections/browse.html.twig', ['collections' => $collections, 'popular' => [], 'q' => $q]);
        }

        // Most followed first, then most recently updated.
        $popular = $repository->createQueryBuilder('c')
            ->leftJoin('c.followers', 'f')
            ->andWhere('c.public = true')
            ->andWhere('c.owner != :me')
            ->setParameter('me', $this->getUser())
            ->groupBy('c.id')
            ->orderBy('COUNT(f.id)', 'DESC')
            ->addOrderBy('c.updatedAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return $this->render('collections/browse.html.twig', ['collections' => $collections, 'popular' => $popular, 'q' => '']);
    }

    #[Route('/collections/category/{slug}', name: 'app_collections_category')]
    #[IsGranted('ROLE_USER')]
    public function category(string $slug, CategoriesRepository $categoriesRepository): Response
    {
        $category = array_find($categoriesRepository->findBy(['active' => true]), fn (Categories $c) => $c->getSlug() === $slug)
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
    public function show(Collections $collection): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $canView = $collection->isPublic()
            || $collection->getOwner() === $user
            || $collection->getContributors()->contains($user)
            || $collection->getViewers()->contains($user);
        if (!$canView) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('collections/show.html.twig', [
            'collection' => $collection,
            'isOwner' => $collection->getOwner() === $user,
            'canEdit' => $this->canEdit($collection, $user),
            'isFollowing' => $collection->getFollowers()->contains($user),
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

            return $this->redirectToRoute('app_collections_show', ['id' => $collection->getId()]);
        }

        return $this->render('collections/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/collections/{id}/items/new', name: 'app_items_new', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function newItem(Collections $collection, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->canEdit($collection, $this->getUser())) {
            throw $this->createAccessDeniedException();
        }

        $item = (new Items())->setCollection($collection);
        $form = $this->createForm(ItemsFormType::class, $item);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $collection->touch();
            $em->persist($item);
            $em->flush();

            return $this->redirectToRoute('app_collections_show', ['id' => $collection->getId()]);
        }

        return $this->render('items/new.html.twig', ['collection' => $collection, 'form' => $form->createView()]);
    }

    private function canEdit(Collections $collection, ?object $user): bool
    {
        return $user !== null && ($collection->getOwner() === $user || $collection->getContributors()->contains($user));
    }

    #[Route('/collections/{id}/follow', name: 'app_collections_follow', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function follow(Collections $collection, Request $request, EntityManagerInterface $em): Response
    {
        return $this->toggleFollow($collection, $request, $em, true);
    }

    #[Route('/collections/{id}/unfollow', name: 'app_collections_unfollow', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function unfollow(Collections $collection, Request $request, EntityManagerInterface $em): Response
    {
        return $this->toggleFollow($collection, $request, $em, false);
    }

    private function toggleFollow(Collections $collection, Request $request, EntityManagerInterface $em, bool $follow): Response
    {
        if (!$this->isCsrfTokenValid('follow' . $collection->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        /** @var User $user */
        $user = $this->getUser();
        if ($follow) {
            // Only public collections can be followed, and not your own.
            if (!$collection->isPublic() || $collection->getOwner() === $user) {
                throw $this->createNotFoundException();
            }
            $user->addCollectionsFollowed($collection);
        } else {
            $user->removeCollectionsFollowed($collection);
        }
        $em->flush();

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_collections'));
    }

    #[Route('/collections/{id}/edit', name: 'app_collections_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function editCollection(Collections $collection, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->canEdit($collection, $this->getUser())) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(CollectionsFormType::class, $collection);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            return $this->redirectToRoute('app_collections_show', ['id' => $collection->getId()]);
        }

        return $this->render('collections/edit.html.twig', ['collection' => $collection, 'form' => $form->createView()]);
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
