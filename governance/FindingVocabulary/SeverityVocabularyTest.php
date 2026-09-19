<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\FindingVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Split off from `SeverityTest` (which keeps the hand-picked exit-code and
 * display-name cases): universal properties swept over every real case,
 * never maintained by hand.
 */
#[CoversClass(Severity::class)]
final class SeverityVocabularyTest extends TestCase
{
    #[Test]
    public function itGivesEveryCaseANonEmptyDisplayName(): void
    {
        foreach (Severity::cases() as $severity) {
            $displayName = $severity->displayName();
            self::assertNotEmpty($displayName);
        }
    }

    #[Test]
    public function itInfoIsExitCodeZeroAllOthersNonZero(): void
    {
        self::assertSame(0, Severity::Info->getExitCode());

        foreach (Severity::cases() as $severity) {
            if ($severity === Severity::Info) {
                continue;
            }

            self::assertGreaterThan(0, $severity->getExitCode(), \sprintf(
                '%s severity should have non-zero exit code',
                $severity->displayName(),
            ));
        }
    }

    #[Test]
    public function itExitCodesAreUniqueAcrossNonInfoSeverities(): void
    {
        $exitCodes = array_map(
            static fn(Severity $severity) => $severity->getExitCode(),
            Severity::cases(),
        );

        // All exit codes must be unique (Info=0 is also unique since others are >0)
        self::assertSame(
            \count($exitCodes),
            \count(array_unique($exitCodes)),
            'Exit codes must be unique for each severity level',
        );
    }
}
