<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Amp\Parallel\Context\ContextException;

final class WorkerPhpFailure extends ContextException
{
    public function __construct(public readonly string $diagnostic, ContextException $previous)
    {
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
