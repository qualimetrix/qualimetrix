<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Formatter\Sarif\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentationInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\Formatter\Sarif\SarifRuleCollector;

/**
 * The guard `docs/internal/plans/sarif-channel-descriptions.md` (package P4)
 * exists for: every real channel must resolve to its producer's own
 * description and to a documentation page carrying *that producer's* `Rule
 * ID:` anchor — not merely a page that exists. "The page exists" is exactly
 * what today's fixed `duplication -> rules/architecture.md` mis-mapping would
 * have satisfied, so this test resolves the anchor instead.
 *
 * The swept codes come from {@see ChannelUniverseInterface::channels()}
 * itself, never spelled by hand — a hand-written list is what let the old
 * table's 9 unreachable arms and 42 unreached channels go unnoticed.
 */
#[CoversClass(SarifRuleCollector::class)]
final class SarifRuleDescriptorCoverageTest extends TestCase
{
    /**
     * Matches {@see \Qualimetrix\Tests\Analysis\Finding\Integration\ChannelPresentationCoverageTest}:
     * a real container built without a resolved run configuration carries
     * only the statically declared channels — the computed-metric catalog is
     * empty until configuration resolves it, so `channels()` and
     * `staticDeclarations()` agree here.
     */
    private const int UNIVERSE_CHANNEL_COUNT = 52;

    /**
     * Mirrors {@see SarifRuleCollector}'s own private `DOCS_BASE_URI` — kept
     * as a literal here rather than exposed, the same trade the collector's
     * own {@see SarifRuleCollector::INFORMATION_URI} constant avoids by being
     * public; this one is duplicated on purpose so the test asserts the
     * collector's actual output against an independently stated expectation.
     */
    private const string DOCS_BASE_URI = 'https://qualimetrix.dev/';

    #[Test]
    public function everyChannelOfTheRealUniverseGetsItsProducersOwnDescriptorAndAWorkingHelpUri(): void
    {
        $container = (new ContainerFactory())->create();

        $universe = $container->get(ChannelUniverseInterface::class);
        \assert($universe instanceof ChannelUniverseInterface);

        $presentationView = $container->get(ChannelPresentationInterface::class);
        \assert($presentationView instanceof ChannelPresentationInterface);

        $collector = new SarifRuleCollector($presentationView);

        $channels = $universe->channels();
        self::assertCount(self::UNIVERSE_CHANNEL_COUNT, $channels);

        $failures = [];

        foreach ($channels as $channel) {
            $failures = [...$failures, ...$this->checkChannel($channel, $universe, $presentationView, $collector)];
        }

        self::assertSame([], $failures, "Channel(s) with a broken SARIF descriptor:\n  - " . implode("\n  - ", $failures));
    }

    /**
     * The sweep above builds its container without a resolved run
     * configuration — see this class's own `UNIVERSE_CHANNEL_COUNT` docblock
     * — so it never exercises a single `computed.*` / `health.*` code. This
     * resolves the six built-in health-score definitions the same way a real
     * run would (through {@see ComputedMetricConfiguratorInterface::resolve()}
     * against an empty config document) and re-sweeps.
     */
    #[Test]
    public function everyConfiguredComputedMetricChannelAlsoGetsItsProducersOwnDescriptorAndAWorkingHelpUri(): void
    {
        $container = (new ContainerFactory())->create();

        $configurator = $container->get(ComputedMetricConfiguratorInterface::class);
        \assert($configurator instanceof ComputedMetricConfiguratorInterface);

        $document = new ConfigurationDocument([], AbsolutePath::fromString('/'));
        $configurator->replace($configurator->resolve($document));

        $universe = $container->get(ChannelUniverseInterface::class);
        \assert($universe instanceof ChannelUniverseInterface);

        $presentationView = $container->get(ChannelPresentationInterface::class);
        \assert($presentationView instanceof ChannelPresentationInterface);

        $collector = new SarifRuleCollector($presentationView);

        $channels = $universe->channels();
        self::assertCount(self::UNIVERSE_CHANNEL_COUNT + \count(ComputedMetricDefaults::getDefaults()), $channels);

        $failures = [];

        foreach ($channels as $channel) {
            $failures = [...$failures, ...$this->checkChannel($channel, $universe, $presentationView, $collector)];
        }

        self::assertSame([], $failures, "Channel(s) with a broken SARIF descriptor:\n  - " . implode("\n  - ", $failures));
    }

