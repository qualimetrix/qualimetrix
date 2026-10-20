<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use RuntimeException;

final class FileTargetFailure extends RuntimeException implements EnvironmentFailureInterface
{
    public function __construct(
        public readonly FileTargetFailureKind $kind,
        public readonly string $spelling,
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct(\sprintf('Cannot use file target "%s": %s%s.', $spelling, $reason, $detail === '' ? '' : ' (' . $detail . ')'));
    }
}
