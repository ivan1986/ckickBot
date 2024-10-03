<?php

namespace App\Message;

final class UpdateUrlUser
{
     public function __construct(
         public readonly string $profile,
         public readonly string $name,
         public readonly bool $debug = false,
     )  {
     }
}
