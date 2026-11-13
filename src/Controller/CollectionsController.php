<?php

namespace App\Controller;

use App\Entity\Collections;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Form\CollectionsFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class CollectionsController extends AbstractController
{
    #[Route('/collections', name: 'app_collections')]
    public function index(): Response
    {
        return $this->render('collections/index.html.twig', []);
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
}