    /** @return list<string> */
    private function checkChannel(
        FindingChannel $channel,
        ChannelUniverseInterface $universe,
        ChannelPresentationInterface $presentationView,
        SarifRuleCollector $collector,
    ): array {
        $code = $channel->code;
        $presentation = $presentationView->presentationFor($code);

        if ($presentation === null) {
            // A channel the universe itself declares must resolve — see
            // ChannelPresentationCoverageTest (P2), which already sweeps this
            // for the plain description/docs-page-exists check. Failing loud
            // here rather than skipping is what makes this branch a guard
            // instead of a silent no-op — see the P4 DoD's "must fail the
            // oracle" requirement. A `computed.*` / `health.*` definition with a
            // blank `description:` does NOT land here — see
            // {@see \Qualimetrix\Tests\Infrastructure\Rule\Unit\ComputedMetricChannelPresentationTest::itFallsBackToTheProducersOwnPresentationWhenTheConfiguredDefinitionsDescriptionIsEmpty()} —
            // it falls back to the producing rule's own presentation instead of
            // resolving to null, so this branch only ever fires for a channel the
            // universe declares but no presenter answers for at all.
            return [\sprintf('%s: no presentation resolved for a channel the universe itself declares.', $code)];
        }

        $rules = $collector->collectRules([self::finding($channel->code, $code)]);

        if (\count($rules) !== 1) {
            return [\sprintf('%s: collectRules() returned %d entries, expected 1.', $code, \count($rules))];
        }

        $rule = $rules[0];
        $failures = [];

        if ($rule['shortDescription']['text'] !== $presentation->description) {
            $failures[] = \sprintf(
                '%s: shortDescription "%s" does not match the presentation\'s own description "%s".',
                $code,
                $rule['shortDescription']['text'],
                $presentation->description,
            );
        }

        if ($rule['fullDescription']['text'] !== $presentation->description) {
            $failures[] = \sprintf('%s: fullDescription does not match the presentation\'s own description.', $code);
        }

        $expectedHelpUri = self::DOCS_BASE_URI . preg_replace('/\.md$/', '/', $presentation->docsPage);

        if ($rule['helpUri'] !== $expectedHelpUri) {
            $failures[] = \sprintf('%s: helpUri "%s" does not match the expected "%s".', $code, $rule['helpUri'], $expectedHelpUri);
        }

        $producer = $universe->producerOf($code);

        if ($producer === null) {
            $failures[] = \sprintf('%s: universe declares the channel but names no producer.', $code);

            return $failures;
        }

        $docsPath = self::docsRoot() . '/' . $presentation->docsPage;

        if (!is_file($docsPath)) {
            $failures[] = \sprintf('%s: helpUri page "%s" does not exist.', $code, $presentation->docsPage);

            return $failures;
        }

        $contents = file_get_contents($docsPath);
        \assert(\is_string($contents));

        $anchor = \sprintf('**Rule ID:** `%s`', $producer);

        if (!str_contains($contents, $anchor)) {
            $failures[] = \sprintf(
                '%s: helpUri page "%s" does not carry the producing rule\'s ("%s") Rule ID anchor'
                . ' — "the page exists" is not enough (this is exactly what the old'
                . ' duplication -> rules/architecture.md mis-mapping would have passed).',
                $code,
                $presentation->docsPage,
                $producer,
            );
        }

        return $failures;
    }

    #[Test]
    public function anUnknownCodeKeepsTheHumanisedFallbackAndTheRepositoryUrl(): void
    {
        $container = (new ContainerFactory())->create();
        $presentationView = $container->get(ChannelPresentationInterface::class);
        \assert($presentationView instanceof ChannelPresentationInterface);

        $collector = new SarifRuleCollector($presentationView);
        $rules = $collector->collectRules([self::finding('custom.made-up-rule', 'custom.made-up-rule')]);

        self::assertCount(1, $rules);
        self::assertSame('Custom made up rule', $rules[0]['shortDescription']['text']);
        self::assertSame(SarifRuleCollector::INFORMATION_URI, $rules[0]['helpUri']);
    }

    private static function finding(string $ruleName, string $code): \Qualimetrix\Analysis\Finding\Contract\Finding
    {
        $symbolPath = SymbolPath::forProject();

        return new \Qualimetrix\Analysis\Finding\Contract\Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate($symbolPath),
            symbolPath: $symbolPath,
            ruleName: $ruleName,
            code: $code,
            message: 'fixture',
            severity: Severity::Warning,
        );
    }

    private static function docsRoot(): string
    {
        return \dirname(__DIR__, 5) . '/website/docs';
    }
}
