<?php

namespace App\Service;

use App\Bots\BotInterface;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;

class BotSelector
{
    #[Required] public CacheService $cacheService;
    #[Required] public ProfileService $profileService;

    /** @var BotInterface[]  */
    private array $bots = [];

    public function __construct(#[AutowireIterator('bot')] iterable $bots)
    {
        foreach ($bots as $b) {
            $name = (new ReflectionClass($b))->getShortName();
            /** @var BotInterface $b */
            $this->bots[$name] = $b;
        }
    }

    public function getNames(): array
    {
        return array_keys($this->bots);
    }

    public function getAll(): array
    {
        return $this->bots;
    }

    public function getForProfile(string $profile): array
    {
        $bots = [];
        foreach ($this->bots as $name => $bot) {
            if ($this->isEnabled($profile, $name)) {
                $bots[] = $bot;
            }
        }
        return $bots;
    }

    public function getBot($name): ?BotInterface
    {
        return $this->bots[$name] ?? null;
    }

    public function toggle(string $profile, string $bot, bool $enable): void
    {
        $key = $bot . ':' . $profile . '::' . 'enable';

        if ($enable) {
            $this->cacheService->set($key, 1);
        } else {
            $this->cacheService->del($key);
        }
    }

    public function getBotUrl(string $profile, string $bot): ?string
    {
        $key = $bot . ':' . $profile . ':' . 'url';
        return $this->cacheService->get($key) ?: false;
    }

    public function isEnabled(string $profile, string $bot): bool
    {
        $key = $bot . ':' . $profile . '::' . 'enable';
        return $this->cacheService->get($key) ?: false;
    }

}
