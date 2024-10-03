<?php

namespace App\Message;

final class CustomFunctionUser
{
    /*
     * Add whatever properties and methods you need
     * to hold the data for this message class.
     */

     public function __construct(
         public readonly string $profile,
         public readonly string $name,
         public readonly string $callback,
     ) {
     }
}
