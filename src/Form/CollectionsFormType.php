<?php

namespace App\Form;

use App\Entity\Categories;
use App\Entity\Collections;
use App\Entity\user;
use App\Repository\CategoriesRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class CollectionsFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, [
                'label' => 'Nom de la collection',
                'attr' => [
                    'placeholder' => 'Entrez le nom de la collection',
                ],
            ])

            ->add('description', null, [
                'label' => 'Description de la collection',
                'attr' => [
                    'placeholder' => 'Entrez une description pour la collection',
                ],
            ])
            ->add('category', EntityType::class, [
                'label' => 'Catégorie',
                'class' => Categories::class,
                'choice_label' => 'name',
                'query_builder' => fn (CategoriesRepository $r) => $r->createQueryBuilder('c')
                    ->andWhere('c.active = true')
                    ->orderBy('c.name'),
            ])

            // ->add('thumbnailFile', FileType::class, [
            //     'label' => 'Thumbnail (Image file)',
            //     'mapped' => false,
            //     'required' => false,
            //     'constraints' => [
            //         new Image([
            //             'maxSize' => '5M',
            //             'mimeTypes' => [
            //                 'image/jpeg',
            //                 'image/png',
            //                 'image/gif',
            //             ],
            //             'mimeTypesMessage' => 'Please upload a valid image file (JPEG, PNG, GIF)',
            //         ])
            //     ],
            // ])
            ->add('save', SubmitType::class, [
                'label' => 'Créer la collection',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Collections::class,
        ]);
    }
}
