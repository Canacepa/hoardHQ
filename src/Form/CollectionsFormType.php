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
                'label' => 'Collection name',
                'attr' => [
                    'placeholder' => 'Enter the collection name',
                ],
            ])

            ->add('description', null, [
                'label' => 'Collection description',
                'attr' => [
                    'placeholder' => 'Enter a description for the collection',
                ],
            ])
            ->add('category', EntityType::class, [
                'label' => 'Category',
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
                'label' => 'Create collection',
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
