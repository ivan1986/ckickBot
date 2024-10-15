<?php

namespace App\Bots;

use App\Message\CustomFunction;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

class TonMusicBot extends BaseBot implements BotInterface
{
    public function getTgBotName() { return 'tonmusic_game_bot'; }

    public function addSchedule(Schedule $schedule)
    {
        $schedule->add(RecurringMessage::every('12 hour', new UpdateUrl($this->getName()))->withJitter(7200));
        $schedule->add(RecurringMessage::every('1 hour', new CustomFunction($this->getName(), 'checkSlots')));
    }

    public function checkSlots()
    {
        $url = $this->getUrl();
        if (!$url) {
            return;
        }

        $client = $this->profileService->getOrCreateBrowser($this->curProfile);
        $client->request('GET', $url);
        $page = $client->getPageSource();
        preg_match('#<meta name="csrf-token" content="(.*?)">#', $page, $matches);
        $csrfToken = $matches[1];

        $apiClient = new \GuzzleHttp\Client([
            'base_uri' => 'https://tg-app.ton-music.com/',
            'cookies' => $this->convertCookies($client->getCookieJar()),
            'headers' => [
                'X-CSRF-Token' => $csrfToken,
                'Content-Type' => 'application/json, text/plain, */*',
                'Sec-Fetch-Dest' => 'empty',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
        $resourcesResponse = $apiClient->get('/api/resources')->getBody()->getContents();
        $resourcesResponse = json_decode($resourcesResponse, true);
        $resources = $resourcesResponse['result']['resources'];
        $resourcesOkForBig = $resources['disks'] > 50 && $resources['cans'] > 50 && $resources['coins'] > 50;

        foreach (['disks', 'cans', 'coins'] as $resource) {
            $this->updateStatItem($resource, $resources[$resource]);
        }

        $slotsResponse = $apiClient->get('/api/slots')->getBody()->getContents();
        $slotsResponse = json_decode($slotsResponse, true);
        $slots = $slotsResponse['result']['inventory_slots'];

        // only available slots
        $slots = array_filter($slots, function ($slot) {
            return $slot['instrument_type'] != null;
        });
        $slots = array_filter($slots, function ($slot) {
            return $slot['seconds_till_end_of_mining'] === null;
        });
        // disable not promo if we have small resources
        $slots = array_filter($slots, function ($slot) use ($resourcesOkForBig) {
            if (str_contains($slot['instrument_type'], 'promo')) {
                return true;
            }
            return $resourcesOkForBig;
        });
        if (empty($slots)) {
            return;
        }


        $price = [
            'energy' => 0,
            'disks' => 0,
            'coins' => 0,
        ];
        foreach ($slots as $slot) {
            foreach ($slot['costs_per_one_mining_period']['activation_price'] as $resource => $item) {
                $price[$resource] = ($price[$resource] ?? 0) + $item;
            }
            foreach ($slot['costs_per_one_mining_period']['mining_price'] ?? [] as $resource => $item) {
                $price[$resource] = ($price[$resource] ?? 0) + $item;
            }
        }


        // бахнуть энергетика
        if ($resources['energy'] < $price['energy']) {
            $needEnergy = $price['energy'] - $resources['energy'];
            $needCans = ceil( $needEnergy / 10);
            $needCans = min($needCans, $resources['cans']);
            $apiClient->post('/api/accounts/drink', ['json' => ['cans' => $needCans]]);
            sleep(2);
        }

        foreach ($slots as $slot) {
            try {
                $apiClient->post('/api/slots/' . $slot['id'] . '/pay_service');
                sleep(2);
            } catch (ClientException $e) {}
            try {
                $apiClient->post('/api/slots/' . $slot['id'] . '/claim');
                sleep(2);
            } catch (ClientException $e) {}
        }

        //var_dump($price);


//        $slots = array_filter($slots, function ($slot) {
//            return $slot['instrument_type'] != null;
//        });

            //var_dump($slots);
    }

}
