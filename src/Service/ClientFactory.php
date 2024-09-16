<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Panther\Client;

class ClientFactory
{
    private string $path;

    const UA = 'Mozilla/5.0 (Linux; Android 9; K) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/80.0.3987.132 Mobile Safari/537.36';

    public function __construct(#[Autowire(param:'kernel.project_dir')] string $path)
    {
        $this->path = $path;
    }

    public function getOrCreateBrowser(bool $headless = true): Client
    {
            $options = ['--user-data-dir=' . $this->path . '/profile', '--no-first-run', '--user-agent=' . self::UA];

            if ($headless) {
                $options[] = '--headless';
            }

            $client = Client::createChromeClient(
                null,
                $options,
            );

        return $client;
    }
}
