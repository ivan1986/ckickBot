<?php

namespace App\Bots;

use GuzzleHttp\Cookie\CookieJar as GuzzleCookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;
use Symfony\Component\Panther\Cookie\CookieJar as SymfonyCookieJar;
use Symfony\Contracts\Service\Attribute\Required;

class BaseBot
{
    #[Required] public CacheItemPoolInterface $cache;

    public function getName(): string
    {
        return (new ReflectionClass(static::class))->getShortName();
    }

    public function runInTg($client)
    {
    }

    public function saveUrl($client, $url)
    {
        $item = $this->cache->getItem($this->getName() . ':url');
        $item->set($url);
        $this->cache->save($item);
    }

    public function getUrl()
    {
        return $this->cache->getItem($this->getName() . ':url')->get();
    }

    protected function platformFix($url)
    {
        return str_replace('tgWebAppPlatform=web', 'tgWebAppPlatform=android', $url);
    }

    protected function convertCookies(SymfonyCookieJar $symfonyCookieJar): GuzzleCookieJar
    {
        $host = parse_url($this->getUrl(), PHP_URL_HOST);
        $jar = new GuzzleCookieJar();
        foreach ($symfonyCookieJar->all() as $cookie) {
            $jar->setCookie(new SetCookie([
                'Domain' => $host,
                'Name' => $cookie->getName(),
                'Value' => $cookie->getValue(),
                'Discard' => true,
            ]));
        }
        return $jar;
    }
}
