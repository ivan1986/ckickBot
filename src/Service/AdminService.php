<?php

namespace App\Service;

use App\Message\UpdateUrlUser;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\Attribute\Required;

class AdminService
{
    #[Required] public CacheService $cacheService;
    #[Required] public BotSelector $botSelector;
    #[Required] public MessageBusInterface $bus;
    #[Required] public ProfileService $profileService;

    public function initEmptyUrls()
    {
        foreach ($this->profileService->list() as $profile) {
            foreach ($this->botSelector->getAll() as $name => $bot) {
                if ($this->botSelector->isEnabled($profile, $name)) {
                    $bot->setProfile($profile);
                    if (!$bot->getUrl()) {
                        $this->bus->dispatch(new UpdateUrlUser($profile, $name));
                    }
                }
            }
        }
    }

    public function clearProfileCache()
    {
        $this->cacheService->del('profiles');
    }
}
