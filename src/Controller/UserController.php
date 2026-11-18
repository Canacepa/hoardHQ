<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\Collections;
use App\Entity\User;
use App\Repository\CategoriesRepository;
use App\Service\CollectionGrouper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\UserRepository;

final class UserController extends AbstractController
{
    #[Route('/user/{slug}', name: 'user_profile')]
    #[IsGranted('ROLE_USER')]
    public function index(string $slug, UserRepository $userRepository, CategoriesRepository $categoriesRepository): Response
    {
        $user = $userRepository->findOneBy(['username' => $slug]);
        

        if (!$user) {
        return $this->redirectToRoute('user_404');
        }

        // One entry per active category: how many of the user's own collections use it.
        $stats = [];
        foreach ($categoriesRepository->findBy(['active' => true], ['name' => 'ASC']) as $category) {
            $stats[$category->getId()] = ['category' => $category, 'collections' => 0];
        }
        // Other people only see the public collections.
        $viewer = $this->getUser();
        $isSelf = $user === $viewer;
        $owned = array_values(array_filter(
            $user->getCollectionsOwned()->toArray(),
            fn (Collections $c) => $isSelf || $c->isPublic(),
        ));
        foreach ($owned as $collection) {
            $category = $collection->getCategory();
            if ($category && isset($stats[$category->getId()])) {
                $stats[$category->getId()]['collections']++;
            }
        }

        return $this->render('user/index.html.twig', [
            'user' => $user,
            'owned' => $owned,
            'isSelf' => $isSelf,
            'isFollowing' => $viewer instanceof User && $viewer->getFollowing()->contains($user),
            'categoryStats' => array_values($stats),
        ]);
    }

    #[Route('/user/{slug}/collections/{type}', name: 'user_collections', requirements: ['type' => 'owned|contributions|followed'])]
    #[IsGranted('ROLE_USER')]
    public function collections(string $slug, string $type, Request $request, UserRepository $userRepository, CollectionGrouper $grouper): Response
    {
        $user = $userRepository->findOneBy(['username' => $slug]) ?? throw $this->createNotFoundException();
        $isSelf = $user === $this->getUser();

        [$title, $all] = match ($type) {
            'owned' => ['Collections', $user->getCollectionsOwned()],
            'contributions' => ['Contributions', $user->getCollectionsContributor()],
            'followed' => ['Followed collections', $user->getCollectionsFollowed()],
        };
        // Other people only see the public ones, newest update first.
        $collections = array_filter($all->toArray(), fn (Collections $c) => $isSelf || $c->isPublic());
        usort($collections, fn (Collections $a, Collections $b) => [$b->getUpdatedAt(), $b->getId()] <=> [$a->getUpdatedAt(), $a->getId()]);
        $categories = $grouper->categories($collections);
        $category = array_values(array_filter((array) ($request->query->all()['category'] ?? []), 'is_string'));
        $collections = $grouper->filter($collections, $category);
        $q = trim($request->query->getString('q'));
        if ($q !== '') {
            $collections = array_values(array_filter($collections, static fn (Collections $c) => false !== mb_stripos($c->getName() . ' ' . $c->getDescription() . ' ' . $c->getOwner()->getUsername(), $q)));
        }

        return $this->render('user/collections.html.twig', [
            'q' => $q,
            'user' => $user,
            'isSelf' => $isSelf,
            'title' => $title,
            'type' => $type,
            'collections' => $collections,
            'categories' => $categories,
            'category' => $category,
            'view' => $request->query->getString('view') === 'grouped' ? 'grouped' : 'grid',
        ] + $grouper->group($collections));
    }

    #[Route('/user/{slug}/follow', name: 'user_follow', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function follow(string $slug, Request $request, UserRepository $userRepository, EntityManagerInterface $em): Response
    {
        return $this->toggleFollow($slug, $request, $userRepository, $em, true);
    }

    #[Route('/user/{slug}/unfollow', name: 'user_unfollow', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function unfollow(string $slug, Request $request, UserRepository $userRepository, EntityManagerInterface $em): Response
    {
        return $this->toggleFollow($slug, $request, $userRepository, $em, false);
    }

    private function toggleFollow(string $slug, Request $request, UserRepository $userRepository, EntityManagerInterface $em, bool $follow): Response
    {
        $target = $userRepository->findOneBy(['username' => $slug]) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('follow-user-' . $target->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        /** @var User $me */
        $me = $this->getUser();
        if ($follow && $me === $target) {
            throw $this->createNotFoundException();
        }
        $follow ? $me->follow($target) : $me->unfollow($target);
        $em->flush();

        // Go back to the page the button was on (same-site paths only), else to the profile.
        $back = $request->getPayload()->getString('_back');
        if (str_starts_with($back, '/') && !str_starts_with($back, '//') && !str_contains($back, '\\')) {
            return $this->redirect($back);
        }

        return $this->redirectToRoute('user_profile', ['slug' => $target->getUserIdentifier()]);
    }

    #[Route('/user/not-found', name: 'user_404')]
    public function notFound(): Response
    {
        return $this->render('user/not_found.html.twig');
    }
}
