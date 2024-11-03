<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
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

    #[ScheduleCallback('2 hour', delta: 1800)]
    public function update()
    {
        if (!$this->getUrl()) {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        sleep(1);
        $client->waitForElementToContain('body', 'Отлично!');
        echo 'LOAD'.PHP_EOL;
        sleep(1);
        $client->executeScript(<<<JS
        var my_awesome_script = document.createElement('script');
        my_awesome_script.setAttribute('src','https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js');
        document.head.appendChild(my_awesome_script);
        JS);
        sleep(2);
        $client->executeScript(<<<JS
            $('[class^="_dialogHolderComeBack"]').find('button').click()
        JS);

        // идем в казну и берем числа
        $client->executeScript(<<<JS
            $('a[href="/treasury"]')[0].click();
        JS);
        sleep(2);
        $result = $client->executeScript(<<<JS
            let result = [];
            $('[class^="_header"] [class^="_info"] [class^="_container"]').each((i, item) => {
                if (i > 0) result.push(item.innerText.split('\\n')[1]);
            });
            result.push($('[class^="_wrapper"] [class^="_money"]')[0].innerText);
            return JSON.stringify(result);
        JS);
        $result = json_decode($result, true);
        $items = [];
        foreach ($result as $i => $item) {
            $items[$i] = str_replace(',', '', $item);
        }
        $this->updateStatItem('income', $items[0]);
        $this->updateStatItem('population', $items[1]);
        $this->updateStatItem('money', $items[2]);

        $client->executeScript(<<<JS
            $('a[href="/city"]')[0].click();
        JS);
        sleep(2);
        $client->executeScript(<<<JS
            $('a[href="/city/build"]')[0].click();
        JS);
        sleep(2);

        $result = $client->executeScript(<<<JS
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            var tabs = $('[class^="_buildNav"] [class^="_navItem"]').filter((i, item) => $(item).find('[class^="_count"]').length>0);
            var f1 = async function (tabs) {
                let activeItems = [];
                for(let t of tabs) { 
                    $(t).click(); 
                    await sleep(1000);
                    let items = $('[class^="_buildPreview"]')
                        .filter((i, item) => $(item).prop('class').search('disabled') < 0)
                        .filter((i, item) => $(item).find('[class^="_cooldown"]').length == 0)
                        .filter((i, item) => $(item).find('button[disabled]').length == 0)
                    for(let item of items) { 
                        let text = $(item).find('[class^="_previewActions"]')[0].innerText;
                        activeItems.push({ href: $(item).attr('href'), text: text });
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
            $price = preg_replace('/[^0-9]/', '', $price);
            array_shift($lines);
            $deltaSum = 0;
            while (count($lines) > 0) {
                $delta = array_shift($lines);
                $delta = preg_replace('/[^0-9]/', '', $delta);
                $deltaSum += intval($delta);
            }
            $items[] = [ 'href' => $item['href'], 'best' => $deltaSum / $price ];
        }
        if (empty($items)) {
            return;
        }
        usort($items, fn ($a, $b) => $b['best'] <=> $a['best']);
        $href = $items[0]['href'];
        $client->executeScript(<<<JS
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            var tabs = $('[class^="_buildNav"] [class^="_navItem"]').filter((i, item) => $(item).find('[class^="_count"]').length>0);
            var f2 = async function (tabs, href) {
                for(let t of tabs) { 
                    $(t).click(); 
                    await sleep(1000);
                    let items = $('[class^="_buildPreview"]')
                        .filter((i, item) => $(item).attr('href') == href )
                    for(let item of items) {
                        $(item).find('button').click();
                        await sleep(1000);
                        $('[class^="_buildDetail"] button').click();
                    }
                }
            };
            f2(tabs, '$href');
        JS);

        sleep(5);
        return true;
    }

    #[ScheduleCallback('24 hour')]
    public function updateAll()
    {
        if (!$this->getUrl()) {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        sleep(1);
        $client->waitForElementToContain('body', 'Отлично!');
        echo 'LOAD' . PHP_EOL;
        sleep(1);
        $client->executeScript(<<<JS
        var my_awesome_script = document.createElement('script');
        my_awesome_script.setAttribute('src','https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js');
        document.head.appendChild(my_awesome_script);
        JS
        );
        sleep(2);
        $client->executeScript(<<<JS
            $('[class^="_dialogHolderComeBack"]').find('button').click()
        JS);
        sleep(2);

        $client->executeScript(<<<JS
            $('a[href="/city"]')[0].click();
        JS);
        sleep(2);
        $client->executeScript(<<<JS
            $('a[href="/city/build"]')[0].click();
        JS);
        sleep(2);

        // психануть и купить все
        $client->getWebDriver()->manage()->timeouts()->setScriptTimeout(1800);
        $client->executeScript(<<<JS
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            var f3 = async function (tabs) {
                let shuffled = tabs
                    .map((i, value) => ({ value, sort: Math.random() }))
                    .sort((a, b) => a.sort - b.sort)
                    .map((i, { value }) => value)
                for(let t of shuffled) {
                    $(t).click();
                    await sleep(1000);
                    let items = $('[class^="_buildPreview"]')
                        .filter((i, item) => $(item).prop('class').search('disabled') < 0)
                        .filter((i, item) => $(item).find('[class^="_cooldown"]').length == 0)
                        .filter((i, item) => $(item).find('button[disabled]').length == 0)
                    if (items.length == 0) {
                        continue;
                    }
                    await sleep(1000);
                    $(items[0]).find('button').click();
                    await sleep(1000);
                    $('[class^="_buildDetail"] button').click();
                    await sleep(1000);
                }
            };
            var tabs = $('[class^="_buildNav"] [class^="_navItem"]').filter((i, item) => $(item).find('[class^="_count"]').length>0);
            while (tabs.length > 0) {
                await sleep(1000);
                console.log(tabs);
                await sleep(1000);
                f3(tabs);
                await sleep(1000);
                tabs = $('[class^="_buildNav"] [class^="_navItem"]').filter((i, item) => $(item).find('[class^="_count"]').length>0);
                await sleep(1000);
            }
        JS);
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
