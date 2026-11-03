<?php

namespace App\Enums;

/** affiliate_conversions: a course sale credited to an affiliate. */
enum ConversionStatus: string
{
    case Confirmed = 'confirmed';
    case Reversed = 'reversed';
}
