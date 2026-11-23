<?php

namespace App\Services\Enrollment;

use RuntimeException;

/** Thrown inside SubmissionService's transaction when a submission is no longer allowed. */
class SubmissionRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
