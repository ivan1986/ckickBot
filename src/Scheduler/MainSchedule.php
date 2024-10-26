<?php

namespace App\Scheduler;

use App\Attributes\ScheduleCallback;
use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Service\BotSelector;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
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
            // Default update url task
            $schedule->add(RecurringMessage::every(
                '12 hour',
                new UpdateUrl($bot->getName()))->withJitter(7200)
            );

            $r = new \ReflectionClass($bot);
            foreach ($r->getMethods() as $method) {
                $attrs = $method->getAttributes(ScheduleCallback::class);
                if (!$attrs) {
                    continue;
                }
                $attribute = $attrs[0];
                $class = new \ReflectionClass(ScheduleCallback::class);
                $info = $class->newInstanceArgs($attribute->getArguments());
                $schedule->add(RecurringMessage::every($info->frequency, new CustomFunction($bot->getName(), $method->getShortName())));
            }

            $bot->addSchedule($schedule);
        }

        return $schedule;
    }
}
