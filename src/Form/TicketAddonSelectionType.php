<?php

namespace App\Form;

use App\Entity\ShopAddon;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class TicketAddonSelectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ShopAddon[] $addons */
        $addons = $options['addons'] ?? [];
        $maxAddonCountCallback = $options['max_addon_count_callback'];

        foreach ($addons as $addon) {
            $name = "addon{$addon->getId()}";
            $max = $maxAddonCountCallback ? $maxAddonCountCallback($addon) : null;
            $formOpt = [];

            if (!is_null($max)) {
                if ($max < 0) {
                    $formOpt['help'] = "Du kannst dieses Add-On nicht (noch einmal) bestellen.";
                    $formOpt['disabled'] = true;
                } else if ($max == 0) {
                    $formOpt['help'] = "Dieses Addon ist nicht mehr verfügbar.";
                    $formOpt['disabled'] = true;
                } else {
                    $formOpt['help'] = "Es sind nur noch {$max} Stück verfügbar.";
                    $formOpt['disabled'] = false;
                }
            }

            // Check if this addon should be "one per ticket" (checkbox) or quantity-based (number input)
            if ($addon->isOnePerTicket()) {
                $builder->add($name, CheckboxType::class, array_merge($formOpt, [
                    'required' => false,
                    'value' => "1",
                    'label' => "Hinzufügen",
                ]));
            } else {
                $max = max(0, $max ?? 20);
                $builder->add($name, IntegerType::class, array_merge($formOpt, [
                    'required' => false,
                    'empty_data' => "0",
                    'attr' => [
                        'min' => 0,
                        'max' => $max,
                    ],
                    'constraints' => [
                        new Assert\GreaterThanOrEqual(0),
                        new Assert\LessThanOrEqual($max)
                    ],
                ]));
            }
        }

        // enforce "requires addons" dependencies within this ticket's selection
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($addons) {
            $form = $event->getForm();
            $data = $event->getData() ?? [];
            $selected = fn(ShopAddon $addon) => !empty($data["addon{$addon->getId()}"]);
            foreach ($addons as $addon) {
                if (!$selected($addon)) {
                    continue;
                }
                foreach ($addon->getRequiresAddons() as $required) {
                    if ($required->isActive() && !$selected($required)) {
                        $form->addError(new FormError(sprintf('"%s" kann nur zusammen mit "%s" gebucht werden.', $addon->getName(), $required->getName())));
                    }
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'addons' => [],
            'max_addon_count_callback' => null,
        ]);

        $resolver
            ->setAllowedTypes('addons', ShopAddon::class.'[]')
            ->setAllowedTypes('max_addon_count_callback', ['null', 'callable']);
    }
}
