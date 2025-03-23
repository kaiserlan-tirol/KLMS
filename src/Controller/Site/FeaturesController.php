<?php

namespace App\Controller\Site;

use App\Service\SettingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FeaturesController extends AbstractController
{
    private readonly SettingService $settings;

    public function __construct(SettingService $settings) {
        $this->settings = $settings;
    }

    #[Route(path: '/features/checklist', name: 'features_checklist')]
    public function checklist(): Response
    {
        $string = $this->settings->get("lan.checklist") ?? "";
        $entries = explode("\n", $string);
        $entries = array_map('trim', $entries);
        $entries = array_filter($entries);

        // Add unique IDs to each entry
        foreach ($entries as &$entry) {
            // if entry starts with -, add it as headline entry
            if (substr($entry, 0, 1) === "-") {
                $entry = [
                    'headline' => substr($entry, 1)
                ];
            } else {
                $entry = [
                    'label' => $entry,
                    'id' => str_replace(' ', '_', strtolower($entry))
                ];
            }
        }
        
        return $this->render("site/features/checklist.html.twig", [
            'entries' => $entries,
        ]);
    }

}
