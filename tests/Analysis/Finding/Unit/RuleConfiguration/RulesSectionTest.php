<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\RuleConfiguration;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

final class RulesSectionTest extends TestCase
{
    #[Test]
    public function itExpandsNestedShorthandsBeforeMergingOnlyTheWrittenHalf(): void
    {
        $document = DocumentComposer::compose(self::schema(), [self::layer(['rules' => [ComplexityRule::NAME => ['threshold' => 17]]], '/base.yaml'), self::layer(['rules' => [ComplexityRule::NAME => ['callable' => ['warning' => 13, 'error' => null]]]], '/overlay.yaml')]);
        self::assertSame(13, $document->get('rules', ComplexityRule::NAME, 'callable', 'warning')?->plain());
        self::assertSame(17, $document->get('rules', ComplexityRule::NAME, 'callable', 'error')?->plain());
    }

    #[Test]
    public function itRefusesOverlappingAuthoredSpreadingPathsInTheSameLayer(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('threshold');
        DocumentComposer::compose(self::schema(), [self::layer(['rules' => [ComplexityRule::NAME => ['threshold' => 17, 'callable' => ['warning' => 13]]]], '/project/qmx.yaml')]);
    }

    #[Test]
    public function itJudgesAMalformedLowerLayerBeforeAValidOverlay(): void
    {
        try {
            DocumentComposer::compose(self::schema(), [self::layer(['rules' => [ComplexityRule::NAME => ['callable' => ['warning' => 'bad']]]], '/bad.yaml'), self::layer(['rules' => [ComplexityRule::NAME => ['callable' => ['warning' => 10]]]], '/good.yaml')]);
            self::fail('The malformed lower layer disappeared.');
        } catch (ConfigurationRefusal $error) {
            self::assertStringContainsString('/bad.yaml', $error->getMessage());
            self::assertStringContainsString('rules.complexity.ccn.callable.warning', $error->getMessage());
        }
    }

    #[Test]
    public function itPreservesBareSwitchAndIndependentSelectionMergePolicies(): void
    {
        $document = DocumentComposer::compose(self::schema(), [self::layer(['rules' => [ComplexityRule::NAME => false], 'only_rules' => ['complexity.*'], 'disabled_rules' => ['size.*']], '/base.yaml'), self::layer(['only_rules' => [], 'disabled_rules' => ['size.*', 'coupling.*']], '/overlay.yaml')]);
        self::assertFalse($document->get('rules', ComplexityRule::NAME, 'enabled')?->plain());
        self::assertSame([], $document->get('only_rules')?->plain());
        self::assertSame(['size.*', 'coupling.*'], $document->get('disabled_rules')?->plain());
        self::assertCount(1, $document->diagnostics());
    }

    #[Test]
    public function itRefusesFrameworkSuppressionInsideALevel(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('suppress_paths');
        DocumentComposer::compose(self::schema(), [self::layer(['rules' => [ComplexityRule::NAME => ['callable' => ['suppress_paths' => []]]]], '/project/qmx.yaml')]);
    }

    #[Test]
    public function itRefusesAnUnsupportedSectionRoot(): void
    {
        self::expectException(LogicException::class);
        new RulesSection(ResolvedOptionsFixture::execution([]), 'unknown');
    }

