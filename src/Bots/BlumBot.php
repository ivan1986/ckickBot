<?php

namespace App\Bots;

use App\Attributes\ScheduleCallback;
use App\Service\ProfileService;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

class BlumBot extends BaseBot implements BotInterface
{
    const ANSWERS = 'https://raw.githubusercontent.com/boytegar/BlumBOT/master/verif.json';
    const ANS2 = 'https://raw.githubusercontent.com/dancayairdrop/blum/refs/heads/main/nv.json';
    const ANS3 = 'https://raw.githubusercontent.com/srutihsan/keywords-/refs/heads/main/keywords.json';
    const ANS4 = 'https://raw.githubusercontent.com/IopiXD/blum/refs/heads/main/answer.json';

    protected string $path;

    /**
     * @param string $path
     */
    public function __construct(#[Autowire('%kernel.project_dir%')] string $path)
    {
        $this->path = $path;
    }

    public function getTgBotName() { return 'BlumCryptoBot'; }

    public function saveUrl($client, $url)
    {
        $url = $this->platformFix($url);

        $urlFragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str($urlFragment, $urlData);
        $tgData = $urlData['tgWebAppData'];
        $this->UCSet('tgData', $tgData);

        parent::saveUrl($client, $url);
    }

    #[ScheduleCallback('4 hour', delta: 1800)]
    public function game()
    {
        if (!$apiClient = $this->getClient('game-domain')) {
            return false;
        }

        $resp = $apiClient->get('user/balance');
        $balance = json_decode($resp->getBody()->getContents(), true);
        $this->updateStatItem('tickets', $balance['playPasses']);

        if ($balance['playPasses'] == 0) {
            return false;
        }

        $resp = $apiClient->post('/api/v2/game/play');
        $game = json_decode($resp->getBody()->getContents(), true);
        if (empty($game['gameId'])) {
            $this->logger->error('{bot} for {profile}: Broken game - no gameId', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
            ]);
            return false;
        }
        $gameId = $game['gameId'];

        $count = random_int(100, 200);
        $freese_count = random_int(3, 5);
        sleep(30 + $freese_count * 5);

        $payloadProcess = new Process(['node', 'blum.mjs', $gameId, $count, $freese_count], $this->path . '/3part/blum');
        $payloadProcess->run();
        $payloadProcess->wait();
        $payload = trim($payloadProcess->getOutput());

        $resp = $apiClient->post('/api/v2/game/claim', [
            'json' => [
                'payload' => $payload,
            ]
        ]);
        $claim = $resp->getBody()->getContents();
        if ($claim != 'OK') {
            $this->logger->error('{bot} for {profile}: Broken game - not ok', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
            ]);
        }
        $this->logger->info('{bot} for {profile}: game ok', [
            'profile' => $this->curProfile,
            'bot' => $this->getName(),
        ]);
        return true;
    }

    #[ScheduleCallback('4 hour', delta: 1800)]
    public function farming()
    {
        if (!$apiClient = $this->getClient('game-domain')) {
            return false;
        }

        try {
            $apiClient->get('daily-reward?offset=-180');
            sleep(1);
            $apiClient->post('daily-reward?offset=-180');
            $this->logger->info('{bot} for {profile}: daily reward', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
            ]);
        } catch (\Exception $e) {}

        $resp = $apiClient->get('user/balance');
        $balance = json_decode($resp->getBody()->getContents(), true);
        $this->updateStatItem('balance', $balance['availableBalance']);

        if (empty($balance['farming'])) {
            $apiClient->post('farming/start');
            return true;
        }
        if ($balance['farming']['endTime'] < $balance['timestamp']) {
            $apiClient->post('farming/claim');
            sleep(random_int(2,4));
            $apiClient->post('farming/start');
            return true;
        }
        return false;
    }

    #[ScheduleCallback('4 hour', delta: 1800)]
    public function tasks()
    {
        if (!$apiClient = $this->getClient('earn-domain')) {
            return false;
        }

        $resp = $apiClient->get('tasks');
        $tasks = json_decode($resp->getBody()->getContents(), true);

        try {
            shuffle($tasks);
            foreach ($tasks as $section) {
                shuffle($section['tasks']);
                foreach ($section['tasks'] as $task) {
                    $this->processTask($task, $apiClient);
                }
                shuffle($section['subSections']);
                foreach ($section['subSections'] as $tasks) {
                    shuffle($tasks['tasks']);
                    foreach ($tasks['tasks'] as $task) {
                        $this->processTask($task, $apiClient);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->info('{bot} for {profile}: task: {msg}', [
                'profile' => $this->curProfile,
                'bot' => $this->getName(),
                'msg' => $e->getMessage(),
            ]);
            return true;
        }
        return false;
    }

    protected function processTask($task, $apiClient)
    {
        if (!empty($task['subTasks'])) {
            foreach ($task['subTasks'] as $stask) {
                $this->processTask($stask, $apiClient);
            }
            return;
        }

        $types = ['WALLET_CONNECTION', 'ONCHAIN_TRANSACTION', 'PROGRESS_TARGET', 'INTERNAL'];
        if (in_array($task['type'], $types)) {
            return;
        }
        if ($task['status'] == 'FINISHED') {
            return;
        }
        if ($task['status'] == 'NOT_STARTED') {
            $apiClient->post('tasks/' . $task['id'] . '/start');
            throw new \Exception('start :' . $task['title']);
        }
        if ($task['status'] == 'READY_FOR_CLAIM') {
            $apiClient->post('tasks/' . $task['id'] . '/claim');
            throw new \Exception('claim :' . $task['title']);
        }
        if ($task['status'] == 'READY_FOR_VERIFY' && $task['validationType'] == 'KEYWORD') {
            $ans = $this->getAnswers();
            if (empty($ans[$task['id']])) {
                $this->logger->error('{bot} for {profile}: no answer for task {id}: {title}', [
                    'profile' => $this->curProfile,
                    'bot' => $this->getName(),
                    'id' => $task['id'],
                    'title' => $task['title'],
                ]);
                return;
            }
            $keyword = $ans[$task['id']];
            $apiClient->post('tasks/' . $task['id'] . '/validate', ['json' => ['keyword' => $keyword]]);
            throw new \Exception('validate :' . $task['title']);
        }

        echo $task['status'] . PHP_EOL;
    }

    protected function getAnswers()
    {
        $ans = $this->cache->get($this->botKey('ans'));
        if ($ans) {
            return json_decode($ans, true);
        }

        $allAnswers = [];

        $ghClient = new \GuzzleHttp\Client([
            'base_uri' => self::ANSWERS,
            'headers' => [
                'Content-Type' => 'application/json',
            ]
        ]);
        $resp = $ghClient->get(self::ANSWERS);
        $ans = $resp->getBody()->getContents();
        $allAnswers = json_decode($ans, true);

        $resp = $ghClient->get(self::ANS2);
        $ans = json_decode($resp->getBody()->getContents(), true);
        foreach ($ans['tasks'] as $item) {
            $allAnswers[$item['id']] = $item['keyword'];
        }

        $resp = $ghClient->get(self::ANS3);
        $ans = $resp->getBody()->getContents();
        $ans = json_decode($ans, true);
        $allAnswers = array_merge($allAnswers, $ans);

        $resp = $ghClient->get(self::ANS4);
        $ans = $resp->getBody()->getContents();
        $ans = json_decode($ans, true);
        $allAnswers = array_merge($allAnswers, $ans);

        // Fixes
        $allAnswers['27ac150a-e5bd-49eb-82e4-13bde70b3f3c'] = 'Elsalvador';

        $this->cache->setEx($this->botKey('ans'), 3600 * 24, json_encode($allAnswers));
        return $allAnswers;
    }

    protected function getAccessToken($tg_data)
    {
        $authClient = new \GuzzleHttp\Client([
            'base_uri' => 'https://user-domain.blum.codes/api/v1/',
            RequestOptions::PROXY => $this->getProxy(),
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
        $resp = $authClient->post('auth/provider/PROVIDER_TELEGRAM_MINI_APP', [
            'json' => [
                'query' => $tg_data,
            ]
        ]);
        $auth = json_decode($resp->getBody()->getContents(), true);
        return $auth['token']['access'];
    }

    protected function getClient($subDomain): ?\GuzzleHttp\Client
    {
        $token = $this->UCGet('token');

        if (!$token) {
            $tgData = $this->UCGet('tgData');
            $token = $this->getAccessToken($tgData);
            $this->UCSet('token', $token, 1800);
        }

        return new \GuzzleHttp\Client([
            'base_uri' => 'https://' . $subDomain . '.blum.codes/api/v1/',
            RequestOptions::PROXY => $this->getProxy(),
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'User-Agent' => ProfileService::UA,
            ]
        ]);
    }
}
