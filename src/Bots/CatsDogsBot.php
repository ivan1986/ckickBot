<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use DateTime;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class CatsDogsBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'catsdogs_game_bot'; }

    public function saveUrl($client, $url)
    {
        parent::saveUrl($client, $url);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($fragment, $params);
        $auth = $params['tgWebAppData'];

        $this->UCSet('auth', $auth);
    }

    #[ScheduleCallback('4 hour', delta: 7200)]
    public function claim()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('user/info');
        $info = json_decode($resp->getBody()->getContents(), true);
        $nowDt = new DateTime('now');
        $date = DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $info['claimed_at']);
        // стартуют каждые 8 часов по utc
        $nts = $nowDt->getTimestamp() - $nowDt->getTimestamp() % 28800;
        $ts = $date->getTimestamp() - $date->getTimestamp() % 28800;
        if ($nts !== $ts) {
            $resp = $apiClient->get('game/current');
            $current = json_decode($resp->getBody()->getContents(), true);
            $amount = $current['reward']['cats']; // TODO: need look diff with dogs
            $apiClient->post('game/claim', ['json' => ['claimed_amount' => $amount]]);

            $this->updateStat($apiClient);
            return true;
        }
    }

    protected function updateStat($apiClient)
    {
        $resp = $apiClient->get('user/balance');
        $balance = json_decode($resp->getBody()->getContents(), true);
        $balanceAll = 0;
        foreach ($balance as $b) {
            $balanceAll += $b;
        }

        $this->updateStatItem('balance', $balanceAll);
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->UCGet('auth');

        if (!$token) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://api.catsdogs.live/',
            RequestOptions::PROXY => $this->getProxy(),
            'headers' => [
                'x-telegram-web-app-data' => $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}
