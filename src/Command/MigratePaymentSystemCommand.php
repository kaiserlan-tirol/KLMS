<?php

namespace App\Command;

use App\Service\PaymentMigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;

#[AsCommand(
    name: 'app:migrate-payment-system',
    description: 'Migrate from legacy payment tables to unified transaction system'
)]
class MigratePaymentSystemCommand extends Command
{
    public function __construct(
        private PaymentMigrationService $migrationService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Show what would be migrated without actually doing it')
            ->addOption('report-only', 'r', InputOption::VALUE_NONE, 'Only generate migration report')
            ->setHelp('
This command migrates data from the legacy payment tables to the new unified transaction system:
- incoming_payment → user_transaction
- catering_credit_transaction → user_transaction
- user_catering_credit → user_balance (recalculated)

WARNING: This will truncate the user_transaction and user_balance tables!
');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Payment System Migration');

        if ($input->getOption('report-only')) {
            return $this->generateReport($io);
        }

        if ($input->getOption('dry-run')) {
            return $this->dryRun($io);
        }

        // Confirm migration
        $io->warning([
            'This migration will:',
            '1. Truncate user_transaction and user_balance tables',
            '2. Migrate all data from legacy payment tables',
            '3. Recalculate user balances from transactions',
            '',
            'Make sure you have a database backup!'
        ]);

        if (!$io->confirm('Do you want to proceed with the migration?', false)) {
            $io->info('Migration cancelled.');
            return Command::SUCCESS;
        }

        $io->section('Starting Migration');

        try {
            $stats = $this->migrationService->migrateFromLegacyTables();
            
            $io->success('Migration completed successfully!');
            
            $table = new Table($output);
            $table->setHeaders(['Category', 'Migrated Count']);
            $table->addRows([
                ['Incoming Payments', $stats['incoming_payments']],
                ['Credit Transactions', $stats['credit_transactions']],
                ['User Balances', $stats['user_balances']]
            ]);
            $table->render();

            if (!empty($stats['errors'])) {
                $io->warning('Some records had errors during migration:');
                foreach ($stats['errors'] as $error) {
                    $io->writeln("- {$error}");
                }
            }

            $io->section('Generating Post-Migration Report');
            $this->generateReport($io);

        } catch (\Exception $e) {
            $io->error([
                'Migration failed!',
                'Error: ' . $e->getMessage()
            ]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function generateReport(SymfonyStyle $io): int
    {
        $io->section('Migration Report');

        try {
            $report = $this->migrationService->generateMigrationReport();

            // Legacy table counts
            $io->writeln('<info>Legacy Table Counts:</info>');
            $legacyTable = new Table($io);
            $legacyTable->setHeaders(['Table', 'Count']);
            foreach ($report['legacy_counts'] as $table => $count) {
                $legacyTable->addRow([$table, number_format($count)]);
            }
            $legacyTable->render();

            // Unified table counts
            $io->writeln('<info>Unified Table Counts:</info>');
            $unifiedTable = new Table($io);
            $unifiedTable->setHeaders(['Table', 'Count']);
            foreach ($report['unified_counts'] as $table => $count) {
                $unifiedTable->addRow([$table, number_format($count)]);
            }
            $unifiedTable->render();

            // Amount comparison
            if (isset($report['amount_comparison']['error'])) {
                $io->warning('Could not compare amounts: ' . $report['amount_comparison']['error']);
            } else {
                $io->writeln('<info>Amount Comparison:</info>');
                $amountTable = new Table($io);
                $amountTable->setHeaders(['Category', 'Amount (cents)']);
                $comparison = $report['amount_comparison'];
                $amountTable->addRows([
                    ['Legacy Incoming Payments', number_format($comparison['legacy_incoming_total'])],
                    ['Legacy Transactions', number_format($comparison['legacy_transactions_total'])],
                    ['Unified Total', number_format($comparison['unified_total'])],
                    ['Difference', number_format($comparison['difference'])]
                ]);
                $amountTable->render();

                if ($comparison['difference'] !== 0) {
                    $io->warning("Amount difference detected: {$comparison['difference']} cents");
                } else {
                    $io->success('Amounts match perfectly!');
                }
            }

        } catch (\Exception $e) {
            $io->error('Failed to generate report: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function dryRun(SymfonyStyle $io): int
    {
        $io->section('Dry Run - What would be migrated');

        try {
            $report = $this->migrationService->generateMigrationReport();

            $io->writeln('<info>Records that would be migrated:</info>');
            $table = new Table($io);
            $table->setHeaders(['Source Table', 'Record Count', 'Target']);
            $table->addRows([
                ['incoming_payment', number_format($report['legacy_counts']['incoming_payment']), 'user_transaction'],
                ['catering_credit_transaction', number_format($report['legacy_counts']['catering_credit_transaction']), 'user_transaction'],
                ['user_catering_credit', number_format($report['legacy_counts']['user_catering_credit']), 'user_balance (recalculated)']
            ]);
            $table->render();

            $totalRecords = array_sum($report['legacy_counts']);
            $io->info("Total records to migrate: " . number_format($totalRecords));

            $io->note('Run without --dry-run to perform the actual migration.');

        } catch (\Exception $e) {
            $io->error('Failed to analyze tables: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
