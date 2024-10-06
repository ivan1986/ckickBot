<?php

namespace App\Command;

use App\Message\UpdateUrlUser;
use App\Service\BotSelector;
use App\Service\ProfileService;
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
    name: 'init:url',
    description: 'For all profiles init bot url',
)]
class InitUrlCommand extends Command
{
    #[Required] public BotSelector $botSelector;
    #[Required] public MessageBusInterface $bus;
    #[Required] public ProfileService $profileService;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->profileService->list() as $profile) {
            foreach ($this->botSelector->getAll() as $name => $bot) {
                if ($this->botSelector->isEnabled($profile, $name)) {
                    $bot->setProfile($profile);
                    if (!$bot->getUrl()) {
                        $this->bus->dispatch(new UpdateUrlUser($profile, $name));
                    }
                }
            }
        }

        return Command::SUCCESS;
    }
}
