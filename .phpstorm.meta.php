<?php declare(strict_types=1);

// see https://confluence.jetbrains.com/display/PhpStorm/PhpStorm+Advanced+Metadata

namespace PHPSTORM_META;

// $container->get(Type::class) → instance of "Type"
use App\Service\BotSelector;

override(BotSelector::getBot(0), map([
    '' => 'App\Bots\@',
]));
