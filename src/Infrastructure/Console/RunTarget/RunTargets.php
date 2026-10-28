<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunTarget;

use Closure;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\Resolution\ResolvedTarget;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
use Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Throwable;

/** Coordinates the output, profile and log targets of one console run. */
final class RunTargets
{
    /** @var array<string, ResolvedTarget> */
    private array $judged = [];

    private bool $reportOnStandardOutput = false;
    private readonly RunTargetClaimLifecycle $claims;

    public function __construct(LoggerFactoryInterface $loggerFactory, OutOfMemoryHint $memoryHint)
    {
        $this->claims = new RunTargetClaimLifecycle($loggerFactory, $memoryHint);
    }

    public static function judgement(string $subject, string $spelling): ResolvedTarget
    {
        try {
            return TargetAccess::inspect($spelling);
        } catch (FileTargetFailure $failure) {
            throw RunTargetClaimLifecycle::refusalFor($subject, $failure);
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
        $this->claims->assertCacheClearSafe($this->judged, $directory);
    }

    /**
     * `$inputFileReadAs` names the file this run reads with an identity; a
     * target that is one is refused, and so is a report on standard output
     * the shell opened on one.
     *
     * @param Closure(?FileIdentity): ?string $inputFileReadAs
     */
    public function claim(Closure $inputFileReadAs): void
    {
        $this->claims->beginClaim();
        // First: any refusal below would otherwise be written to that input.
        $standardOutput = $this->reportOnStandardOutput ? ProcessStreams::identity(1) : null;
        $read = $inputFileReadAs($standardOutput);
        if ($read !== null) {
            throw EnvironmentRefusal::aboutStandardOutput(\sprintf('standard output and %s name the same input target', $read));
        }
        foreach ($this->judged as $subject => $target) {
            $read = $inputFileReadAs(TargetCollisions::identityOf($target));
            if ($read !== null) {
                throw EnvironmentRefusal::aboutFile($target->spelling, 'use', \sprintf('%s and %s name the same input target', $subject, $read));
            }
        }
        TargetCollisions::assertBeforeClaim($this->judged, $standardOutput);

        try {
            $this->claims->claim($this->judged, $this->reportOnStandardOutput);
        } catch (Throwable $failure) {
            $this->abandon();
            throw $failure;
        }
    }

    public function write(string $subject, string $content): void
    {
        $this->claims->write($subject, $content);
    }

    public function settle(): void
    {
        $this->claims->settle();
    }

    public function abandon(): void
    {
        try {
            $this->claims->abandon();
        } finally {
            $this->judged = [];
            $this->reportOnStandardOutput = false;
        }
    }

    public function interruptedSignal(): ?int
    {
        return $this->claims->interruptedSignal();
    }
}
