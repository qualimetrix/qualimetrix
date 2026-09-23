<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContextFactory;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\PhpBuiltinClassHierarchy;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * Where an inheritance chain leaves the analysed set, read through the real
 * dependency graph rather than a hand-built one.
 *
 * The unit tests build their own edges, so they cannot see which edges the
 * graph builder keeps for coupling and which it keeps for declarations: an
 * edge to a PHP type counts toward no coupling metric, and whether layer
 * membership still sees it is decided there. Twice it did not — a class
 * extending `\RuntimeException` lost its layer, and a class declaring
 * `implements \JsonSerializable` was told it does not — so this suite runs the
 * pipeline.
 *
 * Every class in `Domain` depends on `Infra\Db`, and `domain` may not depend
 * on `infra`: a class that stays in `domain` produces a violation, and one
 * that silently left it does not.
 */
#[CoversClass(ClassContextFactory::class)]
#[CoversClass(PhpBuiltinClassHierarchy::class)]
#[Group('integration')]
final class AncestryBorderIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/AncestryBorderSample';

    private const string NS = 'Fixtures\\AncestryBorderSample';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideClassesWhoseParentChainIsKnown(): iterable
    {
        yield 'extends a PHP class' => ['OrderFailed'];
        yield 'implements a PHP interface' => ['Snapshot'];
        yield 'implements a vendor interface' => ['Envelope'];
    }

    #[Test]
    #[DataProvider('provideClassesWhoseParentChainIsKnown')]
    public function itKeepsTheClassInItsLayerWhenAnExcludeByParentCanBeAnswered(string $class): void
    {
        // A vendor interface cannot hide a parent class, and a PHP class has a
        // hierarchy that is known without reading any file. Neither leaves
        // `extends` unanswerable.
        [$policy, $findings] = $this->analyse(self::excludeByParentConfig());
        $subject = SymbolPath::forClass(self::NS . '\\Domain', $class);

        self::assertSame('domain', $policy->registry()->resolveLayer($subject));
        self::assertSame([], $policy->registry()->undecidedLayers($subject));
        self::assertContains(
            self::NS . '\\Domain\\' . $class,
            self::violationSources($findings),
            'The class stayed in `domain`, so its edge to `infra` must be judged.',
        );
    }

    #[Test]
    public function itKeepsAClassWhoseExcludeCannotBeAnsweredInItsLayerAndSaysSo(): void
    {
        // A vendor parent's own parents were never read, so whether it extends
        // the excluded base is unknown. The class stays in `domain` and its
        // edges are judged; the doubt reaches the gap report.
        $config = self::excludeByParentConfig();
        $config['coverage-gap'] = 'warn';
        [$policy, $findings] = $this->analyse($config);
        $subject = SymbolPath::forClass(self::NS . '\\Domain', 'LegacyGateway');

        self::assertSame('domain', $policy->registry()->resolveLayer($subject));
        self::assertSame(['domain'], $policy->registry()->undecidedLayers($subject));
        self::assertContains(self::NS . '\\Domain\\LegacyGateway', self::violationSources($findings));

        $gap = array_values(array_filter(
            $findings,
            static fn(Finding $finding): bool => $finding->ruleName === LayerPolicyPreparationInterface::COVERAGE_DIAGNOSTIC_NAME,
        ));
        self::assertCount(1, $gap);
        self::assertStringContainsString('could not fully decide', $gap[0]->message);
        self::assertStringContainsString(self::NS . '\\Domain\\LegacyGateway', $gap[0]->message);
    }

    #[Test]
    public function itDoesNotReportATemplateInstanceUnreachableBecauseItsExcludeCannotBeAnswered(): void
    {
        // `mod-Ledger` exists only through a class extending a vendor class,
        // so its `exclude:` cannot be answered. The class stays in the
        // instance, which is therefore reached.
        [$policy, $findings] = $this->analyse(self::templateConfig());

        self::assertSame(
            [],
            self::unreachableLayerMessages($findings),
        );
        $subject = SymbolPath::forClass(self::NS . '\\Module\\Ledger', 'LedgerGateway');
        self::assertSame('mod-Ledger', $policy->registry()->resolveLayer($subject));
        self::assertSame(['mod-Ledger'], $policy->registry()->undecidedLayers($subject));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideCriteriaNamingAPhpAncestor(): iterable
    {
        yield 'direct parent' => ['extends', '\\RuntimeException'];
        yield 'grandparent' => ['extends', '\\Exception'];
        yield 'interface of a PHP parent' => ['implements', '\\Throwable'];
    }

    #[Test]
    #[DataProvider('provideCriteriaNamingAPhpAncestor')]
    public function itAnswersACriterionNamingAPhpAncestor(string $kind, string $fqn): void
    {
        [$policy] = $this->analyse([
            'layers' => [
                ['name' => 'failures', $kind => [$fqn]],
                ['name' => 'rest', 'patterns' => [self::NS . '\\**']],
            ],
            'allow' => ['failures' => [], 'rest' => []],
        ]);
        $subject = SymbolPath::forClass(self::NS . '\\Domain', 'OrderFailed');

        self::assertSame('failures', $policy->registry()->resolveLayer($subject));
        self::assertSame([], $policy->registry()->undecidedLayers($subject));
    }

    #[Test]
    public function itAnswersACriterionNamingAPhpClassOutsideTheChain(): void
    {
        // The complete half of the same knowledge: `\LogicException` is not in
        // the chain, and the run can say so rather than doubt it.
        [$policy] = $this->analyse([
            'layers' => [
                ['name' => 'logic', 'extends' => ['\\LogicException']],
                ['name' => 'rest', 'patterns' => [self::NS . '\\**']],
            ],
            'allow' => ['logic' => [], 'rest' => []],
        ]);
        $subject = SymbolPath::forClass(self::NS . '\\Domain', 'OrderFailed');

        self::assertSame('rest', $policy->registry()->resolveLayer($subject));
        self::assertSame([], $policy->registry()->undecidedLayers($subject));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function provideCriteriaNamingAPhpTypeTheClassDeclares(): iterable
    {
        yield 'PHP interface implemented directly' => ['implements', '\\JsonSerializable', 'Snapshot'];
        yield 'PHP attribute on the class' => ['attributes', '\\AllowDynamicProperties', 'Tagged'];
        yield 'interface above a PHP interface implemented directly' => ['implements', '\\Traversable', 'Catalogue'];
    }

    #[Test]
    #[DataProvider('provideCriteriaNamingAPhpTypeTheClassDeclares')]
    public function itAnswersACriterionNamingAPhpTypeTheClassDeclaresItself(string $kind, string $fqn, string $class): void
    {
        [$policy, $findings] = $this->analyse(self::markedByConfig($kind, $fqn));
        $subject = SymbolPath::forClass(self::NS . '\\Domain', $class);

        self::assertSame('marked', $policy->registry()->resolveLayer($subject));
        self::assertSame([], $policy->registry()->undecidedLayers($subject));

        // The legitimate neighbour: a class declaring nothing of the kind is
        // still a decided non-member, not a doubt.
        $plain = SymbolPath::forClass(self::NS . '\\Domain', 'Plain');
        self::assertSame('rest', $policy->registry()->resolveLayer($plain));
        self::assertSame([], $policy->registry()->undecidedLayers($plain));

        // The declaration edge reaches membership only; the allow-list still
        // judges the dependencies it judged before, and none of them is PHP's.
        foreach (self::violationMessages($findings) as $message) {
            foreach (['JsonSerializable', 'AllowDynamicProperties', 'IteratorAggregate'] as $phpType) {
                self::assertStringNotContainsString('→ ' . $phpType, $message);
            }
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function provideCriteriaAnsweredFromWhatPhpDeclares(): iterable
    {
        yield 'interface two steps above the PHP interface an interface extends' => ['extends', '\\Traversable', 'Bag'];
        yield 'the same chain written by the project' => ['extends', self::NS . '\\Library\\Root', 'Leaf'];
        yield 'PHP interface above the one an interface extends' => ['implements', '\\Traversable', 'Bag'];
        yield 'enum' => ['implements', '\\UnitEnum', 'Phase'];
        yield 'backed enum' => ['implements', '\\BackedEnum', 'Status'];
        yield 'interface above the one a backed enum gets' => ['implements', '\\UnitEnum', 'Status'];
        yield 'class declaring __toString' => ['implements', '\\Stringable', 'Label'];
        yield 'interface declaring __toString' => ['implements', '\\Stringable', 'Named'];
        yield 'class implementing an interface that declares __toString' => ['implements', '\\Stringable', 'Draft'];
        yield 'class writing implements Stringable' => ['implements', '\\Stringable', 'Explicit'];
        // Neither answer may depend on the PHP that runs the analysis: no local
        // PHP here loads pdo_firebird, and `uri` exists only from 8.5.
        yield 'PHP class of an extension the analysing PHP may not load' => ['extends', '\\PDO', 'FirebirdLink'];
        yield 'PHP class of a newer PHP than the analysing one' => ['implements', '\\Throwable', 'BadUri'];
    }

    #[Test]
    #[DataProvider('provideCriteriaAnsweredFromWhatPhpDeclares')]
    public function itAnswersACriterionFromWhatPhpDeclaresWhateverPhpRunsTheAnalysis(string $kind, string $fqn, string $class): void
    {
        [$policy, $findings] = $this->analyse(self::markedByConfig($kind, $fqn));
        $subject = SymbolPath::forClass(self::NS . '\\Library', $class);

        self::assertSame('marked', $policy->registry()->resolveLayer($subject));
        self::assertSame([], $policy->registry()->undecidedLayers($subject));

        // What PHP adds unwritten is a declaration fact only: no edge to it
        // reaches the allow-list, which claims PHP's classes under `**`.
        foreach (self::violationMessages($findings) as $message) {
            foreach (['UnitEnum', 'BackedEnum', 'Stringable'] as $phpType) {
                self::assertStringNotContainsString('→ ' . $phpType, $message);
            }
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function provideCriteriaTheClassDecidedlyDoesNotMeet(): iterable
    {
        yield 'a pure enum is not backed' => ['implements', '\\BackedEnum', 'Phase'];
        yield 'a class without __toString' => ['implements', '\\Stringable', 'Silent'];
        yield 'an interface off the chain of the one an interface extends' => ['extends', '\\Countable', 'Bag'];
    }

    #[Test]
    #[DataProvider('provideCriteriaTheClassDecidedlyDoesNotMeet')]
    public function itStillDecidesANonMatchNextToWhatPhpDeclares(string $kind, string $fqn, string $class): void
    {
        [$policy] = $this->analyse(self::markedByConfig($kind, $fqn));
        $subject = SymbolPath::forClass(self::NS . '\\Library', $class);

        self::assertSame('rest', $policy->registry()->resolveLayer($subject));
        self::assertSame([], $policy->registry()->undecidedLayers($subject));
    }

    #[Test]
    public function itAnswersAnAttributeCriterionForAPhpClassMetAsTheFarEndOfAnEdge(): void
    {
        // `Loose extends \stdClass` keeps its edge, so stdClass is classified,
        // and PHP declares it `#[\AllowDynamicProperties]`.
        [$policy] = $this->analyse(self::markedByConfig('attributes', '\\AllowDynamicProperties'));

        self::assertSame('marked', $policy->registry()->resolveLayer(SymbolPath::fromClassFqn('stdClass')));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideCriteriaNamingWhatANestedAnonymousClassDeclares(): iterable
    {
        yield 'PHP interface' => ['implements', '\\JsonSerializable'];
        yield 'PHP attribute' => ['attributes', '\\AllowDynamicProperties'];
    }

    #[Test]
    #[DataProvider('provideCriteriaNamingWhatANestedAnonymousClassDeclares')]
    public function itDoesNotMoveTheHostOfAnAnonymousClassDeclaringAPhpType(string $kind, string $fqn): void
    {
        [$policy] = $this->analyse(self::markedByConfig($kind, $fqn));
        $subject = SymbolPath::forClass(self::NS . '\\Domain', 'Host');

        self::assertSame('rest', $policy->registry()->resolveLayer($subject));
        self::assertSame([], $policy->registry()->undecidedLayers($subject));
    }

    #[Test]
    public function itDoesNotReportATemplateInstanceUnreachableBecauseItsClassExtendsAPhpClass(): void
    {
        // The template shape of the same defect: observation created
        // `mod-Billing` from a class whose exclude could not be answered, the
        // runtime then left the class out, and the instance was reported as a
        // layer nothing reaches — a configuration error failing the run.
        [$policy, $findings] = $this->analyse(self::templateConfig());

        self::assertSame([], self::unreachableLayerMessages($findings));
        self::assertSame(
            'mod-Billing',
            $policy->registry()->resolveLayer(SymbolPath::forClass(self::NS . '\\Module\\Billing', 'PaymentFailed')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function markedByConfig(string $kind, string $fqn): array
    {
        return [
            'layers' => [
                ['name' => 'marked', $kind => [$fqn]],
                // `**` claims PHP's own classes too, so an edge to one that
                // reached the allow-list would be judged and reported.
                ['name' => 'rest', 'patterns' => ['**']],
            ],
            'allow' => ['marked' => [], 'rest' => []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function templateConfig(): array
    {
        return [
            'layers' => [
                ['name' => 'infra', 'patterns' => [self::NS . '\\Infra\\**']],
                [
                    'name' => 'mod-{m}',
                    'patterns' => [self::NS . '\\Module\\{m}\\**'],
                    'exclude' => ['implements' => [self::NS . '\\Testing\\Marker']],
                ],
                ['name' => 'rest', 'patterns' => [self::NS . '\\**']],
            ],
            'allow' => ['infra' => [], 'mod-*' => [], 'rest' => ['infra']],
        ];
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private static function unreachableLayerMessages(array $findings): array
    {
        return array_values(array_map(
            static fn(Finding $finding): string => $finding->message,
            array_filter(
                $findings,
                static fn(Finding $finding): bool => $finding->ruleName === LayerPolicyPreparationInterface::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
            ),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function excludeByParentConfig(): array
    {
        return [
            'layers' => [
                [
                    'name' => 'domain',
                    'patterns' => [self::NS . '\\Domain\\**'],
                    'exclude' => ['extends' => [self::NS . '\\Testing\\Base']],
                ],
                ['name' => 'infra', 'patterns' => [self::NS . '\\Infra\\**']],
                ['name' => 'testing', 'patterns' => [self::NS . '\\Testing\\**']],
            ],
            'allow' => ['infra' => ['domain'], 'domain' => [], 'testing' => []],
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{0: ArchitectureConfiguration, 1: list<Finding>}
     */
    private function analyse(array $config): array
    {
        $container = (new ContainerFactory())->create();

        $holder = $container->get(ArchitecturePolicyConfiguratorInterface::class);
        self::assertInstanceOf(ArchitecturePolicy::class, $holder);
        $holder->bind((new ArchitectureConfigurationFactory())->fromArray($config)->configuration);

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);

        $root = AbsolutePath::fromString(self::FIXTURE_PATH);
        $result = $pipeline->analyze(new RunConfiguration(
            [$root],
            [],
            $root,
            GeneratedFilePolicy::Include,
            coversProjectScope: true,
            authoredPathExcludes: [],
        ));

        $prepared = $holder->getPreparedConfiguration();
        self::assertNotNull($prepared, 'The pipeline must have prepared the architecture policy.');

        return [$prepared, array_values($result->findings)];
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private static function violationMessages(array $findings): array
    {
        return array_values(array_map(
            static fn(Finding $finding): string => $finding->message,
            array_filter($findings, static fn(Finding $finding): bool => $finding->ruleName === LayerViolationRule::NAME),
        ));
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private static function violationSources(array $findings): array
    {
        $sources = [];
        foreach ($findings as $finding) {
            if ($finding->ruleName !== LayerViolationRule::NAME) {
                continue;
            }
            $sources[] = $finding->symbolPath->namespace . '\\' . $finding->symbolPath->type;
        }

        return $sources;
    }
}
