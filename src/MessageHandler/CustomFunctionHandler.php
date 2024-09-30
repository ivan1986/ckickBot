<?php

namespace App\MessageHandler;

use App\Bots\BaseBot;
use App\Message\CustomFunction;
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

    public function __invoke(CustomFunction $message): void
    {
        /** @var BaseBot $bot */
        $bot = $this->botSelector->getBot($message->name);
        $bot->setProfile('ivan');
        $bot->{$message->callback}();

        $bot->cache->hSet(
            $bot->userKey('run'),
            $message->callback,
            Carbon::now()->getTimestamp()
        );
    }
}
