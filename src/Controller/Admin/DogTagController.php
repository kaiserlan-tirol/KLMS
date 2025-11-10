<?php

namespace App\Controller\Admin;

use App\Entity\Ticket;
use App\Entity\User;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[IsGranted('ROLE_ADMIN_PAYMENT')]
#[Route(path: '/dogtags', name: 'dogtags')]
class DogTagController extends AbstractController
{
    private readonly IdmManager $idmManager;
    private readonly IdmRepository $userRepo;
    private readonly TicketRepository $ticketRepo;
    private readonly EntityManagerInterface $em;

    public function __construct(
        IdmManager $idmManager,
        TicketRepository $ticketRepo,
        EntityManagerInterface $em
    ) {
        $this->idmManager = $idmManager;
        $this->userRepo = $idmManager->getRepository(User::class);
        $this->ticketRepo = $ticketRepo;
        $this->em = $em;
    }

    #[Route(path: '', name: '_index')]
    public function index(Request $request): Response
    {
        $searchQuery = $request->query->get('search', '');
        
        // Get all tickets
        $tickets = $this->ticketRepo->findAll();
        
        // Extract unique user UUIDs from tickets (only redeemed tickets have a redeemer UUID)
        // and map tickets by user UUID
        $uniqueUuids = [];
        $ticketsByUser = [];
        foreach ($tickets as $ticket) {
            $redeemer = $ticket->getRedeemer();
            if ($redeemer) {
                $uuidString = $redeemer->toString();
                $uniqueUuids[$uuidString] = $redeemer;
                if (!isset($ticketsByUser[$uuidString])) {
                    $ticketsByUser[$uuidString] = [];
                }
                $ticketsByUser[$uuidString][] = $ticket;
            }
        }
        
        // Fetch only users with tickets using bulk request
        $users = [];
        if (!empty($uniqueUuids)) {
            $userObjects = $this->idmManager->bulk(User::class, array_values($uniqueUuids));
            foreach ($userObjects as $user) {
                // Apply search filter if provided
                if (!empty($searchQuery)) {
                    $searchLower = mb_strtolower($searchQuery);
                    $matchesNickname = mb_stripos($user->getNickname() ?? '', $searchLower) !== false;
                    $matchesFirstname = mb_stripos($user->getFirstname() ?? '', $searchLower) !== false;
                    $matchesSurname = mb_stripos($user->getSurname() ?? '', $searchLower) !== false;
                    
                    if (!($matchesNickname || $matchesFirstname || $matchesSurname)) {
                        continue;
                    }
                }
                
                $users[$user->getUuid()->toString()] = $user;
            }
        }
        
        // Group users by their dogTagGroup
        $groups = [];
        $unassignedUsers = [];
        
        foreach ($users as $uuid => $user) {
            $userTickets = $ticketsByUser[$uuid] ?? [];
            $ticket = !empty($userTickets) ? $userTickets[0] : null; // Get first ticket
            
            $groupNumber = $user->getDogTagGroup();
            if ($groupNumber !== null) {
                if (!isset($groups[$groupNumber])) {
                    $groups[$groupNumber] = [];
                }
                $groups[$groupNumber][] = [
                    'user' => $user,
                    'hasTicket' => true, // All users in this list have tickets
                    'ticket' => $ticket,
                    'dogtagDone' => $ticket ? $ticket->isDogtagDone() : false,
                ];
            } else {
                $unassignedUsers[] = [
                    'user' => $user,
                    'hasTicket' => true,
                    'ticket' => $ticket,
                    'dogtagDone' => $ticket ? $ticket->isDogtagDone() : false,
                ];
            }
        }
        
        // Sort groups by group number
        ksort($groups);
        
        // Sort users within each group by nickname
        foreach ($groups as &$group) {
            usort($group, function($a, $b) {
                return strcasecmp($a['user']->getNickname() ?? '', $b['user']->getNickname() ?? '');
            });
        }
        
        // Sort unassigned users by nickname
        usort($unassignedUsers, function($a, $b) {
            return strcasecmp($a['user']->getNickname() ?? '', $b['user']->getNickname() ?? '');
        });
        
        // Calculate available groups (groups 1-20 with less than 16 users)
        $availableGroups = [];
        for ($i = 1; $i <= 8; $i++) {
            $groupSize = isset($groups[$i]) ? count($groups[$i]) : 0;
            if ($groupSize < 16) {
                $availableGroups[] = [
                    'number' => $i,
                    'free' => 16 - $groupSize
                ];
            }
        }
        
        return $this->render('admin/dogtag/index.html.twig', [
            'groups' => $groups,
            'unassignedUsers' => $unassignedUsers,
            'searchQuery' => $searchQuery,
            'availableGroups' => $availableGroups,
        ]);
    }

