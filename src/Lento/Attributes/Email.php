<?php

namespace Lento\Attributes;

use Attribute;

/**
 *
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Email
{
    public function __construct()
    {
    }
}