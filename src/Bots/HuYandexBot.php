<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\CustomFunctionUser;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use Carbon\Carbon;
use GuzzleHttp\Cookie\CookieJar as GuzzleCookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class HuYandexBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'qlyukerbot'; }

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('2 hour', new CustomFunction($this->getName(), 'claim')));
        $schedule->add(RecurringMessage::every('4 hour', new CustomFunction($this->getName(), 'upgrade')));
    }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);

        $urlFragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($urlFragment, $urlData);
        $tg_data = urldecode($urlData['tgWebAppData']);

        $this->UCSet('tgData', $tg_data);

        parent::saveUrl($client, $url);
    }

    protected $auth;


    public function claim()
    {
        if (!$auth = $this->startRequest()) {
            return;
        }
        $client = $this->getClient($auth);

        if (empty($this->auth['user']['dailyReward']['claimed'])) {
            sleep(5);
            $client->post('tasks/daily');
        }

        if ($this->auth['user']['currentEnergy'] > 400) {
            $time = rand(5, 10);
            $taps = rand($time * 10, $time * 12);
            $recover = $time * $this->auth['user']['energyPerSec'];
            sleep($time);
            $data = [
                'clientTime' => time(),
                'currentEnergy' => $this->auth['user']['currentEnergy'] - $taps + rand($recover - 1, $recover + 1),
                'taps' => $taps
            ];
            $resp = $client->post('game/sync', [ 'json' => $data ]);
            $res = json_decode($resp->getBody()->getContents(), true);
            $this->updateStat($res['currentCoins'], $this->auth['user']['minePerHour']);
        }
    }

    public function upgrade()
    {
        if (!$auth = $this->startRequest()) {
            return;
        }
        $client = $this->getClient($auth);

        $exist = [];
        foreach ($this->auth['user']['upgrades'] as $k => $v) {
            if ($v['kind'] == 'minePerHour') {
                $exist[$k] = $v['level'];
            }
        }
        if ($this->auth['user']['minePerHour'] > 300000) {
            return;
        }
        $profit = $this->auth['upgrades'];
        $profit = array_filter($profit, function ($item) use ($exist) {
            if ($item['kind'] != 'minePerHour') {
                return false;
            }
            if (!empty($item['condition'])) {
                $c = $item['condition'];
                if ($c['kind'] == 'friends') if ($c['friends'] > $this->auth['user']['friendsCount']) {
                    return false;
                }
                if ($c['kind'] == 'upgrade') if (!isset($exist[$c['upgradeId']]) || $exist[$c['upgradeId']] < $c['level']) {
                    return false;
                }
            }
            if (!empty($item['upgradedAt'])) {
                $delay = $this->auth['sharedConfig']['upgradeDelay'][$item['level'] + 1];
                if ($item['upgradedAt'] + $delay + 1 > time()) {
                    return false;
                }
            }

            return isset($item['next']['price']) && $item['next']['price'] < $this->auth['user']['currentCoins'];
        });
        usort($profit, fn ($a, $b) => $b['next']['increment'] / $b['next']['price'] <=> $a['next']['increment'] / $a['next']['price']);
        if (empty($profit)) {
            return;
        }

        $client->post('upgrades/buy', [ 'json' => ['upgradeId' => $profit[0]['id']] ]);
        $this->cache->hSet(
            $this->userKey('run'),
            'realUpgrade',
            Carbon::now()->getTimestamp()
        );
        $this->bus->dispatch(
            new CustomFunctionUser($this->curProfile, $this->getName(), 'upgrade'),
            [new DelayStamp(10 * 1000)]
        );
    }

    protected function updateStat($balance, $profit)
    {
        $gauge = $this->collectionRegistry->getOrRegisterGauge(
            $this->getName(),
            'balance',
            'Balance',
            ['user']
        );
        $gauge->set($balance, [$this->curProfile]);
        $gauge = $this->collectionRegistry->getOrRegisterGauge(
            $this->getName(),
            'profit',
            'Profit per hour',
            ['user']
        );
        $gauge->set($profit, [$this->curProfile]);
        $this->cache->hSet($this->userKey('status'), 'balance', $balance);
        $this->cache->hSet($this->userKey('status'), 'profit', $profit);
    }


    protected function getClient($cookie): ?\GuzzleHttp\Client
    {
        $jar = new GuzzleCookieJar();
        $jar->setCookie(SetCookie::fromString($cookie));
        return new \GuzzleHttp\Client([
            'base_uri' => 'https://qlyuker.io/api/',
            'cookies' => $jar,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }

    protected function startRequest()
    {
        $tgData = $this->UCGet('tgData');
        if (!$tgData) {
            return null;
        }

        $authClient = new \GuzzleHttp\Client([
            'base_uri' => 'https://qlyuker.io/api/',
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
        $resp = $authClient->post('auth/start', ['json' => ['startData' => $tgData]]);
        $this->auth = json_decode($resp->getBody()->getContents(), true);

        $authCookie = null;
        foreach ($resp->getHeaders() as $k => $v) {
            if ($k === 'set-cookie') {
                foreach ($v as $cookie) {
                    if (str_contains($cookie, 'qlyuker=')) {
                        $authCookie = $cookie;
                        break 2;
                    }
                }
            }
        }
        if (!$authCookie) {
            return null;
        }
        $this->UCSet('authCookie', $authCookie, 3600);

        return $authCookie;
    }
}
