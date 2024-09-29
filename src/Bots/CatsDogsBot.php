<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Service\ClientFactory;
use DateTime;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class CatsDogsBot extends BaseBot implements BotInterface
{
    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName(), '/k/#@catsdogs_game_bot'))->withJitter(7200));
        $schedule->add(RecurringMessage::every('4 hour', new CustomFunction($this->getName(), 'claim')));
    }

    public function saveUrl($client, $url)
    {
        parent::saveUrl($client, $url);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($fragment, $params);
        $auth = $params['tgWebAppData'];

        $item = $this->cache->getItem($this->getName() . ':auth');
        $item->set($auth);
        $this->cache->save($item);
    }

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
        }
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->cache->getItem($this->getName() . ':auth')->get();

        if (!$token) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://api.catsdogs.live/',
            'headers' => [
                'x-telegram-web-app-data' => $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ClientFactory::UA,
            ]
        ]);
    }
}
