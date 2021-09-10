<?php

namespace Polion1232\Exceptions;

use Exception;
use Throwable;

class TableNotFoundException extends Exception
{
    public function __construct(string $message = "Table not found", int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
