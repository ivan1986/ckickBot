<?php

namespace App\Command;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Service\Attribute\Required;

#[AsCommand(
    name: 'factora:upEnergy',
    description: 'TODO',
)]
class FactoriaUpEnergyCommand extends Command
{
    const KEY = 'auth';

    #[Required] public CacheItemPoolInterface $cache;
    private $client;
    private $auth;

    protected function configure(): void
    {
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->client = new \GuzzleHttp\Client([
            'base_uri' => 'https://api.factoragame.com/FactoraTapApi/',
            'headers' => [
                'Content-Type' => 'application/json, text/plain, */*',
                'Sec-Fetch-Dest' => 'empty',
                'User-Agent' => 'Mozilla/5.0 (Linux; Android 9; K) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/80.0.3987.132 Mobile Safari/537.36',
            ]
        ]);
        $authCache = $this->cache->getItem(self::KEY);
        $this->auth = $authCache->get();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $auth = 'dXNlcj0lN0IlMjJpZCUyMiUzQTE5NDMxMDgwNiUyQyUyMmZpcnN0X25hbWUlMjIlM0ElMjJJdmFuJTIyJTJDJTIybGFzdF9uYW1lJTIyJTNBJTIyQm9yemVua292JTIyJTJDJTIydXNlcm5hbWUlMjIlM0ElMjJJdmFuQm9yemVua292MTk4NiUyMiUyQyUyMmxhbmd1YWdlX2NvZGUlMjIlM0ElMjJydSUyMiUyQyUyMmFsbG93c193cml0ZV90b19wbSUyMiUzQXRydWUlN0QmY2hhdF9pbnN0YW5jZT0zMzk1ODAyNjcxMzE4NTMzMjU3JmNoYXRfdHlwZT1zZW5kZXImYXV0aF9kYXRlPTE3MjQyNzM2NzkmaGFzaD04NTJhNGY0NTJkNzFjMzU0Nzk1YjgxODFhZTI5ZTdmZjFlZmUzN2Y5ZTQ5MDU5NTJiYjBiM2Y4MzcxOWI0YTNj';

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

//        $userInfo['totalEnergyConsumptionPerHour']
//        $userInfo['currentEnergy'];
//        $userInfo['energyLimit'];
//        $userInfo['tapPower'];

        return 0;
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
