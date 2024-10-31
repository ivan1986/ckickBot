<?php

namespace App\Model;

enum ActionState: string
{
    case START = 'start';
    case CHANGE = 'change';
    case ERROR = 'error';
    case FINISH = 'finish';
}
