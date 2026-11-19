<?php

namespace App\Controller;

use App\Entity\Categories;
use App\Entity\Collections;
use App\Entity\CollectionItem;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Form\CollectionsFormType;
use App\Repository\CategoriesRepository;
use App\Repository\CollectionItemRepository;
use App\Service\CollectionBulkEditor;
use App\Service\CollectionGrouper;
use App\Service\EntrySorter;
use App\Service\ItemImporter;
use App\Service\TmdbProvider;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use App\Repository\CollectionsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestHttpException;
use Symfony\Component\HttpFoundation\Request;

final class CollectionsController extends AbstractController
{
    /** At most one page of TMDB search results can be imported at once. */
    private const MAX_IMPORT = 20;

    #[Route('/collections', name: 'app_collections')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request, CollectionGrouper $grouper): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $all = $user->getCollectionsOwned()->toArray();
        $category = array_values(array_filter((array) ($request->query->all()['category'] ?? []), 'is_string'));
        $collections = $grouper->filter($all, $category);
        $q = trim($request->query->getString('q'));
        if ($q !== '') {
            $collections = array_values(array_filter($collections, static fn (Collections $c) => false !== mb_stripos($c->getName() . ' ' . $c->getDescription(), $q)));
        }
        $sort = $grouper->sortFrom($request->query->getString('sort'));
        $collections = $grouper->sort($collections, $sort);

