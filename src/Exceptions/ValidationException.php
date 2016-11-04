<?php

namespace Greabock\RestMapper\Exceptions;

use Exception;

class ValidationException extends Exception
{
    public $errors;

    public function __construct($errors)
    {
        parent::__construct('The given data failed to pass validation.');

        $this->errors = $errors;
    }
}