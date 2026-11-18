<?php

namespace App\Enums;

enum SuppressionType: string
{
    case Email = 'email';
    case Domain = 'domain';
}
