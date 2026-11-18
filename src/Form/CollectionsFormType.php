<?php

namespace App\Form;

use App\Entity\Categories;
use App\Entity\Collections;
use App\Entity\user;
use App\Repository\CategoriesRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

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
            ->add('public', CheckboxType::class, [
                'label' => 'Public collection',
                'required' => false,
                'row_attr' => ['class' => 'flex flex-row-reverse items-center justify-end gap-3'],
                'attr' => ['class' => "inset relative h-7 w-12 shrink-0 cursor-pointer appearance-none rounded-full outline-none focus-visible:ring-2 focus-visible:ring-wine/40 before:absolute before:top-1 before:left-1 before:size-5 before:rounded-full before:bg-taupe before:shadow-[2px_2px_5px_rgb(114_47_55/.35)] before:transition-all checked:before:translate-x-5 checked:before:bg-wine"],
                'label_attr' => ['class' => '!mb-0'],
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
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Collections::class,
        ]);
    }
}
