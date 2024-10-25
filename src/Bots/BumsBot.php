<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\CustomFunctionUser;
use App\Message\UpdateUrl;
use App\Message\UpdateUrlUser;
use App\Service\ProfileService;
use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class BumsBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'bums_ton_bot'; }

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('2 hour', new CustomFunction($this->getName(), 'update')));
        $schedule->add(RecurringMessage::every('8 hour', new CustomFunction($this->getName(), 'daily')));
    }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);

        $urlFragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($urlFragment, $urlData);
        $tg_data = $urlData['tgWebAppData'];

        $authClient = new \GuzzleHttp\Client([
            'base_uri' => 'https://api.bums.bot/',
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
        $resp = $authClient->post('miniapps/api/user/telegram_auth', [
            'multipart' => [
                [
                    'name' => 'initData',
                    'contents' => $tg_data,
                ],
            ]
        ]);
        $auth = json_decode($resp->getBody()->getContents(), true);
        $token = $auth['data']['token'];

        $this->UCSet('token', $token);

        parent::saveUrl($client, $url);
    }

    public function update()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        try {
            $resp = $apiClient->get('miniapps/api/user_game_level/getGameInfo');
        } catch (ClientException $e) {
            $this->logger->error($this->getName() . ' for ' . $this->curProfile . ' auth error');
            $this->bus->dispatch(
                new UpdateUrlUser($this->curProfile, $this->getName()),
                [new DelayStamp(10 * 1000)]
            );
            return;
        }
        $info = json_decode($resp->getBody()->getContents(), true);
        $pph = $info['data']['mineInfo']['minePower'];
        $info = $info['data']['gameInfo'];

        $this->updateStatItem('coins', $info['coin']);
        $this->updateStatItem('profit', $pph);
        $this->updateStatItem('level', $info['level']);

        $resp = $apiClient->post('miniapps/api/mine/getMineLists');
        $list = json_decode($resp->getBody()->getContents(), true);
        $list = $list['data']['lists'];
        $list = array_filter($list, fn ($item) => $item['status'] > 0);
        $list = array_filter($list, fn ($item) => $item['nextLevelCost'] < $info['coin']);

        usort($list, fn ($a, $b) => $b['distance'] / $b['nextLevelCost'] <=> $a['distance'] / $a['nextLevelCost']);

        if (empty($list)) {
            return;
        }

        $list = current($list);
        $apiClient->post('miniapps/api/mine/upgrade', [
            'multipart' => [
                [
                    'name' => 'mineId',
                    'contents' => $list['mineId'],
                ],
            ]
        ]);
        $this->cache->hSet(
            $this->userKey('run'),
            'realUpgrade',
            Carbon::now()->getTimestamp()
        );
        $this->bus->dispatch(
            new CustomFunctionUser($this->curProfile, $this->getName(), 'update'),
            [new DelayStamp(10 * 1000)]
        );
    }

    public function daily()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('miniapps/api/sign/getSignLists');
        $info = json_decode($resp->getBody()->getContents(), true);
        $signStatus = $info['data']['signStatus'];

        if ($signStatus) {
            return;
        }
        $apiClient->post('miniapps/api/sign/sign');
        $this->cache->hSet(
            $this->userKey('run'),
            'getDaily',
            Carbon::now()->getTimestamp()
        );
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->UCGet('token');

        if (!$token) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://api.bums.bot/',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}
