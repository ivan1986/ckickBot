<?php

namespace App\MessageHandler;

use App\Message\CustomFunction;
use App\Service\BotSelector;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

#[AsMessageHandler]
final class CustomFunctionHandler
{
    #[Required] public BotSelector $botSelector;

    public function __invoke(CustomFunction $message): void
    {
        //echo 'here: '.$message->name . ' ' . $message->callback . PHP_EOL;
        $this->botSelector->getBot($message->name)->{$message->callback}();
    }
}
