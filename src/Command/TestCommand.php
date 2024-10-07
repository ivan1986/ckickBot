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
use App\MessageHandler\UpdateUrlHandler;
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
    #[Required] public MessageBusInterface $bus;
    #[Required] public ProfileService $profileService;
    #[Required] public UpdateUrlHandler $handler;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $x = $this->handler;
        $x(new UpdateUrlUser('ivan', 'HuYandexBot', true));

        return Command::SUCCESS;
    }
}
