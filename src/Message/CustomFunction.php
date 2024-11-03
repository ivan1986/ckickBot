<?php

namespace App\Message;

final class CustomFunction
{
    /*
     * Add whatever properties and methods you need
     * to hold the data for this message class.
     */

     public function __construct(
         public readonly string $name,
         public readonly string $callback,
         public readonly int $delta = 0,
     ) {
     }
}
