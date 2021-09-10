<?php

namespace Polion1232\Exceptions;

use Exception;
use Throwable;

class UnknowIndetifierException extends Exception
{
    public function __construct(string $message = "Identifier not index nor foreign key", int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
