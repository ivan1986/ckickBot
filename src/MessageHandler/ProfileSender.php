<?php

namespace App\MessageHandler;

use App\Message\CustomFunction;
use App\Message\CustomFunctionBrowserUser;
use App\Message\CustomFunctionUser;
use App\Message\UpdateUrl;
use App\Message\UpdateUrlUser;
use App\Service\BotSelector;
use App\Service\CacheService;
use App\Service\ProfileService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Service\Attribute\Required;

class ProfileSender
{
    #[Required] public ProfileService $profileService;
    #[Required] public BotSelector $botSelector;
    #[Required] public CacheService $cache;
    #[Required] public MessageBusInterface $bus;

    #[AsMessageHandler]
    public function urlHandler(UpdateUrl $message)
    {
        foreach($this->profiles($message->name) as $profile) {
            $stamps = [];
            if ($message->delta) {
                $stamps[] = new DelayStamp(random_int(0, $message->delta) * 1000);
            }
            $this->bus->dispatch(new UpdateUrlUser(
                $profile,
                $message->name,
                $message->debug
            ), $stamps);
        }
    }

    #[AsMessageHandler]
    public function customHandler(CustomFunction $message)
    {
        foreach($this->profiles($message->name) as $profile) {
            $stamps = [];
            if ($message->delta) {
                $stamps[] = new DelayStamp(random_int(0, $message->delta) * 1000);
            }
            if ($message->browser) {
                $this->bus->dispatch(new CustomFunctionBrowserUser(
                    $profile,
                    $message->name,
                    $message->callback
                ), $stamps);
            } else {
                $this->bus->dispatch(new CustomFunctionUser(
                    $profile,
                    $message->name,
                    $message->callback
                ), $stamps);
            }
        }
    }

    protected function profiles($bot)
    {
        $profiles = [];
        foreach ($this->profileService->list() as $profile) {
            if ($this->botSelector->isEnabled($profile, $bot)) {
                $profiles[] = $profile;
            }
        }
        return $profiles;
    }
}