    #[Route(path: '/assign/{uuid}', name: '_assign', methods: ['POST'])]
    public function assign(string $uuid, Request $request): Response
    {
        $user = $this->userRepo->findOneById($uuid);
        
        if (!$user) {
            $this->addFlash('error', 'Gamer nicht gefunden');
            return $this->redirectToRoute('admin_dogtags_index');
        }
        
        $groupNumber = $request->request->get('groupNumber');
        
        if ($groupNumber === 'auto') {
            // Find the next available group number
            $allUsers = $this->userRepo->findAll();
            $usedGroups = [];
            foreach ($allUsers as $u) {
                $grp = $u->getDogTagGroup();
                if ($grp !== null) {
                    if (!isset($usedGroups[$grp])) {
                        $usedGroups[$grp] = 0;
                    }
                    $usedGroups[$grp]++;
                }
            }
            
            // Find first group with less than 16 users, or create new group
            $targetGroup = null;
            for ($i = 1; $i <= 1000; $i++) {
                if (!isset($usedGroups[$i]) || $usedGroups[$i] < 16) {
                    $targetGroup = $i;
                    break;
                }
            }
            
            if ($targetGroup === null) {
                $this->addFlash('error', 'Could not find available group');
                return $this->redirectToRoute('admin_dogtags_index');
            }
            
            $user->setDogTagGroup($targetGroup);
        } else {
            $user->setDogTagGroup((int)$groupNumber);
        }
        
        $this->idmManager->persist($user);
        $this->idmManager->flush();
        
        $this->addFlash('success', sprintf('Gamer %s zu Gruppe %d hinzugefügt', $user->getNickname(), $user->getDogTagGroup()));
        
        return $this->redirectToRoute('admin_dogtags_index');
    }

    #[Route(path: '/bulk-assign', name: '_bulk_assign', methods: ['POST'])]
    public function bulkAssign(Request $request): Response
    {
        $uuids = $request->request->all('uuids');
        $groupNumber = $request->request->get('groupNumber');
        
        if (empty($uuids)) {
            $this->addFlash('error', 'Keine Benutzer ausgewählt');
            return $this->redirectToRoute('admin_dogtags_index');
        }
        
        $assignedCount = 0;
        $targetGroup = null;
        
        // If auto mode, find the target group once
        if ($groupNumber === 'auto') {
            $allUsers = $this->userRepo->findAll();
            $usedGroups = [];
            foreach ($allUsers as $u) {
                $grp = $u->getDogTagGroup();
                if ($grp !== null) {
                    if (!isset($usedGroups[$grp])) {
                        $usedGroups[$grp] = 0;
                    }
                    $usedGroups[$grp]++;
                }
            }
            
            // Find first group with less than 16 users
            for ($i = 1; $i <= 1000; $i++) {
                $currentCount = $usedGroups[$i] ?? 0;
                $remainingSpace = 16 - $currentCount;
                
                if ($remainingSpace > 0) {
                    $targetGroup = $i;
                    break;
                }
            }
            
            if ($targetGroup === null) {
                $this->addFlash('error', 'Keine verfügbare Gruppe gefunden');
                return $this->redirectToRoute('admin_dogtags_index');
            }
        } else {
            $targetGroup = (int)$groupNumber;
        }
        
        // Assign users to the group
        foreach ($uuids as $uuid) {
            $user = $this->userRepo->findOneById($uuid);
            
            if (!$user) {
                continue;
            }
            
            $user->setDogTagGroup($targetGroup);
            $this->idmManager->persist($user);
            $assignedCount++;
            
            // In auto mode, check if current group is full and move to next
            if ($groupNumber === 'auto') {
                // Count users in current group
                $allUsers = $this->userRepo->findAll();
                $currentGroupCount = 0;
                foreach ($allUsers as $u) {
                    if ($u->getDogTagGroup() === $targetGroup) {
                        $currentGroupCount++;
                    }
                }
                
                // If group is full, move to next group
                if ($currentGroupCount >= 16) {
                    $targetGroup++;
                }
            }
        }
        
        $this->idmManager->flush();
        
        if ($assignedCount > 0) {
            $this->addFlash('success', sprintf('%d Benutzer erfolgreich zugewiesen', $assignedCount));
        } else {
            $this->addFlash('warning', 'Keine Benutzer zugewiesen');
        }
        
        return $this->redirectToRoute('admin_dogtags_index');
    }

