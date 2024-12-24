<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;

class TgAppCenterBot extends BaseBot implements BotInterface
{
    const API_ENDPOINT = 'https://tappscenter.org/';

    public function getTgBotName() { return 'tapps_bot'; }

    public function saveUrl($client, $url)
    {
        $this->platformFix($url);
        parent::saveUrl($client, $url);
    }


    #[ScheduleCallback('8 hour', delta: 3600)]
    public function dailyApp()
    {
        if (!$this->getUrl()) {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile, false);
        $client->request('GET', $this->getUrl());
        sleep(2);

        sleep(200);
    }

}
