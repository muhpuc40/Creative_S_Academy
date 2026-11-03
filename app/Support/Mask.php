<?php

namespace App\Support;

class Mask
{
    /** student1@CreativeSacademy.com -> st************flow.com */
    public static function email(string $email): string
    {
        $length = strlen($email);
        [$start, $end] = $length > 14 ? [2, 8] : [1, 4];

        return substr($email, 0, $start)
            . str_repeat('*', max(1, $length - $start - $end))
            . substr($email, -$end);
    }

    /** 01711000001 -> 01*******01 */
    public static function phone(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($phone, 0, 2) . str_repeat('*', $length - 4) . substr($phone, -2);
    }
}