    #[Route(path: '/unassign/{uuid}', name: '_unassign', methods: ['POST'])]
    public function unassign(string $uuid): Response
    {
        $user = $this->userRepo->findOneById($uuid);
        
        if (!$user) {
            $this->addFlash('error', 'Gamer nicht gefunden');
            return $this->redirectToRoute('admin_dogtags_index');
        }
        
        $user->setDogTagGroup(null);
        $this->idmManager->persist($user);
        $this->idmManager->flush();

        $this->addFlash('success', sprintf('Gamer %s aus Gruppe entfernt', $user->getNickname()));

        return $this->redirectToRoute('admin_dogtags_index');
    }

    #[Route(path: '/mark-done/{ticketId}', name: '_mark_done', methods: ['POST'])]
    public function markDone(int $ticketId): Response
    {
        $ticket = $this->ticketRepo->find($ticketId);
        
        if (!$ticket) {
            $this->addFlash('error', 'Ticket nicht gefunden');
            return $this->redirectToRoute('admin_dogtags_index');
        }
        
        $ticket->setDogtagDone(true);
        $this->em->flush();
        
        $this->addFlash('success', 'DogTag als produziert markiert');
        
        return $this->redirectToRoute('admin_dogtags_index');
    }

    #[Route(path: '/mark-undone/{ticketId}', name: '_mark_undone', methods: ['POST'])]
    public function markUndone(int $ticketId): Response
    {
        $ticket = $this->ticketRepo->find($ticketId);
        
        if (!$ticket) {
            $this->addFlash('error', 'Ticket nicht gefunden');
            return $this->redirectToRoute('admin_dogtags_index');
        }
        
        $ticket->setDogtagDone(false);
        $this->em->flush();
        
        $this->addFlash('success', 'DogTag als nicht produziert markiert');
        
        return $this->redirectToRoute('admin_dogtags_index');
    }

    #[Route(path: '/bulk-mark-done', name: '_bulk_mark_done', methods: ['POST'])]
    public function bulkMarkDone(Request $request): Response
    {
        $ticketIds = $request->request->all('ticketIds');
        
        if (empty($ticketIds)) {
            $this->addFlash('error', 'Keine DogTags ausgewählt');
            return $this->redirectToRoute('admin_dogtags_index');
        }
        
        $count = 0;
        foreach ($ticketIds as $ticketId) {
            $ticket = $this->ticketRepo->find($ticketId);
            if ($ticket) {
                $ticket->setDogtagDone(true);
                $count++;
            }
        }
        
        $this->em->flush();
        
        if ($count > 0) {
            $this->addFlash('success', sprintf('%d DogTag(s) als produziert markiert', $count));
        } else {
            $this->addFlash('warning', 'Keine DogTags markiert');
        }
        
        return $this->redirectToRoute('admin_dogtags_index');
    }

