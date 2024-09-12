<?php

namespace App\Scheduler;

use App\Message\UpdateUrl;
use App\Service\BotSelector;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\Attribute\Required;

#[AsSchedule('default')]
final class MainSchedule implements ScheduleProviderInterface
{
    #[Required] public CacheInterface $cache;
    #[Required] public BotSelector $botSelector;

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();
        $schedule->stateful($this->cache);

        foreach ($this->botSelector->getAll() as $bot) {
            $bot->addSchedule($schedule);
        }

        return $schedule;
    }
}
