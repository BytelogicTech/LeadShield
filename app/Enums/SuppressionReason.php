<?php

namespace App\Enums;

enum SuppressionReason: string
{
    case Bounced = 'bounced';
    case Unsubscribed = 'unsubscribed';
    case Replied = 'replied';
    case NotInterested = 'not_interested';
    case Contacted = 'contacted';
    case Dirty = 'dirty';
    case Manual = 'manual';
}
