<?php

namespace App\Command;

use App\Bots\FactoraBot;
use App\Message\UpdateUrl;
use App\Service\BotSelector;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\Attribute\Required;

#[AsCommand(
    name: 'test:test',
    description: 'Add a short description for your command',
)]
class TestCommand extends Command
{
    #[Required] public BotSelector $botSelector;
    #[Required] public MessageBusInterface $bus;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //$this->botSelector->getBot('FactoraBot')->topUpEnergy();
        //$this->bus->dispatch(new UpdateUrl('DogiatorsBot', '/k/#@Dogiators_bot', true));
        //$this->bus->dispatch(new UpdateUrl('OneWinBot', '/k/#@token1win_bot', true));
        //$this->bus->dispatch(new UpdateUrl('TonMusicBot', '/k/#@tonmusic_game_bot'));
        //$this->botSelector->getBot('TonMusicBot')->checkSlots();
        //$this->botSelector->getBot('OneWinBot')->update();
        //$this->botSelector->getBot('DogiatorsBot')->passiveIncome();
        $this->botSelector->getBot('DogiatorsBot')->update();

        return Command::SUCCESS;
    }
}
