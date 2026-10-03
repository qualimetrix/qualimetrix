<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunTarget;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusalInterface;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\HeldTarget;
use Qualimetrix\Core\FileTarget\ResolvedTarget;
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

    public function claim(): void
    {
        TargetCollisions::assertBeforeClaim(
            $this->judged,
            $this->reportOnStandardOutput ? ProcessStreams::identity(1) : null,
        );

        try {
            foreach ($this->judged as $subject => $target) {
                try {
                    $this->held[$subject] = HeldTarget::claim($target);
                } catch (FileTargetFailure $failure) {
                    throw self::refusalFor($subject, $failure);
                }
            }
            TargetCollisions::assertAfterClaim(
                $this->held,
                $this->judged,
                $this->reportOnStandardOutput ? ProcessStreams::identity(1) : null,
            );

            if (isset($this->held['--log-file'])) {
                $this->loggerFactory->attachFileTarget($this->held['--log-file']);
                $this->settle();
            }
        } catch (Throwable $failure) {
            $this->abandon();
            throw $failure;
        }
    }

    public function write(string $subject, string $content): void
    {
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
        $failure = null;
        foreach ($this->held as $target) {
            try {
                $target->release();
            } catch (Throwable $caught) {
                $failure ??= $caught;
            }
        }
        $this->held = [];
        $this->judged = [];
        $this->reportOnStandardOutput = false;
        $this->loggerFactory->reset();
        if ($failure !== null) {
            throw $failure;
        }
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
