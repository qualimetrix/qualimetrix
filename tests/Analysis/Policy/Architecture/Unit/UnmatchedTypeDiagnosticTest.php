<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch;
use Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Population\PopulationSession;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerPolicy;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\NamedType;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\UnmatchedTypeOccurrence;
use Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration\LayerDeclarationRule;
use Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration\UnmatchedTypeDiagnostic;
use Qualimetrix\Analysis\Policy\Architecture\Observation\LayerEvidence;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(UnmatchedTypeDiagnostic::class)]
#[CoversClass(UnmatchedTypeOccurrence::class)]
#[CoversClass(ArchitectureConfiguration::class)]
#[CoversClass(LayerEvidence::class)]
final class UnmatchedTypeDiagnosticTest extends TestCase
{
    #[Test]
    public function itReportsEachUnmetPositiveAndExcludeTypeBesideAKnownType(): void
    {
        $positive = self::type('App\Knwon', ['architecture', 'layers', '0', 'implements', '1']);
        $excluded = self::type('App\Knwon', ['architecture', 'layers', '0', 'exclude', 'extends', '0']);
        $membership = new MembershipSpec(
            implements: ['App\Known', $positive->fqn],
            namedTypes: [self::type('App\Known', ['architecture', 'layers', '0', 'implements', '0']), $positive],
            exclude: new ExcludeSpec(extends: [$excluded->fqn], namedTypes: [$excluded]),
        );
        $evidence = $this->evidence([new LayerDefinition('typed', $membership)], [SymbolPath::forClass('App', 'Known')]);

        $findings = UnmatchedTypeDiagnostic::forEvidence($evidence, new ProjectScopeJudgement(), new AnalysisContext(new InMemoryMetricRepository()));

        self::assertCount(2, $findings);
        self::assertStringContainsString('architecture.layers[0].implements[1]:17', $findings[0]->message);
        self::assertStringContainsString('architecture.layers[0].exclude.extends[0]:17', $findings[1]->message);
        self::assertNotEquals($findings[0]->occurrenceKey, $findings[1]->occurrenceKey);
        foreach ($findings as $finding) {
            self::assertSame('architecture.unmatched-type', $finding->code);
            self::assertSame(Severity::Warning, $finding->severity);
            self::assertSame('project:', $finding->symbolPath->toCanonical());
            self::assertStringContainsString('configuration file "qmx.yaml"', $finding->message);
            self::assertStringContainsString('App\Knwon', $finding->message);
        }
    }

    #[Test]
    public function itKeepsSeparateAuthoredPositionsWhileCollapsingTemplateCopies(): void
    {
        $first = self::type('App\Missing', ['architecture', 'layers', '0', 'extends', '0']);
        $second = self::type('App\Missing', ['architecture', 'layers', '1', 'extends', '0']);
        $evidence = $this->evidence([
            new LayerDefinition('one', new MembershipSpec(extends: [$first->fqn], namedTypes: [$first])),
            new LayerDefinition('copy', new MembershipSpec(extends: [$first->fqn], namedTypes: [$first])),
            new LayerDefinition('other', new MembershipSpec(extends: [$second->fqn], namedTypes: [$second])),
        ]);

        $findings = UnmatchedTypeDiagnostic::forEvidence($evidence, new ProjectScopeJudgement(), new AnalysisContext(new InMemoryMetricRepository()));

        self::assertCount(2, $findings);
        self::assertNotEquals($findings[0]->occurrenceKey, $findings[1]->occurrenceKey);
    }

    #[Test]
    public function itKeepsAnUnmetMemberAttributeBesideAKnownPositiveType(): void
    {
        $evidence = $this->evidence([
            new LayerDefinition('typed', new MembershipSpec(extends: ['App\Known'], memberAttributes: ['App\MissingAttribute'])),
        ], [SymbolPath::forClass('App', 'Known')]);

        self::assertSame(['App\MissingAttribute'], $evidence->contests()['typed']['unmetTypes']);
    }

    #[Test]
    public function itJudgesAnAuthoredTypeEvenWhenItsTemplateProducedNoInstance(): void
    {
        $type = self::type('Vendor\Missing', ['architecture', 'layers', '0', 'extends', '0']);
        $entry = new TemplateLayerDefinition('module-{module}', new MembershipSpec(
            patterns: ['App\{module}\**'],
            extends: [$type->fqn],
            mode: MatchMode::All,
            namedTypes: [$type],
        ));
        $evidence = $this->evidence([], entries: [$entry]);

        $findings = UnmatchedTypeDiagnostic::forEvidence($evidence, new ProjectScopeJudgement(), new AnalysisContext(new InMemoryMetricRepository()));

        self::assertCount(1, $findings);
        self::assertStringContainsString('Vendor\Missing', $findings[0]->message);
    }

