<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use RuntimeException;

final class CacheRootUntrusted extends RuntimeException implements EnvironmentFailureInterface {}
