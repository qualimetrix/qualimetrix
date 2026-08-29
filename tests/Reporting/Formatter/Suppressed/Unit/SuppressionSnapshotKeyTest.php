<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Formatter\Suppressed\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pure key/normalization logic of `scripts/generate-suppression-snapshot.php`,
 * exercised directly rather than through the slow self-analysis subprocess —
 * see PLAN.md, rule-vocabulary Ш6 decision (м) for the two probes this
 * defends: a directive's own line number must not enter the key (a line
 * shift with no decision change must not redden), while everything the
 * snapshot's key is actually built from must (a removed directive/threshold
 * must redden). The script is include-safe so this can call its functions
 * directly instead of round-tripping a subprocess.
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
     */
    #[Test]
    public function itLeavesEveryOtherMechanismsSuppressorUnchanged(): void
    {
        self::assertSame('src/Generated/*', normalizeSuppressor('path-exclusion', 'src/Generated/*'));
        self::assertSame('App\\Generated', normalizeSuppressor('namespace-exclusion', 'App\\Generated'));
        self::assertSame('example.rule', normalizeSuppressor('rule-namespace-exclusion', 'example.rule'));
        self::assertSame('example.rule', normalizeSuppressor('rule-path-exclusion', 'example.rule'));
        self::assertSame('main..HEAD', normalizeSuppressor('git-scope', 'main..HEAD'));
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
        ];

        self::assertSame(
            compositionKey($entry('src/Foo.php:10')),
            compositionKey($entry('src/Foo.php:11')),
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
        ];

        $withNullFile = compositionKey([...$base, 'file' => null]);
        $withLiteralPlaceholder = compositionKey([...$base, 'file' => '(no file)']);

        self::assertSame($withNullFile, $withLiteralPlaceholder);
    }

    #[Test]
    public function itRendersAnEmptyCompositionAsJustTheHeader(): void
    {
        self::assertSame("mechanism\tsuppressor\tchannel\tfile\tsymbol\tcount\n", renderComposition([]));
    }

    #[Test]
    public function itRendersCompositionRowsInTheGivenOrderWithCounts(): void
    {
        $rendered = renderComposition([
            "suppression\tsrc/A.php\tchan\tsrc/A.php\tApp\\A" => 2,
            "suppression\tsrc/B.php\tchan\tsrc/B.php\tApp\\B" => 1,
        ]);

        self::assertSame(
            "mechanism\tsuppressor\tchannel\tfile\tsymbol\tcount\n"
            . "suppression\tsrc/A.php\tchan\tsrc/A.php\tApp\\A\t2\n"
            . "suppression\tsrc/B.php\tchan\tsrc/B.php\tApp\\B\t1\n",
            $rendered,
        );
    }

    #[Test]
    public function itRendersAnEmptyInertListAsJustTheHeader(): void
    {
        self::assertSame("mechanism\tsuppressor\n", renderInert([]));
    }
}
