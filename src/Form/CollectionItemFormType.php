<?php

namespace App\Form;

use App\Entity\CollectionItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class CollectionItemFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('item', TextType::class, [
                'label' => 'Item name',
                'property_path' => 'item.name',
                'constraints' => [new NotBlank()],
                'attr' => ['placeholder' => 'Enter the item name'],
            ])
            ->add('condition', null, ['required' => false, 'attr' => ['placeholder' => 'e.g. Mint, Good, Worn']])
            ->add('notes', TextareaType::class, ['required' => false])
            ->add('save', SubmitType::class, ['label' => 'Add item'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CollectionItem::class]);
    }
}
