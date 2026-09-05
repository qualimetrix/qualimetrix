<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Formatter\Suppressed\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\FindingProjection\SuppressionMechanism;

/**
 * The pure key/normalization logic of `scripts/generate-suppression-snapshot.php`,
 * exercised directly rather than through the slow self-analysis subprocess —
 * see PLAN.md, rule-vocabulary Ш6 decision (м) for the two probes this
 * defends: a directive's own line number must not enter the key (a line
 * shift with no decision change must not redden), while everything the
 * snapshot's key is actually built from must (a removed directive/threshold,
 * or a severity change on an already-suppressed finding, must redden). The
 * script is include-safe so this can call its functions directly instead of
 * round-tripping a subprocess.
 */
final class SuppressionSnapshotKeyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 5) . '/scripts/generate-suppression-snapshot.php';
    }

    /**
     * `suppression` is the one mechanism whose raw suppressor is a directive
     * locator (`file:line`); this is the normalization the DoD's line-shift
     * probe depends on.
     */
    #[Test]
    public function itStripsTheLineFromASuppressionDirectiveLocator(): void
    {
        self::assertSame(
            'src/Foo.php',
            normalizeSuppressor('suppression', 'src/Foo.php:42'),
        );
    }

    /**
     * The other six mechanisms' suppressors never carry a line number
     * (matched pattern, producer rule name, baseline subject+code, git ref)
     * — normalizing them would silently discard part of their identity.
     *
     * The mechanism names are read off {@see SuppressionMechanism} rather than
     * spelled here. `normalizeSuppressor()` compares against one literal and
     * treats every other string as opaque, so hand-copied names would keep
     * passing after a rename and this test would stop being a witness to what
     * the product actually publishes.
     */
    #[Test]
    public function itLeavesEveryOtherMechanismsSuppressorUnchanged(): void
    {
        foreach (SuppressionMechanism::cases() as $mechanism) {
            if ($mechanism === SuppressionMechanism::Suppression) {
                continue;
            }

            self::assertSame(
                'src/Generated/*',
                normalizeSuppressor($mechanism->value, 'src/Generated/*'),
                $mechanism->name,
            );
        }
    }

    /**
     * A directive locator with no colon (defensive: today's format always
     * has one) is returned unchanged rather than mangled.
     */
    #[Test]
    public function itLeavesASuppressorWithoutAColonUnchanged(): void
    {
        self::assertSame('no-colon-here', normalizeSuppressor('suppression', 'no-colon-here'));
    }

    /**
     * Two entries differing only by the directive's line number collapse to
     * the same key — the line-shift probe (DoD, decision (м)).
     */
    #[Test]
    public function itBuildsTheSameKeyForTwoDirectiveLocatorsAtDifferentLines(): void
    {
        $entry = static fn(string $suppressor): array => [
            'mechanism' => 'suppression',
            'suppressor' => $suppressor,
            'channel' => 'example.channel',
            'file' => 'src/Foo.php',
            'symbol' => 'App\\Foo',
            'severity' => 'warning',
        ];

        self::assertSame(
            compositionKey($entry('src/Foo.php:10')),
            compositionKey($entry('src/Foo.php:11')),
        );
    }

    /**
     * Severity is part of the key (native F4): a config change that raises
     * or lowers an already-suppressed finding's level is a changed decision
     * — `--fail-on` reads severity — and Ш5e2b's precedent is exactly a
     * decision change no field of the key caught. Without this, 26 findings
     * moving warning -> error left the snapshot byte-for-byte identical.
     */
    #[Test]
    public function itBuildsADifferentKeyWhenOnlySeverityChanges(): void
    {
        $entry = static fn(string $severity): array => [
            'mechanism' => 'namespace-suppression',
            'suppressor' => 'App\\Generated',
            'channel' => 'example.channel',
            'file' => 'src/Foo.php',
            'symbol' => 'App\\Foo',
            'severity' => $severity,
        ];

        self::assertNotSame(
            compositionKey($entry('warning')),
            compositionKey($entry('error')),
        );
    }

    /**
     * A missing `file` (a finding with no location) does not collide with a
     * finding whose file happens to be that literal placeholder string.
     */
    #[Test]
    public function itDistinguishesANullFileFromTheNoFilePlaceholderString(): void
    {
        $base = [
            'mechanism' => 'git-scope',
            'suppressor' => 'main..HEAD',
            'channel' => 'example.channel',
            'symbol' => 'App\\Foo',
            'severity' => 'warning',
        ];

        $withNullFile = compositionKey([...$base, 'file' => null]);
        $withLiteralPlaceholder = compositionKey([...$base, 'file' => '(no file)']);

        self::assertSame($withNullFile, $withLiteralPlaceholder);
    }

    #[Test]
    public function itRendersAnEmptyCompositionAsJustTheHeader(): void
    {
        self::assertSame("mechanism\tsuppressor\tchannel\tfile\tsymbol\tseverity\tcount\n", renderComposition([]));
    }

    #[Test]
    public function itRendersCompositionRowsInTheGivenOrderWithCounts(): void
    {
        $rendered = renderComposition([
            "suppression\tsrc/A.php\tchan\tsrc/A.php\tApp\\A\twarning" => 2,
            "suppression\tsrc/B.php\tchan\tsrc/B.php\tApp\\B\terror" => 1,
        ]);

        self::assertSame(
            "mechanism\tsuppressor\tchannel\tfile\tsymbol\tseverity\tcount\n"
            . "suppression\tsrc/A.php\tchan\tsrc/A.php\tApp\\A\twarning\t2\n"
            . "suppression\tsrc/B.php\tchan\tsrc/B.php\tApp\\B\terror\t1\n",
            $rendered,
        );
    }

    #[Test]
    public function itRendersAnEmptyInertListAsJustTheHeader(): void
    {
        self::assertSame("mechanism\tsuppressor\n", renderInert([]));
    }
}
