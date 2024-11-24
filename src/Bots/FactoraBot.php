<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Message\CustomFunction;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class FactoraBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'FactoraBot'; }

    private $auth;
    private $client;

    public function saveUrl($client, $url)
    {
        parent::saveUrl($client, $url);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($fragment, $params);
        $auth = base64_encode($params['tgWebAppData']);
        $this->UCSet('auth', $auth);
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
        $this->auth = $this->UCGet('auth');
    }

    #[ScheduleCallback('1 hour')]
    public function topUpEnergy()
    {
        $this->initClient();
        if (!$this->auth) {
            return;
        }
        $userInfo = $this->getuserInfo();

        if ($userInfo['currentEnergy'] > $userInfo['totalEnergyConsumptionPerHour'] * 1.5) {
            return;
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
        return true;
    }

    #[ScheduleCallback('12 hour', delta: 3600)]
    public function upgradeMain()
    {
        $this->initClient();
        if (!$this->auth) {
            return;
        }
        $userInfo = $this->getuserInfo();
        $this->updateStatItem('main', $userInfo['rank'] . ':' . $userInfo['energyLevel'] . ':' . $userInfo['tapLevel']);

        if ($userInfo['nextRank'] && $userInfo['nextRank']['cost'] <= $userInfo['balance']) {
            $this->logger->info('{bot} for {profile}: reactor update to {level}', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
                'level' => $userInfo['nextRank']['level'],
            ]);
            $resp = $this->client->post('RankUpgrade?' . http_build_query([
                    'authData' => $this->auth,
                ]), [
                'headers' => [
                    'content-length' => '0',
                ],
            ]);
            return true;
        }

        if ($userInfo['energyLimitUpgradeToNextLevel'] && $this->checkUpgradeBust($userInfo['energyLimitUpgradeToNextLevel'], $userInfo)) {
            $this->logger->info('{bot} for {profile}: energy limit update to {level}', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
                'level' => $userInfo['energyLimitUpgradeToNextLevel']['level'],
            ]);
            $resp = $this->client->post('EnergyLimitUpgrade?' . http_build_query([
                    'authData' => $this->auth,
                ]), [
                'headers' => [
                    'content-length' => '0',
                ],
            ]);
            return true;
        }
        if ($userInfo['tapPowerUpgradeToNextLevel'] && $this->checkUpgradeBust($userInfo['tapPowerUpgradeToNextLevel'], $userInfo)) {
            $this->logger->info('{bot} for {profile}: tap power update to {level}', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
                'level' => $userInfo['tapPowerUpgradeToNextLevel']['level'],
            ]);
            $resp = $this->client->post('TapPowerUpgrade?' . http_build_query([
                    'authData' => $this->auth,
                ]), [
                'headers' => [
                    'content-length' => '0',
                ],
            ]);
            return true;
        }
    }

    protected function checkUpgradeBust($bust, $userInfo)
    {
        if ($bust['cost'] > $userInfo['balance']) {
            return false;
        }
        $cond = $bust['condition'];
        if ($cond['referrals'] && $cond['referrals'] > $userInfo['inviteesCount']) {
            return false;
        }
        if ($cond['rank'] && $cond['rank'] > $userInfo['rank']) {
            return false;
        }
        return true;
    }

    #[ScheduleCallback('6 hour', delta: 3600)]
    public function upgradeBuildings()
    {
        $this->initClient();
        if (!$this->auth) {
            return;
        }
        $userInfo = $this->getuserInfo();
        $this->updateStatItem('energy', $userInfo['currentEnergy']);
        $this->updateStatItem('balance', $userInfo['balance']);

        $resp = $this->client->get('GetUserBuildings?' . http_build_query([
                'authData' => $this->auth,
        ]));
        $userBuildings = json_decode($resp->getBody()->getContents(), true);
        $exist = [];
        foreach ($userBuildings as $building) {
            $exist[$building['buildingId']] = $building['level'];
        }
        $userBuildings = array_filter($userBuildings, function ($i) use ($userInfo, $exist) {
            $cond = $i['buildingUpgrade']['upgradeCondition'];
            if ($cond['referrals'] && $cond['referrals'] > $userInfo['inviteesCount']) {
                return false;
            }
            if ($i['buildingUpgrade']['cost'] > $userInfo['balance']) {
                return false;
            }
            if ($cond['building']) {
                if (empty($exist[$cond['building']['buildingId']])) {
                    return false;
                }
                if ($exist[$cond['building']['buildingId']] < $cond['building']['buildingLevel']) {
                    return false;
                }
            }
            // TODO: add other conditions
            return true;
        });
        usort($userBuildings, function ($a, $b) {
            $aDelta = $a['buildingUpgrade']['incomePerHour'] - $a['incomePerHour'];
            $bDelta = $b['buildingUpgrade']['incomePerHour'] - $b['incomePerHour'];
            return $bDelta / $b['buildingUpgrade']['cost'] <=> $aDelta / $a['buildingUpgrade']['cost'];
        });

        if (empty($userBuildings)) {
            return $this->tryBuildNewBuilding($userInfo, $exist);
        }
        $building = current($userBuildings);
        $this->logger->info('{bot} for {profile}: upgrade building {building}', [
            'profile' => $this->curProfile,
            'bot' => $this->getName(),
            'building' => $building['buildingInfo']['nameEn'],
        ]);
        $resp = $this->client->post('BuildingUpgrade?' . http_build_query([
                'buildingId' => $building['buildingId'],
                'authData' => $this->auth,
            ]), [
            'headers' => [
                'content-length' => '0',
            ],
        ]);
        $status = $resp->getBody()->getContents();
        return $status == 'ok';
    }

    protected function tryBuildNewBuilding($userInfo, $exist)
    {
        $resp = $this->client->get('GetBuildings?' . http_build_query([
                'authData' => $this->auth,
            ]));
        $buildings = json_decode($resp->getBody()->getContents(), true);
        $buildings = array_filter($buildings, function ($i) use ($userInfo, $exist) {
            if (isset($exist[$i['buildingId']])) {
                return false;
            }
            $cond = $i['buyCondition'];
            if ($cond['referrals'] && $cond['referrals'] > $userInfo['inviteesCount']) {
                return false;
            }
            if ($cond['rank'] && $cond['rank'] > $userInfo['rank']) {
                return false;
            }
            if ($cond['building']) {
                if (empty($exist[$cond['building']['buildingId']])) {
                    return false;
                }
                if ($exist[$cond['building']['buildingId']] < $cond['building']['buildingLevel']) {
                    return false;
                }
            }
            if ($i['cost'] > $userInfo['balance']) {
                return false;
            }
            // TODO: add other conditions
            return true;
        });
        usort($buildings, fn ($a, $b) => $b['incomePerHour'] / $b['cost'] <=> $a['incomePerHour'] / $a['cost']);

        if (empty($buildings)) {
            return false;
        }
        $building = current($buildings);
        $this->logger->info('{bot} for {profile}: build new building {building}', [
            'profile' => $this->curProfile,
            'bot' => $this->getName(),
            'building' => $building['buildingInfo']['nameEn'],
        ]);
        $resp = $this->client->post('BuildingUpgrade?' . http_build_query([
                'buildingId' => $building['buildingId'],
                'authData' => $this->auth,
            ]), [
            'headers' => [
                'content-length' => '0',
            ],
        ]);
        $status = $resp->getBody()->getContents();
        return $status == 'ok';
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
