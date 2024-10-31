<?php

namespace App\MessageHandler;

use App\Bots\BaseBot;
use App\Message\CustomFunctionUser;
use App\Model\ActionState;
use App\Service\BotSelector;
use App\Service\CacheService;
use Carbon\Carbon;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

#[AsMessageHandler]
final class CustomFunctionHandler
{
    #[Required] public LoggerInterface $logger;
    #[Required] public BotSelector $botSelector;
    #[Required] public CacheService $cache;

    public function __invoke(CustomFunctionUser $message): void
    {
        /** @var BaseBot $bot */
        $bot = $this->botSelector->getBot($message->name);
        $bot->setProfile($message->profile);
        $this->logger->info('Run for {profile}: {bot}->{callback}', [
            'profile' => $message->profile,
            'bot' => $message->name,
            'callback' => $message->callback
        ]);
        $bot->logAction($message->callback, ActionState::START);
        try {
            $result = $bot->{$message->callback}();
            if ($result) {
                $bot->logAction($message->callback, ActionState::CHANGE);
            }
        } catch (Exception $e) {
            $bot->logAction($message->callback, ActionState::ERROR);
            $this->logger->error('Error for ' . $message->profile . ' in ' . $message->name . ' ' . $message->callback . ' :' . $e->getMessage());
            return;
        }
        $bot->logAction($message->callback, ActionState::FINISH);
    }
}
