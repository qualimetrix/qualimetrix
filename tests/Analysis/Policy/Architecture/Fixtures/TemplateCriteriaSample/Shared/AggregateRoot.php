<?php

declare(strict_types=1);

namespace Fixtures\TemplateCriteriaSample\Shared;

abstract class AggregateRoot
{
    public function identity(): string
    {
        return static::class;
    }
}
