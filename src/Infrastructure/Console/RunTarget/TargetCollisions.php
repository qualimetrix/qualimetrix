<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunTarget;

use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\HeldTarget;
use Qualimetrix\Core\FileTarget\ResolvedTarget;
use Qualimetrix\Core\FileTarget\TargetKind;
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
     * @param array<string, HeldTarget> $held
     * @param array<string, ResolvedTarget> $judged
     */
    public static function assertAfterClaim(array $held, array $judged, ?FileIdentity $standardOutput): void
    {
        $previous = [];
        foreach ($held as $subject => $target) {
            $identity = $target->identity();
            foreach ($previous as [$otherSubject, $otherIdentity]) {
                if ($identity->sameAs($otherIdentity)) {
                    self::refuseCollision($judged, $subject, $otherSubject, $identity);
                }
            }
            self::assertSeparateFromStandardOutput($judged, $subject, $identity, $standardOutput);
            $previous[] = [$subject, $identity];
        }
    }

    private static function sameBeforeClaim(
        ResolvedTarget $target,
        ?FileIdentity $identity,
        ResolvedTarget $otherTarget,
        ?FileIdentity $otherIdentity,
    ): bool {
        if ($identity !== null && $otherIdentity !== null && $identity->sameAs($otherIdentity)) {
            return true;
        }

        return $target->path !== null
            && $otherTarget->path !== null
            && $target->path->value() === $otherTarget->path->value();
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
