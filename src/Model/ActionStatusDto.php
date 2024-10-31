<?php

namespace App\Model;

use Carbon\Carbon;

class ActionStatusDto
{
    private array $redisData;

    public function __construct(array $redisData)
    {
        $this->redisData = $redisData;
    }

    public function timeAgo(ActionState $state)
    {
        if (empty($this->redisData[$state->value])) {
            return '';
        }
        return str_replace(' ago', '',
            Carbon::createFromTimestamp($this->redisData[$state->value])
                ->diffForHumans()
        );
    }

    public function lastStatus()
    {
        $maxK = ''; $maxV = 0;
        foreach ($this->redisData as $k => $v) {
            if ($v >= $maxV) {
                $maxV = $v;
                $maxK = $k;
            }
        }
        return $maxK;
    }
}
