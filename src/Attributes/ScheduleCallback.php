<?php

namespace App\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class ScheduleCallback
{
    public function __construct(
        public string $frequency
    )
    {}
}
