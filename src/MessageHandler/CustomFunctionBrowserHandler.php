<?php

namespace App\MessageHandler;

use App\Bots\BaseBot;
use App\Message\CustomFunctionBrowserUser;
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
final class CustomFunctionBrowserHandler
{
    #[Required] public LoggerInterface $logger;
    #[Required] public BotSelector $botSelector;
    #[Required] public CacheService $cache;

    public function __invoke(CustomFunctionBrowserUser $message): void
    {
        /** @var BaseBot $bot */
        $bot = $this->botSelector->getBot($message->name);
        $bot->setProfile($message->profile);
        $this->logger->info('Run for {profile} in browser: {bot}->{callback}', [
            'profile' => $message->profile,
            'bot' => $message->name,
            'callback' => $message->callback
        ]);
        $bot->logAction($message->callback, ActionState::START);
        try {
            $result = $bot->{$message->callback}();
            $bot->logAction($message->callback, $result ? ActionState::CHANGE : ActionState::FINISH);
            $this->logger->info('Run for {profile} in browser: {bot}->{callback} success', [
                'profile' => $message->profile,
                'bot' => $message->name,
                'callback' => $message->callback
            ]);
        } catch (Exception $e) {
            $bot->logAction($message->callback, ActionState::ERROR);
            $this->logger->error('Error for {profile} in {bot}->{callback}: {error}', [
                'profile' => $message->profile,
                'bot' => $message->name,
                'callback' => $message->callback,
                'error' => $e->getMessage()
            ]);
            if (str_contains($e->getMessage(), 'Chrome failed to start')) {
                $this->logger->error('Restart worker - fail Webdriver');
                exit(1);
            }
            return;
        }
    }
}
