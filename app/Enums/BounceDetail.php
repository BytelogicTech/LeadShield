<?php

namespace App\Enums;

enum BounceDetail: string
{
    case AddressNotFound = 'address_not_found';
    case SpamRejection = 'spam_rejection';
    case Unknown = 'unknown';
}
