<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Service\ProfileService;
use GuzzleHttp\RequestOptions;

class HeartBot extends BaseBot implements BotInterface
{
    const API = 'https://shark-app-t4cok.ondigitalocean.app/miniapp/';

    public function getTgBotName() { return 'heart_game_bot'; }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);

        $urlFragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($urlFragment, $urlData);
        $tgData = $urlData['tgWebAppData'];
        $this->UCSet('tgData', $tgData);

        parse_str($tgData, $tgDataArray);
        $this->UCSet('hash', $tgDataArray['hash']);

        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('4 hour', delta: 7200)]
    public function tasks()
    {
        $hash = $this->UCGet('hash');
        if (!$hash) {
            return false;
        }
        $apiClient = $this->getClient();
        $resp = $apiClient->post('auth', [
            'json' => [
                'data' => $this->UCGet('tgData')
            ]
        ]);
        $auth = json_decode($resp->getBody()->getContents(), true);
        $this->updateStatItem('balance', $auth['balance']);

        foreach ($auth['promotions'] as $promotion) {
            if ($promotion['done']) {
                continue;
            }
            if (in_array($promotion['category'], ['nickname', 'stars_purchase', 'ton_transaction', 'story', 'invite_friends', 'ad'])) {
                continue;
            }

            switch ($promotion['category']) {
                case 'channel':
                    // todo: subscribe channel
                    break;
                case 'daily':
                    $apiClient->get('promotions/' . $promotion['id'], [
                        'headers' => [
                            'Auth-Token' => $hash
                        ]
                    ]);
                    return true;
                case 'lucky':
                    $map = [];
                    foreach ($promotion['lucky'] as $n => $f) {
                        $map[$f['type']][] = $n;
                    }
                    shuffle($map['prize']);
                    shuffle($map['empty']);
                    shuffle($map['bomb']);
                    $map['prize'] = array_slice($map['prize'], 0, random_int(0,100) < 20 ? 3 : 4);
                    $map['empty'] = array_slice($map['empty'], 0, random_int(1,5));
                    $map['bomb'] = array_slice($map['bomb'], 0, random_int(0,100) < 1 ? 1 : 0);
                    $result = array_merge($map['prize'], $map['empty'], $map['bomb']);
                    $apiClient->get('promotions/' . $promotion['id'], [
                        'query' => [
                            'promotion_data' => json_encode($result)
                        ],
                        'headers' => [
                            'Auth-Token' => $hash
                        ]
                    ]);
                    return true;
                case 'telegram':
                case 'website':
                    $apiClient->get('https://app.heartgame.fun/api/promotions/' . $auth['id'] . '/' . $promotion['id'], ['allow_redirects' => false]);
                    sleep(4);
                    $apiClient->get('promotions/' . $promotion['id'], [
                        'headers' => [
                            'Auth-Token' => $hash
                        ]
                    ]);
                    return true;
                default:
                    $this->logger->error('Unknown promotion category: ' . $promotion['category']);
                    var_dump($promotion);
            }
        }
    }

    #[ScheduleCallback('1 hour', delta: 900, browser: true)]
    public function watchAd()
    {
        $tgData = $this->UCGet('tgData');
        if (!$tgData) {
            return false;
        }
        $apiClient = $this->getClient();
        $resp = $apiClient->post('auth', [
            'json' => [
                'data' => $tgData
            ]
        ]);
        $auth = json_decode($resp->getBody()->getContents(), true);
        $adProm = null;
        foreach ($auth['promotions'] as $promotion) {
            if ($promotion['done'] || $promotion['category'] != 'ad') {
                continue;
            }
            if ($promotion['attempts'] == 0) {
                return;
            }
            $adProm = $promotion;
            break;
        }
        if (!$adProm) {
            return false;
        }
        $this->logger->info('{bot} for {profile}: watchAD start {left}', [
            'profile' => $this->curProfile,
            'bot' => $this->getName(),
            'left' => $adProm['attempts'],
        ]);

        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->get($this->getUrl());

        $client->waitForVisibility('div.font-semibold', 60);

        $client->executeScript(<<<JS
            let node = Array.prototype.slice.call(document.querySelectorAll('div.font-semibold')).filter(function (el) {
                return el.textContent === 'Watch ads from partners'
            })[0];
            while (node.parentNode) {
                node = node.parentNode;
                if (node.querySelector('button')) {
                    node.querySelector('button').click();
                    break;
                }
            }
        JS);
        sleep(5);
        $existPopup = true;
        while ($existPopup) {
            sleep(5);
            $existPopup = $client->executeScript(<<<JS
                return document.querySelectorAll('html > div').length > 0;
            JS);
        }
        $client->executeScript(<<<JS
            document.querySelector('button.float-right').click();
        JS);
        $this->updateStatItem('watchLast', --$adProm['attempts']);
        $this->logger->info('{bot} for {profile}: watchAD success left {left}', [
            'profile' => $this->curProfile,
            'bot' => $this->getName(),
            'left' => $adProm['attempts'],
        ]);
        return true;
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client([
            'base_uri' => self::API,
            RequestOptions::PROXY => $this->getProxy(),
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
                'x-requested-with' => 'org.telegram.messenger',
            ]
        ]);
    }
}
