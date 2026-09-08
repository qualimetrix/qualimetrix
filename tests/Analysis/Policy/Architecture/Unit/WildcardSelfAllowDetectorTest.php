<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Configuration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowListEntry;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowTarget;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelector;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelectorParser;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\WildcardSelfAllowDetector;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationWarning;

#[CoversClass(WildcardSelfAllowDetector::class)]
#[CoversClass(ArchitectureConfigurationWarning::class)]
final class WildcardSelfAllowDetectorTest extends TestCase
{
    private WildcardSelfAllowDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new WildcardSelfAllowDetector();
    }

    #[Test]
    public function itEmitsNoWarningForAnEmptyEntryList(): void
    {
        $warnings = [];
        $this->detector->detect([], $warnings);

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itWarnsOnAGlobTargetThatReferencesItsOwnSourceGlob(): void
    {
        $warnings = [];

        $this->detector->detect(
            [
                new AllowListEntry(
                    LayerSelector::glob('domain-*'),
                    [new AllowTarget(LayerSelector::glob('domain-*'))],
                ),
            ],
            $warnings,
        );

        self::assertCount(1, $warnings);
        self::assertStringContainsString("wildcard-self-allow detected on entry(s) 'domain-*'", $warnings[0]->message);
    }

    #[Test]
    public function itSilencesTheWarningWhenAllowCrossInstanceIsSet(): void
    {
        $warnings = [];

        $this->detector->detect(
            [
                new AllowListEntry(
                    LayerSelector::glob('domain-*'),
                    [
                        new AllowTarget(
                            LayerSelector::glob('domain-*'),
                            allowCrossInstance: true,
                        ),
                    ],
                ),
            ],
            $warnings,
        );

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itEmitsNoWarningForAnExactToExactEntry(): void
    {
        $warnings = [];

        $this->detector->detect(
            [
                new AllowListEntry(
                    LayerSelector::exact('controller'),
                    [new AllowTarget(LayerSelector::exact('service'))],
                ),
            ],
            $warnings,
        );

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itEmitsNoWarningForAGlobToExactEntry(): void
    {
        $warnings = [];

        $this->detector->detect(
            [
                new AllowListEntry(
                    LayerSelector::glob('domain-*'),
                    [new AllowTarget(LayerSelector::exact('vendor'))],
                ),
            ],
            $warnings,
        );

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itEmitsNoWarningForAnExactToGlobEntry(): void
    {
        $warnings = [];

        $this->detector->detect(
            [
                new AllowListEntry(
                    LayerSelector::exact('controller'),
                    [new AllowTarget(LayerSelector::glob('repo-*'))],
                ),
            ],
            $warnings,
        );

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itEmitsNoWarningForDivergentGlobPatterns(): void
    {
        // Strict-superset relationship — intentional directional structure, not
        // a self-reference. Diverging glob shapes are NOT flagged.
        $warnings = [];

        $this->detector->detect(
            [
                new AllowListEntry(
                    LayerSelector::glob('domain-a*'),
                    [new AllowTarget(LayerSelector::glob('domain-*'))],
                ),
            ],
            $warnings,
        );

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itEmitsNoWarningForACapturedSelfReferenceBoundAtRuntime(): void
    {
        // Captured-on-both-sides enforces binding identity at runtime, so
        // self-reference is not a footgun — no warning needed.
        $warnings = [];

        $this->detector->detect(
            [
                new AllowListEntry(
                    LayerSelectorParser::parse('domain-{m}'),
                    [new AllowTarget(LayerSelectorParser::parse('domain-{m}'))],
                ),
            ],
            $warnings,
        );

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itAccumulatesMultipleSelfGlobEntriesIntoASingleWarning(): void
    {
        $warnings = [];

        $this->detector->detect(
            [
                new AllowListEntry(
                    LayerSelector::glob('domain-*'),
                    [new AllowTarget(LayerSelector::glob('domain-*'))],
                ),
                new AllowListEntry(
                    LayerSelector::glob('app-*'),
                    [new AllowTarget(LayerSelector::glob('app-*'))],
                ),
            ],
            $warnings,
        );

        self::assertCount(1, $warnings);
        self::assertStringContainsString("'domain-*'", $warnings[0]->message);
        self::assertStringContainsString("'app-*'", $warnings[0]->message);
    }

    #[Test]
    public function itFlagsOnlyTheSelfGlobTargetAmongMixedTargets(): void
    {
        // First target is self-glob → flag. Second target is glob-to-exact →
        // ignore. Result: one warning, one pattern listed.
        $warnings = [];

        $this->detector->detect(
            [
                new AllowListEntry(
                    LayerSelector::glob('domain-*'),
                    [
                        new AllowTarget(LayerSelector::glob('domain-*')),
                        new AllowTarget(LayerSelector::exact('vendor')),
                    ],
                ),
            ],
            $warnings,
        );

        self::assertCount(1, $warnings);
        self::assertStringContainsString("'domain-*'", $warnings[0]->message);
    }

    #[Test]
    public function itStillWarnsWhenOneOfTwoSelfGlobTargetsIsSilenced(): void
    {
        // Entry has two glob targets matching the source pattern; one has
        // allow_cross_instance: true (silenced), the other does not (flagged).
        // The user is opting out for some but not all — flag the unsilenced one.
        $warnings = [];

        $this->detector->detect(
            [
                new AllowListEntry(
                    LayerSelector::glob('domain-*'),
                    [
                        new AllowTarget(
                            LayerSelector::glob('domain-*'),
                            allowCrossInstance: true,
                        ),
                        new AllowTarget(LayerSelector::glob('domain-*')),
                    ],
                ),
            ],
            $warnings,
        );

        self::assertCount(1, $warnings);
    }
}
