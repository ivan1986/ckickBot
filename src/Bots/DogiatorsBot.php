<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Service\Attribute\Required;

class DogiatorsBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'Dogiators_bot'; }

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName()))->withJitter(7200));
        $schedule->add(RecurringMessage::every('1 hour', new CustomFunction($this->getName(), 'passiveIncome')));
        $schedule->add(RecurringMessage::every('6 hour', new CustomFunction($this->getName(), 'dailyIncome')));
        $schedule->add(RecurringMessage::every('3 hour', new CustomFunction($this->getName(), 'update')));
    }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);

        $urlFragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($urlFragment, $urlData);
        $tg_data = $urlData['tgWebAppData'];

        $this->UCSet('tgData', $tg_data);

        parent::saveUrl($client, $url);
    }

    public function passiveIncome()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->post('profile/init', [
            'json' => [
                'profit' => 0,
                'taps' => 0,
                'timezone' => "Europe/Moscow",
                'ts' => 0,
            ]
        ]);
        $balance = json_decode($resp->getBody()->getContents(), true);
        $balance = $balance['result']['profile'];
        $this->updateStat($balance);
    }

    public function dailyIncome()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('quests/info');
        $quests = json_decode($resp->getBody()->getContents(), true);
        $quests = $quests['result']['daily_rewards']['reward_days'];
        $toCollect = null;
        foreach ($quests as $k => $v) {
            if ($v['is_completed'] === false && $v['is_current'] === true) {
                $toCollect = $v['day'];
                break;
            }
        }
        if (!$toCollect) {
            return;
        }
        $apiClient->post('quests/daily-reward/claim');
    }

    public function update()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->post('profile/init', [
            'json' => [
                'profit' => 0,
                'taps' => 0,
                'timezone' => "Europe/Moscow",
                'ts' => 0,
            ]
        ]);
        $balance = json_decode($resp->getBody()->getContents(), true);
        $balance = $balance['result']['profile'];
        $this->updateStat($balance);
        $coinsBalance = $balance['balance'];
        $userLevel = $balance['level'];
        $refs = $balance['referrals_count'];

        $resp = $apiClient->get('upgrade/list');
        $updateData = json_decode($resp->getBody()->getContents(), true);
        $updateData = $updateData['result'];

        $exist = [];
        foreach ($updateData['profile_upgrades'] as $k => $v) {
            preg_match('#(\D+)(\d+)#', $v['id'], $matches);
            $exist[$v['upgrade_id']] = $v['level'];
        }

        // оставляем только следующий номер для каждого инструмента
        $updates = [];
        foreach (array_merge($updateData['system_upgrades'], $updateData['special_upgrades']) as $v) {
            $level = $exist[$v['id']] ?? 0;
            foreach ($v['modifiers'] as $m) {
                if ($m['level'] == $level + 1) {
                    $v['next'] = $m;
                    break;
                }
            }
            unset($v['modifiers']);
            $updates[$v['id']] = $v;
        }

        // оставляем только те у которых есть услови других зданий
        $updates = array_filter($updates, function ($i) use ($exist, $userLevel, $refs) {
            if (empty($i['requirements'])) {
                return true;
            }
            $req = $i['requirements'][0];
            if ($req['level'] > $userLevel) {
                return false;
            }
            if ($req['min_referrals_count'] > $refs) {
                return false;
            }
            if ($req['reach_upgrade_id']) {
                if (!isset($exist[$req['reach_upgrade_id']])) {
                    return false;
                }
                if ($req['reach_upgrade_level'] > $exist[$req['reach_upgrade_id']]) {
                    return false;
                }
            }
            return true;
        });

        $updates = array_filter($updates, function ($i) use ($coinsBalance) {
            return $i['next']['price'] <= $coinsBalance;
        });
        usort($updates, fn ($a, $b) => $b['next']['profit_per_hour_relative'] / $b['next']['price'] <=> $a['next']['profit_per_hour_relative'] / $a['next']['price']);

        if (empty($updates)) {
            return;
        }
        $updates = current($updates);
        $apiClient->post('upgrade/buy', [
            'json' => ['upgrade_id' => $updates['id']]
        ]);
    }

    protected function updateStat($balance)
    {
        $b = round($balance['balance']);
        $p = round($balance['profit_per_hour']);
        $gauge = $this->collectionRegistry->getOrRegisterGauge(
            $this->getName(),
            'balance',
            'Balance',
            ['user']
        );
        $gauge->set($b, [$this->curProfile]);
        $gauge = $this->collectionRegistry->getOrRegisterGauge(
            $this->getName(),
            'profit',
            'Profit per hour',
            ['user']
        );
        $gauge->set($p, [$this->curProfile]);
        $this->cache->hSet($this->userKey('status'), 'balance', $b);
        $this->cache->hSet($this->userKey('status'), 'profit', $p);
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $tgData = $this->UCGet('tgData');

        if (!$tgData) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://tte.dogiators.com/api/v1/',
            'query' => [
                'tg_data' => $tgData,
            ],
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }

}
