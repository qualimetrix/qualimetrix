<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * `FindingConfigurationResolver::mergeRuleOptions()` is one of the two merge
 * sites `RuleOptionThresholdShorthand::unfold()` runs at — the other is
 * `RuleOptionsFactory::deepMerge()` (config file/preset document ↔ CLI),
 * covered by `RuleOptionsFactoryTest`. This file covers the earlier merge:
 * multiple `rules:` document contributions (preset, `qmx.yaml`, ...) folding
 * into one before the factory ever runs.
 */
final class FindingConfigurationResolverTest extends TestCase
{
    #[Test]
    public function itFoldsRuleSourcesUnfoldingBothLayersAndSelectorSemantics(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'preset', 'values' => [
                'rules' => ['size.method-count' => ['warning' => 10, 'error' => 20]],
                'disabled_rules' => ['size'],
                'only_rules' => ['complexity'],
            ]],
            ['source' => 'qmx.yaml', 'values' => [
                'rules' => ['size.method-count' => ['threshold' => 15]],
                'disabled_rules' => ['security'],
                'only_rules' => ['design'],
            ]],
        ], AbsolutePath::fromString('/project'));

        $configuration = (new FindingConfigurationResolver())->resolve($document, new FindingCliOverrides());

        // The overlay's `threshold` shorthand is unfolded into the graduated
        // pair BEFORE merging, so both survive as `warning`/`error` — not as
        // a bare `threshold` (that was eviction's shape, now gone).
        self::assertSame(['warning' => 15, 'error' => 15], $configuration->ruleOptions->rules['size.method-count']);
        self::assertSame(['design'], $configuration->selection->only);
        self::assertSame(['size', 'security'], $configuration->selection->disabled);
    }

    /**
     * The reverse direction of the case above: the LOWER layer (preset)
     * carries the `threshold` shorthand, the HIGHER layer (`qmx.yaml`)
     * carries one half of the graduated pair. Eviction only ever rewrote the
     * lower layer, so this direction — the higher layer only rewriting half
     * the band — used to merge into a 3-key array and reach `ThresholdParser`
     * as a false "Cannot mix". Unfolding both layers fixes it: both are
     * unfolded before merging, so the merge sees `{warning: 25, error: 25}`
     * under `{warning: 10}` and produces `{warning: 10, error: 25}` — the
     * error boundary preserved from the preset, not the constructor default.
     */
    #[Test]
    public function itAppliesTheHigherLayersHalfOfTheBandOverTheLowerLayersThreshold(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'preset', 'values' => [
                'rules' => ['size.method-count' => ['threshold' => 25]],
            ]],
            ['source' => 'qmx.yaml', 'values' => [
                'rules' => ['size.method-count' => ['warning' => 10]],
            ]],
        ], AbsolutePath::fromString('/project'));

        $configuration = (new FindingConfigurationResolver())->resolve($document, new FindingCliOverrides());

        self::assertSame(['warning' => 10, 'error' => 25], $configuration->ruleOptions->rules['size.method-count']);
    }

    /**
     * §4 of `measurement/observations.md`: an overlay's `threshold: ~` used to
     * be treated as PRESENT (eviction asked presence, not writtenness) and
     * silenced the lower layer's whole band. `~` selects no mode, so the
     * lower layer's `warning`/`error` must survive untouched.
     */
    #[Test]
    public function itLeavesTheLowerLayersBandAloneWhenTheOverlaysThresholdIsUnwritten(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'preset', 'values' => [
                'rules' => ['size.method-count' => ['warning' => 2, 'error' => 3]],
            ]],
            ['source' => 'qmx.yaml', 'values' => [
                'rules' => ['size.method-count' => ['threshold' => null]],
            ]],
        ], AbsolutePath::fromString('/project'));

        $configuration = (new FindingConfigurationResolver())->resolve($document, new FindingCliOverrides());

        // The overlay's `threshold: ~` is written verbatim into the merged
        // array (nothing stood behind IT specifically, only behind
        // `warning`/`error` — single-document `null` semantics are
        // untouched); what matters is that it selects no mode, so
        // `warning`/`error` survive from the lower layer and
        // `ThresholdParser` still reads the graduated pair when this reaches
        // `Options::fromArray()` (see `RuleOptionsFactoryTest` for the
        // end-to-end proof through a real Options class).
        self::assertSame(['warning' => 2, 'error' => 3, 'threshold' => null], $configuration->ruleOptions->rules['size.method-count']);
    }

    /**
     * §5 of `measurement/observations.md`, the mirror of the case above: an
     * overlay's `warning: ~` is a value-slot write of `null`, not a
     * shorthand-key question, so unfolding does not touch it — the merge
     * itself must not let that `null` erase the lower layer's `warning: 2`.
     */
    #[Test]
    public function itLeavesTheLowerLayersWarningAloneWhenTheOverlayWritesNullOverIt(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'preset', 'values' => [
                'rules' => ['size.method-count' => ['warning' => 2, 'error' => 100]],
            ]],
            ['source' => 'qmx.yaml', 'values' => [
                'rules' => ['size.method-count' => ['warning' => null]],
            ]],
        ], AbsolutePath::fromString('/project'));

        $configuration = (new FindingConfigurationResolver())->resolve($document, new FindingCliOverrides());

        self::assertSame(['warning' => 2, 'error' => 100], $configuration->ruleOptions->rules['size.method-count']);
    }

    /**
     * The band is asserted as a pair on purpose (per this round's DoD: no
     * fixture checks only one half). Lower layer writes the `threshold`
     * shorthand (unfolds to both halves); the overlay writes `warning: ~`,
     * which must not erase the unfolded `warning` — both halves must read 5.
     */
    #[Test]
    public function itKeepsBothHalvesOfTheBandWhenTheOverlaysNullTargetsAnUnfoldedHalf(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'preset', 'values' => [
                'rules' => ['size.method-count' => ['threshold' => 5]],
            ]],
            ['source' => 'qmx.yaml', 'values' => [
                'rules' => ['size.method-count' => ['warning' => null]],
            ]],
        ], AbsolutePath::fromString('/project'));

        $configuration = (new FindingConfigurationResolver())->resolve($document, new FindingCliOverrides());

        self::assertSame(['warning' => 5, 'error' => 5], $configuration->ruleOptions->rules['size.method-count']);
    }

    /**
     * The same question one level up: `mergeRules()` merges by RULE NAME, not
     * by option key, and used to assign an overlay's value unconditionally —
     * so `rules: {complexity.ccn: ~}` erased the WHOLE rule config a lower
     * layer wrote, not just one key of it. `RuleOptionsFactory::normalizeScalarConfig()`
     * already treats a rule written `~` in a single document as "no config,
     * take the defaults" (the same meaning `~` has everywhere else), so a
     * `~` written by a higher layer over an already-configured rule must mean
     * the same thing across the layer boundary: nothing was written here,
     * not "reset to defaults". Measured directly: preset
     * `{complexity.ccn: {callable: {warning: 2, error: 3}}}` alone reports
     * `error@3`; the same preset under `qmx.yaml`'s `{complexity.ccn: ~}`
     * used to report no finding at all.
     */
    #[Test]
    public function itLeavesTheLowerLayersWholeRuleConfigAloneWhenTheOverlayWritesNullOverTheRuleName(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'preset', 'values' => [
                'rules' => ['complexity.ccn' => ['callable' => ['warning' => 2, 'error' => 3]]],
            ]],
            ['source' => 'qmx.yaml', 'values' => [
                'rules' => ['complexity.ccn' => null],
            ]],
        ], AbsolutePath::fromString('/project'));

        $configuration = (new FindingConfigurationResolver())->resolve($document, new FindingCliOverrides());

        self::assertSame(
            ['callable' => ['warning' => 2, 'error' => 3]],
            $configuration->ruleOptions->rules['complexity.ccn'],
        );
    }

    /**
     * The control for the fix above: `false` (and `true`) on a rule's own
     * name is an explicit scalar switch, not "nothing was written" — it must
     * keep overwriting the base unconditionally. Only `null` changed.
     */
    #[Test]
    public function itStillLetsAnExplicitFalseSwitchOffARuleWithConfiguredOptions(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'preset', 'values' => [
                'rules' => ['complexity.ccn' => ['callable' => ['warning' => 2, 'error' => 3]]],
            ]],
            ['source' => 'qmx.yaml', 'values' => [
                'rules' => ['complexity.ccn' => false],
            ]],
        ], AbsolutePath::fromString('/project'));

        $configuration = (new FindingConfigurationResolver())->resolve($document, new FindingCliOverrides());

        self::assertFalse($configuration->ruleOptions->rules['complexity.ccn']);
    }
}
