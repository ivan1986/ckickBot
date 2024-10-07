<?php

namespace App\Bots;

use App\Service\CacheService;
use App\Service\ProfileService;
use GuzzleHttp\Cookie\CookieJar as GuzzleCookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Prometheus\CollectorRegistry;
use ReflectionClass;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\Cookie\CookieJar as SymfonyCookieJar;
use Symfony\Contracts\Service\Attribute\Required;

class BaseBot
{
    const TTL = 3600 * 24;
    #[Required] public CacheService $cache;
    #[Required] public ProfileService $profileService;
    #[Required] public CollectorRegistry $collectionRegistry;
    protected string $curProfile = '';

    public function setProfile(string $profile)
    {
        $this->curProfile = $profile;
        return $this;
    }

    public function getName(): string
    {
        return (new ReflectionClass(static::class))->getShortName();
    }

    public function UCSet($key, $value, $ttl = self::TTL)
    {
        return $this->cache->setEx($this->userKey($key), $ttl, $value);
    }
    public function UCGet($key)
    {
        return $this->cache->get($this->userKey($key));
    }

    public function runInTg(Client $client)
    {
    }

    public function saveUrl($client, $url)
    {
        $this->UCSet('url', $url);
    }

    public function getUrl()
    {
        return $this->UCGet('url');
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

    public function userKey(string $key)
    {
        return $this->getName() . ':' . $this->curProfile . ':' . $key;
    }

    public function botKey(string $key)
    {
        return $this->getName() . ':::' . $key;
    }
}
