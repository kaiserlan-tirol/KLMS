<?php

namespace App\Form;

use App\Entity\ShopAddon;
use App\Repository\ShopAddonsRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class AdminShopAddAddonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('addon', EntityType::class, [
                'class' => ShopAddon::class,
                'choice_label' => function(ShopAddon $addon) {
                    $price = $addon->getPrice() ? ' (' . ($addon->getPrice() / 100) . '€)' : ' (kostenlos)';
                    return $addon->getName() . $price;
                },
                'placeholder' => 'Addon auswählen',
                'required' => true,
                'query_builder' => function(ShopAddonsRepository $repository) {
                    return $repository->createQueryBuilder('a')
                        ->where('a.active = :active')
                        ->setParameter('active', true)
                        ->orderBy('a.sortIndex', 'ASC')
                        ->addOrderBy('a.name', 'ASC');
                },
                'attr' => [
                    'class' => 'form-control'
                ]
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Anzahl',
                'required' => true,
                'data' => 1,
                'attr' => [
                    'min' => 1,
                    'class' => 'form-control'
                ],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive(),
                ]
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Addon hinzufügen',
                'attr' => [
                    'class' => 'btn btn-primary'
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Configure your form options here
        ]);
    }
}