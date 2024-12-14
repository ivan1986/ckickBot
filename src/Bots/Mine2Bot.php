<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Service\ProfileService;
use GuzzleHttp\RequestOptions;

class Mine2Bot extends BaseBot implements BotInterface
{
    const API_ENDPOINT = 'https://dev.clonetap.tech/api/';

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

        $client = new \GuzzleHttp\Client([
            'base_uri' => self::API_ENDPOINT,
            RequestOptions::PROXY => $this->getProxy(),
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
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

        $resp = $client->get('user/profile', [
            'headers' => [
                'Authorization' => 'Bearer ' . $resp['token'],
            ]
        ]);
        $resp = json_decode($resp->getBody()->getContents(), true);
        $this->UCSet('uuid', $resp['uuid']);

        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('4 hour', delta: 1800)]
    public function repairAndUpgrade()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('user/profile');
        $resp = json_decode($resp->getBody()->getContents(), true);
        if ($resp['power_plant']['wear'] < 0.8) {
            $apiClient->post('power-plants/repair');
            $this->markRun('repair');
        }
        $this->updateStatItem('wear', number_format($resp['power_plant']['wear'], 2));
    }

    #[ScheduleCallback('1 hour', delta: 1800)]
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
            usleep(random_int(5, 10));
            $message = json_decode($client->receive()->getContent(), true);
            if (empty($message['tap_info'])) {
                break;
            }
        }
        $client->text('status');
        $message = json_decode($client->receive()->getContent(), true);
        $this->updateStatItem('energy', number_format($message['tap_info']['user_energy'], 2));
        $this->logger->info('{bot} for {profile}: energy: {energy} - {mined} - {rest}', [
            'profile' => $this->curProfile,
            'bot' => $this->getName(),
            'energy' => $message['tap_info']['user_energy'],
            'mined' => $message['tap_info']['user_mined_energy'],
            'rest' => $message['tap_info']['station_energy'],
        ]);

        $client->close();
        return true;
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        if (!$token = $this->UCGet('token')) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => self::API_ENDPOINT,
            RequestOptions::PROXY => $this->getProxy(),
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}
