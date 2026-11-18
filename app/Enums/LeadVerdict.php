<?php

namespace App\Enums;

enum LeadVerdict: string
{
    case Safe = 'safe';
    case Gateway = 'gateway';
    case Later = 'later';
    case Rejected = 'rejected';
}
