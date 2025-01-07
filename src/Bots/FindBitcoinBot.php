<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use Carbon\Carbon;

class FindBitcoinBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'btctma_bot'; }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);
        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('30 min', delta: 600, browser: true)]
    public function watchAd()
    {
        if (!$this->getUrl()) {
            return;
        }
        $lastKey = 'last-'.Carbon::now()->format('Y-m-d');
        $last = $this->UCGet($lastKey);
        if ($last !== false && $last === "0") {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        sleep(1);
        $client->waitForVisibility('button.Touch', 60);
        sleep(5);

        $client->executeScript(<<<JS
            [...document.querySelectorAll('button.Touch')].filter(a => a.innerHTML.includes("Баланс"))[0].click()
        JS);
        sleep(1);

        $balance = $client->executeScript(<<<JS
            return [...document.querySelectorAll('div')].filter(a => a.innerHTML == "Баланс")[0].nextSibling.nextSibling.innerText
        JS);

        $balance = explode(PHP_EOL, $balance);
        $this->updateStatItem('usdt', $balance[0]);
        $this->updateStatItem('btc', $balance[2]);
        $this->updateStatItem('ton', $balance[4]);


        $client->executeScript(<<<JS
            [...document.querySelectorAll('button.Touch')].filter(a => a.innerHTML.includes("Бонусы"))[0].click()
        JS);
        sleep(1);

        $last = $client->executeScript(<<<JS
            let textNode = [...document.querySelectorAll('div')].filter(a => a.innerHTML == "Посмотрите 20 реклам")[0];
            return textNode.nextSibling.innerText;
        JS);
        $last = explode(PHP_EOL, $last);
        $last = $last[2] - $last[0];
        $this->updateStatItem('last', $last);
        $this->UCSet($lastKey, $last, 8*3600);
        if ($last == 0) {
            return;
        }

        $client->executeScript(<<<JS
            let textNode = [...document.querySelectorAll('div')].filter(a => a.innerHTML == "Посмотрите 20 реклам")[0];
            return textNode.nextSibling.nextSibling.click();
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
}
