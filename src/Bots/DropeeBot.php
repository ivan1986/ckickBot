<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Service\ProfileService;
use Carbon\Carbon;
use GuzzleHttp\RequestOptions;

class DropeeBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'DropeeBot'; }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);
        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('2 hour', delta: 3600, browser: true)]
    public function online()
    {
        if (!$this->getUrl()) {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        sleep(1);
        $client->waitForVisibility('#base-modal');
        $auth = $client->executeScript(<<<JS
            return window.localStorage.getItem('dropee.v1.auth')
        JS);
        $auth = json_decode($auth, true);
        if (empty($auth['token'])) {
            $this->logger->error($this->getName() . ' for ' . $this->curProfile . ' get token error');
            $this->UCSet('token', '');
            return;
        }
        $this->UCSet('token', $auth['token']);
        return true;
    }

    #[ScheduleCallback('2 hour', delta: 3600)]
    public function upgrade()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $config = $apiClient->get('game/config');
        $config = $config->getBody()->getContents();
        $config = json_decode($config, true);
        $config = $config['config'];

        $sync = $apiClient->post('game/sync', ['json' => ['initialSync' => true]]);
        $sync = $sync->getBody()->getContents();
        $sync = json_decode($sync, true);
        $sync = $sync['playerStats'];

        $this->updateStatItem('coins', round($sync['coins']));
        $this->updateStatItem('profit', round($sync['profit']));

        $myUpgrades = [];
        foreach ($sync['upgrades'] as $k => $v) {
            $myUpgrades[$k] = $v['level'];
        }

        $upgrades = $config['upgrades'];
        $upgrades = array_filter($upgrades, function ($i) use ($sync, $myUpgrades) {
            if ($i['price'] >= $sync['coins']) {
                return false;
            }
            if (!empty($i['requirements']['upgrade'])) {
                $u = $i['requirements']['upgrade'];
                if (!isset($myUpgrades[$u['id']])) {
                    return false;
                }
                if ($myUpgrades[$u['id']] < $u['level']) {
                    return false;
                }
            }
            if (!empty($i['requirements']['referrals'])) {
                $u = $i['requirements']['referrals'];
                if ($u['count'] > $sync['referrals']['count']) {
                    return false;
                }
            }
            if ($i['cooldownUntil'] > time() + 10) {
                return false;
            }
            if ($i['expiresOn'] && $i['expiresOn'] < time()) {
                return false;
            }
            return true;
        });
        usort($upgrades, function ($a, $b) {
            if ($a['expiresOn']) return -1;
            if ($b['expiresOn']) return 1;
            return $b['profitDelta'] / $b['price'] <=> $a['profitDelta'] / $a['price'];
        });

        if (empty($upgrades)) {
            return;
        }

        // Обновляем лучшие 10 карточек пока есть деньги
        $coins = $sync['coins'];
        $upgrades = array_slice($upgrades, 0, 10);
        foreach ($upgrades as $upgrade) {
            if ($coins < $upgrade['price']) {
                break;
            }

            $coins -= $upgrade['price'];
            $apiClient->post('game/actions/upgrade', [
                'json' => ['upgradeId' => $upgrade['id']]
            ]);
            sleep(random_int(8, 15));
        }
        return true;
    }

    #[ScheduleCallback('1 hour', delta: 3600)]
    public function spin()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $whell = $apiClient->get('game/fortune-wheel');
        $whell = $whell->getBody()->getContents();
        $whell = json_decode($whell, true);
        $whell = $whell['state'];

        $this->updateStatItem('usdt', $whell['usdtCentsBalance'] / 100);
        $this->updateStatItem('spins', $whell['spins']['available']);

        if ($whell['spins']['available'] > 20) {
            for ($i=0; $i < 10; $i++) {
                $this->oneSpin($apiClient);
                sleep(5);
            }
        }
        if ($whell['spins']['available'] > 0) {
            $this->oneSpin($apiClient);
            return true;
        }
    }

    protected function oneSpin($apiClient)
    {
        $result = $apiClient->post('game/actions/fortune-wheel/spin', [
            'json' => ['version' => 2]
        ]);
        $result = $result->getBody()->getContents();
        $result = json_decode($result, true);
        $this->logger->info($this->getName() . ' for ' . $this->curProfile . ' spin bonus: {id}', $result['prize']);
    }

    #[ScheduleCallback('6 hour', delta: 3600, browser: true)]
    public function daily()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $sync = $apiClient->post('game/sync', ['json' => ['initialSync' => true]]);
        $sync = $sync->getBody()->getContents();
        $sync = json_decode($sync, true);
        $sync = $sync['playerStats'];

        $rest = 10 - $sync['activities']['watchAdForSpin'];
        while ($rest > 0) {
            $s = random_int(40, 130);
            sleep($s);
            $apiClient->post('game/actions/extra-spin-by-ad');
            $rest--;
            $this->markRun('ad-spin');
        }

        if ($sync['tasks']['dailyCheckin']['lastCheckin'] != date('Y-m-d')) {
            $apiClient->post('game/actions/tasks/daily-checkin', ['json' => ['timezoneOffset' => -180]]);
            return true;
        }
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->UCGet('token');

        if (!$token) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://dropee.clicker-game-api.tropee.com/api/',
            RequestOptions::PROXY => $this->getProxy(),
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}
