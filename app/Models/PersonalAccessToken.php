<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum's API token, with one change: Sanctum saves last_used_at on EVERY authenticated
 * request (one write per call - the video player alone calls every few seconds). Here it is
 * saved at most once a minute, like user_devices.last_used_at.
 */
class PersonalAccessToken extends SanctumToken
{
    protected static function booted(): void
    {
        static::updating(function (self $token) {
            $onlyLastUsed = array_keys($token->getDirty()) === ['last_used_at'];
            $previous = $token->getOriginal('last_used_at');

            if ($onlyLastUsed && $previous && $token->asDateTime($previous)->gt(now()->subMinute())) {
                return false;   // skip this write
            }
        });
    }
}
