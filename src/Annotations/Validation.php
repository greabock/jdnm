<?php

namespace Greabock\RestMapper\Annotations;

use Doctrine\Common\Annotations\Annotation;

/**
 * Locale annotation for Translatable behavioral extension
 *
 * @Annotation
 * @Target("PROPERTY")
 */
class Validation extends Annotation
{
    /**
     * @var string
     */
    public $rule;

    /**
     * @var string
     */
    public $sub;
}