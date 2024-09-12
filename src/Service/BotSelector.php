<?php

namespace App\Service;

use App\Bots\BotInterface;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class BotSelector
{
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

    public function getAll(): array
    {
        return $this->bots;
    }

    public function getBot($name): ?BotInterface
    {
        return $this->bots[$name] ?? null;
    }
}
