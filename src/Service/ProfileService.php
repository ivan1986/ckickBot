<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Panther\Client;
use Symfony\Contracts\Service\Attribute\Required;

class ProfileService
{
    #[Required] public CacheService $cacheService;

    const TTL = 3600 * 24 * 2;
    const PROXY_KEY = 'proxy';

    private string $path;
    private string $profilePath;
    private string $tmpPath;
    private array $profiles = [];

    //const UA = 'Mozilla/5.0 (Linux; Android 9; K) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/80.0.3987.132 Mobile Safari/537.36';
    const UA = 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.5615.101 Mobile Safari/537.36 Telegram-Android/11.2.2 (Xiaomi Redmi Note 9; Android 13; SDK 33; AVERAGE)';
    //const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

    public function __construct(
        #[Autowire('%kernel.project_dir%')] string $path,
        #[Autowire(param:'env(PROFILES)')] string $profilePath,
        #[Autowire('%kernel.cache_dir%')] string $tmpPath,
    )
    {
        $this->path = $path;
        $this->tmpPath = $tmpPath;
        $this->profilePath = $profilePath;
    }

    public function getOrCreateBrowser(
        string $profile,
        bool $headless = true,
        string $ua = ''
    ): Client
    {
        $arguments = [
            '--user-data-dir=' . $this->profilePath . '/' . $profile,
            '--no-first-run',
            '--disable-gpu',
            '--user-agent=' . ($ua ?: self::UA)
        ];

        if ($headless) {
            $arguments[] = '--headless';
        }

        $options = ['request_timeout_in_ms' => 100 * 1000];

        if ($proxy = $this->getGuzzleProxy($profile)) {
            $proxyFile = $this->generateProxyConfig($proxy);
            $arguments[] = '--disable-ipv6';
            $options['proxychain'] = $proxyFile;
        }


        $client = Client::createChromeClient(
            null,
            $arguments,
            $options
        );
        $client->manage()->window()->maximize();

        return $client;
    }

    protected function generateProxyConfig(string $proxy): string
    {
        $file = $this->tmpPath . '/' . md5($proxy) . '.conf';

        if (file_exists($file)) {
            return $file;
        }

        $info = parse_url($proxy);

        $string = $info['scheme'] . "\t" . $info['host'] . "\t" . ($info['port'] ?? 3128);
        if (isset($info['user']) && isset($info['pass'])) {
            $string .= "\t" . $info['user'] . "\t" . $info['pass'];
        }

        $fileContent = <<<EOF
        tcp_read_time_out 15000
        tcp_connect_time_out 8000

        localnet 127.0.0.0/255.0.0.0
        localnet ::1/128
        
        [ProxyList]
        $string
        EOF;

        file_put_contents($file, $fileContent);

        return $file;
    }

    public function list(): array
    {
        if ($this->profiles) {
            return $this->profiles;
        }
        $cached = $this->cacheService->get('profiles');
        if ($cached) {
            return $this->profiles = json_decode($cached, true);
        }

        $files = glob($this->profilePath . '/*');
        $profiles = [];
        foreach ($files as $file) {
            $profiles[] = basename($file);
        }
        if(empty($profiles)) {
            return [];
        }
        $this->cacheService->setex('profiles', self::TTL, json_encode($profiles));
        $this->profiles = $profiles;
        return $this->profiles;
    }

    public function getAllProxy(): array
    {
        $exist = $this->cacheService->hGetAll(self::PROXY_KEY) ?? [];
        $result = [];
        foreach ($this->list() as $profile) {
            $result[$profile] = $exist[$profile] ?? '';
        }
        return $result;
    }

    public function setProxy(string $profile, string $proxy)
    {
        $this->cacheService->hSet(self::PROXY_KEY, $profile, $proxy);
    }

    public function getGuzzleProxy(string $profile): ?string
    {
        return $this->cacheService->hGet(self::PROXY_KEY, $profile) ?? null;
    }

}
