<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunTarget;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusalInterface;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\HeldTarget;
use Qualimetrix\Core\FileTarget\NewName;
use Qualimetrix\Core\FileTarget\PreparedTarget;
use Qualimetrix\Core\FileTarget\ResolvedTarget;
use Qualimetrix\Core\FileTarget\TargetKind;
use Qualimetrix\Core\FileTarget\TargetPath;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
use Qualimetrix\Infrastructure\Console\Refusal\FileTargetRefusal;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Throwable;

/** Coordinates the output, profile and log targets of one console run. */
final class RunTargets
{
    /** @var array<string, ResolvedTarget> */
    private array $judged = [];

    /** @var array<string, HeldTarget> */
    private array $held = [];

    /** @var array<string, PreparedTarget> */
    private array $prepared = [];

    private ?StagedSignalGuard $signals = null;
    private ?int $lastSignal = null;

    private bool $reportOnStandardOutput = false;

    public function __construct(private readonly LoggerFactoryInterface $loggerFactory) {}

    public static function judgement(string $subject, string $spelling): ResolvedTarget
    {
        try {
            return TargetAccess::inspect($spelling);
        } catch (FileTargetFailure $failure) {
            throw self::refusalFor($subject, $failure);
        }
    }

    public function judge(string $subject, string $spelling): void
    {
        $this->judged[$subject] = self::judgement($subject, $spelling);
    }

    public function reportOnStandardOutput(): void
    {
        $this->reportOnStandardOutput = true;
    }

    /** @return list<string> */
    public function exposureWarnings(): array
    {
        $warnings = [];
        foreach ($this->judged as $subject => $target) {
            foreach ($target->exposure as $exposure) {
                $warnings[] = \sprintf(
                    'File target %s (%s) passes through %s, which can be changed by %s.',
                    $target->spelling,
                    $subject,
                    $exposure->directory,
                    $exposure->changedBy,
                );
            }
        }

        return $warnings;
    }

    /** @param array<string, string> $inputs */
    public function assertSeparateFromInputs(array $inputs): void
    {
        TargetCollisions::assertSeparateFromInputs($this->judged, $inputs);
    }

    public function assertCacheClearSafe(string $directory): void
    {
        $cache = TargetPath::resolve($directory);
        $root = $cache->path?->value() ?? throw new LogicException('Cache root has no path');
        $prefix = rtrim($root, '/') . '/';
        foreach ($this->judged as $target) {
            $path = $target->path?->value();
            $sibling = null;
            foreach ($this->prepared as $prepared) {
                if ($prepared->target() === $target) {
                    $sibling = $prepared->siblingPath();
                    break;
                }
            }
            if (($path !== null && ($path === $root || str_starts_with($path, $prefix)))
                || ($sibling !== null && ($sibling === $root || str_starts_with($sibling, $prefix)))) {
                throw EnvironmentRefusal::aboutFile($target->spelling, 'clear cache', 'cache directory contains a claimed output target');
            }
        }
    }

    public function claim(): void
    {
        $this->lastSignal = null;
        TargetCollisions::assertBeforeClaim(
            $this->judged,
            $this->reportOnStandardOutput ? ProcessStreams::identity(1) : null,
        );

        try {
            foreach ($this->judged as $subject => $target) {
                if ($subject !== '--log-file' && ($target->kind === TargetKind::Regular || $target->kind === TargetKind::Absent)) {
                    $this->signals = StagedSignalGuard::start($target->spelling);
                    break;
                }
            }
            foreach ($this->judged as $subject => $target) {
                try {
                    if ($subject !== '--log-file' && ($target->kind === TargetKind::Regular || $target->kind === TargetKind::Absent)) {
                        $this->prepared[$subject] = PreparedTarget::prepare($target);
                    } else {
                        $this->held[$subject] = HeldTarget::claim($target);
                    }
                } catch (FileTargetFailure $failure) {
                    throw self::refusalFor($subject, $failure);
                }
            }
            TargetCollisions::assertAfterClaim(
                [...$this->held, ...$this->prepared],
                $this->judged,
                $this->reportOnStandardOutput ? ProcessStreams::identity(1) : null,
            );

            if (isset($this->held['--log-file'])) {
                $this->loggerFactory->attachFileTarget($this->held['--log-file']);
                $this->held['--log-file']->markAttached();
                $this->settle();
            }
        } catch (Throwable $failure) {
            $this->abandon();
            throw $failure;
        }
    }

    public function write(string $subject, string $content): void
    {
        if (isset($this->prepared[$subject])) {
            try {
                $prepared = $this->prepared[$subject];
                $prepared->publish(
                    $content,
                    null,
                    $prepared->target()->kind === TargetKind::Absent ? NewName::Exclusive : NewName::LastWriterWins,
                    $this->signals === null ? null : $this->signals->assertNotInterrupted(...),
                );
                unset($this->prepared[$subject]);
            } catch (FileTargetFailure $failure) {
                throw self::refusalFor($subject, $failure);
            }

            return;
        }
        $target = $this->held[$subject] ?? throw new LogicException('Target has not been claimed: ' . $subject);
        try {
            $target->write($content);
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

    public function abandon(): void
    {
        $this->signals?->beginCleanup();
        $failure = null;
        foreach ($this->prepared as $target) {
            try {
                $target->discard();
            } catch (Throwable $caught) {
                $failure ??= $caught;
            }
        }
        $this->prepared = [];
        foreach ($this->held as $target) {
            try {
                $target->release();
            } catch (Throwable $caught) {
                $failure ??= $caught;
            }
        }
        $this->held = [];
        $this->lastSignal = $this->signals?->interruptedSignal();
        $this->signals?->close();
        $this->signals = null;
        $this->judged = [];
        $this->reportOnStandardOutput = false;
        $this->loggerFactory->reset();
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function interruptedSignal(): ?int
    {
        return $this->signals?->interruptedSignal() ?? $this->lastSignal;
    }

    private static function refusalFor(string $subject, FileTargetFailure $failure): RefusalInterface
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
