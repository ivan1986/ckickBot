<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Message\CustomFunction;
use App\Message\CustomFunctionUser;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Service\Attribute\Required;

class DogiatorsBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'Dogiators_bot'; }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);

        $urlFragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($urlFragment, $urlData);
        $tg_data = $urlData['tgWebAppData'];

        $this->UCSet('tgData', $tg_data);

        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('2 hour', delta: 1800)]
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

    #[ScheduleCallback('6 hour', delta: 3600)]
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
        return true;
    }

    #[ScheduleCallback('4 hour', delta: 1800)]
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
        foreach ($updateData['profile_upgrades'] ?? [] as $k => $v) {
            preg_match('#(\D+)(\d+)#', $v['id'], $matches);
            $exist[$v['upgrade_id']] = $v['level'];
        }

        // оставляем только следующий номер для каждого инструмента
        $updates = [];
        foreach (array_merge($updateData['system_upgrades'], $updateData['special_upgrades'], $updateData['arena_upgrades']) as $v) {
            $updates[$v['id']] = $v;
        }

        // оставляем только те у которых есть услови других зданий
        $updates = array_filter($updates, function ($i) use ($exist, $profile, $coinsBalance) {
            if (!in_array($i['status'], ['active', 'inactive'])) {
                return false;
            }
            return $i['next_modifier']['price'] < $coinsBalance;
        });

        usort($updates, fn ($a, $b) =>
            $b['next_modifier']['profit_per_hour_relative'] / $b['next_modifier']['price'] <=>
            $a['next_modifier']['profit_per_hour_relative'] / $a['next_modifier']['price']);

        if (empty($updates)) {
            return;
        }
        $updates = current($updates);
        $r = $apiClient->post('upgrade/buy', [
            'json' => ['upgrade_id' => $updates['id']]
        ]);
        $this->logger->info($this->getName() . ' for ' . $this->curProfile . ' update: {title}', ['title' => $updates['title']]);
        $this->bus->dispatch(
            new CustomFunctionUser($this->curProfile, $this->getName(), 'update'),
            [new DelayStamp(10 * 1000)]
        );
        return true;
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
            RequestOptions::PROXY => $this->getProxy(),
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
