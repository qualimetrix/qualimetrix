<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Profiler\Contract;

enum ProfileFormat: string
{
    case Json = 'json';
    case ChromeTracing = 'chrome-tracing';
}
