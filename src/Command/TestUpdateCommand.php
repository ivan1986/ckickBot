<?php

namespace App\Command;

use App\Message\UpdateUrlUser;
use App\MessageHandler\UpdateUrlHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;

#[AsCommand(
    name: 'test:update',
    description: 'Run UpdateUrl for debug',
)]
class TestUpdateCommand extends Command
{
    #[Required] public UpdateUrlHandler $handler;

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::REQUIRED, 'Profile')
            ->addArgument('bot', InputArgument::REQUIRED, 'Bot')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ($this->handler)(new UpdateUrlUser($input->getArgument('profile'), $input->getArgument('bot'), true));

        return Command::SUCCESS;
    }
}
