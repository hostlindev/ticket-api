<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class InvalidCredentialsException extends Exception implements ShouldntReport
{
    /**
     * Create a new invalid credentials exception instance.
     *
     * @param  array<string, string>  $errors
     */
    public function __construct(
        string $message = 'Invalid credentials.',
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
