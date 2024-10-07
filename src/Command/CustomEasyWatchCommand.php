<?php

namespace App\Command;

use App\Bots\EasyWatchBot;
use App\Service\BotSelector;
use App\Service\CacheService;
use App\Service\ProfileService;
use Carbon\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Service\Attribute\Required;

#[AsCommand(
    name: 'custom:easy-watch',
    description: 'Custom for EasyWatch Bot',
)]
class CustomEasyWatchCommand extends Command
{
    #[Required] public BotSelector $botSelector;
    #[Required] public ProfileService $profileService;
    #[Required] public CacheService $cacheService;
    public EasyWatchBot $bot;

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bot = $this->botSelector->getBot('EasyWatchBot');
        while (true) {
            if ($this->cacheService->get($this->bot->botKey('stream'))) {
                $this->activeStream();
            } else {
                sleep(60 * 10);
            }
        }
        return Command::SUCCESS;
    }

    public function activeStream()
    {
        $activeProfiles = $this->getActiveProfiles();
        /** @var Process[] $processList */
        $processList = [];
        while ($this->cacheService->get($this->bot->botKey('stream'))) {
            foreach ($activeProfiles as $profile => $cookie) {
                if (empty($processList[$profile]) || !$processList[$profile]->isRunning()) {
                    if (!empty($processList[$profile])) {
                        $output = $processList[$profile]->getOutput();
                        $stop = 'Stream has not been started';
                        if (str_contains($output, $stop)) {
                            $this->cacheService->del($this->bot->botKey('stream'));
                            continue;
                        }
                        $authError = 'User is not authorized';
                        if (str_contains($output, $authError)) {
                            echo $profile;
                            $this->bot->setProfile($profile);
                            $this->cacheService->hSet(
                                $this->bot->userKey('run'),
                                'errorAuth',
                                Carbon::now()->getTimestamp()
                            );
                            continue;
                        }
                    }
                    $processList[$profile] = $this->getProcess($cookie);
                    $processList[$profile]->start();
                }
            }
            sleep(5);
        }
        $processList = [];
    }

    /**
     * @return array
     */
    public function getActiveProfiles(): array
    {
        $activeProfiles = [];
        foreach ($this->profileService->list() as $profile) {
            if ($this->botSelector->isEnabled($profile, 'EasyWatchBot')) {
                $this->bot->setProfile($profile);
                $cookie = $this->bot->UCGet('cookies');
                if ($cookie) {
                    $activeProfiles[$profile] = $cookie;
                }
            }
        }
        return $activeProfiles;
    }

    protected function getProcess($cookiesStr)
    {
        return new Process([
            'curl',
            'https://easywatch.tech/wallet/v1/stream/income/events',
            '-H', $cookiesStr,
            '-H', 'user-agent: ' . ProfileService::UA,
            '-H', 'referer: https://easywatch.tech/stream',
            '-H', 'accept: text/event-stream',
        ], timeout: 500);
    }
}
