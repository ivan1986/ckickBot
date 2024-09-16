<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class FactoraBot extends BaseBot implements BotInterface
{
    private $auth;
    private $client;

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName(), '/k/#@FactoraBot'))->withJitter(7200));
        $schedule->add(RecurringMessage::every('1 hour', new CustomFunction($this->getName(), 'topUpEnergy')));
    }

    public function saveUrl($url)
    {
        parent::saveUrl($url);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($fragment, $params);
        $auth = base64_encode($params['tgWebAppData']);
        $item = $this->cache->getItem($this->getName() . ':auth');
        $item->set($auth);
        $this->cache->save($item);
    }

    protected function initClient()
    {
        $this->client = new \GuzzleHttp\Client([
            'base_uri' => 'https://api.factoragame.com/FactoraTapApi/',
            'headers' => [
                'Content-Type' => 'application/json, text/plain, */*',
                'Sec-Fetch-Dest' => 'empty',
                'User-Agent' => 'Mozilla/5.0 (Linux; Android 9; K) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/80.0.3987.132 Mobile Safari/537.36',
            ]
        ]);
        $authCache = $this->cache->getItem($this->getName() . ':auth');
        $this->auth = $authCache->get();
    }

    public function topUpEnergy()
    {
        $this->initClient();
        if (!$this->auth) {
            return;
        }
        $userInfo = $this->getuserInfo();

        if ($userInfo['currentEnergy'] > $userInfo['totalEnergyConsumptionPerHour'] * 1.5) {
            return 0;
        }

        $topUp = intval($userInfo['energyLimit'] * random_int(95,98) / 100);

        while ($userInfo['currentEnergy'] < $topUp) {
            $maxClicks = $topUp - $userInfo['currentEnergy'];
            $maxClicks /= $userInfo['tapPower'];
            $maxClicks = intval($maxClicks);
            $clicks = min(random_int(20,40), $maxClicks);

            $this->tap($clicks);
            $this->tap(0);
            $userInfo = $this->getuserInfo();
        }
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getUserInfo(): array
    {
        $userInfoResp = $this->client->get('GetUser?' . http_build_query([
                'authData' => $this->auth,
                'needUserBuildings' => 'false',
            ]));
        if ($userInfoResp->getStatusCode() !== 200) {
            throw new \Exception('Incorrect status code ' . $userInfoResp->getStatusCode());
        };
        $userInfo = $userInfoResp->getBody()->getContents();
        return json_decode($userInfo, true);
    }

    /**
     * @param int $tapsCount
     * @return bool
     */
    public function tap(int $tapsCount): bool
    {
        $resp = $this->client->post('NewTaps?' . http_build_query([
                'tapsCount' => $tapsCount,
                'authData' => $this->auth,
            ]), [
            'headers' => [
                'content-length' => '0',
            ],
        ]);
        return $resp->getBody()->getContents() == 'ok';
    }
}
