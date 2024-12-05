<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Service\ProfileService;

class Mine2Bot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'tBTCminer_bot'; }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);

        $urlFragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($urlFragment, $urlData);
        $tg_data = $urlData['tgWebAppData'];

        $data = urldecode($tg_data);
        parse_str($data, $data);
        $data = json_decode($data['user'], true);
        $id = $data['id'];

        $this->UCSet('tgData', $tg_data);
        $this->UCSet('tgId', $id);

        $client = $this->getClient();
        $resp = $client->request('POST', 'auth/register?ref_id=', [
            'json' => [
                'hash' => $tg_data,
                'message' => [
                    'chat' => ['id' => $id],
                    'from' => [
                        'id' => $id,
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'username' => $data['username'],
                        'language_code' => $data['language_code'],
                    ]
                ]
            ]
        ]);
        $resp = $client->request('POST', 'auth/login?telegram_id=' . $id, [
            'json' => [
                'hash' => $tg_data,
            ]
        ]);

        $resp = json_decode($resp->getBody()->getContents(), true);
        $this->UCSet('token', $resp['token']);
        var_dump($resp['token']);

        $resp = $client->get('user/profile', [
            'headers' => [
                'Authorization' => 'Bearer ' . $resp['token'],
            ]
        ]);
        $resp = json_decode($resp->getBody()->getContents(), true);
        var_dump($resp['uuid']);
        $this->UCSet('uuid', $resp['uuid']);

        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('2 hour', delta: 3600)]
    public function energy()
    {
        $uuid = $this->UCGet('uuid');
        if (!$uuid) {
            return;
        }

        $url = "wss://mine.tbtc.one:8001/" . $uuid . "?token=" . $this->UCGet('token');
        $client = new \WebSocket\Client($url);
        $client->addHeader('user-agent', ProfileService::UA);
        $client->addHeader('Origin', 'https://miner-tap2earn.vercel.app');
        $client->connect();

        $client->text('status');
        $message = json_decode($client->receive()->getContent(), true);
        if (is_null($message) || $message['tap_info']['station_energy'] < 0.1) {
            return false;
        }

        while ($message['tap_info']['station_energy'] > 0.1) {
            $client->text('tap');
            $message = json_decode($client->receive()->getContent(), true);
            usleep(random_int(5, 10));
        }
        $client->text('status');
        $message = json_decode($client->receive()->getContent(), true);
        $this->updateStatItem('energy', $message['tap_info']['user_energy']);

        $client->close();
        return true;
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client([
            'base_uri' => 'https://dev.clonetap.tech/api/',
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}
