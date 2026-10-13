<?php

namespace App\Services;

use RuntimeException;

class UnverifiedGoogleEmailException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Google account email is not verified.');
    }
}
