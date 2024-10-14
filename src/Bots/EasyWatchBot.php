<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use Carbon\Carbon;
use Symfony\Component\Panther\Client;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class EasyWatchBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'ESWatch_bot'; }

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName()))->withJitter(7200));
        $schedule->add(RecurringMessage::every('30 min', new CustomFunction($this->getName(), 'checkStream')));
    }

    public function runInTg(Client $client)
    {
        $client->executeScript(<<<JS
            if (document.querySelector('.reply-markup-button') === null) {
                document.querySelector('.autocomplete-peer-helper-list-element').click();
            }
            document.querySelector('.reply-markup-button').click();
        JS
        );
        sleep(5);
        parent::runInTg($client);
    }

    public function checkStream()
    {
        if (!$this->getUrl()) {
            return;
        }
        if ($this->cache->get($this->botKey('stream'))) {
            if ($this->cache->ttl($this->userKey('cookies')) > 3600 * 12) {
                return;
            }
        }
        // Ночью все скипаем
        if (Carbon::now()->format('H') < 6) {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $this->getUrl());
        sleep(2);
        $cookies = $client->getCookieJar();
        $client->waitForVisibility('[data-test-id="user-balance"]');
        $balance = $client->executeScript(<<<JS
            return document.querySelector('[data-test-id="user-balance"]').dataset;
        JS);
        $balance = (float)str_replace(' ', '', $balance['title']);
        $this->updateStat($balance);
        $client->executeScript(<<<JS
            document.querySelector('a[href="/streams"]').click();
        JS);
        sleep(2);
        $stream = $client->executeScript(<<<JS
            return document.querySelector('a[href^="/streams/"]').href;
        JS);
        $stream = explode('/', $stream);
        $stream = array_pop($stream);

        $cookiesArray = [];
        foreach ($cookies->all() as $cookie) {
            $cookiesArray[] = $cookie->getName() . '=' . $cookie->getValue();
        }
        $this->UCSet('cookies', 'cookie: ' . join('; ', $cookiesArray));
        $this->cache->hSet(
            $this->userKey('run'),
            'updateCookie',
            Carbon::now()->getTimestamp()
        );

        $this->cache->set($this->botKey('stream'), $stream);
    }

    protected function updateStat($balance)
    {
        $balance = round($balance, 3);
        $gauge = $this->collectionRegistry->getOrRegisterGauge(
            $this->getName(),
            'balance',
            'Balance',
            ['user']
        );
        $gauge->set($balance, [$this->curProfile]);
        $this->cache->hSet($this->userKey('status'), 'balance', $balance);
    }
}
