<?php

namespace App\Command;

use App\Bots\CatsDogsBot;
use App\Bots\DogiatorsBot;
use App\Bots\FactoraBot;
use App\Bots\OneWinBot;
use App\Bots\WeMineBot;
use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Message\UpdateUrlUser;
use App\Service\BotSelector;
use App\Service\CacheService;
use App\Service\ProfileService;
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
    #[Required] public ProfileService $profileService;
    #[Required] public CacheService $cacheService;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
//        $bot = $this->botSelector->getBot('CatsDogsBot');
//        $bot->setProfile('ivan');
//        /** @var CatsDogsBot $bot */
//        $bot->claim();
//        $x = new CustomFunction('EasyWatchBot', 'checkStream');
//        $x = new UpdateUrl('EasyWatchBot', 'checkStream');

//        $this->bus->dispatch(new UpdateUrlUser('olya', 'EasyWatchBot', '/k/#@ESWatch_bot', true));
//        $this->bus->dispatch(new UpdateUrlUser('olya', 'WeMineBot', '/k/#@WeMineBot', true));


        //$this->botSelector->getBot('FactoraBot')->topUpEnergy//();
        //$this->bus->dispatch(new UpdateUrl('CatsDogsBot', '/k/#@catsdogs_game_bot'));
        //$this->bus->dispatch(new UpdateUrl('CityHolderBot', '/k/#@cityholder', true));
        //$this->bus->dispatch(new UpdateUrl('OneWinBot', '/k/#@token1win_bot', true));
        //$this->bus->dispatch(new UpdateUrl('TonMusicBot', '/k/#@tonmusic_game_bot'));
        //$this->bus->dispatch(new UpdateUrl('WeMineBot', '/k/#@WeMineBot'));
        //$this->botSelector->getBot('TonMusicBot')->checkSlots;
        //$this->botSelector->getBot('OneWinBot')->update();
        //$this->botSelector->getBot('CityHolderBot')->update();
        //$this->botSelector->getBot('WeMineBot')->claimAndReset();
        //$this->botSelector->getBot('CatsDogsBot')->claim();
        //$this->botSelector->getBot('DogiatorsBot')->dailyIncome();


        return Command::SUCCESS;
    }
}
