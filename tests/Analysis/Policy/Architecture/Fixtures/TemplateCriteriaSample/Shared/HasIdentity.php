<?php

declare(strict_types=1);

namespace Fixtures\TemplateCriteriaSample\Shared;

interface HasIdentity
{
    public function identity(): string;
}
