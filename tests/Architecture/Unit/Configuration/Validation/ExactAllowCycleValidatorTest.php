<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Configuration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Configuration\Validation\ExactAllowCycleValidator;
use Qualimetrix\Architecture\Domain\Allow\AllowListEntry;
use Qualimetrix\Architecture\Domain\Allow\AllowTarget;
use Qualimetrix\Architecture\Domain\Allow\LayerSelector;
use Qualimetrix\Architecture\Domain\Allow\LayerSelectorParser;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Tests\Architecture\Support\AllowListBuilder;

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
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $exception) {
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

        $this->expectException(ConfigLoadException::class);
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
