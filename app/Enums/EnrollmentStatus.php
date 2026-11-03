<?php

namespace App\Enums;

/** enrolled_user_list: one course a student has access to. */
enum EnrollmentStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
