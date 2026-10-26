<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\RuleConfiguration\Selection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelPresentation;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelPresentationInterface;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\ChannelFileScope;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\RuleConfiguration\Selection\RuleEnablementResolver;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

/**
 * A per-rule `suppress_namespaces` / `suppress_paths` list on a producer whose
 * every channel is project-scoped can never match; it is refused at load.
 */
#[CoversClass(RuleEnablementResolver::class)]
final class ProjectScopedSuppressionRefusalTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function suppressionOptions(): iterable
    {
        yield 'namespaces' => ['suppress_namespaces'];
        yield 'paths' => ['suppress_paths'];
    }

    #[Test]
    #[DataProvider('suppressionOptions')]
    public function itRefusesANonEmptyListOnAProducerWithOnlyProjectScopedChannels(string $option): void
    {
        try {
            self::decide('demo.cycle', [$option => [['subtree' => 'App']]]);
            self::fail('The option was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString(\sprintf('Option "%s" for rule "demo.cycle"', $option), $refusal->summary());
            self::assertStringContainsString('findings of this rule are project-level and cannot be addressed by path or namespace', $refusal->summary());
            self::assertStringContainsString('use a baseline', $refusal->summary());
            self::assertStringContainsString('See https://qualimetrix.dev/rules/demo/.', $refusal->summary());
            self::assertSame(['rules', 'demo.cycle', $option], $refusal->position()?->segments);
        }
    }

    #[Test]
    #[DataProvider('suppressionOptions')]
    public function itAcceptsAnEmptyOrNullList(string $option): void
    {
        self::assertSame([], self::decide('demo.cycle', [$option => []])->diagnostics());
        self::assertSame([], self::decide('demo.cycle', [$option => null])->diagnostics());
    }

    #[Test]
    #[DataProvider('suppressionOptions')]
    public function itJudgesTheListTheHigherLayerLeavesEffective(string $option): void
    {
        $metadata = self::metadata();
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['rules' => ['demo.cycle' => [$option => [['subtree' => 'App']]]]]],
            ['source' => 'preset', 'values' => ['rules' => ['demo.cycle' => [$option => []]]]],
        ], AbsolutePath::fromString('/project'), $metadata);

        self::assertSame([], self::resolver()->decide($document->resolved(), self::universe())->diagnostics());
    }

    #[Test]
    #[DataProvider('suppressionOptions')]
    public function itAcceptsAProducerWithAFileScopedChannel(string $option): void
    {
        self::assertSame([], self::decide('demo.mixed', [$option => [['subtree' => 'App']]])->diagnostics());
    }

    /** A configured computed metric reduced to the project level is file-scoped by default. */
    #[Test]
    #[DataProvider('suppressionOptions')]
    public function itAcceptsAProjectLevelChannelThatNoCapabilityDeclaresProjectScoped(string $option): void
    {
        self::assertSame([], self::decide('demo.metric', [$option => [['subtree' => 'App']]])->diagnostics());
    }

    #[Test]
    public function itOmitsTheDocsLinkWhenNoPageIsKnown(): void
    {
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['rules' => ['demo.cycle' => ['suppress_paths' => [['subtree' => 'src']]]]]],
        ], AbsolutePath::fromString('/project'), self::metadata());

        try {
            (new RuleEnablementResolver(new ChannelFileScope(['demo.cycle']), self::presentation(null)))->decide($document->resolved(), self::universe());
            self::fail('The option was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringNotContainsString('See ', $refusal->summary());
        }
    }

    /** The scope is the source of truth: with nothing declared project-scoped, nothing is refused. */
    #[Test]
    public function itRefusesNothingWhenNoChannelIsDeclaredProjectScoped(): void
    {
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['rules' => ['demo.cycle' => ['suppress_namespaces' => [['subtree' => 'App']]]]]],
        ], AbsolutePath::fromString('/project'), self::metadata());

        self::assertSame([], (new RuleEnablementResolver(new ChannelFileScope([]), self::presentation()))->decide($document->resolved(), self::universe())->diagnostics());
    }

    /** @param array<string, mixed> $options */
    private static function decide(string $producer, array $options): \Qualimetrix\Analysis\Finding\Contract\Selection\StatedEnablement
    {
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['rules' => [$producer => $options]]],
        ], AbsolutePath::fromString('/project'), self::metadata());

        return self::resolver()->decide($document->resolved(), self::universe());
    }

    private static function resolver(): RuleEnablementResolver
    {
        return new RuleEnablementResolver(new ChannelFileScope(['demo.cycle', 'demo.mixed-project']), self::presentation());
    }

    private static function presentation(?string $docsPage = 'rules/demo.md'): ChannelPresentationInterface
    {
        return new readonly class ($docsPage) implements ChannelPresentationInterface {
            public function __construct(private ?string $docsPage) {}

            public function presentationFor(string $code): ?ChannelPresentation
            {
                return $this->docsPage === null ? null : new ChannelPresentation('Demo', $this->docsPage);
            }
        };
    }

    /** @return list<RuleMetadata> */
    private static function metadata(): array
    {
        return array_map(
            static fn(string $name): RuleMetadata => new RuleMetadata($name, CodeSmellOptions::class, '', [], false),
            ['demo.cycle', 'demo.mixed', 'demo.metric'],
        );
    }

    private static function universe(): ChannelUniverseInterface
    {
        return new ChannelUniverse(
            [
                'demo.cycle' => ChannelDeclaration::occurrence(SymbolLevel::Project),
                'demo.mixed-class' => ChannelDeclaration::occurrence(SymbolLevel::Class_),
                'demo.mixed-project' => ChannelDeclaration::occurrence(SymbolLevel::Project),
                'demo.metric' => ChannelDeclaration::occurrence(SymbolLevel::Project),
            ],
            [
                'demo.cycle' => ['demo.cycle'],
                'demo.mixed' => ['demo.mixed-class', 'demo.mixed-project'],
                'demo.metric' => ['demo.metric'],
            ],
            ['demo.cycle' => false, 'demo.mixed' => false, 'demo.metric' => false],
            new ResolvedComputedMetricDefinitions([]),
            ...ResolvedOptionsFixture::unusedReachPorts(),
        );
    }
}
