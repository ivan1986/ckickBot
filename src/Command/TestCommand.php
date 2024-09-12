<?php

namespace App\Command;

use App\Bots\FactoraBot;
use App\Service\BotSelector;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;

#[AsCommand(
    name: 'test:test',
    description: 'Add a short description for your command',
)]
class TestCommand extends Command
{
    #[Required] public BotSelector $botSelector;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->botSelector->getBot('FactoraBot')->topUpEnergy();

        return Command::SUCCESS;
    }
}
