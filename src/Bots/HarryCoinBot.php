<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class HarryCoinBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'harry_coin_bot'; }

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName()))->withJitter(7200));
        $schedule->add(RecurringMessage::every('1 hour', new CustomFunction($this->getName(), 'resetMine')));
    }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);
        parent::saveUrl($client, $url);
    }

    public function resetMine()
    {
        if (!$this->getUrl()) {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        sleep(1);
        $client->waitForVisibility('.user-tap-button');
        sleep(5);

        $limits = $client->executeScript('return document.querySelector(".user-stats").innerText');
        $balance = $client->executeScript('return document.querySelector(".user-balance-large").innerText');
        $limits = explode("\n\n", $limits);
        $balance = explode("\n", $balance);

        $taps = intval($limits[1]);
        $mining = $limits[3];
        $balance = floatval($balance[0]);
        $this->cache->hSet($this->userKey('status'), 'mining', $mining);
        $this->updateStat($balance);

        if ($mining == '00:00:00') {
            $client->executeScript('document.querySelector(".user-tap-row button").click()');
            sleep(1);
        }

        if ($taps > 0) {
            $client->getWebDriver()->manage()->timeouts()->setScriptTimeout(300);
            for ($i = 0; $i < $taps ; $i++) {
                $client->executeScript('document.querySelector("button.user-tap-button").click()');
                sleep(1);
            }
        }
    }

    protected function updateStat($balance)
    {
        $b = round($balance, 2);
        $gauge = $this->collectionRegistry->getOrRegisterGauge(
            $this->getName(),
            'balance',
            'Balance',
            ['user']
        );
        $gauge->set($b, [$this->curProfile]);
        $this->cache->hSet($this->userKey('status'), 'balance', $b);
    }
}
