<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Service\ClientFactory;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Service\Attribute\Required;

class WeMineBot extends BaseBot implements BotInterface
{
    #[Required] public ClientFactory $clientFactory;

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName(), '/k/#@WeMineBot'))->withJitter(7200));
        $schedule->add(RecurringMessage::every('30 minutes', new CustomFunction($this->getName(), 'claimAndReset')));
    }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);
        $client->request('GET', $url);
        $client->waitForElementToContain('#root .balanceWrapper', 'wBTC/d');
        $token = $client->executeScript('return window.localStorage.getItem("accessToken");');

        $item = $this->cache->getItem($this->getName() . ':token');
        $item->set($token);
        $this->cache->save($item);

        parent::saveUrl($client, $url);
    }

    public function claimAndReset()
    {
        if (!$apiClient = $this->getClient()) {
            return;
        }

        $resp = $apiClient->get('auth/profile');
        $profile = json_decode($resp->getBody()->getContents(), true);
        $start = \DateTime::createFromFormat(\DateTime::RFC3339_EXTENDED, $profile['miningStartTime']);
        $last = \DateTime::createFromFormat(\DateTime::RFC3339_EXTENDED, $profile['lastClaimTime']);
        //var_dump($profile['balance']);
        $delta = $last ? $last->diff(New \DateTime()) : \DateInterval::createFromDateString('30 minutes');
        $limit = \DateInterval::createFromDateString('20 minutes');
        $deltaS = $delta->h * 3600 + $delta->i * 60 + $delta->s;
        $limitS = $limit->i * 60 + $limit->s;
        if ($deltaS > $limitS) {
            $apiClient->post('mining/start-claim');
        }
    }

    protected function getClient(): ?\GuzzleHttp\Client
    {
        $token = $this->cache->getItem($this->getName() . ':token')->get();

        if (!$token) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://app.wemine.pro/api/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ClientFactory::UA,
            ]
        ]);
    }
}
