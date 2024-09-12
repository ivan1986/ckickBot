<?php

namespace App\Bots;

use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;
use Symfony\Contracts\Service\Attribute\Required;

class BaseBot
{
    #[Required] public CacheItemPoolInterface $cache;

    public function getName(): string
    {
        return (new ReflectionClass(static::class))->getShortName();
    }

    public function saveUrl($url)
    {
        $item = $this->cache->getItem($this->getName() . ':url');
        $item->set($url);
        $this->cache->save($item);
    }
}
