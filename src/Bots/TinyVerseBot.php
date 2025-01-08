<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Message\UpdateUrlUser;
use App\Service\ProfileService;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class TinyVerseBot extends BaseBot implements BotInterface
{
    const HOST = 'https://app.tonverse.app/';

    public function getTgBotName() { return 'tverse'; }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);
        $client->request('GET', $url);
        $html = $client->getPageSource();
        preg_match('#app.js\?([0-9.]+)\"#', $html, $m);
        //$client->waitForElementToContain('#root', 'Не забудь собрать ежедневную награду');
        sleep(10);

        $token = $client->executeScript(<<<JS
            for (var key in localStorage) { 
                if (key.search('session') > 0) {
                    return window.localStorage.getItem(key);
                }
            }
        JS);

        if ($token) {
            $this->UCSet('token', $token);
            $this->UCSet('version', $m[1]);
        }

        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('30 min', delta: 900)]
    public function collectDust()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->post('/user/info', [
            'form_params' => [
                'session' => $this->UCGet('token')
            ],
        ]);
        $info = json_decode($resp->getBody()->getContents(), true);
        $info = $info['response'];

        $this->updateStatItem('dust', $info['dust']);
        $this->updateStatItem('stars', $info['stars']);

        if ($info['dust_progress'] > 0.5) {
            $resp = $apiClient->post('/galaxy/collect', [
                'form_params' => [
                    'session' => $this->UCGet('token')
                ],
                'headers' => [
                    'X-Api-Request-Id' => $this->getApiReqId(),
                ]
            ]);
            $result = $resp->getBody()->getContents();
            return true;
        }
    }

    #[ScheduleCallback('6 hour', delta: 3600)]
    public function addStars()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->post('/user/info', [
            'form_params' => [
                'session' => $this->UCGet('token')
            ],
            'headers' => [
                'X-Api-Request-Id' => $this->getApiReqId(),
            ]
        ]);
        $info = json_decode($resp->getBody()->getContents(), true);
        $info = $info['response'];

        $starsList = [1000, 500, 100];
        $stars = 0;
        foreach ($starsList as $count) {
            $need = $this->calcFreeStart($count, $info['stars']);
            if ($need < $info['dust']) {
                $stars = $count;
                break;
            }
        }
        if (!$stars) {
            return false;
        }

        $resp = $apiClient->post('/galaxy/get', [
            'form_params' => [
                'session' => $this->UCGet('token'),
            ],
            'headers' => [
                'X-Api-Request-Id' => $this->getApiReqId(),
            ]
        ]);
        $info = json_decode($resp->getBody()->getContents(), true);
        $info = $info['response'];
        sleep(1);
        $resp = $apiClient->post('/stars/create', [
            'form_params' => [
                'session' => $this->UCGet('token'),
                'galaxy_id' => $info['id'],
                'stars' => $stars,
            ],
            'headers' => [
                'X-Api-Request-Id' => $this->getApiReqId(),
            ]
        ]);
        $this->logger->info('{bot} for {profile}: create {stars} stars', [
            'profile' => $this->curProfile,
            'bot' => $this->getName(),
            'stars' => $stars
        ]);
        return true;
    }

    #[ScheduleCallback('8 hour', delta: 3600)]
    public function findCiv()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->post('/user/info', [
            'form_params' => [
                'session' => $this->UCGet('token')
            ],
            'headers' => [
                'X-Api-Request-Id' => $this->getApiReqId(),
            ]
        ]);
        $info = json_decode($resp->getBody()->getContents(), true);
        $info = $info['response'];
        if ($info['dust'] < 12000 || !$info['is_scan_available']) {
            return;
        }

        $resp = $apiClient->post('/galaxy/get', [
            'form_params' => [
                'session' => $this->UCGet('token'),
            ],
            'headers' => [
                'X-Api-Request-Id' => $this->getApiReqId(),
            ]
        ]);
        $info = json_decode($resp->getBody()->getContents(), true);
        $info = $info['response'];

        if ($info['civilization'] == 0 && empty($info['scan'])) {
            $resp = $apiClient->post('/scan/start', [
                'form_params' => [
                    'session' => $this->UCGet('token'),
                    'galaxy_id' => $info['id'],
                    'power' => 1,
                ],
                'headers' => [
                    'X-Api-Request-Id' => $this->getApiReqId(),
                ]
            ]);
            $this->logger->info('{bot} for {profile}: start scan', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
            ]);
            return true;
        }
        if (!empty($info['scan']) && $info['scan']['progress'] == 1) {
            $resp = $apiClient->post('/scan/result', [
                'form_params' => [
                    'session' => $this->UCGet('token'),
                    'galaxy_id' => $info['id'],
                ],
                'headers' => [
                    'X-Api-Request-Id' => $this->getApiReqId(),
                ]
            ]);
            $info = json_decode($resp->getBody()->getContents(), true);
            $info = $info['response'];
            $this->logger->info('{bot} for {profile}: scan result: {res}', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
                'res' => json_encode($info)
            ]);
            return true;
        }
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->UCGet('token');

        if (!$token) {
            $this->bus->dispatch(
                new UpdateUrlUser($this->curProfile, $this->getName()),
                [new DelayStamp(10 * 1000)]
            );
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://api.tonverse.app/',
            RequestOptions::PROXY => $this->getProxy(),
            RequestOptions::HEADERS => [
                'X-Application-Version' => $this->UCGet('version'),
                'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With' => 'XMLHttpRequest',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }

    protected function calcFreeStart($new, $current)
    {
        $a = $current / 70;
        return $new * $a - -(($current + --$new) / 70 - $a);
    }

    protected function getApiReqId()
    {
        return ';;;0.' . substr(str_shuffle(str_repeat('1234567890', 5)),0,16);
    }
}
