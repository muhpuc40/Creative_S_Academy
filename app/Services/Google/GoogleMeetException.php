<?php

namespace App\Services\Google;

use RuntimeException;

/** Something went wrong talking to Google. The message is safe to show to the admin. */
class GoogleMeetException extends RuntimeException
{
}
