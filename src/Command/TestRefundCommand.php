<?php

namespace App\Command;

use App\Repository\CateringOrderRepository;
use App\Service\CateringService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-refund',
    description: 'Test the refund functionality for a catering order'
)]
class TestRefundCommand extends Command
{
    public function __construct(
        private readonly CateringService $cateringService,
        private readonly CateringOrderRepository $orderRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('order-id', InputArgument::REQUIRED, 'The ID of the order to refund')
            ->setHelp('This command tests the refund functionality by refunding a specific order.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $orderId = $input->getArgument('order-id');

        try {
            // Load the order
            $order = $this->orderRepository->find($orderId);
            if (!$order) {
                $io->error("Order with ID {$orderId} not found.");
                return Command::FAILURE;
            }

            $io->info("Testing refund for order #{$order->getId()}");
            $io->text("Current status: {$order->getStatus()->name}");
            $io->text("Order total: {$order->calculateTotal()} cents");
            $io->text("Orderer: {$order->getOrderer()->toString()}");

            // Check current credit before refund
            $currentCredit = $this->cateringService->getUserCredit($order->getOrderer());
            $io->text("Current user credit: {$currentCredit} cents");

            // Perform the refund
            $io->text("Performing refund...");
            $this->cateringService->refundOrder($order);

            // Check credit after refund
            $newCredit = $this->cateringService->getUserCredit($order->getOrderer());
            $io->text("New user credit: {$newCredit} cents");
            $io->text("Credit difference: " . ($newCredit - $currentCredit) . " cents");

            $io->success("Refund completed successfully!");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error("Error during refund: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
