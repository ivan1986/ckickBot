<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class CacheService extends \Redis
{
    public function __construct(#[Autowire(param:'env(REDIS_DSN)')] string $dsn)
    {
        $info = parse_url($dsn);
        $db = trim($info['path'], '/');
        $db = intval($db);
        parent::__construct();
        $this->pconnect($info['host'], $info['port'] ?? 6379);
        $this->select($db);
    }
}
