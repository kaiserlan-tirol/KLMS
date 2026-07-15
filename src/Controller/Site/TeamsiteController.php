<?php

namespace App\Controller\Site;

use App\Entity\Teamsite;
use App\Service\TeamsiteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TeamsiteController extends AbstractController
{
    private readonly TeamsiteService $service;

    public function __construct(TeamsiteService $service)
    {
        $this->service = $service;
    }

    #[Route(path: '/teamsite/{id}', name: 'teamsite')]
    public function byId(Teamsite $teamsite): Response
    {
        // Preload users to avoid N+1 queries in template
        $users = $this->service->getUsersOfTeamsite($teamsite);

        return $this->render('site/teamsite/index.html.twig', [
            'teamsite' => $teamsite,
            'users' => $users,
        ]);
    }
}
