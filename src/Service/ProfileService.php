<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Panther\Client;
use Symfony\Contracts\Service\Attribute\Required;

class ProfileService
{
    #[Required] public CacheService $cacheService;

    const TTL = 3600 * 24 * 2;
    private string $path;
    private array $profiles = [];

    //const UA = 'Mozilla/5.0 (Linux; Android 9; K) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/80.0.3987.132 Mobile Safari/537.36';
    const UA = 'Mozilla/5.0 (Linux; Android 13; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.5615.101 Mobile Safari/537.36 Telegram-Android/11.2.2 (Xiaomi Redmi Note 9; Android 13; SDK 33; AVERAGE)';
    //const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

    public function __construct(#[Autowire(param:'env(PROFILES)')] string $path)
    {
        $this->path = $path;
    }

    public function getOrCreateBrowser(string $profile, bool $headless = true, string $ua = ''): Client
    {
        $options = ['--user-data-dir=' . $this->path . '/' . $profile, '--no-first-run', '--user-agent=' . ($ua ?: self::UA)];

        if ($headless) {
            $options[] = '--headless';
        }

        $client = Client::createChromeClient(
            null,
            $options,
        );

        return $client;
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

        $files = glob($this->path . '/*');
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
}
