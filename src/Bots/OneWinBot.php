<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Message\CustomFunction;
use App\Message\CustomFunctionUser;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Service\Attribute\Required;

class OneWinBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'token1win_bot'; }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);
        $client->request('GET', $url);
        //$client->waitForElementToContain('#root', 'Не забудь собрать ежедневную награду');
        sleep(10);
        $client->request('GET', 'https://cryptocklicker-frontend-rnd-prod.100hp.app/' . 'home');
        sleep(5);
        $client->waitForElementToContain('#root', 'Главная');
        $token = $client->executeScript('return window.localStorage.getItem("token");');
        $userId = $client->executeScript('return window.localStorage.getItem("tgId");');

        $this->UCSet('token', $token);
        $this->UCSet('userId', $userId);

        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('1 hour', delta: 600)]
    public function passiveIncome()
    {
        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        //$client->waitForElementToContain('#root', 'Не забудь собрать ежедневную награду');
        sleep(10);
    }

    #[ScheduleCallback('9 hour', delta: 1800)]
    public function dailyIncome()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('/tasks/everydayreward');
        $exist = json_decode($resp->getBody()->getContents(), true);
        foreach ($exist['days'] as $k => $v) {
            if (!empty($v['isCollected'])) {
                return;
            }
        }
        $apiClient->post('/tasks/everydayreward');
        return true;
    }

    #[ScheduleCallback('4 hour', delta: 900)]
    public function updateCity()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('/city/config?lang=ru');
        $config = json_decode($resp->getBody()->getContents(), true);
        $profit = $config['cityBuildingsConfig'];

        try {
            $resp = $apiClient->get('/city/launch');
            $exist = json_decode($resp->getBody()->getContents(), true);
        } catch (ClientException $e) {
            $names = ['Мухосранск', 'Зажопинск', 'Устьпердюйск', 'Дрочеподск'];
            $name = $names[array_rand($names)];
            $name.= '-' . random_int(10000, 99999);
            $apiClient->post('/city/launch', ['json' => ['cityName' => $name]]);
            $this->logger->info($this->getName() . ' for ' . $this->curProfile . ' created city: {city}', ['city' => $name]);
            return true;
        }
        $existMap = [];
        foreach ($exist['buildings'] ?? [] as $k => $v) {
            $existMap[$v['buildingName']] = $v['level'];
            $existMap[$v['buildingName'] . '_time'] = $v['updatedAt'];
        }

        $resp = $apiClient->get('/minings');
        $exist_pp = json_decode($resp->getBody()->getContents(), true);
        foreach ($exist_pp as $k => $v) {
            preg_match('#(\D+)(\d+)#', $v['id'], $matches);
            $existMap[$matches[1]] = (int)$matches[2];
        }
        $coinsBalance = $exist['balance'];

        // оставляем только следующий номер для каждого инструмента
        $profit = array_filter($profit, function ($i) use (&$existMap) {
            preg_match('#(\D+)(\d+)#', $i['id'], $matches);
            if (isset($existMap[$i['name']])) {
                preg_match('#(\D+)(\d+)#', $i['id'], $matches);
                $existMap[$matches[1]] = $existMap[$i['name']];
                $existMap[$matches[1] . '_time'] = $existMap[$i['name'] . '_time'];
                return $i['level'] == $existMap[$i['name']] + 1;
            }
            return $i['level'] == 1;
        });

        // оставляем только те у которых есть услови других зданий
        $profit = array_filter($profit, function ($i) use ($exist, $existMap) {
            if ($i['requiredNewReferralsCount'] > 0) {
                return false;
            }
            if ($i['requiredPopulation'] > $exist['population']) {
                return false;
            }
            if ($i['requiredReferralsCount'] > 0) {
                return false;
            }
            if (count($i['requiredQuests']) > 0) {
                return false;
            }
            foreach (array_merge($i['requiredBuildings'], $i['requiredPassiveProfit']) as $id) {
                preg_match('#(\D+)(\d+)#', $id, $matches);
                if (empty($existMap[$matches[1]])) {
                    return false;
                }
                if ($existMap[$matches[1]] < $matches[2]) {
                    return false;
                }
                if (isset($existMap[$matches[1] . '_time'])) {
                    $to = $existMap[$matches[1] . '_time'] + $i['time'];
                    if ($to > time()) {
                        return false;
                    }
                }
            }
            return true;
        });
        $profit = array_filter($profit, function ($i) use ($coinsBalance) {
            return $i['cost'] <= $coinsBalance;
        });
        usort($profit, fn ($a, $b) => $b['profit'] / $b['cost'] <=> $a['profit'] / $a['cost']);

        if (empty($profit)) {
            return;
        }
        $profit = current($profit);
        $apiClient->post('/city/building', [
            'json' => ['buildingId' => $profit['id'], 'type' => $profit['type']]
        ]);
        $this->bus->dispatch(
            new CustomFunctionUser($this->curProfile, $this->getName(), 'updateCity'),
            [new DelayStamp(10 * 1000)]
        );
        return true;

    }

    #[ScheduleCallback('4 hour', delta: 900)]
    public function update()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('/game/config?lang=ru');
        $config = json_decode($resp->getBody()->getContents(), true);
        $profit = $config['PassiveProfit'];

        $resp = $apiClient->get('/user/balance');
        $balance = json_decode($resp->getBody()->getContents(), true);
        $coinsBalance = $balance['coinsBalance'];
        $this->updateStatItem('balance', $balance['coinsBalance']);
        $this->updateStatItem('profit', $balance['miningPerHour']);

        $resp = $apiClient->get('/minings');
        $exist = json_decode($resp->getBody()->getContents(), true);
        foreach ($exist as $k => $v) {
            preg_match('#(\D+)(\d+)#', $v['id'], $matches);
            $exist[$matches[1]] = $matches[2];
        }

        // оставляем только следующий номер для каждого инструмента
        $profit = array_filter($profit, function ($i) use ($exist) {
            preg_match('#(\D+)(\d+)#', $i['id'], $matches);
            if (isset($exist[$matches[1]])) {
                return $matches[2] == $exist[$matches[1]] + 1;
            }
            return $matches[2] == 1;
        });
        // оставляем только те у которых есть услови других зданий
        $profit = array_filter($profit, function ($i) use ($exist) {
            if (isset($i['required'][0]['newReferralCount'])) {
                return false;
            }
            if (!isset($i['required'][0]['PassiveProfit'])) {
                return true;
            }
            preg_match('#(\D+)(\d+)#', $i['required'][0]['PassiveProfit'], $matches);
            if (isset($exist[$matches[1]])) {
                return $matches[2] <= $exist[$matches[1]];
            }
            return false;
        });
        $profit = array_filter($profit, function ($i) use ($coinsBalance) {
            return $i['cost'] <= $coinsBalance;
        });
        usort($profit, fn ($a, $b) => $b['profit'] / $b['cost'] <=> $a['profit'] / $a['cost']);

        if (empty($profit)) {
            return;
        }
        $profit = current($profit);
        $apiClient->post('/minings', [
            'json' => ['id' => $profit['id']]
        ]);
        $this->bus->dispatch(
            new CustomFunctionUser($this->curProfile, $this->getName(), 'update'),
            [new DelayStamp(10 * 1000)]
        );
        return true;
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->UCGet('token');
        $userId = $this->UCGet('userId');

        if (!$token || !$userId) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://crypto-clicker-backend-go-prod.100hp.app/',
            'headers' => [
                'X-User-Id' => $userId,
                'referer' => 'https://cryptocklicker-frontend-rnd-prod.100hp.app/',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}
