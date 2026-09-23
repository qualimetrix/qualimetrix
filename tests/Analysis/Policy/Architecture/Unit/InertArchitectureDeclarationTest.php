<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\LayersValidator;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\LongFormAllowEntryNormalizer;

/**
 * Three ways a declaration used to be accepted and then do nothing, or do
 * something other than what it says.
 *
 * They share a failure direction rather than a mechanism: each is a written
 * policy that the run widens or drops on its own, so the config file and the
 * verdict disagree while both look healthy. A refusal is the cure in all three
 * because there is no correct silent reading to fall back on — the author asked
 * for something the engine cannot express.
 */
#[CoversClass(LayersValidator::class)]
#[CoversClass(LongFormAllowEntryNormalizer::class)]
#[CoversClass(ArchitectureConfigurationFactory::class)]
final class InertArchitectureDeclarationTest extends TestCase
{
    /**
     * The four non-pattern criterion kinds, enumerated from the code that
     * carries them ({@see \Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec}
     * declares five; `patterns` is the one that takes capture variables, so it
     * is the one that CAN be bound to an instance).
     *
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function provideUnbindableCriterionKinds(): iterable
    {
        yield 'suffix' => ['suffix', ['Repository']];
        yield 'attributes' => ['attributes', ['App\\Attr\\AsEntity']];
        yield 'implements' => ['implements', ['App\\Contract\\Marker']];
        yield 'extends' => ['extends', ['App\\Domain\\AggregateRoot']];
    }

    /** @param list<string> $values */
    #[Test]
    #[DataProvider('provideUnbindableCriterionKinds')]
    public function itRefusesATemplateCriterionItCannotBindToTheInstance(string $kind, array $values): void
    {
        // Accepted before, and every expanded instance then carried the same
        // project-wide net: a class in no module at all was assigned to
        // whichever module sorted first among the binding values.
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('cannot be combined with "match: any" on a template layer');

        (new LayersValidator())->validate([
            ['name' => 'domain-{module}', 'patterns' => ['App\\Module\\{module}\\**'], $kind => $values],
        ]);
    }

    /** @param list<string> $values */
    #[Test]
    #[DataProvider('provideUnbindableCriterionKinds')]
    public function itAcceptsTheSameTemplateCriterionUnderMatchAll(string $kind, array $values): void
    {
        // The control, and the escape the message names: under `all` the
        // criterion narrows the instance inside the scope its own substituted
        // pattern fixes, which is bound after all.
        $entries = (new LayersValidator())->validate([
            ['name' => 'domain-{module}', 'patterns' => ['App\\Module\\{module}\\**'], $kind => $values, 'match' => 'all'],
        ]);

        self::assertCount(1, $entries);
    }

    /** @param list<string> $values */
    #[Test]
    #[DataProvider('provideUnbindableCriterionKinds')]
    public function itLeavesTheSameCriterionAloneOnAStaticLayer(string $kind, array $values): void
    {
        // The second control: a static layer has no instances, so nothing is
        // unbound and `match: any` keeps meaning what it documents.
        $entries = (new LayersValidator())->validate([
            ['name' => 'domain', 'patterns' => ['App\\Module\\**'], $kind => $values],
        ]);

        self::assertCount(1, $entries);
    }

    #[Test]
    public function itRefusesATemplateExcludeNowhereNearThisRule(): void
    {
        // Scope check: `exclude:` is a narrowing clause the author writes once,
        // it already substitutes bindings during observation, and it stays
        // accepted under the default mode. A refusal that swept it in would
        // break a documented shape for no defect.
        $entries = (new LayersValidator())->validate([
            [
                'name' => 'domain-{module}',
                'patterns' => ['App\\Module\\{module}\\**'],
                'exclude' => ['suffix' => ['Proxy']],
            ],
        ]);

        self::assertCount(1, $entries);
    }

    #[Test]
    public function itRefusesARelationsKeyWrittenWithNoValue(): void
    {
        // `relations:` with an empty list, commented-out items or a lost indent
        // is null in YAML, and null used to mean "no filter" — the widest
        // possible reading of a key whose whole purpose is to narrow. Its
        // neighbour `relations: []` was already refused for exactly that,
        // so the two spellings of one slip disagreed, quietly, in the
        // permissive direction.
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must list at least one relation kind');

        LongFormAllowEntryNormalizer::normalize('domain', 0, ['target' => 'vendorlib', 'relations' => null]);
    }

    #[Test]
    public function itKeepsAnAbsentRelationsKeyMeaningAnyRelation(): void
    {
        // The control that keeps the refusal narrow: not writing the key at all
        // is still "any relation allowed", which is what a bare target means.
        [$target, , $relations] = LongFormAllowEntryNormalizer::normalize('domain', 0, ['target' => 'vendorlib']);

        self::assertSame('vendorlib', $target);
        self::assertNull($relations);
    }

    #[Test]
    public function itKeepsADeclaredRelationsListWorking(): void
    {
        [, , $relations] = LongFormAllowEntryNormalizer::normalize('domain', 0, [
            'target' => 'vendorlib',
            'relations' => ['extends'],
        ]);

        self::assertSame([DependencyType::Extends], $relations);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function provideLayerlessSections(): iterable
    {
        yield 'no layers key at all' => [['coverage-gap' => 'error']];
        yield 'layers declared empty' => [['layers' => [], 'coverage-gap' => 'warn']];
    }

    /** @param array<string, mixed> $section */
    #[Test]
    #[DataProvider('provideLayerlessSections')]
    public function itRefusesACoverageModeWithNothingToEnforce(array $section): void
    {
        // Accepted before, and the run then exited 0 on a tree where every
        // single class was outside every layer — the loudest setting of the
        // option producing the quietest possible outcome.
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('requires at least one entry under "architecture.layers"');

        (new ArchitectureConfigurationFactory())->fromArray($section);
    }

    #[Test]
    public function itAcceptsALayerlessSectionThatAsksForNothing(): void
    {
        // The control: `ignore` is the default and declares no policy, so an
        // empty `layers:` beside it is not a contradiction.
        $result = (new ArchitectureConfigurationFactory())->fromArray(['layers' => [], 'coverage-gap' => 'ignore']);

        self::assertTrue($result->configuration->isEmpty());
    }
}
