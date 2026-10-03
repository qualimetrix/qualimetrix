<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunTarget;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusalInterface;
use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use Qualimetrix\Infrastructure\Console\Refusal\ConsoleExitCode;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Owns a console run's target lifetime and terminal refusal channel. */
final class RunTargetSession
{
    private bool $outputPublished = false;

    public function __construct(
        private readonly RunTargets $runTargets,
        private readonly RefusalPresenter $refusalPresenter,
    ) {}

    public function targets(): RunTargets
    {
        return $this->runTargets;
    }

    /** @param callable(): int $action */
    public function run(OutputInterface $output, ?string $envelopeFormat, callable $action): int
    {
        $this->outputPublished = false;
        $result = null;
        $primary = null;
        $cleanup = null;

        try {
            $result = $action();
        } catch (Throwable $failure) {
            $primary = $failure;
        }

        try {
            $this->runTargets->abandon();
        } catch (Throwable $failure) {
            $cleanup = $failure;
        }

        try {
            return $this->presentOutcome($output, $envelopeFormat, $result, $primary, $cleanup);
        } finally {
            $this->outputPublished = false;
        }
    }

    public function refuseBeforeExecution(OutputInterface $output, ?string $format, ConfigurationRefusal $refusal): int
    {
        return $this->refusalPresenter->refusal($output, $format, $refusal);
    }

    public function markOutputPublished(): void
    {
        $this->outputPublished = true;
    }

    /** @param callable(): void $action */
    public function afterPublishedReport(callable $action, int $successExit): int
    {
        $this->markOutputPublished();
        $action();

        return $successExit;
    }

    private function presentOutcome(
        OutputInterface $output,
        ?string $format,
        ?int $result,
        ?Throwable $primary,
        ?Throwable $cleanup,
    ): int {
        if ($primary === null && $cleanup === null) {
            return $result ?? throw new LogicException('Console action returned no exit code');
        }
        if ($primary === null) {
            return $this->presentCleanupFailure($output, $format, $cleanup ?? throw new LogicException('Cleanup failure is missing'));
        }
        if ($cleanup === null) {
            return $this->presentFailure($output, $format, $primary);
        }

        return $this->presentBoth($output, $format, $primary, $cleanup);
    }

    private function presentBoth(OutputInterface $output, ?string $format, Throwable $primary, Throwable $cleanup): int
    {
        if ($this->outputPublished) {
            $primaryExit = $this->presentFailure($output, null, $primary);
            $cleanupExit = $this->presentCleanupFailure($output, null, $cleanup);

            return $this->cleanupDominates($primary, $cleanup) ? $cleanupExit : $primaryExit;
        }

        if ($this->cleanupDominates($primary, $cleanup)) {
            $this->presentFailure($output, null, $primary);

            return $this->presentCleanupFailure($output, $format, $cleanup);
        }

        $primaryExit = $this->presentFailure($output, $format, $primary);
        $this->presentCleanupFailure($output, null, $cleanup);

        return $primaryExit;
    }

    private function cleanupDominates(Throwable $primary, Throwable $cleanup): bool
    {
        return self::cleanupExit($cleanup) === ConsoleExitCode::InternalError->value
            || $this->failureExit($primary) !== ConsoleExitCode::InternalError->value;
    }

    private function failureExit(Throwable $failure): int
    {
        if (self::isRefusalFailure($failure)
            || (!$this->outputPublished && $failure instanceof InvalidArgumentException)) {
            return ConsoleExitCode::Refusal->value;
        }

        return ConsoleExitCode::InternalError->value;
    }

    private static function cleanupExit(Throwable $failure): int
    {
        return self::isRefusalFailure($failure)
            ? ConsoleExitCode::Refusal->value
            : ConsoleExitCode::InternalError->value;
    }

    private static function isRefusalFailure(Throwable $failure): bool
    {
        return $failure instanceof RefusalInterface
            || $failure instanceof EnvironmentFailureInterface;
    }

    private function presentFailure(OutputInterface $output, ?string $format, Throwable $failure): int
    {
        if ($this->outputPublished) {
            return $this->refusalPresenter->unhandledAfterPublishedReport($output, $failure);
        }
        if ($failure instanceof ConfigurationRefusal) {
            return $this->refusalPresenter->refusal($output, $format, $failure);
        }
        if ($failure instanceof InvalidArgumentException) {
            return $this->refusalPresenter->fallbackRefusal($output, $format, $failure);
        }

        return $this->refusalPresenter->unhandled($output, $format, $failure);
    }

    private function presentCleanupFailure(OutputInterface $output, ?string $format, Throwable $failure): int
    {
        return $this->outputPublished
            ? $this->refusalPresenter->unhandledAfterPublishedReport($output, $failure)
            : $this->refusalPresenter->unhandled($output, $format, $failure);
    }
}
