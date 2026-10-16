<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Amp\Parallel\Context\ThreadContext;
use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use RuntimeException;

final class WorkerStartFailure extends RuntimeException implements EnvironmentFailureInterface
{
    public static function capabilityMissing(string $capability, WorkerRequest $request): self
    {
        return self::because('required capability ' . $capability . ' is unavailable', $request);
    }

    public static function noWorkerWithin(int $seconds, string $startupLog, WorkerRequest $request): self
    {
        return self::because('did not answer within ' . $seconds . ' s' . self::logDetail($startupLog), $request);
    }

    public static function failedToStart(string $reason, string $startupLog, WorkerRequest $request): self
    {
        return self::because($reason . self::logDetail($startupLog), $request);
    }

    private static function logDetail(string $log): string
    {
        return $log === '' ? '' : '; startup log: ' . $log;
    }

    private static function because(string $reason, WorkerRequest $request): self
    {
        $transport = ThreadContext::isSupported() ? ' ext-parallel is loaded; qmx runs workers as processes.' : '';

        return new self('Worker process startup failed for ' . $request->describe() . ': ' . $reason . '. Use --workers=0 to run sequentially.' . $transport);
    }
}
