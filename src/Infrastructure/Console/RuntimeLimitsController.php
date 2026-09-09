<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use RuntimeException;
use Throwable;

final readonly class RuntimeLimitsController
{
    private string $startupLimit;
    public function __construct()
    {
        $this->startupLimit = (string) \ini_get('memory_limit');
    }

    /**
     * Restores the process-start `memory_limit`. Failure here is not caused
     * by anything the run's configuration asked for — it is an environment
     * problem restoring a value nobody chose — so it stays a plain
     * `RuntimeException` rather than a {@see ConfigurationRefusal}.
     */
    public function reset(): void
    {
        $this->setMemoryLimit(
            $this->startupLimit,
            static fn(): Throwable => new RuntimeException('Cannot restore process-start memory_limit.'),
        );
    }

    /**
     * Applies a `memory_limit` the run's configuration requested. A value
     * that is syntactically valid but rejected by the runtime is the
     * configuration author's problem to fix (`01-refusal-verdicts.md` §6.2,
     * route 16), so failure here carries a {@see ConfigurationRefusal}.
     */
    public function apply(RuntimeLimits $limits): void
    {
        if ($limits->memoryLimit !== null) {
            $this->setMemoryLimit(
                $limits->memoryLimit,
                static fn(): Throwable => ConfigurationRefusal::aboutInput(
                    ConfigurationOrigin::of(ConfigurationSource::Resolved, ConfigSchema::MEMORY_LIMIT),
                    'Cannot set requested memory_limit.',
                ),
            );
        }
    }

    /** @param callable(): Throwable $failure */
    private function setMemoryLimit(string $limit, callable $failure): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            try {
                $previousLimit = ini_set('memory_limit', $limit);
            } catch (Throwable $exception) {
                $wrapped = $failure();
                throw $wrapped instanceof ConfigurationRefusal
                    ? ConfigurationRefusal::aboutInput($wrapped->origin(), $wrapped->summary(), $exception)
                    : new RuntimeException($wrapped->getMessage(), 0, $exception);
            }
        } finally {
            restore_error_handler();
        }

        if ($previousLimit === false) {
            throw $failure();
        }
    }
}
