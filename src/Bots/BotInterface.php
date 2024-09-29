<?php

namespace App\Bots;

use App\Message\UpdateUrl;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Scheduler\Schedule;

#[AutoconfigureTag('bot')]
interface BotInterface
{
    public function addSchedule(Schedule $schedule);
    public function setProfile(string $profile);
    public function runInTg($client);
    public function saveUrl($client, $url);

}
