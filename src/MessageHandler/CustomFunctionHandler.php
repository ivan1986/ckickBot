<?php

namespace App\MessageHandler;

use App\Bots\BaseBot;
use App\Message\CustomFunctionUser;
use App\Service\BotSelector;
use App\Service\CacheService;
use Carbon\Carbon;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

#[AsMessageHandler]
final class CustomFunctionHandler
{
    #[Required] public BotSelector $botSelector;
    #[Required] public CacheService $cache;

    public function __invoke(CustomFunctionUser $message): void
    {
        /** @var BaseBot $bot */
        $bot = $this->botSelector->getBot($message->name);
        $bot->setProfile($message->profile);
        $bot->{$message->callback}();

        $bot->cache->hSet(
            $bot->userKey('run'),
            $message->callback,
            Carbon::now()->getTimestamp()
        );
    }
}
