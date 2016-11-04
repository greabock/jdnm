<?php

namespace Greabock\RestMapper\Exceptions;

use Exception;

class PermissionDeniedException extends Exception
{
    /**
     * @var int
     */
    protected $entity;

    /**
     * @var Exception
     */
    protected $field;

    /**
     * @var Exception
     */
    protected $ability;

    public function __construct($entity, $field, $ability)
    {
        $this->ability = $ability;
        $this->entity = $entity;
        $this->field = $field;
    }
}