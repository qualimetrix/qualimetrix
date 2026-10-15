<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunTarget;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusalInterface;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\Publication\HeldTarget;
use Qualimetrix\Core\FileTarget\Publication\NewName;
use Qualimetrix\Core\FileTarget\Publication\PreparedTarget;
use Qualimetrix\Core\FileTarget\ResolvedTarget;
use Qualimetrix\Core\FileTarget\TargetKind;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
use Qualimetrix\Infrastructure\Console\Refusal\FileTargetRefusal;
use Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Throwable;

/**
 * Owns claimed targets and interruption cleanup for one console run.
 *
 * @qmx-threshold complexity.wmc 53 -- Claim, publication, interruption and exhaustion share the same held target identities; splitting their owner would duplicate lifecycle state.
 */
final class RunTargetClaimLifecycle
{
    /** @var array<string, HeldTarget> */
    private array $held = [];

    /** @var array<string, PreparedTarget> */
    private array $prepared = [];

    private ?StagedSignalGuard $signals = null;
    private ?int $lastSignal = null;

    private ?Closure $removeExhaustion = null;
    /** @var array<string, true> */
    private array $unwritten = [];

    public function __construct(private readonly LoggerFactoryInterface $loggerFactory, private readonly OutOfMemoryHint $memoryHint) {}

    public function beginClaim(): void
    {
        $this->lastSignal = null;
    }

    /** @param array<string, ResolvedTarget> $judged */
    public function claim(array $judged, bool $hasStandardOutputReport): void
    {
        $this->removeExhaustion = $this->memoryHint->onExhaustion($this->abandon(...));
        $this->startSignalsForStagedOutput($judged);
        $this->claimTargets($judged);
        TargetCollisions::assertAfterClaim(
            [...$this->held, ...$this->prepared],
            $judged,
            $hasStandardOutputReport ? ProcessStreams::identity(1) : null,
        );
        $this->attachLogTarget();
    }

    /** @param array<string, ResolvedTarget> $judged */
    private function startSignalsForStagedOutput(array $judged): void
    {
        foreach ($judged as $subject => $target) {
            if (self::needsStaging($subject, $target)) {
                $this->signals = StagedSignalGuard::start();
                break;
            }
        }
    }

    /** @param array<string, ResolvedTarget> $judged */
    private function claimTargets(array $judged): void
    {
        foreach ($judged as $subject => $target) {
            try {
                if (self::needsStaging($subject, $target)) {
                    $this->prepared[$subject] = PreparedTarget::prepare($target);
                } else {
                    $this->held[$subject] = HeldTarget::claim($target);
                }
                $this->unwritten[$subject] = true;
            } catch (FileTargetFailure $failure) {
                throw self::refusalFor($subject, $failure);
            }
        }
    }

    private static function needsStaging(string $subject, ResolvedTarget $target): bool
    {
        return $subject !== '--log-file' && \in_array($target->kind, [TargetKind::Regular, TargetKind::Absent], true);
    }

    private function attachLogTarget(): void
    {
        if (!isset($this->held['--log-file'])) {
            return;
        }
        $this->loggerFactory->attachFileTarget($this->held['--log-file']);
        $this->held['--log-file']->markAttached();
        $this->markWritten('--log-file');
        $this->settle();
    }

    public function write(string $subject, string $content): void
    {
        try {
            if (isset($this->prepared[$subject])) {
                $prepared = $this->prepared[$subject];
                $prepared->publish(
                    $content,
                    null,
                    $prepared->target()->kind === TargetKind::Absent ? NewName::Exclusive : NewName::LastWriterWins,
                    $this->signals === null ? null : $this->signals->assertNotInterrupted(...),
                );
                unset($this->prepared[$subject]);
                $this->markWritten($subject);

                return;
            }
            $target = $this->held[$subject] ?? throw new LogicException('Target has not been claimed: ' . $subject);
            $target->write($content);
            $this->markWritten($subject);
        } catch (FileTargetFailure $failure) {
            throw self::refusalFor($subject, $failure);
        }
    }

    public function settle(): void
    {
        try {
            $this->loggerFactory->settle();
        } catch (FileTargetFailure $failure) {
            throw self::refusalFor('--log-file', $failure);
        }
    }

    private function markWritten(string $subject): void
    {
        unset($this->unwritten[$subject]);
        if ($this->unwritten === []) {
            $this->removeExhaustion?->__invoke();
            $this->removeExhaustion = null;
        }
    }

    public function abandon(): void
    {
        $this->removeExhaustion?->__invoke();
        $this->removeExhaustion = null;
        $this->unwritten = [];
        $this->signals?->beginCleanup();
        $failure = self::releaseAll($this->prepared, static fn(PreparedTarget $target) => $target->discard());
        $this->prepared = [];
        $heldFailure = self::releaseAll($this->held, static fn(HeldTarget $target) => $target->release());
        $failure ??= $heldFailure;
        $this->held = [];
        $this->lastSignal = $this->signals?->interruptedSignal();
        $this->signals?->close();
        $this->signals = null;
        $this->loggerFactory->reset();
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @template T of HeldTarget|PreparedTarget
     * @param array<string, T> $targets
     * @param callable(T): void $release
     */
    private static function releaseAll(array $targets, callable $release): ?Throwable
    {
        $failure = null;
        foreach ($targets as $target) {
            try {
                $release($target);
            } catch (Throwable $caught) {
                $failure ??= $caught;
            }
        }

        return $failure;
    }

    /** @param array<string, ResolvedTarget> $judged */
    public function assertCacheClearSafe(array $judged, string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $root = AbsolutePath::fromString($directory)->canonicalize()->value();
        foreach ($judged as $subject => $target) {
            $sibling = null;
            if (isset($this->prepared[$subject]) && $this->prepared[$subject]->target() === $target) {
                $sibling = $this->prepared[$subject]->siblingPath();
            }
            if (self::within($target->path?->value(), $root) || self::within($sibling, $root)) {
                throw EnvironmentRefusal::aboutFile($target->spelling, 'clear cache', 'cache directory contains a claimed output target');
            }
        }
    }

    private static function within(?string $path, string $root): bool
    {
        return $path !== null && str_starts_with(rtrim($path, '/') . '/', rtrim($root, '/') . '/');
    }

    public function interruptedSignal(): ?int
    {
        return $this->signals?->interruptedSignal() ?? $this->lastSignal;
    }

    public static function refusalFor(string $subject, FileTargetFailure $failure): RefusalInterface
    {
        $refusal = FileTargetRefusal::from($subject, $failure);
        if ($refusal instanceof ConfigurationRefusal) {
            return ConfigurationRefusal::aboutCommandLineInput(
                $subject,
                \sprintf('Option %s names "%s": %s', $subject, $failure->spelling, $failure->reason),
                $failure,
            );
        }

        return FileTargetRefusal::from($subject, new FileTargetFailure(
            $failure->kind,
            $failure->spelling,
            $subject . ': ' . $failure->reason,
            $failure->detail,
        ));
    }
}