    /** @param class-string<\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface> $optionsClass */
    #[Test]
    #[\PHPUnit\Framework\Attributes\TestWith(['complexity.ccn', ComplexityOptions::class, 'suppress-namespaces'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['complexity.ccn', ComplexityOptions::class, 'suppress-paths'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['complexity.ccn', ComplexityOptions::class, 'suppress-namespace-channels'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['coupling.distance', \Qualimetrix\Analysis\Evidence\Coupling\DistanceOptions::class, 'include-namespaces'])]
    public function itRefusesAnInvalidSemanticSelectorBeforeAValidOverlay(string $producer, string $optionsClass, string $key): void
    {
        $execution = ResolvedOptionsFixture::execution([new RuleMetadata($producer, $optionsClass, '', [], false)]);
        $schema = new DocumentSchema([new RulesSection($execution, 'rules')]);
        $bad = [['regex' => '[']];
        $good = [['subtree' => 'App']];
        $position = ['rules', $producer, $key];
        if ($key === 'suppress-namespace-channels') {
            $bad = ['complexity.ccn.callable' => $bad];
            $good = ['complexity.ccn.callable' => $good];
            $position[] = 'complexity.ccn.callable';
        }
        $position[] = '0';
        $upper = self::layer(['rules' => [$producer => [$key => $good]]], '/good.yaml');
        try {
            DocumentComposer::compose($schema, [self::layer(['rules' => [$producer => [$key => $bad]]], '/bad.yaml'), $upper]);
            self::fail('The invalid lower selector disappeared.');
        } catch (ConfigurationRefusal $error) {
            $prefix = $key === 'include-namespaces'
                ? 'Option "include_namespaces" for rule "coupling.distance" entry 0 is invalid: '
                : 'Option "' . implode('.', \array_slice($position, 2)) . '" for rule "complexity.ccn" ';
            self::assertSame($prefix . 'Selector "regex:[" is not valid PCRE: preg_match(): Compilation failed: escape sequence is invalid in character class at offset 49.', $error->summary());
            self::assertSame(ConfigurationSource::ConfigFile, $error->sources()[0]->source());
            self::assertSame('/bad.yaml', $error->sources()[0]->locator());
            self::assertSame($position, $error->position()?->segments);
            self::assertSame('0', $error->position()->written);
        }
        $document = DocumentComposer::compose($schema, [$upper]);
        $options = (new \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild($execution))->build(new \Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration(
            new \Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument(),
            new \Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides(),
            new \Qualimetrix\Analysis\Finding\Contract\RuleSelection(),
            document: $document,
        ));
        if ($key === 'include-namespaces') {
            $typed = $options->for($producer);
            self::assertInstanceOf(\Qualimetrix\Analysis\Evidence\Coupling\DistanceOptions::class, $typed);
            $patterns = $typed->includeNamespaces;
        } else {
            $suppression = $options->suppressionFor($producer);
            $patterns = match ($key) {
                'suppress-paths' => $suppression->paths,
                'suppress-namespaces' => $suppression->namespaces,
                default => $suppression->namespaceChannels['complexity.ccn.callable'],
            };
        }
        self::assertNotNull($patterns);
        self::assertCount(1, $patterns);
        self::assertSame('subtree:App', $patterns[0]->definition->display());
    }

    /** @param array<string, list<array<string, string>>> $channels */
    #[Test]
    #[\PHPUnit\Framework\Attributes\TestWith([[]])]
    #[\PHPUnit\Framework\Attributes\TestWith([['complexity.ccn.callable' => []]])]
    public function itRefusesAnEmptyLowerChannelSelectorBeforeAValidOverlay(array $channels): void
    {
        try {
            DocumentComposer::compose(self::schema(), [
                self::layer(['rules' => [ComplexityRule::NAME => ['suppress-namespace-channels' => $channels]]], '/bad.yaml'),
                self::layer(['rules' => [ComplexityRule::NAME => ['suppress-namespace-channels' => ['complexity.ccn.callable' => [['subtree' => 'App']]]]]], '/good.yaml'),
            ]);
            self::fail('The empty lower channel selector disappeared.');
        } catch (ConfigurationRefusal $error) {
            self::assertSame($channels === []
                ? 'Option "suppress_namespace_channels" for rule "complexity.ccn" must be a non-empty channel map.'
                : 'Option "suppress_namespace_channels.complexity.ccn.callable" for rule "complexity.ccn" must be a non-empty list of explicit selector mappings.', $error->summary());
            self::assertSame('/bad.yaml', $error->sources()[0]->locator());
            self::assertSame(['rules', 'complexity.ccn', 'suppress-namespace-channels'], $error->position()?->segments);
        }
    }

    private static function schema(): DocumentSchema
    {
        $execution = ResolvedOptionsFixture::execution([new RuleMetadata(ComplexityRule::NAME, ComplexityOptions::class, '', [], false)]);
        return new DocumentSchema([new RulesSection($execution, 'rules'), new RulesSection($execution, 'only_rules'), new RulesSection($execution, 'disabled_rules')]);
    }

    /** @param array<string, mixed> $values */
    private static function layer(array $values, string $file): AuthoredLayer
    {
        return new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $file), AuthoredNode::fromPlain($values));
    }
}
