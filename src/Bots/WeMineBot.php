<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Message\CustomFunction;
use App\Message\CustomFunctionUser;
use App\Message\UpdateUrl;
use App\Service\ProfileService;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Contracts\Service\Attribute\Required;

class WeMineBot extends BaseBot implements BotInterface
{
    use MultiUser;

    public function getTgBotName() { return 'WeMineBot'; }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);
        $client->request('GET', $url);
        $client->waitForElementToContain('#root .balanceWrapper', 'wBTC/d');
        $token = $client->executeScript('return window.localStorage.getItem("accessToken");');

        $this->UCSet('token', $token);

        parent::saveUrl($client, $url);
    }

    public function getProxy()
    {
        return '';
        //return 'socks://127.0.0.1:2080';
    }

    #[ScheduleCallback('30 min', delta: 900)]
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
//        if (isset($profile['balance']['usdt']) && $profile['balance']['usdt'] > 0) {
//            $apiClient->post('mining/usdt/start-claim');
//            sleep(10);
//        }
        if ($deltaS > $limitS) {
            $apiClient->post('mining/wbtc/start-claim');
            return true;
        }
    }

    #[ScheduleCallback('6 hour', delta: 3600)]
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
            $this->markRun('exchange');
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
            return true;
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
                            return true;
                        }
                    }
                }
            }
        }
        $this->updateStatItem('level', $curLevelsCode);
    }

    public function addSchedule(Schedule $schedule): void
    {
        $profiles = $this->getEnabledProfiles();
        if (count($profiles) < 3) {
            return;
        }

        $schedule->add(RecurringMessage::cron('0 20 * * *',
            new CustomFunctionUser($profiles[0], $this->getName(), 'findKey'),
            new \DateTimeZone('Europe/Moscow')
        ));
    }

    //region Crack daily case
    public function findKey()
    {
        $profiles = $this->getEnabledProfiles();
        if (count($profiles) < 3) {
            //TODO: Не хватает профилей
            return false;
        }
        $findDigits = [];
        sleep(10);

        $check = [
            0 => [0,1,2,3,4],
            1 => [5,6,7,8,9],
        ];
        foreach ($check as $num => $digits) {
            $this->curProfile = $profiles[$num];
            $apiClient = $this->getClient();
            foreach ($digits as $d) {
                if (count($findDigits) == 3) {
                    break 2;
                }
                $key = $d . $d . $d;
                $result = $this->enterKey($apiClient, $key);
                if ($result == 1) {
                    $findDigits[] = $d;
                }
                if ($result == 2) {
                    $findDigits[] = $d;
                    $findDigits[] = $d;
                }
                if ($result == 3) {
                    // Мы его нашли
                    $this->logger->info('{bot}: find key: {key}', [
                        'bot' => $this->getName(),
                        'key' => $key,
                    ]);
                    $this->enterKeyForAll($key);
                    return true;
                }
            }
        }

        $this->logger->info('{bot}: find digits: {digits}', [
            'bot' => $this->getName(),
            'digits' => join(', ', $findDigits),
        ]);

        // генерим перестановки
        $needCheck = [
            $findDigits[0] . $findDigits[1] . $findDigits[2],
            $findDigits[0] . $findDigits[2] . $findDigits[1],
            $findDigits[1] . $findDigits[0] . $findDigits[2],
            $findDigits[1] . $findDigits[2] . $findDigits[0],
            $findDigits[2] . $findDigits[0] . $findDigits[1],
            $findDigits[2] . $findDigits[1] . $findDigits[0],
        ];
        // для повторяющихся цифр
        $needCheck = array_unique($needCheck);

        $this->curProfile = $profiles[3];
        $apiClient = $this->getClient();

        foreach ($needCheck as $key) {
            $result = $this->enterKey($apiClient, $key);
            if ($result == 3) {
                // Мы его нашли
                $this->logger->info('{bot}: find key: {key}', [
                    'bot' => $this->getName(),
                    'key' => $key,
                ]);
                $this->enterKeyForAll($key);
                return true;
            }
        }

        return false;
    }

    protected function enterKeyForAll($key)
    {
        $profiles = $this->getEnabledProfiles();
        $other = array_filter($profiles, fn ($profile) => $profile !== $this->curProfile);
        foreach ($other as $profile) {
            $this->curProfile = $profile;
            $apiClient = $this->getClient();
            try {
                $this->enterKey($apiClient, $key);
            } catch (\Exception $e) {
                $this->logger->error('{bot} enter key for {profile}: {error}', [
                    'bot' => $this->getName(),
                    'profile' => $this->curProfile,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function enterKey($apiClient, $key)
    {
        $resp = $apiClient->post('roulette/check/' . $key);
        $content = $resp->getBody()->getContents();
        $this->logger->debug('{bot} roulette/check for {profile} key {key}: {result}', [
            'bot' => $this->getName(),
            'profile' => $this->curProfile,
            'key' => $key,
            'result' => $content,
        ]);
        $info = json_decode($content, true);
        return $info['matchCount'];
    }
    //endregion

    protected function updateStat($balance)
    {
        if (isset($balance['balance']['usdt'])) {
            $this->updateStatItem('USDT', round($balance['balance']['usdt'], 8));
        }
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
            'base_uri' => 'https://api.wemine.pro/api/v1/',
            RequestOptions::PROXY => $this->getProxy(),
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}
