<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Configuration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowListEntry;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowTarget;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelector;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelectorParser;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ExactAllowCycleValidator;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\SectionSpot;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\AllowListBuilder;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\ArchitectureDocument;

#[CoversClass(ExactAllowCycleValidator::class)]
final class ExactAllowCycleValidatorTest extends TestCase
{
    private ExactAllowCycleValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ExactAllowCycleValidator();
    }

    #[Test]
    public function itAcceptsEmptyAndAcyclicExactGraphs(): void
    {
        $acyclic = [
            'application' => ['domain', 'persistence'],
            'domain' => ['persistence'],
            'persistence' => [],
        ];

        $this->validator->validate([], ArchitectureDocument::allow(null));
        $this->validator->validate(AllowListBuilder::entriesFromExactMap($acyclic), ArchitectureDocument::allow($acyclic));

        self::addToAssertionCount(1);
    }

    #[Test]
    public function itReportsADeterministicClosedCyclePath(): void
    {
        $cyclic = [
            'persistence' => ['application'],
            'application' => ['domain'],
            'domain' => ['persistence'],
        ];

        try {
            $this->validator->validate(AllowListBuilder::entriesFromExactMap($cyclic), ArchitectureDocument::allow($cyclic));
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $exception) {
            self::assertCount(1, $exception->sources());
            self::assertSame(ConfigurationSource::ConfigFile, $exception->sources()[0]->source());
            self::assertSame(ArchitectureDocument::FILE, $exception->sources()[0]->locator());
            self::assertStringContainsString(
                'application -> domain -> persistence -> application',
                $exception->getMessage(),
            );
        }
    }

    #[Test]
    public function itTreatsDisjointRelationFiltersAsADeclaredCycle(): void
    {
        $entries = [
            new AllowListEntry(
                LayerSelector::exact('application'),
                [new AllowTarget(LayerSelector::exact('domain'), [DependencyType::Extends])],
            ),
            new AllowListEntry(
                LayerSelector::exact('domain'),
                [new AllowTarget(LayerSelector::exact('application'), [DependencyType::StaticCall])],
            ),
        ];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('application -> domain -> application');

        $this->validator->validate($entries, ArchitectureDocument::allow([
            'application' => [['target' => 'domain', 'relations' => ['extends']]],
            'domain' => [['target' => 'application', 'relations' => ['static_call']]],
        ]));
    }

    #[Test]
    public function itNamesEveryLayerThatWroteAnEdgeOfTheCycle(): void
    {
        // A preset allows one direction and the project file the other: the
        // cycle exists only in the merged map, so neither file alone is the
        // place to fix it.
        $document = ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer([
                'layers' => [
                    ['name' => 'application', 'patterns' => ['App\\Application']],
                    ['name' => 'domain', 'patterns' => ['App\\Domain']],
                ],
                'allow' => ['application' => ['domain']],
            ]),
            ArchitectureDocument::fileLayer(['allow' => ['domain' => ['application']]]),
        );
        $allow = SectionSpot::section('architecture', $document->get('architecture'))->child('allow');

        try {
            $this->validator->validate(AllowListBuilder::entriesFromExactMap([
                'application' => ['domain'],
                'domain' => ['application'],
            ]), $allow);
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $exception) {
            self::assertSame(
                [ConfigurationSource::Preset, ConfigurationSource::ConfigFile],
                array_map(static fn($origin) => $origin->source(), $exception->sources()),
            );
        }
    }

    #[Test]
    public function itDoesNotProjectGlobOrCapturedSelectorsAsConcreteNodes(): void
    {
        $entries = [
            new AllowListEntry(
                LayerSelector::exact('application'),
                [new AllowTarget(LayerSelector::glob('domain-*'))],
            ),
            new AllowListEntry(
                LayerSelector::glob('domain-*'),
                [new AllowTarget(LayerSelector::exact('application'))],
            ),
            new AllowListEntry(
                LayerSelectorParser::parse('app-{module}'),
                [new AllowTarget(LayerSelectorParser::parse('domain-{module}'))],
            ),
        ];

        $this->validator->validate($entries, ArchitectureDocument::spot([
            'layers' => [['name' => 'application', 'patterns' => ['App\\Application']]],
            'allow' => [
                'application' => ['domain-*'],
                'domain-*' => ['application'],
                'app-{module}' => ['domain-{module}'],
            ],
        ], 'allow'));

        self::addToAssertionCount(1);
    }
}
