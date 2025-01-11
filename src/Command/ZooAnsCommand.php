<?php

namespace App\Command;

use App\Service\BotSelector;
use Carbon\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;

#[AsCommand(
    name: 'zoo:ans',
    description: 'Set today answer',
)]
class ZooAnsCommand extends Command
{
    #[Required] public BotSelector $botSelector;

    protected function configure(): void
    {
        $this
            ->addOption('rebus', null, InputOption::VALUE_REQUIRED, 'Rebus answer')
            ->addOption('question', null, InputOption::VALUE_REQUIRED, 'Question answer')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bot = $this->botSelector->getBot('ZooBot');

        $today = Carbon::today()->format('Y-m-d');

        $rebus = $bot->botKey('rebus-'.$today);
        $task = $bot->botKey('task-'.$today);

        $rebusAns = $input->getOption('rebus');
        $taskAns = $input->getOption('question');

        if ($rebusAns) {
            $bot->cache->set($rebus, $rebusAns);
        }
        if ($taskAns) {
            $bot->cache->set($task, $taskAns);
        }

        return Command::SUCCESS;
    }
}
