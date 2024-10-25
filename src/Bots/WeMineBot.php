<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Service\Attribute\Required;

class WeMineBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'WeMineBot'; }

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('30 minutes', new CustomFunction($this->getName(), 'claimAndReset')));
        $schedule->add(RecurringMessage::every('6 hour', new CustomFunction($this->getName(), 'convertAndUpgrade')));
    }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);
        $client->request('GET', $url);
        $client->waitForElementToContain('#root .balanceWrapper', 'wBTC/d');
        $token = $client->executeScript('return window.localStorage.getItem("accessToken");');

        $this->UCSet('token', $token);

        parent::saveUrl($client, $url);
    }

    public function claimAndReset()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('auth/profile');
        $profile = json_decode($resp->getBody()->getContents(), true);
        $this->updateStat($profile);
        $start = \DateTime::createFromFormat(\DateTime::RFC3339_EXTENDED, $profile['miningStartTime']);
        $last = \DateTime::createFromFormat(\DateTime::RFC3339_EXTENDED, $profile['lastClaimTime']);
        $delta = $last ? $last->diff(New \DateTime()) : \DateInterval::createFromDateString('30 minutes');
        $limit = \DateInterval::createFromDateString('20 minutes');
        $deltaS = $delta->h * 3600 + $delta->i * 60 + $delta->s;
        $limitS = $limit->i * 60 + $limit->s;
        if ($deltaS > $limitS) {
            $apiClient->post('mining/wbtc/start-claim');
        }
    }

    public function convertAndUpgrade()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('auth/profile');
        $profile = json_decode($resp->getBody()->getContents(), true);
        $this->updateStat($profile);
        $last = \DateTime::createFromFormat(\DateTime::RFC3339_EXTENDED, $profile['lastExchangeTime']);
        $delta = $last ? $last->diff(New \DateTime()) : \DateInterval::createFromDateString('30 minutes');
        $deltaS = $delta->h * 3600 + $delta->i * 60 + $delta->s;
        if ($deltaS > 1800 && $profile['balance']['wBTC'] > 0.001) {
            $apiClient->post('exchange/btc-to-usd', ['json' => ['amount' => $profile['balance']['wBTC']]]);
        }

        $curAsicId = $profile['currentAsic'];
        $curAsicLevel = null;
        $nextAsic = null;
        $resp = $apiClient->get('mining/wbtc/asics');
        $asics = json_decode($resp->getBody()->getContents(), true);
        foreach ($asics as $k => $v) {
            if ($v['_id'] === $curAsicId) {
                $curAsicLevel = $v['level'];
                $nextAsic = $asics[$k + 1] ?? null;
                break;
            }
        }
        if ($nextAsic && $nextAsic['purchaseCost'] < $profile['balance']['wUSD']) {
            $apiClient->post('mining/purchase', ['json' => ['asicId' => $nextAsic['_id']]]);
        }

        $curLevelsCode = $curAsicLevel * 10;
        $upgrades = [
            'coolingSystem' => 8,
            'ramCapacity' => 8,
            'softwareEnhancement' => 8,
            //'reductionOfHeatLoss' => 4,
            //'processAutomation' => 4
        ];
        $info = null;
        foreach ($upgrades as $upgrade => $maxLevel) {
            $curLevel = $profile['upgrades'][$upgrade];
            $curLevelsCode = $curLevelsCode * 10 + $curLevel;
            if ($curLevel < min($curAsicLevel, $maxLevel)) {
                if (!$info) {
                    $resp = $apiClient->get('upgrades');
                    $info = json_decode($resp->getBody()->getContents(), true);
                }
                foreach ($info as $v) {
                    if ($v['type'] === $upgrade) {
                        if ($v['levelCosts'][$curLevel + 1] < $profile['balance']['wUSD']) {
                            $apiClient->post('upgrades/upgrade', ['json' => ['upgradeType' => $upgrade]]);
                            break;
                        }
                    }
                }
            }
        }
        $this->updateStatItem('level', $curLevelsCode);
    }

    protected function updateStat($balance)
    {
        $this->updateStatItem('wUSD', round($balance['balance']['wUSD'], 2));
        $this->updateStatItem('wBTC', round($balance['balance']['wBTC'], 8));
        $this->updateStatItem('All', round($balance['allTimeBTC'], 8));
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->UCGet('token');

        if (!$token) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://app.wemine.pro/api/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}
