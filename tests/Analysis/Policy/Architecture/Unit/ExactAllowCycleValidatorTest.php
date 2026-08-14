<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Configuration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowListEntry;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowTarget;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelector;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelectorParser;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ExactAllowCycleValidator;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationException;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\AllowListBuilder;

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
        $this->validator->validate([]);
        $this->validator->validate(AllowListBuilder::entriesFromExactMap([
            'application' => ['domain', 'persistence'],
            'domain' => ['persistence'],
            'persistence' => [],
        ]));

        self::addToAssertionCount(1);
    }

    #[Test]
    public function itReportsADeterministicClosedCyclePath(): void
    {
        try {
            $this->validator->validate(AllowListBuilder::entriesFromExactMap([
                'persistence' => ['application'],
                'application' => ['domain'],
                'domain' => ['persistence'],
            ]));
            self::fail('Expected ArchitectureConfigurationException');
        } catch (ArchitectureConfigurationException $exception) {
            self::assertSame('architecture', $exception->configPath);
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

        $this->expectException(ArchitectureConfigurationException::class);
        $this->expectExceptionMessage('application -> domain -> application');

        $this->validator->validate($entries);
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

        $this->validator->validate($entries);

        self::addToAssertionCount(1);
    }
}
