<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use RuntimeException;

final class GitUnavailable extends RuntimeException implements EnvironmentFailureInterface
{
    public function __construct()
    {
        parent::__construct('proc_open is unavailable; Git reporting (--report=git:*) requires process execution. Remove the Git report scope or enable proc_open.');
    }
}