    #[Route(path: '/bulk-mark-undone', name: '_bulk_mark_undone', methods: ['POST'])]
    public function bulkMarkUndone(Request $request): Response
    {
        $ticketIds = $request->request->all('ticketIds');
        
        if (empty($ticketIds)) {
            $this->addFlash('error', 'Keine DogTags ausgewählt');
            return $this->redirectToRoute('admin_dogtags_index');
        }
        
        $count = 0;
        foreach ($ticketIds as $ticketId) {
            $ticket = $this->ticketRepo->find($ticketId);
            if ($ticket) {
                $ticket->setDogtagDone(false);
                $count++;
            }
        }
        
        $this->em->flush();
        
        if ($count > 0) {
            $this->addFlash('success', sprintf('%d DogTag(s) als nicht produziert zurückgesetzt', $count));
        } else {
            $this->addFlash('warning', 'Keine DogTags zurückgesetzt');
        }
        
        return $this->redirectToRoute('admin_dogtags_index');
    }

    #[Route(path: '/export-csv', name: '_export_csv')]
    public function exportCsv(): Response
    {
        // Clear any output buffers to prevent debug output from appearing in CSV
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Get all tickets
        $tickets = $this->ticketRepo->findAll();
        
        // Extract unique user UUIDs from tickets (only redeemed tickets have a redeemer UUID)
        // and map tickets by user UUID
        $uniqueUuids = [];
        $ticketsByUser = [];
        foreach ($tickets as $ticket) {
            $redeemer = $ticket->getRedeemer();
            if ($redeemer) {
                $uuidString = $redeemer->toString();
                $uniqueUuids[$uuidString] = $redeemer;
                if (!isset($ticketsByUser[$uuidString])) {
                    $ticketsByUser[$uuidString] = [];
                }
                $ticketsByUser[$uuidString][] = $ticket;
            }
        }
        
        // Fetch only users with tickets using bulk request
        $users = [];
        if (!empty($uniqueUuids)) {
            $userObjects = $this->idmManager->bulk(User::class, array_values($uniqueUuids));
            foreach ($userObjects as $user) {
                $users[$user->getUuid()->toString()] = $user;
            }
        }
        
        // Group users by their dogTagGroup
        $groups = [];
        
        foreach ($users as $uuid => $user) {
            $userTickets = $ticketsByUser[$uuid] ?? [];
            $ticket = !empty($userTickets) ? $userTickets[0] : null;
            
            $groupNumber = $user->getDogTagGroup();
            if ($groupNumber !== null) {
                if (!isset($groups[$groupNumber])) {
                    $groups[$groupNumber] = [];
                }
                $groups[$groupNumber][] = [
                    'user' => $user,
                    'hasTicket' => true,
                    'ticket' => $ticket,
                    'dogtagDone' => $ticket ? $ticket->isDogtagDone() : false,
                ];
            }
        }
        
        // Sort groups by group number
        ksort($groups);
        
        // Sort users within each group by nickname
        foreach ($groups as &$group) {
            usort($group, function($a, $b) {
                return strcasecmp($a['user']->getNickname() ?? '', $b['user']->getNickname() ?? '');
            });
        }
        
        // Generate CSV content
        $csv = [];
        
        // Header
        $csv[] = ['Group', 'Nickname', 'Clans', 'First Name', 'Last Name', 'Done'];
        
        foreach ($groups as $groupNumber => $groupUsers) {
            foreach ($groupUsers as $item) {
                $user = $item['user'];
                $dogtagDone = $item['dogtagDone'] ?? false;
                
                // Get clan tags
                $clanTags = [];
                $clans = $user->getClans();
                if ($clans) {
                    foreach ($clans as $clan) {
                        $clanTags[] = $clan->getClantag();
                    }
                }
                
                $csv[] = [
                    $groupNumber,
                    $user->getNickname() ?? '',
                    implode(', ', $clanTags),
                    $user->getFirstname() ?? '',
                    $user->getSurname() ?? '',
                    $dogtagDone ? 'X' : '',
                ];
            }
            
            // Add empty line between groups
            $csv[] = ['', '', '', '', '', ''];
        }
        
        // Create response
        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="dogtag-groups-' . date('Y-m-d') . '.csv"');
        
        // Add BOM for Excel compatibility
        $content = "\xEF\xBB\xBF";
        
        // Generate CSV content
        $handle = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $content .= stream_get_contents($handle);
        fclose($handle);
        
        $response->setContent($content);
        
        return $response;
    }
}
