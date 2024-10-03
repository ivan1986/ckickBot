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
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName()))->withJitter(7200));
        $schedule->add(RecurringMessage::every('30 minutes', new CustomFunction($this->getName(), 'claimAndReset')));
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
            $apiClient->post('mining/start-claim');
        }
    }

    protected function updateStat($balance)
    {
        $usd = round($balance['balance']['wUSD'], 2);
        $btc = round($balance['balance']['wBTC'], 8);
        $all = round($balance['allTimeBTC'], 8);
        $gauge = $this->collectionRegistry->getOrRegisterGauge(
            $this->getName(),
            'balance_wusd',
            'Balance wUSD',
            ['user']
        );
        $gauge->set($usd, [$this->curProfile]);
        $gauge = $this->collectionRegistry->getOrRegisterGauge(
            $this->getName(),
            'balance_wbtc',
            'Balance wBTC',
            ['user']
        );
        $gauge->set($btc, [$this->curProfile]);
        $gauge = $this->collectionRegistry->getOrRegisterGauge(
            $this->getName(),
            'all_wbtc',
            'All wBTC',
            ['user']
        );
        $gauge->set($all, [$this->curProfile]);
        $this->cache->hSet($this->userKey('status'), 'wUSD', $usd);
        $this->cache->hSet($this->userKey('status'), 'wBTC', $btc);
        $this->cache->hSet($this->userKey('status'), 'All', $all);
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
