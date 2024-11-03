<?php

namespace App\Message;

final class UpdateUrl
{
     public function __construct(
         public readonly string $name,
         public readonly bool $debug = false,
         public readonly int $delta = 0,
     ) {
     }
}
