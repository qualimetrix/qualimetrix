<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunTarget;

use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\HeldTarget;
use Qualimetrix\Core\FileTarget\PreparedTarget;
use Qualimetrix\Core\FileTarget\ResolvedTarget;
use Qualimetrix\Core\FileTarget\TargetKind;
use Qualimetrix\Core\FileTarget\TargetPath;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;

/** Identity and name collisions among the targets of one console run. */
final class TargetCollisions
{
    /**
     * @param array<string, ResolvedTarget> $judged
     */
    public static function assertBeforeClaim(array $judged, ?FileIdentity $standardOutput): void
    {
        $previous = [];
        foreach ($judged as $subject => $target) {
            $identity = self::identityOf($target);
            foreach ($previous as [$otherSubject, $otherTarget, $otherIdentity]) {
                if (self::sameBeforeClaim($target, $identity, $otherTarget, $otherIdentity)) {
                    self::refuseCollision($judged, $subject, $otherSubject, $identity);
                }
            }
            self::assertSeparateFromStandardOutput($judged, $subject, $identity, $standardOutput);
            $previous[] = [$subject, $target, $identity];
        }
    }

    /**
     * @param array<string, ResolvedTarget> $judged
     * @param array<string, string> $inputs
     */
    public static function assertSeparateFromInputs(array $judged, array $inputs): void
    {
        foreach ($inputs as $inputSubject => $spelling) {
            $input = TargetPath::resolve($spelling);
            foreach ($judged as $outputSubject => $output) {
                if (($output->path !== null && $input->path !== null && $output->path->equals($input->path))
                    || ($output->identity !== null && $input->identity !== null && $output->identity->sameAs($input->identity))) {
                    throw EnvironmentRefusal::aboutFile(
                        $output->spelling,
                        'use',
                        \sprintf('%s and %s name the same input target', $outputSubject, $inputSubject),
                    );
                }
            }
        }
    }

    /**
     * @param array<string, HeldTarget|PreparedTarget> $held
     * @param array<string, ResolvedTarget> $judged
     */
    public static function assertAfterClaim(array $held, array $judged, ?FileIdentity $standardOutput): void
    {
        $previous = [];
        foreach ($held as $subject => $target) {
            $identity = $target instanceof PreparedTarget ? $target->target()->identity : $target->identity();
            $path = $judged[$subject]->path?->value();
            foreach ($previous as [$otherSubject, $otherIdentity, $otherPath]) {
                if (self::sameTarget($identity, $path, $otherIdentity, $otherPath)) {
                    self::refuseCollision($judged, $subject, $otherSubject, $identity);
                }
            }
            if ($identity !== null) {
                self::assertSeparateFromStandardOutput($judged, $subject, $identity, $standardOutput);
            }
            $previous[] = [$subject, $identity, $path];
        }
    }

    private static function sameBeforeClaim(
        ResolvedTarget $target,
        ?FileIdentity $identity,
        ResolvedTarget $otherTarget,
        ?FileIdentity $otherIdentity,
    ): bool {
        return self::sameTarget($identity, $target->path?->value(), $otherIdentity, $otherTarget->path?->value());
    }

    private static function sameTarget(?FileIdentity $identity, ?string $path, ?FileIdentity $otherIdentity, ?string $otherPath): bool
    {
        return ($identity !== null && $otherIdentity !== null && $identity->sameAs($otherIdentity))
            || ($path !== null && $path === $otherPath);
    }

    private static function identityOf(ResolvedTarget $target): ?FileIdentity
    {
        if ($target->kind === TargetKind::Descriptor) {
            return ProcessStreams::identity($target->descriptor ?? -1);
        }

        return $target->identity;
    }

    /** @param array<string, ResolvedTarget> $judged */
    private static function assertSeparateFromStandardOutput(
        array $judged,
        string $subject,
        ?FileIdentity $identity,
        ?FileIdentity $standardOutput,
    ): void {
        if ($judged[$subject]->kind === TargetKind::Descriptor && $judged[$subject]->descriptor !== 1) {
            return;
        }
        if ($identity !== null && $standardOutput !== null && $identity->sameAs($standardOutput)) {
            self::refuseCollision($judged, $subject, 'standard output', $identity);
        }
    }

    /** @param array<string, ResolvedTarget> $judged */
    private static function refuseCollision(array $judged, string $subject, string $other, ?FileIdentity $identity): void
    {
        if ($identity !== null && $identity->isCharacterDevice() && self::isAllowedCharacterDevice($identity)) {
            return;
        }

        throw EnvironmentRefusal::aboutFile(
            $judged[$subject]->spelling,
            'use',
            \sprintf('%s and %s name the same output target', $subject, $other),
        );
    }

    private static function isAllowedCharacterDevice(FileIdentity $identity): bool
    {
        $null = stat('/dev/null');
        if ($null !== false && $identity->sameAs(FileIdentity::fromStat($null))) {
            return true;
        }

        foreach ([1, 2] as $fd) {
            $standard = ProcessStreams::identity($fd);
            if ($standard !== null && $identity->sameAs($standard)) {
                $handle = $fd === 1 ? \STDOUT : \STDERR;
                if (stream_isatty($handle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