        return $this->render('collections/index.html.twig', [
            'q' => $q,
            'sort' => $sort,
            'sorts' => CollectionGrouper::SORTS,
            'collections' => $collections,
            'categories' => $grouper->categories($all),
            'category' => $category,
            'view' => $grouper->viewFrom($request->query->getString('view'), 'grouped'),
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
    public function show(Collections $collection, Request $request, EntrySorter $sorter): Response
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

        $q = trim($request->query->getString('q'));
        $entries = $collection->getEntries()->toArray();
        if ($q !== '') {
            $entries = array_values(array_filter($entries, static fn (CollectionItem $e) => false !== mb_stripos($e->getItem()->getName(), $q)));
        }
        $sort = $sorter->sortFrom($request->query->getString('sort'), $collection);

        return $this->render('collections/show.html.twig', [
            'q' => $q,
            'sort' => $sort,
            'sorts' => $sorter->optionsFor($collection),
            'entries' => $sorter->sort($entries, $sort),
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

    /** The only way to add items: they come from an external API, never from user input. */
    #[Route('/collections/{id}/items/import', name: 'app_items_import', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function importItem(Collections $collection, Request $request, TmdbProvider $tmdb, ItemImporter $importer, CollectionItemRepository $entries): Response
    {
        if (!$this->canEdit($collection, $this->getUser())) {
            throw $this->createAccessDeniedException();
        }
        if (!$collection->isImportable()) {
            throw $this->createNotFoundException();
        }

        $q = trim($request->query->getString('q'));
        $results = [];
        $error = null;
        try {
            if ($request->isMethod('POST')) {
                if (!$this->isCsrfTokenValid('import' . $collection->getId(), $request->getPayload()->getString('_token'))) {
                    throw $this->createAccessDeniedException();
                }
                // Re-fetch by id: the catalog is shared, so nothing but the ids is taken from the request.
                $ids = array_slice(array_filter($request->getPayload()->all('externalId'), 'is_string'), 0, self::MAX_IMPORT);
                $found = $tmdb->fetchMany($ids, $collection->getImportType()) ?: throw $this->createNotFoundException();
                foreach ($found as $data) {
                    $importer->add($collection, TmdbProvider::SOURCE, $data);
                }

                return $this->redirectToRoute('app_collections_show', ['id' => $collection->getId()]);
            }
            if ($q !== '') {
                $owned = array_flip($entries->externalIds($collection, TmdbProvider::SOURCE));
                $results = array_values(array_filter($tmdb->search($q, $collection->getImportType()), static fn (array $r) => !isset($owned[$r['externalId']])));
            }
        } catch (HttpClientException $e) {
            $error = 'TMDB could not be reached. Check that TMDB_API_TOKEN is set, or try again later.';
        }

        return $this->render('items/import.html.twig', ['collection' => $collection, 'q' => $q, 'results' => $results, 'error' => $error]);
    }

    /** Marks an entry as wanna-watch / watched, or clears it (empty status). Only movie and TV collections have it. */
    #[Route('/collections/{id}/items/{entry}/status', name: 'app_items_status', requirements: ['id' => '\d+', 'entry' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function setItemStatus(Collections $collection, int $entry, Request $request, EntityManagerInterface $em, CollectionItemRepository $entries): Response
    {
        $item = $this->entryToUpdate($collection, $entry, $request, $entries);

        $status = $request->getPayload()->getString('status');
        if ($status !== '' && !\in_array($status, CollectionItem::STATUSES, true)) {
            throw new BadRequestHttpException();
        }
        $item->applyStatus($status ?: null);
        $collection->touch();
        $em->flush();

        return $this->redirectToRoute('app_collections_show', ['id' => $collection->getId()]);
    }

    /** Ticks or un-ticks one episode, a season or the whole show; see CollectionItem::toggleWatched() for the targets. */
    #[Route('/collections/{id}/items/{entry}/episodes', name: 'app_items_episodes', requirements: ['id' => '\d+', 'entry' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function toggleEpisodes(Collections $collection, int $entry, Request $request, EntityManagerInterface $em, CollectionItemRepository $entries): Response
    {
        $item = $this->entryToUpdate($collection, $entry, $request, $entries);

        if (!$item->toggleWatched($request->getPayload()->getString('target'))) {
            throw new BadRequestHttpException();
        }
        $collection->touch();
        $em->flush();

        return $this->redirectToRoute('app_collections_show', ['id' => $collection->getId()]);
    }

    /** Shared checks for the watch-state routes: editor, valid CSRF token, movie/TV collection, entry of this collection. */
    private function entryToUpdate(Collections $collection, int $entry, Request $request, CollectionItemRepository $entries): CollectionItem
    {
        if (!$this->canEdit($collection, $this->getUser())
            || !$this->isCsrfTokenValid('status' . $collection->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$collection->isImportable()) {
            throw $this->createNotFoundException();
        }

        // Scoped to this collection, so another collection's entry id is a 404.
        return $entries->findOneBy(['id' => $entry, 'collection' => $collection]) ?? throw $this->createNotFoundException();
    }

    /**
     * The body of a TV show's modal on the import page, as a Turbo frame loaded the first time the modal opens: the seasons
     * the collection doesn't have yet, each addable, and an "Add full show" button that adds all of them. A collection that
     * holds the show as one combined item (an older kind of item) gets nothing offered: it is already covered.
     */
    #[Route('/collections/{id}/items/import/tv/{tv}/seasons', name: 'app_items_seasons', requirements: ['id' => '\d+', 'tv' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function showSeasons(Collections $collection, int $tv, TmdbProvider $tmdb, CollectionItemRepository $entries): Response
    {
        if (!$this->canEdit($collection, $this->getUser())) {
            throw $this->createAccessDeniedException();
        }
        if ($collection->getImportType() !== 'tv') {
            throw $this->createNotFoundException();
        }

        $seasons = [];
        $error = null;
        try {
            $seasons = $tmdb->seasonsOf($tv);
        } catch (HttpClientException) {
            $error = 'The seasons could not be loaded. Try again later.';
        }
        $owned = array_flip($entries->externalIds($collection, TmdbProvider::SOURCE));
        $ownsFull = isset($owned["tv:$tv"]);
        // the older combined item already contains every season, so none is offered on its own then
        $available = $ownsFull ? [] : array_values(array_filter($seasons, static fn (array $s) => !isset($owned[$s['externalId']])));

        return $this->render('items/_seasons.html.twig', [
            'tv' => $tv,
            'seasons' => $available,
            'remainingEpisodes' => array_sum(array_map(static fn (array $s) => $s['attributes']['episodes'], $available)),
            'total' => \count($seasons),
            'episodes' => array_sum(array_map(static fn (array $s) => $s['attributes']['episodes'], $seasons)),
            'ownsFull' => $ownsFull,
            'error' => $error,
        ]);
    }

    /**
     * Edit mode: applies one action to the selected entries of this collection. "remove" takes them out of the
     * collection (the shared catalog item stays for everyone else); "want", "watched" and "clear" set the status, which
     * for a TV show covers all its seasons and episodes.
     */
    #[Route('/collections/{id}/items/bulk', name: 'app_items_bulk', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function bulk(Collections $collection, Request $request, EntityManagerInterface $em, CollectionItemRepository $entries): Response
    {
        if (!$this->canEdit($collection, $this->getUser())
            || !$this->isCsrfTokenValid('bulk' . $collection->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $statuses = ['want' => CollectionItem::WANT, 'watched' => CollectionItem::WATCHED, 'clear' => null];
        $action = $request->getPayload()->getString('action');
        if ($action !== 'remove' && !\array_key_exists($action, $statuses)) {
            throw new BadRequestHttpException();
        }
        if ($action !== 'remove' && !$collection->isImportable()) {
            throw $this->createNotFoundException(); // only movie and TV collections have a status
        }

        $ids = array_filter($request->getPayload()->all('entry'), static fn ($id) => \is_string($id) && ctype_digit($id));
        // Scoped to this collection, so ids belonging to other collections are ignored.
        foreach ($ids ? $entries->findBy(['collection' => $collection, 'id' => $ids]) : [] as $entry) {
            $action === 'remove' ? $em->remove($entry) : $entry->applyStatus($statuses[$action]);
        }
        $collection->touch();
        $em->flush();

        return $this->redirectToRoute('app_collections_show', ['id' => $collection->getId()]);
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

        return $this->render('collections/edit.html.twig', [
            'collection' => $collection,
            'form' => $form->createView(),
            'isOwner' => $collection->getOwner() === $this->getUser(),
        ]);
    }

    /**
     * Edit mode on the collections list: removes, publishes or hides the ticked collections. Only the user's own are touched
     * (see CollectionBulkEditor); anyone else's ids in the request are ignored.
     */
    #[Route('/collections/bulk', name: 'app_collections_bulk', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function bulkCollections(Request $request, CollectionBulkEditor $editor): Response
    {
        if (!$this->isCsrfTokenValid('bulk-collections', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $action = $request->getPayload()->getString('action');
        if (!\in_array($action, CollectionBulkEditor::ACTIONS, true)) {
            throw new BadRequestHttpException();
        }

        $ids = array_filter($request->getPayload()->all('entry'), static fn ($id) => \is_string($id) && ctype_digit($id));
        /** @var User $user */
        $user = $this->getUser();
        $editor->apply($action, $ids, $user);

        // Back to the list as it was (view, sort, filter), but only to a page of this site.
        $referer = $request->headers->get('referer');

        return $this->redirect($referer !== null && str_starts_with($referer, $request->getSchemeAndHttpHost() . '/') ? $referer : $this->generateUrl('app_collections'));
    }

    /** Deletes the collection with its entries (the shared catalog items stay). Owner only: contributors can edit but not delete. */
    #[Route('/collections/{id}/delete', name: 'app_collections_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteCollection(Collections $collection, Request $request, EntityManagerInterface $em): Response
    {
        if ($collection->getOwner() !== $this->getUser()
            || !$this->isCsrfTokenValid('delete' . $collection->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($collection);
        $em->flush();

        return $this->redirectToRoute('app_collections');
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