    /** @param list<ProjectScopeDoor> $doors */
    #[Test]
    #[DataProvider('withheldJudgements')]
    public function itWithholdsFindingsWhenScopeOrInstallCannotJudgeAbsence(array $doors, bool $install): void
    {
        $type = self::type('App\Missing', ['architecture', 'layers', '0', 'extends', '0']);
        $evidence = $this->evidence([
            new LayerDefinition('typed', new MembershipSpec(extends: [$type->fqn], namedTypes: [$type])),
        ], install: $install);

        self::assertSame([], UnmatchedTypeDiagnostic::forEvidence($evidence, new ProjectScopeJudgement($doors), new AnalysisContext(new InMemoryMetricRepository())));
    }

    /** @return iterable<string, array{list<ProjectScopeDoor>, bool}> */
    public static function withheldJudgements(): iterable
    {
        yield 'narrowed' => [[ProjectScopeDoor::Paths], true];
        yield 'install not read' => [[], false];
        yield 'both' => [[ProjectScopeDoor::Paths], false];
    }

    #[Test]
    public function itPreservesTheImporterChainInTheAuthoredOccurrenceIdentity(): void
    {
        $origin = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'layers.yaml');
        $one = new NamedType('App\Missing', new Provenance($origin->importedThrough(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'one.yaml')), ['architecture', 'layers', '0', 'extends', '0'], 2, 17));
        $two = new NamedType('App\Missing', new Provenance($origin->importedThrough(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'two.yaml')), ['architecture', 'layers', '0', 'extends', '0'], 2, 17));
        $evidence = $this->evidence([
            new LayerDefinition('one', new MembershipSpec(extends: [$one->fqn], namedTypes: [$one])),
            new LayerDefinition('two', new MembershipSpec(extends: [$two->fqn], namedTypes: [$two])),
        ]);

        $findings = UnmatchedTypeDiagnostic::forEvidence($evidence, new ProjectScopeJudgement(), new AnalysisContext(new InMemoryMetricRepository()));

        self::assertCount(2, $findings);
        self::assertNotEquals($findings[0]->occurrenceKey, $findings[1]->occurrenceKey);
        self::assertStringContainsString('imported by configuration file "one.yaml"', $findings[0]->message);
        self::assertStringContainsString('imported by configuration file "two.yaml"', $findings[1]->message);
    }

    #[Test]
    public function itAccountsKnownAuthoredTypesBesideMissingTypesAndWithholdsEveryOccurrenceTogether(): void
    {
        $known = self::type('App\Known', ['architecture', 'layers', '0', 'extends', '0']);
        $missing = self::type('App\Missing', ['architecture', 'layers', '0', 'extends', '1']);
        $evidence = $this->evidence([new LayerDefinition('typed', new MembershipSpec(extends: [$known->fqn, $missing->fqn], namedTypes: [$known, $missing]))], [SymbolPath::forClass('App', 'Known')]);
        foreach ([[], [ProjectScopeDoor::Paths]] as $doors) {
            $session = new PopulationSession((new ChannelPublication(new RuleEnablement([
                new EnablementDecision(
                    new SelectionCellAddress(LayerDeclarationRule::NAME, new FindingChannel('architecture.unmatched-type'), SymbolLevel::Project, ChannelSelectionRole::Selectable),
                    new AuthoredCellDecision(CellSwitch::On, CellAdmission::Direct),
                ),
            ], null)))->publishes(...));
            $scope = new ProjectScopeJudgement($doors);
            $context = (new AnalysisContext(new InMemoryMetricRepository(), projectScope: $scope))->withPopulationTrace($session);
            $findings = UnmatchedTypeDiagnostic::forEvidence($evidence, $scope, $context);
            self::assertCount($doors === [] ? 1 : 0, $findings);
            self::assertSame($doors === [] ? 2 : 0, $session->freeze()->judgedCount());
            self::assertSame($doors === [] ? 0 : 2, $session->freeze()->unjudgedCount());
        }
    }

    /** @param list<string> $path */
    private static function type(string $fqn, array $path): NamedType
    {
        return new NamedType($fqn, new Provenance(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'), $path, 0, 17));
    }

    /**
     * @param list<LayerDefinition> $definitions
     * @param list<SymbolPath> $classes
     * @param list<LayerDefinition|TemplateLayerDefinition>|null $entries
     */
    private function evidence(array $definitions, array $classes = [], bool $install = true, ?array $entries = null): LayerEvidence
    {
        $registry = new LayerRegistry($definitions);
        $registry->contextFactory()->bindGraph(self::createStub(DependencyGraphInterface::class), $classes, $install ? static fn(string $fqn): bool => false : null);

        return new LayerEvidence(
            new ArchitectureConfiguration($registry, new LayerPolicy([]), CoverageMode::Ignore, $entries),
            [],
            [],
            ['matched' => [], 'excluded' => [], 'unanswered' => [], 'undecided' => [], 'contended' => [], 'ownsIfExcluded' => []],
            [],
            ['classes' => [], 'analysed' => 0],
            ['sourceEdges' => 0, 'targetEdges' => 0, 'classes' => [], 'undecidable' => [], 'undecidableOutsidePaths' => [], 'doubted' => [], 'doubtedOutsidePaths' => []],
            [],
            [],
            [],
        );
    }
}
