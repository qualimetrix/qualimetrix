<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use RuntimeException;

final class BaselineReadFailure extends RuntimeException implements EnvironmentFailureInterface
{
    public function __construct(public readonly string $path, public readonly string $reason)
    {
        parent::__construct(\sprintf('Cannot read baseline file "%s": %s. Check its read access and retry.', $path, $reason));
    }
}
