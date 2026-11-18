<?php

namespace App\Enums;

enum LeadVerdict: string
{
    case Safe = 'safe';
    case Risky = 'risky';
    case Gateway = 'gateway';
    case Rejected = 'rejected';
}
