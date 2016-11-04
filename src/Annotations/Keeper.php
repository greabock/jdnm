<?php

namespace Greabock\RestMapper\Annotations;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\Common\Annotations\Annotation\Target;

/**
 * Locale annotation for Translatable behavioral extension
 *
 * @Annotation
 * @Target("CLASS", "PROPERTY")
 */
class Keeper extends Annotation
{
    const ON_FAILS_IGNORE = 0;
    const ON_FAILS_RESTRICT = 1;

    /**
     * @var string
     */
    public $ability;

    /**
     * @var string
     */
    public $strategy;
}