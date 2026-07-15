<?php

namespace App\Form;

use App\Entity\ShopAddon;
use App\Repository\ShopAddonsRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ShopAddonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ShopAddon|null $addon */
        $addon = $builder->getData();
        $selfId = $addon?->getId();
        $othersQuery = function (ShopAddonsRepository $repository) use ($selfId) {
            $qb = $repository->createQueryBuilder('a')
                ->orderBy('a.sortIndex', 'ASC')
                ->addOrderBy('a.name', 'ASC');
            if ($selfId) {
                $qb->where('a.id != :self')->setParameter('self', $selfId);
            }
            return $qb;
        };
        $choiceLabel = fn(ShopAddon $a) => sprintf('%s (%s €)', $a->getName(), number_format($a->getPrice() / 100, 2, ',', '.'));

        $builder
            ->add('name', TextType::class, ['label' => 'Name'])
            ->add('price', MoneyType::class, ['label' => 'Preis', 'divisor' => 100])
            ->add('active', CheckboxType::class, ['label' => 'Aktiv', 'required' => false])
            ->add('onlyOnce', CheckboxType::class, ['label' => 'Kann nur einmal pro User gekauft werden.', 'required' => false])
            ->add('onePerTicket', CheckboxType::class, ['label' => 'Per-Ticket: Als Checkbox (Ein pro Ticket)', 'required' => false, 'help' => 'Wenn aktiviert, wird dieses Add-on im Per-Ticket System als Checkbox angezeigt (max. 1 pro Ticket). Sonst als Anzahl-Eingabefeld.'])
            ->add('maxQuantityGlobal', IntegerType::class, ['label' => 'Maximale Anzahl (global)', 'required' => false, 'attr' => ['min' => 1], 'constraints' => [new Assert\Positive()]])
            ->add('sortIndex', IntegerType::class, ['label' => 'Sortierung', 'required' => false, 'attr' => ['min' => 1], 'constraints' => [new Assert\Positive()]])
            ->add('description', TextAreaType::class, ['label' => 'Beschreibung', 'required' => false])
            ->add('zerosTicketPrice', CheckboxType::class, [
                'label' => 'Setzt Ticketpreis auf 0 €',
                'required' => false,
                'help' => 'Wenn dieses Add-on gewählt wird, kostet der Eintritt (Ticket-Grundpreis) des zugehörigen Tickets 0 €. Nur im Per-Ticket System wirksam.',
            ])
            ->add('zerosAddons', EntityType::class, [
                'class' => ShopAddon::class,
                'label' => 'Setzt folgende Add-ons auf 0 €',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'by_reference' => false,
                'choice_label' => $choiceLabel,
                'query_builder' => $othersQuery,
                'help' => 'Diese Add-ons kosten 0 €, wenn sie zusammen mit diesem Add-on auf demselben Ticket gewählt werden.',
            ])
            ->add('requiresAddons', EntityType::class, [
                'class' => ShopAddon::class,
                'label' => 'Erfordert folgende Add-ons',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'by_reference' => false,
                'choice_label' => $choiceLabel,
                'query_builder' => $othersQuery,
                'help' => 'Dieses Add-on kann nur zusammen mit den gewählten Add-ons gebucht werden (pro Ticket).',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ShopAddon::class,
        ]);
    }
}
