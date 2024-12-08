<?php

namespace App\Bots;

use App\Service\BotSelector;
use App\Service\ProfileService;
use Symfony\Contracts\Service\Attribute\Required;

trait MultiUser
{
    #[Required] public ProfileService $profileService;
    #[Required] public BotSelector $selector;

    public function getEnabledProfiles(): array
    {
        $profiles = [];

        foreach ($this->profileService->list() as $profile) {
            if ($this->selector->isEnabled($profile, $this->getName())) {
                $profiles[] = $profile;
            }
        }
        return $profiles;
    }
}
