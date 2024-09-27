<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Service\ClientFactory;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Service\Attribute\Required;

class DogiatorsBot extends BaseBot implements BotInterface
{
    #[Required] public ClientFactory $clientFactory;

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName(), '/k/#@Dogiators_bot'))->withJitter(7200));
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

        $item = $this->cache->getItem($this->getName() . ':tgData');
        $item->set($tg_data);
        $this->cache->save($item);

        parent::saveUrl($client, $url);
    }

    public function passiveIncome()
    {
//        $client = $this->clientFactory->getOrCreateBrowser();
//        $client->request('GET', $this->getUrl());
//        sleep(2);
//        $client->waitForElementToContain('.MenuModule', 'Upgrade');
//        sleep(2);

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
        $coinsBalance = $balance['balance'];
        $miningPerHour = $balance['profit_per_hour'];
    }

    public function dailyIncome()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        return; // TODO

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
//        $update = $update['result']['upgrades'];
//        $coinsBalance = $balance['coinsBalance'];
//        $miningPerHour = $balance['miningPerHour'];

    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $tgData = $this->cache->getItem($this->getName() . ':tgData')->get();

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
                'User-Agent' => ClientFactory::UA,
            ]
        ]);
    }

}
