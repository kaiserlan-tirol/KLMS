<?php

namespace App\Command;

use App\Entity\UserBalance;
use App\Entity\User;
use App\Helper\EmailRecipient;
use App\Idm\IdmManager;
use App\Repository\UserBalanceRepository;
use App\Service\EmailService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:send-catering-negative-balance-reminders', description: 'Send reminder emails to users with negative catering balance')]
class SendNegativeCateringBalanceRemindersCommand extends Command
{
    public function __construct(
        private readonly UserBalanceRepository $balanceRepository,
        private readonly IdmManager $idmManager,
        private readonly EmailService $emailService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('min', null, InputOption::VALUE_REQUIRED, 'Minimum negative balance (in cents) to include', '1');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list users, do not send emails');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of emails to send', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $min = (int) $input->getOption('min');
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');

        $balances = $this->balanceRepository->findUsersWithNegativeCateringBalance();
        $countSent = 0;

        foreach ($balances as $balance) {
            if (!$balance instanceof UserBalance) { continue; }
            $cateringBalance = $balance->getCateringBalance();
            if ($cateringBalance >= 0) { continue; }
            if (abs($cateringBalance) < $min) { continue; }
            if ($limit > 0 && $countSent >= $limit) { break; }

            $userRepo = $this->idmManager->getRepository(User::class);
            $user = $userRepo->findOneBy(['uuid' => $balance->getUser()]);
            if (!$user instanceof User) {
                $io->warning('Could not load user for UUID '.$balance->getUser()->toString());
                continue;
            }
            $recipient = EmailRecipient::fromUser($user);
            if ($recipient === null) {
                $io->warning('Skipping user without valid email '.$user->getUuid()?->toString());
                continue;
            }
            if ($dryRun) {
                $io->text(sprintf('[DRY] Would send reminder to %s (%s) balance=%s', $recipient->getEmailAddress(), $recipient->getNickname(), $cateringBalance));
                continue;
            }
            $ok = $this->emailService->scheduleHook(EmailService::APP_HOOK_CATERING_NEGATIVE, $recipient, [
                'user' => [
                    'firstname' => $user->getFirstname(),
                    'nickname' => $user->getNickname(),
                ],
                'balance' => $cateringBalance,
            ]);
            if ($ok) {
                $countSent++;
                $io->success(sprintf('Queued reminder for %s (%s) balance=%s', $recipient->getEmailAddress(), $recipient->getNickname(), $cateringBalance));
            } else {
                $io->error('Failed scheduling email for '.$recipient->getEmailAddress());
            }
        }

        $io->success(sprintf('Completed. Reminders queued: %d', $countSent));

        return Command::SUCCESS;
    }
}
