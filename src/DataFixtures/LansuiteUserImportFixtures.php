<?php

namespace App\DataFixtures;

use DateTime;
use App\Entity\User;
use App\Entity\UserAdmin;
use App\Entity\UserGamer;
use App\Helper\LansuiteImporter;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;

class LansuiteUserImportFixtures extends Fixture implements FixtureGroupInterface
{
    // -------- Usage -------- //

    // php bin/console doctrine:fixtures:load --group=lansuite --append
    private readonly IdmRepository $userRepo;

    public function __construct(IdmManager $manager)
    {
        $this->userRepo = $manager->getRepository(User::class);
    }

    public static function getGroups(): array
    {
        return ['lansuite'];
    }

    public function load(ObjectManager $manager): void
    {
        $filteredUsers = LansuiteImporter::getFilteredUsersFromExports(dirname(__DIR__) . '/../../exports-for-migration/');
        
        foreach ($filteredUsers as $id => $d) {
            if ($d['username'] == "supl1an") {
                continue;
            }
            $userExists = $this->userRepo->findOneBy(['email' => $d['email']]);
            if (!$userExists) {
                $userExists = $this->userRepo->findOneBy(['nickname' => $d['username']]);
                if (!$userExists) {
                    echo "User with nickname {$d['username']} ({$d['email']}) does not exist, skipping\n";
                    continue;
                }
            }
        
            $uuid = Uuid::fromInteger(12300 . strval($id));
            $isAdmin = $d['type'] == 3;
            if ($isAdmin) {
                // lansuite type 3 = admin
                $admin = new UserAdmin($uuid);
                $admin->setPermissions(['ADMIN_SUPER']);
                $this->setReference('user-ls-'.$d['userid'], $admin);
                $manager->persist($admin);
            } else {
                $user = new UserGamer($uuid);
                $this->setReference('user-ls-'.$d['userid'], $user);
                $manager->persist($user);
            }
            echo "Created user {$d['username']} ({$d['email']})\n";
            $manager->flush();
        }

    }
}
