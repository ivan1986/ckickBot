<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Service\Attribute\Required;

class CityHolderBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'cityholder'; }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);

        $urlFragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($urlFragment, $urlData);
        $tg_data = $urlData['tgWebAppData'];

        $this->UCSet('tgData', $tg_data);

        $authClient = new \GuzzleHttp\Client([
            'base_uri' => 'https://api-reserve.city-holder.com/',
            RequestOptions::PROXY => $this->getProxy(),
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
        $resp = $authClient->post('auth', ['json' => ['auth' => $tg_data]]);
        $auth = json_decode($resp->getBody()->getContents(), true);

        $this->UCSet('token', $auth['token']);
        $this->UCSet('settings', $auth['settings']);

        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('2 hour', delta: 1800, browser: true)]
    public function update()
    {
        if (!$this->getUrl()) {
            $this->runUpdate();
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile, false);
        $client->request('GET', $this->getUrl());
        sleep(2);

        $btn = false;
        $ret = 20;
        while (!$btn && --$ret > 0) {
            sleep(1);
            $btn = $client->executeScript(<<<JS
                return document.querySelector('[class^="_dialogHolderComeBack"] button') !== null;
            JS);
        }

        $client->executeScript(<<<JS
            if (document.querySelector('[class^="_dialogHolderComeBack"] button')) {
                document.querySelector('[class^="_dialogHolderComeBack"] button').click()
            }
        JS);

        // идем в казну и берем числа
        $client->executeScript(<<<JS
            document.querySelector('a[href="/treasury"]').click();
        JS);
        sleep(2);
        $result = $client->executeScript(<<<JS
            let result = [];
            let items = document.querySelectorAll('[class^="_header"] [class^="_info"] [class^="_container"]');
            for (let item of items) {
              result.push(item.innerText.split('\\n')[1]);
            }
            let money = document.querySelector('[class^="_wrapper"] [class^="_money"]').innerText;
            result.push(money);
            return JSON.stringify(result);
        JS);
        $result = json_decode($result, true);
        $items = [];
        foreach ($result as $i => $item) {
            $items[$i] = str_replace(',', '', $item);
        }
        $this->updateStatItem('income', $items[1]);
        $this->updateStatItem('population', $items[2]);
        $this->updateStatItem('money', $items[3]);
        $this->logger->info('{bot} for {profile}: get money info ({money})', [
            'profile' => $this->curProfile,
            'bot' => $this->getName(),
            'money' => $items[3],
        ]);

        $btn = $client->executeScript(<<<JS
            return document.querySelector('[class^="_dialogHolderComeBack"] button') !== null;
        JS);
        if ($btn) {
            echo 'btn2';
            $client->executeScript(<<<JS
                document.querySelector('[class^="_dialogHolderComeBack"] button').click()
            JS);
        }

        $client->executeScript(<<<JS
            document.querySelector('a[href="/city"]').click();
        JS);
        sleep(2);
        $client->executeScript(<<<JS
            document.querySelector('a[href="/city/build"]').click();
        JS);
        sleep(2);

        $result = $client->executeScript(<<<JS
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            let tabs = document.querySelectorAll('[class^="_buildNav"] [class^="_navItem"]');
            tabs = Array.from(tabs).filter((item) => item.querySelector('[class^="_count"]') != null);
            var f1 = async function (tabs) {
                let activeItems = [];
                for(let t of tabs) { 
                    t.click(); 
                    await sleep(1000);
                    let items = document.querySelectorAll('[class^="_buildPreview"]');
                    items = Array.from(items)
                        .filter((item) => item.className.search('disabled') < 0)
                        .filter((item) => item.querySelector('[class^="_cooldown"]') == null)
                        .filter((item) => item.querySelector('button[disabled]') == null)
                    ;
                    for(let item of items) { 
                        let title = item.querySelector('[class^="_title"]').innerText;
                        let text = item.querySelector('[class^="_previewActions"]').innerText;
                        activeItems.push({ href: item.href, title: title, text: text });
                    }
                }
                return activeItems;
            };
            return JSON.stringify(await f1(tabs));
        JS);
        $result = json_decode($result, true);
        $items = [];
        foreach ($result as $item) {
            $lines = explode(PHP_EOL, $item['text']);
            if ($lines[0] != 'Улучшить' && $lines[0] != 'Построить') {
                continue;
            }
            array_shift($lines);
            $price = array_shift($lines);
            $price = preg_replace('/[^0-9]/', '', $price) ?: 1;
            array_shift($lines);
            $deltaSum = 0;
            while (count($lines) > 0) {
                $delta = array_shift($lines);
                $delta = preg_replace('/[^0-9]/', '', $delta);
                $deltaSum += intval($delta);
            }
            $items[] = [ 'href' => $item['href'], 'title' => $item['title'], 'best' => $deltaSum / $price ];
        }
        if (empty($items)) {
            $this->logger->info('{bot} for {profile}: not have money for any upgrade', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
            ]);
            return;
        }

        usort($items, fn ($a, $b) => $b['best'] <=> $a['best']);
        $href = $items[0]['href'];
        $this->logger->info('{bot} for {profile}: try upgrade {title}', [
            'profile' => $this->curProfile,
            'bot' => $this->getName(),
            'title' => $items[0]['title'],
        ]);

        $curLevel =$client->executeScript(<<<JS
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            let tabs = document.querySelectorAll('[class^="_buildNav"] [class^="_navItem"]');
            tabs = Array.from(tabs).filter((item) => item.querySelector('[class^="_count"]') != null);
            var f2 = async function (tabs, href) {
                for(let t of tabs) { 
                    t.click(); 
                    await sleep(1000);
                    let items = document.querySelectorAll('[class^="_buildPreview"]');
                    items = Array.from(items).filter((item) => item.href == href);
                    for(let item of items) {
                        item.querySelector('button').click();
                        await sleep(1000);
                        let popup = document.querySelector('[class^="_buildDetail"]');
                        let current = popup.querySelector('[class^="_detailLevel"]').innerText;
                        popup.querySelector('button span').click();
                        return current;
                    }
                }
            };
            return await f2(tabs, '$href');
        JS);

        $this->logger->info('{bot} for {profile}: upgrade {title} from {curLevel}', [
            'profile' => $this->curProfile,
            'bot' => $this->getName(),
            'title' => $items[0]['title'],
            'curLevel' => $curLevel
        ]);
        return true;
    }

    #[ScheduleCallback('24 hour', browser: true)]
    public function updateAll()
    {
        if (!$this->getUrl()) {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        sleep(2);

        $btn = false;
        $ret = 20;
        while (!$btn && --$ret > 0) {
            sleep(1);
            $btn = $client->executeScript(<<<JS
                return document.querySelector('[class^="_dialogHolderComeBack"] button') !== null;
            JS);
        }

        $client->executeScript(<<<JS
            document.querySelector('[class^="_dialogHolderComeBack"] button').click()
        JS);
        sleep(2);

        $client->executeScript(<<<JS
            document.querySelector('a[href="/city"]').click();
        JS);
        sleep(2);
        $client->executeScript(<<<JS
            document.querySelector('a[href="/city/build"]').click();
        JS);
        sleep(2);

        // психануть и купить все
        $client->getWebDriver()->manage()->timeouts()->setScriptTimeout(1800);
        $count = $client->executeScript(<<<JS
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            let count = 0;
            var f3 = async function (tabs) {
                let shuffled = tabs
                    .map((value) => ({ value, sort: Math.random() }))
                    .sort((a, b) => a.sort - b.sort)
                    .map(({ value }) => value)
                for(let t of shuffled) {
                    t.click();
                    await sleep(1000);
                    let items = document.querySelectorAll('[class^="_buildPreview"]');
                    items = Array.from(items)
                        .filter((item) => item.className.search('disabled') < 0)
                        .filter((item) => item.querySelector('[class^="_cooldown"]') == null)
                        .filter((item) => item.querySelector('button[disabled]') == null)
                        .filter((item) => item.querySelector('button[class*="_secondary"]') == null)
                    ;
                    if (items.length == 0) {
                        continue;
                    }
                    await sleep(1000);
                    items[0].querySelector('button').click();
                    await sleep(1000);
                    document.querySelector('[class^="_buildDetail"] button[class*="_upgrade"]').click();
                    await sleep(1000);
                    count++;
                }
            };
            let tabs = document.querySelectorAll('[class^="_buildNav"] [class^="_navItem"]');
            tabs = Array.from(tabs).filter((item) => item.querySelector('[class^="_count"]') != null);
            while (tabs.length > 0) {
                await sleep(1000);
                f3(tabs);
                await sleep(1000);
                tabs = document.querySelectorAll('[class^="_buildNav"] [class^="_navItem"]');
                tabs = Array.from(tabs).filter((item) => item.querySelector('[class^="_count"]') != null);
                await sleep(1000);
            }
            return count;
        JS);
        $this->logger->info('{bot} for {profile}: batch upgrade {count}', [
            'profile' => $this->curProfile,
            'bot' => $this->getName(),
            'count' => $count,
        ]);
        return true;
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->UCGet('token');

        if (!$token) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://api-reserve.city-holder.com/',
            RequestOptions::PROXY => $this->getProxy(),
            'query' => [
                'tg_data' => $token,
            ],
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}
