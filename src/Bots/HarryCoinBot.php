<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use Carbon\Carbon;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class HarryCoinBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'harry_coin_bot'; }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);
        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('1 hour')]
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
            return true;
        }

        if ($taps > 0) {
            $client->getWebDriver()->manage()->timeouts()->setScriptTimeout(1800);
            for ($i = 0; $i < $taps ; $i++) {
                $client->executeScript('document.querySelector("button.user-tap-button").click()');
                sleep(1);
            }
            $this->markRun('tap');
        }
    }

    #[ScheduleCallback('1 hour', delta: 900)]
    public function watchAd()
    {
        if (!$this->getUrl()) {
            return;
        }
        if ($this->UCGet('adLock')) {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        sleep(1);
        $client->waitForVisibility('.user-tap-button');
        sleep(5);

        $client->executeScript(<<<JS
            document.querySelector('a[href="/tasks"]').click();
        JS);
        sleep(1);

        sleep(10);

        // spna - да, там опечатка
        $lock = $client->executeScript(<<<JS
            let item = document.querySelectorAll('.earn-item')[0];
            return item.querySelector('spna').innerText;
        JS);
        $lock = trim($lock, '()');
        if ($lock) {
            $time = explode(':', $lock);
            $timeSec = ($time[0] * 60 + $time[1]) * 60 + $time[2];
            $this->UCSet('adLock', 1, $timeSec);
            return;
        }

        $client->executeScript(<<<JS
            let item = document.querySelectorAll('.earn-item')[0];
            item.querySelector('button').click();
        JS);
        $existPopup = true;
        while ($existPopup) {
            sleep(10);
            $existPopup = $client->executeScript(<<<JS
                return document.querySelectorAll('html > div').length > 0;
            JS);
        }
        return true;
    }

    #[ScheduleCallback('1 day', delta: 7200)]
    public function claimRewards()
    {
        if (!$this->getUrl()) {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        sleep(1);
        $client->waitForVisibility('.user-tap-button');
        sleep(5);

        $client->executeScript(<<<JS
            document.querySelector('a[href="/friends"]').click();
        JS);
        sleep(2);

        $client->executeScript(<<<JS
            document.querySelector('.airdrop-top button').click();
        JS);
        sleep(2);
    }

    #[ScheduleCallback('8 hour', delta: 7200)]
    public function spin()
    {
        if (!$this->getUrl()) {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile, false);
        $client->request('GET', $this->getUrl());
        sleep(1);
        $client->waitForVisibility('.user-tap-button');
        sleep(5);

        $client->executeScript(<<<JS
            document.querySelector('img[src*="spinn-wheel.jpg"').click()
        JS);
        sleep(2);
        $last = $client->executeScript(<<<JS
            return document.querySelector('#wheel-container').nextElementSibling.firstChild.innerText
        JS);
        $last = explode('/', $last)[0];
        if ($last > 0) {
            $client->executeScript(<<<JS
                document.querySelector('#wheel-container').nextElementSibling.firstElementChild.nextElementSibling.click()
            JS);
            sleep(15);
            return true;
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
