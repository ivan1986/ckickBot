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

class DogiatorsBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'Dogiators_bot'; }

    public function addSchedule(Schedule $schedule)
    {
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
        $profile = json_decode($resp->getBody()->getContents(), true);
        $profile = $profile['result']['profile'];
        $this->updateStat($profile);
        $coinsBalance = $profile['balance'];

        $resp = $apiClient->get('upgrade/list');
        $updateData = json_decode($resp->getBody()->getContents(), true);
        $updateData = $updateData['result'];

        $exist = [];
        foreach ($updateData['profile_upgrades'] as $k => $v) {
            preg_match('#(\D+)(\d+)#', $v['id'], $matches);
            $exist[$v['upgrade_id']] = $v['level'];
        }
        $arenaStat = $updateData['arena_stats'];

        // оставляем только следующий номер для каждого инструмента
        $updates = [];
        foreach (array_merge($updateData['system_upgrades'], $updateData['special_upgrades'], $updateData['arena_upgrades']) as $v) {
            $level = $exist[$v['id']] ?? 0;
            foreach ($v['modifiers'] as $m) {
                if ($m['level'] == $level + 1) {
                    $v['next'] = $m;
                    if (isset($v['requirements'][$m['level']])) {
                        $v['reqn'] = $v['requirements'][$level];
                    }
                    break;
                }
            }
            unset($v['modifiers']);
            $updates[$v['id']] = $v;
        }

        // оставляем только те у которых есть услови других зданий
        $updates = array_filter($updates, function ($i) use ($exist, $profile, $arenaStat) {
            if (empty($i['requirements'])) {
                return true;
            }
            $req = $i['reqn'] ?? $i['requirements'][0];
            if ($req['level'] > $profile['level']) {
                return false;
            }
            if ($req['min_referrals_count'] > $profile['referrals_count']) {
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
            $reqMap = [
                'min_rating' => 'rating',
                'min_fight_pvp_count' => 'battle_in_the_arena_count',
                'min_fight_pve_count' => 'battle_in_the_dungeon_count',
                'min_in_rest_count' => 'in_rest_count',
                'min_in_planning_count' => 'in_planning_count',
                'min_in_rage_count' => 'in_rage_count',
                'min_chest_bronze_open_count' => 'chest_bronze_open_count',
                'min_chest_silver_open_count' => 'chest_silver_open_count',
                'min_chest_gold_open_count' => 'chest_gold_open_count',
                'min_feed_count' => 'feed_count',
                'min_durability_repair_count' => 'durability_repair_count',
                'min_tokens_earned' => 'tokens_earned',
            ];
            foreach ($reqMap as $k => $v) {
                if ($req[$k] > $arenaStat[$v]) {
                    return false;
                }
            }
            if ($req['min_player_item_upgrade_count'] > 0) {
                return false;
            }
            return true;
        });

        $updates = array_filter($updates, function ($i) use ($coinsBalance) {
            if (empty($i['next'])) {
                return false;
            }
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
        $this->bus->dispatch(
            new CustomFunctionUser($this->curProfile, $this->getName(), 'update'),
            [new DelayStamp(10 * 1000)]
        );
    }

    protected function updateStat($balance)
    {
        $this->updateStatItem('balance', round($balance['balance']));
        $this->updateStatItem('profit', round($balance['profit_per_hour']));
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
