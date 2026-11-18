<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\CollectionsRepository;
use App\Repository\ItemsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OverviewController extends AbstractController
{
    #[Route('/overview', name: 'app_overview')]
    #[IsGranted('ROLE_USER')]
    public function index(CollectionsRepository $collections, ItemsRepository $items): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $mine = $collections->findBy(['owner' => $user], ['updatedAt' => 'DESC', 'id' => 'DESC'], 6);

        return $this->render('overview/index.html.twig', [
            'user' => $user,
            'featured' => $mine[0] ?? null,
            'recentCollections' => array_slice($mine, 0, 5),
            'recentItems' => $items->latestOwnedBy($user, 5),
            'fromFollowing' => $collections->latestPublicOf($user->getFollowing(), 5),
        ]);
    }
}
