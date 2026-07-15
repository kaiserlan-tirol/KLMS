<?php

namespace App\Tests\Integration\Service;

use App\DataFixtures\SettingsFixture;
use App\DataFixtures\ShopFixture;
use App\Entity\ShopAddon;
use App\Entity\ShopOrder;
use App\Entity\ShopOrderPositionAddon;
use App\Entity\ShopOrderPositionTicket;
use App\Entity\ShopOrderStatus;
use App\Entity\Ticket;
use App\Entity\User;
use App\Exception\OrderLifecycleException;
use App\Idm\IdmManager;
use App\Service\SettingService;
use App\Service\ShopService;
use App\Service\TicketService;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Nonstandard\Uuid;

class ShopServiceIntegrationTest extends DatabaseTestCase
{
    private function getUser(int $id): ?User
    {
        $manager = self::getContainer()->get(IdmManager::class);
        $userRepo = $manager->getRepository(User::class);
        return $userRepo->findOneById(Uuid::fromInteger($id));
    }
    public function testOrderPaid()
    {
        $this->databaseTool->loadFixtures([ShopFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $ticketService = $this->getContainer()->get(TicketService::class);
        $user = $this->getUser(19);

        $this->assertCount(1, $shopService->getOrderByUser($user, ShopOrderStatus::Created));
        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Paid));
        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Canceled));

        $order = $shopService->getOrderByUser($user, ShopOrderStatus::Created)[0];
        $this->assertEquals(ShopOrderStatus::Created, $order->getStatus());
        $this->assertEmpty($ticketService->getTicketUser($user));

        $shopService->setOrderPaid($order);

        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Created));
        $this->assertCount(1, $shopService->getOrderByUser($user, ShopOrderStatus::Paid));
        $this->assertEquals(ShopOrderStatus::Paid, $order->getStatus());
        $ticket = $ticketService->getTicketUser($user);
        $this->assertNotEmpty($ticket);
        $this->assertTrue($ticket->isRedeemed());
        $this->assertFalse($ticket->isPunched());
    }

    public function testOrderCancelled()
    {
        $this->databaseTool->loadFixtures([ShopFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $ticketService = $this->getContainer()->get(TicketService::class);
        $user = $this->getUser(19);

        $this->assertCount(1, $shopService->getOrderByUser($user, ShopOrderStatus::Created));
        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Paid));
        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Canceled));

        $order = $shopService->getOrderByUser($user, ShopOrderStatus::Created)[0];
        $this->assertEquals(ShopOrderStatus::Created, $order->getStatus());
        $this->assertEmpty($ticketService->getTicketUser($user));

        $shopService->cancelOrder($order);

        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Created));
        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Paid));
        $this->assertCount(1, $shopService->getOrderByUser($user, ShopOrderStatus::Canceled));
        $this->assertEquals(ShopOrderStatus::Canceled, $order->getStatus());
        $this->assertEmpty($ticketService->getTicketUser($user));
    }

    public function testOrderRefund()
    {
        $this->databaseTool->loadFixtures([ShopFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $ticketService = $this->getContainer()->get(TicketService::class);
        $user = $this->getUser(13);

        // first unassign ticket
        $order = $shopService->getOrderByUser($user, ShopOrderStatus::Paid)[0];
        $this->assertTrue($order->getStatus()->isActive());
        $this->assertTrue($order->countRedeemedTickets() > 0);
        foreach ($order->getShopOrderPositions() as $pos){
            if ($pos instanceof ShopOrderPositionTicket){
                $this->assertNotEmpty($pos->getTicket());
                $ticketService->unassignTicket($pos->getTicket());
            }
        }
        $this->assertTrue($order->countRedeemedTickets() == 0);

        // then refund order
        $shopService->refundOrder($order);
        $this->assertTrue($order->getStatus()->isDead());
    }

    public function testOrderRefundUsedTicket()
    {
        $this->databaseTool->loadFixtures([ShopFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(13);

        $order = $shopService->getOrderByUser($user, ShopOrderStatus::Paid)[0];
        $this->assertTrue($order->countRedeemedTickets() > 0);
        $this->expectException(OrderLifecycleException::class);
        $shopService->refundOrder($order);
    }

    public function testCreateEmptyOrder()
    {
        $this->databaseTool->loadFixtures([ShopFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(3);

        $this->assertCount(0, $shopService->getOrderByUser($user));

        $order = $shopService->allocOrder($user);
        $this->expectException(OrderLifecycleException::class);
        // can't save empty order
        $shopService->placeOrder($order);
    }

    private function setValue(string $key, ?int $value): void
    {
        $settingService = $this->getContainer()->get(SettingService::class);
        if (is_null($value)) {
            $settingService->remove($key);
        } else {
            $settingService->set($key, strval($value));
        }
    }

    private function getPriceData(): array
    {
        return [
            [1, 1234, 400, 9,     1234],
            [2, 1234, 400, 9, 2 * 1234],
            [3, 1234, 400, 3, 3 * 400],
            [9, 1234, 200, 3, 9 * 200],
            [1, 1234, 0, null, 1234],
            [5, 1234, 0, null, 5 * 1234],
            [5, 1234, null, 3, 5 * 1234],
            [1, null, 0, null,     ShopService::DEFAULT_TICKET_PRICE],
            [5, null, 0, null, 5 * ShopService::DEFAULT_TICKET_PRICE],
        ];
    }

    /**
     * @dataProvider getPriceData
     */
    public function testCreateOrderTicket(int $count, ?int $price, ?int $discountPrice, ?int $discountLimit, int $expectedTotal)
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(3);

        $this->setValue('lan.signup.price', $price);
        $this->setValue('lan.signup.discount.price', $discountPrice);
        $this->setValue('lan.signup.discount.limit', $discountLimit);

        $this->assertCount(0, $shopService->getOrderByUser($user));

        $order = $shopService->allocOrder($user);
        $shopService->orderAddTickets($order, $count);
        $shopService->placeOrder($order);

        $this->assertEquals($expectedTotal, $order->calculateTotal());
        $this->assertCount($count, $order->getShopOrderPositions());
        $this->assertCount(1, $shopService->getOrderByUser($user));
        $this->assertCount(1, $shopService->getOrderByUser($user, ShopOrderStatus::Created));
    }

    public function testAddonBuyDisabled(): void
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(8);

        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Created));
        $addonsAll = $shopService->getAddons(true);
        $addons = $shopService->getAddons(false);
        $this->assertCount(4, $addonsAll);
        $this->assertCount(3, $addons);

        // get an addon that is disabled
        $addon = array_values(array_udiff($addonsAll, $addons, fn($a, $b) => $a->getId() - $b->getId()));
        $this->assertCount(1, $addon);
        $addon = $addon[0];
        $this->assertEquals("VIP Seat", $addon->getName());
        $this->assertFalse($addon->isActive());

        // try to buy it anyway
        $order = $shopService->allocOrder($user);
        $shopService->orderAddAddon($order, $addon, 1);

        $this->expectException(OrderLifecycleException::class);
        $shopService->placeOrder($order);
    }

    public function testAddonBuyOnlyOnce(): void
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(8);

        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Created));
        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Paid));
        $addons = array_values(array_filter($shopService->getAddons(), fn($a) => $a->getOnlyOnce() == true));
        $this->assertNotEmpty($addons);
        $addon = $addons[0];

        $order = $shopService->allocOrder($user);
        $shopService->orderAddAddon($order, $addon, 1);
        $this->assertEquals("Own Chair", $addon->getName());
        $shopService->placeOrder($order);
        $this->assertEquals(0, $order->calculateTotal());
        $this->assertEquals(ShopOrderStatus::Paid, $order->getStatus());
        $this->assertCount(1, $shopService->getOrderByUser($user, ShopOrderStatus::Paid));
    }

    public function testAddonBuyOnlyOnceTwice(): void
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(8);

        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Created));
        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Paid));
        $addons = array_values(array_filter($shopService->getAddons(), fn($a) => $a->getOnlyOnce() == true));
        $this->assertNotEmpty($addons);
        $addon = $addons[0];

        $order = $shopService->allocOrder($user);
        $shopService->orderAddAddon($order, $addon, 2);
        $this->assertEquals("Own Chair", $addon->getName());
        $this->expectException(OrderLifecycleException::class);
        $shopService->placeOrder($order);
    }

    public function testAddonBuyOnlyOnceAgain(): void
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(8);

        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Created));
        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Paid));
        $addons = array_values(array_filter($shopService->getAddons(), fn($a) => $a->getOnlyOnce() == true));
        $this->assertNotEmpty($addons);
        $addon = $addons[0];

        $order = $shopService->allocOrder($user);
        $shopService->orderAddAddon($order, $addon, 1);
        $shopService->placeOrder($order);
        $this->assertEquals(0, $order->calculateTotal());
        $this->assertEquals(ShopOrderStatus::Paid, $order->getStatus());

        $this->assertCount(1, $shopService->getOrderByUser($user, ShopOrderStatus::Paid));

        // try to buy it again
        $order = $shopService->allocOrder($user);
        $shopService->orderAddAddon($order, $addon, 1);
        $this->expectException(OrderLifecycleException::class);
        $shopService->placeOrder($order);
    }

    public function testAddonBuyWithGlobalLimit(): void
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(8);

        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Created));
        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Paid));
        $addons = array_values(array_filter($shopService->getAddons(), fn($a) => !is_null($a->getMaxQuantityGlobal())));
        $this->assertNotEmpty($addons);
        $addon = $addons[0];
        $this->assertEquals(4, $addon->getMaxQuantityGlobal());
        $this->assertEquals(2, $shopService->countOrderedAddon($addon));

        // let's order one more
        $order = $shopService->allocOrder($user);
        $shopService->orderAddAddon($order, $addon, 1);
        $shopService->placeOrder($order);
        $this->assertEquals(3, $shopService->countOrderedAddon($addon));
        $this->assertEquals(ShopOrderStatus::Created, $order->getStatus());

        // let's pay the order
        $shopService->setOrderPaid($order);
        $this->assertEquals(ShopOrderStatus::Paid, $order->getStatus());
        $this->assertEquals(3, $shopService->countOrderedAddon($addon));

        // try to buy one more (reach the max)
        $order = $shopService->allocOrder($user);
        $shopService->orderAddAddon($order, $addon, 1);
        $shopService->placeOrder($order);
        $this->assertEquals(4, $shopService->countOrderedAddon($addon));
        $this->assertEquals(ShopOrderStatus::Created, $order->getStatus());

        $shopService->cancelOrder($order);
        $this->assertEquals(3, $shopService->countOrderedAddon($addon));
    }

    public function testAddonBuyWithExceedingGlobalLimit(): void
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(8);

        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Created));
        $this->assertCount(0, $shopService->getOrderByUser($user, ShopOrderStatus::Paid));
        $addons = array_values(array_filter($shopService->getAddons(), fn($a) => !is_null($a->getMaxQuantityGlobal())));
        $this->assertNotEmpty($addons);
        $addon = $addons[0];
        $this->assertEquals(4, $addon->getMaxQuantityGlobal());
        $this->assertEquals(2, $shopService->countOrderedAddon($addon));

        // exceed the maximum
        $order = $shopService->allocOrder($user);
        $shopService->orderAddAddon($order, $addon, 3);
        $this->expectException(OrderLifecycleException::class);
        $shopService->placeOrder($order);
    }

    /**
     * "Erstbesuch U18" setup: zeroes the ticket price and "Unter 18" explicitly;
     * "Tagespass" is intentionally NOT listed to exercise the negative-price floor rule.
     *
     * @return ShopAddon[] [u18, daypass, foodflat, firstVisit]
     */
    private function createZeroRuleAddons(): array
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);
        $u18 = (new ShopAddon())->setName('Unter 18')->setPrice(-1000)->setOnlyOnce(false)->setActive(true)->setOnePerTicket(true);
        $daypass = (new ShopAddon())->setName('Tagespass')->setPrice(-1000)->setOnlyOnce(false)->setActive(true)->setOnePerTicket(true);
        $foodflat = (new ShopAddon())->setName('Foodflat')->setPrice(1800)->setOnlyOnce(false)->setActive(true)->setOnePerTicket(true);
        $firstVisit = (new ShopAddon())->setName('Erstbesuch U18')->setPrice(0)->setOnlyOnce(false)->setActive(true)->setOnePerTicket(true)
            ->setZerosTicketPrice(true)
            ->addZerosAddon($u18)
            ->addRequiresAddon($u18);
        foreach ([$u18, $daypass, $foodflat, $firstVisit] as $addon) {
            $em->persist($addon);
        }
        $em->flush();
        return [$u18, $daypass, $foodflat, $firstVisit];
    }

    private function ticketPositions(ShopOrder $order): array
    {
        return array_values(array_filter(
            $order->getShopOrderPositions()->toArray(),
            fn($pos) => $pos instanceof ShopOrderPositionTicket
        ));
    }

    private function addonPositionFor(ShopOrder $order, ShopAddon $addon): ?ShopOrderPositionAddon
    {
        foreach ($order->getShopOrderPositions() as $pos) {
            if ($pos instanceof ShopOrderPositionAddon && $pos->getAddon() && $pos->getAddon()->getId() === $addon->getId()) {
                return $pos;
            }
        }
        return null;
    }

    public function testZeroRuleZerosTicketAndTargets(): void
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(3);

        $this->setValue('lan.signup.price', 4000);
        $this->setValue('lan.signup.discount.price', null);
        $this->setValue('lan.signup.discount.limit', null);
        [$u18, $daypass, $foodflat, $firstVisit] = $this->createZeroRuleAddons();

        $order = $shopService->allocOrder($user);
        $shopService->orderAddTickets($order, 1);
        $shopService->processTicketAddons($order, [0 => [
            "addon{$firstVisit->getId()}" => 1,
            "addon{$u18->getId()}" => 1,
            "addon{$daypass->getId()}" => 1,
            "addon{$foodflat->getId()}" => 1,
        ]]);

        // only the foodflat remains payable
        $this->assertEquals(1800, $order->calculateTotal());

        $ticket = $this->ticketPositions($order)[0];
        $this->assertEquals(0, $ticket->getPrice());
        $this->assertStringContainsString('gratis: Erstbesuch U18', $ticket->getText());

        // explicitly listed target
        $u18Pos = $this->addonPositionFor($order, $u18);
        $this->assertEquals(0, $u18Pos->getPrice());
        $this->assertEquals('Unter 18 (gratis: Erstbesuch U18)', $u18Pos->getText());

        // not listed, but negative price -> floor rule zeroes it as well
        $daypassPos = $this->addonPositionFor($order, $daypass);
        $this->assertEquals(0, $daypassPos->getPrice());

        // excluded addon keeps its price
        $foodflatPos = $this->addonPositionFor($order, $foodflat);
        $this->assertEquals(1800, $foodflatPos->getPrice());
        $this->assertEquals('Foodflat', $foodflatPos->getText());

        $shopService->placeOrder($order);
        $this->assertEquals(ShopOrderStatus::Created, $order->getStatus());
    }

    public function testZeroRuleWithoutTriggerKeepsPrices(): void
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(3);

        $this->setValue('lan.signup.price', 4000);
        $this->setValue('lan.signup.discount.price', null);
        $this->setValue('lan.signup.discount.limit', null);
        [$u18, , $foodflat, ] = $this->createZeroRuleAddons();

        $order = $shopService->allocOrder($user);
        $shopService->orderAddTickets($order, 1);
        $shopService->processTicketAddons($order, [0 => [
            "addon{$u18->getId()}" => 1,
            "addon{$foodflat->getId()}" => 1,
        ]]);

        $this->assertEquals(4000 - 1000 + 1800, $order->calculateTotal());
    }

    public function testZeroRuleKeepsGroupDiscountForOtherTickets(): void
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(3);

        $this->setValue('lan.signup.price', 4000);
        $this->setValue('lan.signup.discount.price', 3000);
        $this->setValue('lan.signup.discount.limit', 3);
        [$u18, , , $firstVisit] = $this->createZeroRuleAddons();

        $order = $shopService->allocOrder($user);
        $shopService->orderAddTickets($order, 3);
        $shopService->processTicketAddons($order, [0 => [
            "addon{$firstVisit->getId()}" => 1,
            "addon{$u18->getId()}" => 1,
        ]]);

        // the free ticket still counts towards the discount limit
        $tickets = $this->ticketPositions($order);
        $this->assertEquals(0, $tickets[0]->getPrice());
        $this->assertEquals(3000, $tickets[1]->getPrice());
        $this->assertEquals(3000, $tickets[2]->getPrice());
        $this->assertEquals(6000, $order->calculateTotal());
    }

    public function testZeroRuleOnlyAffectsOwnTicket(): void
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(3);

        $this->setValue('lan.signup.price', 4000);
        $this->setValue('lan.signup.discount.price', null);
        $this->setValue('lan.signup.discount.limit', null);
        [$u18, , , $firstVisit] = $this->createZeroRuleAddons();

        $order = $shopService->allocOrder($user);
        $shopService->orderAddTickets($order, 2);
        $shopService->processTicketAddons($order, [
            0 => ["addon{$firstVisit->getId()}" => 1, "addon{$u18->getId()}" => 1],
            1 => ["addon{$u18->getId()}" => 1],
        ]);

        $tickets = $this->ticketPositions($order);
        $this->assertEquals(0, $tickets[0]->getPrice());
        $this->assertEquals(4000, $tickets[1]->getPrice());
        // the second ticket's "Unter 18" keeps its discount price
        $this->assertEquals(0 + 4000 - 1000, $order->calculateTotal());
    }

    public function testZeroRuleAppliesInAdminAddAddonFlow(): void
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $user = $this->getUser(3);

        $this->setValue('lan.signup.price', 4000);
        $this->setValue('lan.signup.discount.price', null);
        $this->setValue('lan.signup.discount.limit', null);
        [$u18, $daypass, , $firstVisit] = $this->createZeroRuleAddons();

        // free order -> auto-paid on placement
        $order = $shopService->allocOrder($user);
        $shopService->orderAddTickets($order, 1);
        $shopService->processTicketAddons($order, [0 => [
            "addon{$firstVisit->getId()}" => 1,
            "addon{$u18->getId()}" => 1,
        ]]);
        $shopService->placeOrder($order);
        $this->assertEquals(0, $order->calculateTotal());
        $this->assertEquals(ShopOrderStatus::Paid, $order->getStatus());

        // admin adds a day pass afterwards: zero-rule must apply, no bogus credit
        $shopService->addAddonToOrder($order, $daypass, 1);
        $daypassPos = $this->addonPositionFor($order, $daypass);
        $this->assertNotNull($daypassPos);
        $this->assertEquals(0, $daypassPos->getPrice());
        $this->assertEquals(0, $order->calculateTotal());
    }

    public function testZeroRuleAppliesInCateringBalanceFlow(): void
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        $em = $this->getContainer()->get(EntityManagerInterface::class);
        [, $daypass, , $firstVisit] = $this->createZeroRuleAddons();

        $ticket = (new Ticket())->setCode('CODE1-KRRUG-ZZZZZ')->setCreatedAt(new DateTimeImmutable());
        $ticketPos = (new ShopOrderPositionTicket())->setTicket($ticket)->setPrice(0);
        $order = (new ShopOrder())
            ->setOrderer(Uuid::fromInteger(strval(3)))
            ->setCreatedAt(new DateTimeImmutable())
            ->setStatus(ShopOrderStatus::Paid)
            ->addShopOrderPosition($ticketPos);
        $firstVisitPos = (new ShopOrderPositionAddon())->fillWithAddon($firstVisit, $ticketPos);
        $ticketPos->addAddon($firstVisitPos);
        $order->addShopOrderPosition($firstVisitPos);
        $em->persist($ticket);
        $em->persist($order);
        $em->flush();

        $shopService->addAddonToTicketWithCateringBalance($ticket, $daypass);

        $daypassPos = $this->addonPositionFor($order, $daypass);
        $this->assertNotNull($daypassPos);
        $this->assertEquals(0, $daypassPos->getPrice());
        $this->assertStringContainsString('gratis: Erstbesuch U18', $daypassPos->getText());
        $this->assertEquals(0, $order->calculateTotal());
    }

    public function testGetAddonConfigWarnings(): void
    {
        $this->databaseTool->loadFixtures([ShopFixture::class, SettingsFixture::class]);
        $shopService = $this->getContainer()->get(ShopService::class);
        [$u18, $daypass, $foodflat, $firstVisit] = $this->createZeroRuleAddons();

        // daypass is negative but not listed -> warning naming it, but not the others
        $warnings = $shopService->getAddonConfigWarnings($firstVisit);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Tagespass', $warnings[0]);
        $this->assertStringNotContainsString('Unter 18', $warnings[0]);
        $this->assertStringNotContainsString('Foodflat', $warnings[0]);

        // non-trigger addons don't warn
        $this->assertEmpty($shopService->getAddonConfigWarnings($foodflat));
        $this->assertEmpty($shopService->getAddonConfigWarnings($u18));
        $this->assertEmpty($shopService->getAddonConfigWarnings($daypass));
    }
}