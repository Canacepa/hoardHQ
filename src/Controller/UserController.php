<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Repository\CategoriesRepository;
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
        foreach ($user->getCollectionsOwned() as $collection) {
            $category = $collection->getCategory();
            if ($category && isset($stats[$category->getId()])) {
                $stats[$category->getId()]['collections']++;
            }
        }

        return $this->render('user/index.html.twig', [
            'user' => $user,
            'categoryStats' => array_values($stats),
        ]);
    }

    #[Route('/user/not-found', name: 'user_404')]
    public function notFound(): Response
    {
        return $this->render('user/not_found.html.twig');
    }
}
