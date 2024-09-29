<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Service\Attribute\Required;

class CityHolderBot extends BaseBot implements BotInterface
{
    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName(), '/k/#@cityholder'))->withJitter(7200));
//        $schedule->add(RecurringMessage::every('1 hour', new CustomFunction($this->getName(), 'passiveIncome')));
//        $schedule->add(RecurringMessage::every('6 hour', new CustomFunction($this->getName(), 'dailyIncome')));
        $schedule->add(RecurringMessage::every('2 hour', new CustomFunction($this->getName(), 'update')));
    }

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

    public function update()
    {
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
        echo 'script'.PHP_EOL;
        $client->executeScript(<<<JS
        $('[class^="_dialogHolderComeBack"]').find('button').click()
        JS);
        echo 'OK'.PHP_EOL;
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
