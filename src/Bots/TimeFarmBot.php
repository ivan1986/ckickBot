<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Message\CustomFunctionUser;
use App\Service\ProfileService;
use Carbon\Carbon;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Panther\Client;

class TimeFarmBot extends BaseBot implements BotInterface
{
    const HOST = 'https://tg-bot-tap.laborx.io/';

    public function getTgBotName() { return 'TimeFarmCryptoBot'; }

    public function runInTg(Client $client)
    {
        $client->executeScript(<<<JS
            if (document.querySelectorAll('button.reply-markup-button').length === 0) {
                document.querySelector('.autocomplete-peer-helper-list-element').click();
            }
            [...document.querySelectorAll('button.reply-markup-button')].filter(a => a.innerText.includes("Открыть приложение"))[0].click()
        JS
        );
        sleep(5);
        parent::runInTg($client);
    }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);

        $urlFragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($urlFragment, $urlData);
        $tg_data = $urlData['tgWebAppData'];

        $authClient = new \GuzzleHttp\Client([
            'base_uri' => self::HOST,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
        $resp = $authClient->post('api/v1/auth/validate-init/v2', [
            'json' => [
                'initData' => $tg_data,
                'platfrm' => 'android',
            ]
        ]);
        $auth = json_decode($resp->getBody()->getContents(), true);
        $token = $auth['token'];

        $this->UCSet('token', $token);

        parent::saveUrl($client, $url);
    }

//    #[ScheduleCallback('12 hours', delta: 7200)]
//    public function daily()
//    {
//    }

    #[ScheduleCallback('2 hour', delta: 3600)]
    public function claimAndReset()
    {
        if ($this->UCGet('lock')) {
            return;
        }
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('api/v1/farming/info');
        $status = json_decode($resp->getBody()->getContents(), true);
        $this->updateStatItem('balance', intval($status['balance']));


        $lock = Carbon::createFromFormat('Y-m-d\\TH:i:s.vP', $status['activeFarmingStartedAt']);
        $lock->add($status['farmingDurationInSec'], 'seconds');
        $secondsLeft = $lock->diff(null, true)->totalSeconds;
        $delay = intval(abs($secondsLeft));
        var_dump($delay);
        var_dump($secondsLeft);

        if ($secondsLeft < 0) {
            $this->bus->dispatch(
                new CustomFunctionUser($this->curProfile, $this->getName(), 'claimAndReset'),
                [new DelayStamp(($delay + 2) * 1000)]
            );
            $this->markRun('check');
        } else {


            $apiClient->post('api/v1/farming/finish');
            sleep(2);
            $apiClient->post('api/v1/farming/start');

            $delay = $status['farmingDurationInSec'];
            $this->UCSet('lock', 1, $delay + 1);
            $this->bus->dispatch(
                new CustomFunctionUser($this->curProfile, $this->getName(), 'claimAndReset'),
                [new DelayStamp(($delay + 2) * 1000)]
            );
            return true;
        }
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->UCGet('token');

        if (!$token) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => self::HOST,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}
