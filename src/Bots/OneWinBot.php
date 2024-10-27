<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\CustomFunctionUser;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Service\Attribute\Required;

class OneWinBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'token1win_bot'; }

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('1 hour', new CustomFunction($this->getName(), 'passiveIncome')));
        $schedule->add(RecurringMessage::every('6 hour', new CustomFunction($this->getName(), 'dailyIncome')));
        $schedule->add(RecurringMessage::every('3 hour', new CustomFunction($this->getName(), 'update')));
    }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);
        $client->request('GET', $url);
        //$client->waitForElementToContain('#root', 'Не забудь собрать ежедневную награду');
        sleep(10);
        $client->request('GET', 'https://cryptocklicker-frontend-rnd-prod.100hp.app/' . 'earnings');
        sleep(5);
        $client->waitForElementToContain('#root', 'Ежедневные');
        $token = $client->executeScript('return window.localStorage.getItem("token");');
        $userId = $client->executeScript('return window.localStorage.getItem("tgId");');

        $this->UCSet('token', $token);
        $this->UCSet('userId', $userId);

        parent::saveUrl($client, $url);
    }

    public function passiveIncome()
    {
        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        //$client->waitForElementToContain('#root', 'Не забудь собрать ежедневную награду');
        sleep(10);
    }

    public function dailyIncome()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('/tasks/everydayreward');
        $exist = json_decode($resp->getBody()->getContents(), true);
        $toCollect = null;
        foreach ($exist['days'] as $k => $v) {
            if ($v['isCollected'] === false) {
                $toCollect = $v['id'];
                break;
            }
        }
        if (!$toCollect) {
            return;
        }
        $apiClient->post('/tasks/everydayreward');
    }

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
        $this->updateStat($balance);

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
    }

    protected function updateStat($balance)
    {
        $gauge = $this->collectionRegistry->getOrRegisterGauge(
            $this->getName(),
            'balance',
            'Balance',
            ['user']
        );
        $gauge->set($balance['coinsBalance'], [$this->curProfile]);
        $gauge = $this->collectionRegistry->getOrRegisterGauge(
            $this->getName(),
            'profit',
            'Profit per hour',
            ['user']
        );
        $gauge->set($balance['miningPerHour'], [$this->curProfile]);
        $this->cache->hSet($this->userKey('status'), 'balance', $balance['coinsBalance']);
        $this->cache->hSet($this->userKey('status'), 'profit', $balance['miningPerHour']);
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
