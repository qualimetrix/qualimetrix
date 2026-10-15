<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use RuntimeException;

final class BaselineLockFailure extends RuntimeException implements EnvironmentFailureInterface
{
    public function __construct(public readonly string $path, FileTargetFailure $failure)
    {
        parent::__construct(\sprintf(
            'Cannot acquire the baseline lock for "%s": %s Check the lock destination and retry when it is available.',
            $path,
            $failure->getMessage(),
        ), 0, $failure);
    }
}
