<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Symbol\Unit\TraitComposition;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitCompositionResult;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitDefinitionFacts;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitMethodSource;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitUseFacts;
use Qualimetrix\Core\Symbol\TraitComposition\TraitComposition;
use Qualimetrix\Core\Symbol\TraitComposition\TraitCompositionScope;
use Qualimetrix\Core\Symbol\TraitComposition\TraitMethodPool;

/**
 * @phpstan-type SourceSnapshot array{string, string, string, bool}
 * @phpstan-type CompositionCase array{list<string>, list<TraitUseFacts>, array<string, TraitDefinitionFacts>, array<string, SourceSnapshot>}
 * @phpstan-type ConflictCase array{list<TraitUseFacts>, array<string, TraitDefinitionFacts>, string}
 */
#[CoversClass(TraitComposition::class)]
#[CoversClass(TraitCompositionResult::class)]
#[CoversClass(TraitCompositionScope::class)]
#[CoversClass(TraitDefinitionFacts::class)]
#[CoversClass(TraitMethodPool::class)]
#[CoversClass(TraitMethodSource::class)]
#[CoversClass(TraitUseFacts::class)]
final class TraitCompositionTest extends TestCase
{
    /**
     * @param list<string> $ownMethods
     * @param list<TraitUseFacts> $uses
     * @param array<string, TraitDefinitionFacts> $definitions
     * @param array<string, SourceSnapshot> $expected
     */
    #[Test]
    #[DataProvider('compositions')]
    public function itComposesImportedMethods(array $ownMethods, array $uses, array $definitions, array $expected): void
    {
        $result = TraitComposition::compose($ownMethods, $uses, static fn(string $trait): ?TraitDefinitionFacts => $definitions[ClassNameSpelling::fold($trait)] ?? null);

        self::assertSame($expected, self::snapshot($result));
        self::assertSame([], $result->unread);
    }

    /** @return array<string, CompositionCase> */
    public static function compositions(): array
    {
        $a = new TraitDefinitionFacts(['Run' => new TraitMethodSource('App\\A', 'Run'), 'Keep' => new TraitMethodSource('App\\A', 'Keep', 'private')]);
        $b = new TraitDefinitionFacts(['Run' => new TraitMethodSource('App\\B', 'Run', 'protected')]);
        $definitions = ['app\\a' => $a, 'app\\b' => $b];
        $runA = ['App\\A', 'Run', 'public', false];
        $runB = ['App\\B', 'Run', 'protected', false];
        $keep = ['App\\A', 'Keep', 'private', false];
        $alias = ['trait' => null, 'method' => 'run', 'alias' => 'Another', 'visibility' => null];
        $preferB = ['trait' => 'App\\B', 'method' => 'Run', 'insteadOf' => ['App\\A']];
        $nested = new TraitDefinitionFacts([], [new TraitUseFacts(['App\\A'], [['trait' => 'App\\A', 'method' => 'Run', 'alias' => 'Nested', 'visibility' => 'protected']])]);

        return [
            'imported only and private source' => [['Own'], [new TraitUseFacts(['App\\A'])], $definitions, ['run' => $runA, 'keep' => $keep]],
            'unqualified alias preserves original' => [[], [new TraitUseFacts(['App\\A'], [$alias])], $definitions, ['another' => $runA, 'run' => $runA, 'keep' => $keep]],
            'visibility only leaves alias original visibility' => [[], [new TraitUseFacts(['App\\A'], [
                ['trait' => null, 'method' => 'run', 'alias' => null, 'visibility' => 'protected'], $alias,
            ])], $definitions, ['another' => $runA, 'run' => ['App\\A', 'Run', 'protected', false], 'keep' => $keep]],
            'explicit alias visibility leaves original' => [[], [new TraitUseFacts(['App\\A'], [
                ['trait' => 'App\\A', 'method' => 'run', 'alias' => 'Hidden', 'visibility' => 'private'],
            ])], $definitions, ['hidden' => ['App\\A', 'Run', 'private', false], 'run' => $runA, 'keep' => $keep]],
            'excluded source remains aliasable' => [[], [new TraitUseFacts(['App\\A', 'App\\B'], [
                ['trait' => 'App\\A', 'method' => 'Run', 'alias' => 'Saved', 'visibility' => null],
            ], [$preferB])], $definitions, ['saved' => $runA, 'keep' => $keep, 'run' => $runB]],
            'own method resolves conflict and alias collision' => [['RUN', 'Another'], [new TraitUseFacts(['App\\A', 'App\\B'], [
                ['trait' => 'App\\A', 'method' => 'Run', 'alias' => 'Another', 'visibility' => null],
            ])], $definitions, ['keep' => $keep]],
            'concrete method satisfies abstract peer' => [[], [new TraitUseFacts(['Requirement', 'App\\A'])], $definitions + [
                'requirement' => new TraitDefinitionFacts(['run' => new TraitMethodSource('Requirement', 'run', 'public', true)]),
            ], ['run' => $runA, 'keep' => $keep]],
            'abstract source and alias remain abstract' => [[], [new TraitUseFacts(['Requirement'], [$alias])], [
                'requirement' => new TraitDefinitionFacts(['run' => new TraitMethodSource('Requirement', 'run', 'protected', true)]),
            ], ['another' => ['Requirement', 'run', 'protected', true], 'run' => ['Requirement', 'run', 'protected', true]]],
            'nested alias uses direct qualifier and leaf source' => [[], [new TraitUseFacts(['Outer'], [
                ['trait' => 'Outer', 'method' => 'Nested', 'alias' => 'Last', 'visibility' => 'private'],
            ])], $definitions + ['outer' => $nested], ['last' => ['App\\A', 'Run', 'private', false], 'nested' => ['App\\A', 'Run', 'protected', false], 'run' => $runA, 'keep' => $keep]],
            'nested own abstract method overrides imported concrete' => [[], [new TraitUseFacts(['Outer'])], $definitions + [
                'outer' => new TraitDefinitionFacts(['RUN' => new TraitMethodSource('Outer', 'RUN', 'public', true)], [new TraitUseFacts(['App\\A'])]),
            ], ['keep' => $keep, 'run' => ['Outer', 'RUN', 'public', true]]],
            'diamond reuses one source' => [[], [new TraitUseFacts(['Left', 'Right'])], $definitions + [
                'left' => new TraitDefinitionFacts([], [new TraitUseFacts(['App\\A'])]),
                'right' => new TraitDefinitionFacts([], [new TraitUseFacts(['app\\a'])]),
            ], ['run' => $runA, 'keep' => $keep]],
            'adaptations span separate use statements' => [[], [new TraitUseFacts(['App\\A'], [
                ['trait' => 'App\\B', 'method' => 'Run', 'alias' => 'Saved', 'visibility' => null],
            ], [$preferB]), new TraitUseFacts(['App\\B'])], $definitions, ['saved' => $runB, 'keep' => $keep, 'run' => $runB]],
            'unqualified alias spans separate use statements' => [[], [new TraitUseFacts(['App\\A']), new TraitUseFacts(['App\\B'], [
                ['trait' => null, 'method' => 'Keep', 'alias' => 'Saved', 'visibility' => null],
            ], [$preferB])], $definitions, ['saved' => $keep, 'keep' => $keep, 'run' => $runB]],
            'ascii identity and high bytes remain distinct' => [['keep'], [new TraitUseFacts(['APP\\a'], [
                ['trait' => 'aPP\\A', 'method' => 'rUN', 'alias' => 'NEXT', 'visibility' => null],
            ]), new TraitUseFacts(['Ä', 'ä'])], $definitions + [
                'Ä' => new TraitDefinitionFacts(['Ä' => new TraitMethodSource('Ä', 'Ä')]),
                'ä' => new TraitDefinitionFacts(['ä' => new TraitMethodSource('ä', 'ä')]),
            ], ['next' => $runA, 'run' => $runA, 'Ä' => ['Ä', 'Ä', 'public', false], 'ä' => ['ä', 'ä', 'public', false]]],
        ];
    }

    /**
     * @param list<TraitUseFacts> $uses
     * @param array<string, TraitDefinitionFacts> $definitions
     */
    #[Test]
    #[DataProvider('conflicts')]
    public function itRefusesKnownConflicts(array $uses, array $definitions, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        TraitComposition::compose([], $uses, static fn(string $trait): ?TraitDefinitionFacts => $definitions[ClassNameSpelling::fold($trait)] ?? null);
    }

    /** @return array<string, ConflictCase> */
    public static function conflicts(): array
    {
        $a = new TraitDefinitionFacts(['run' => new TraitMethodSource('A', 'run')]);
        $definitions = ['a' => $a, 'b' => new TraitDefinitionFacts(['run' => new TraitMethodSource('B', 'run')])];
        $alias = ['trait' => 'A', 'method' => 'run', 'alias' => 'RUN', 'visibility' => null];

        return [
            'concrete sources collide' => [[new TraitUseFacts(['A', 'B'])], $definitions, 'Conflicting trait methods for run'],
            'unqualified alias searches original pool after precedence' => [[new TraitUseFacts(['A', 'B'], [
                ['trait' => null, 'method' => 'run', 'alias' => 'Saved', 'visibility' => null],
            ], [['trait' => 'B', 'method' => 'run', 'insteadOf' => ['A']]])], $definitions, 'Ambiguous trait alias for method run'],
            'alias same name collides' => [[new TraitUseFacts(['A'], [$alias])], $definitions, 'Conflicting trait methods for run'],
            'aliases collide even with same source' => [[new TraitUseFacts(['A'], [
                ['trait' => 'A', 'method' => 'run', 'alias' => 'saved', 'visibility' => null],
                ['trait' => 'A', 'method' => 'run', 'alias' => 'saved', 'visibility' => null],
            ])], $definitions, 'Conflicting trait methods for saved'],
            'diamond visibility mismatch collides' => [[new TraitUseFacts(['Left', 'Right'])], $definitions + [
                'left' => new TraitDefinitionFacts([], [new TraitUseFacts(['A'], [['trait' => 'A', 'method' => 'run', 'alias' => null, 'visibility' => 'protected']])]),
                'right' => new TraitDefinitionFacts([], [new TraitUseFacts(['A'])]),
            ], 'Conflicting trait methods for run'],
            'qualifier must be direct trait' => [[new TraitUseFacts(['Outer'], [['trait' => 'A', 'method' => 'run', 'alias' => 'saved', 'visibility' => null]])], $definitions + [
                'outer' => new TraitDefinitionFacts([], [new TraitUseFacts(['A'])]),
            ], 'Adaptation names unused trait a for method run'],
            'known empty differs from unread' => [[new TraitUseFacts(['Empty'], [['trait' => 'Empty', 'method' => 'run', 'alias' => 'saved', 'visibility' => null]])], [
                'empty' => new TraitDefinitionFacts(),
            ], 'Unknown trait method empty::run'],
            'unqualified missing method is invalid when complete' => [[new TraitUseFacts(['A'], [['trait' => null, 'method' => 'missing', 'alias' => 'saved', 'visibility' => null]])], $definitions, 'Unknown trait alias method missing'],
            'precedence names direct excluded trait' => [[new TraitUseFacts(['A'], [], [['trait' => 'A', 'method' => 'run', 'insteadOf' => ['Unused']]])], $definitions, 'Precedence names unused trait Unused for method run'],
        ];
    }

    #[Test]
    public function itPreservesKnownMethodsAcrossUnreadNestedUses(): void
    {
        $definitions = [
            'partial' => new TraitDefinitionFacts(['own' => new TraitMethodSource('Partial', 'own')], [new TraitUseFacts(['Missing', 'Known'])]),
            'known' => new TraitDefinitionFacts(['run' => new TraitMethodSource('Known', 'run')]),
            'empty' => new TraitDefinitionFacts(),
        ];
        $uses = [new TraitUseFacts(['Partial', 'Empty'], [
            ['trait' => 'Partial', 'method' => 'run', 'alias' => 'saved', 'visibility' => 'private'],
            ['trait' => 'Partial', 'method' => 'missing', 'alias' => 'unresolved', 'visibility' => null],
        ])];
        $result = TraitComposition::compose([], $uses, static fn(string $trait): ?TraitDefinitionFacts => $definitions[ClassNameSpelling::fold($trait)] ?? null);

        self::assertSame(['saved' => ['Known', 'run', 'private', false], 'run' => ['Known', 'run', 'public', false], 'own' => ['Partial', 'own', 'public', false]], self::snapshot($result));
        self::assertSame(['Missing'], $result->unread);
    }

    #[Test]
    public function itDoesNotGuessAnUnqualifiedAliasWithUnreadCandidates(): void
    {
        $result = TraitComposition::compose([], [new TraitUseFacts(['Known', 'Missing'], [
            ['trait' => null, 'method' => 'run', 'alias' => 'uncertain', 'visibility' => null],
        ])], static fn(string $trait): ?TraitDefinitionFacts => $trait === 'Known' ? new TraitDefinitionFacts(['run' => new TraitMethodSource('Known', 'run')]) : null);

        self::assertSame(['run' => ['Known', 'run', 'public', false]], self::snapshot($result));
        self::assertSame(['Missing'], $result->unread);
    }

    #[Test]
    public function itMarksOnlyActiveCycleMembersUnreadAndKeepsOuterOwnMethods(): void
    {
        $definitions = [
            'outer' => new TraitDefinitionFacts(['outer' => new TraitMethodSource('Outer', 'outer')], [new TraitUseFacts(['A', 'Known'])]),
            'a' => new TraitDefinitionFacts(['a' => new TraitMethodSource('A', 'a')], [new TraitUseFacts(['B'])]),
            'b' => new TraitDefinitionFacts(['b' => new TraitMethodSource('B', 'b')], [new TraitUseFacts(['a'])]),
            'known' => new TraitDefinitionFacts(['run' => new TraitMethodSource('Known', 'run')]),
        ];
        $result = TraitComposition::compose([], [new TraitUseFacts(['Outer', 'A'])], static fn(string $trait): ?TraitDefinitionFacts => $definitions[ClassNameSpelling::fold($trait)] ?? null);

        self::assertSame(['run' => ['Known', 'run', 'public', false], 'outer' => ['Outer', 'outer', 'public', false]], self::snapshot($result));
        self::assertSame(['A', 'B'], $result->unread);
    }

    #[Test]
    public function itReadsEachFoldedTraitOncePerCompositionWithoutSharingRuns(): void
    {
        $reads = [];
        $callback = static function (string $trait) use (&$reads): TraitDefinitionFacts {
            $reads[] = $trait;

            return match ($trait) {
                'Left', 'Right' => new TraitDefinitionFacts([], [new TraitUseFacts(['Leaf'])]),
                default => new TraitDefinitionFacts(['run' => new TraitMethodSource('Leaf', 'run')]),
            };
        };
        $uses = [new TraitUseFacts(['Left', 'Right', 'LEAF'])];
        $first = TraitComposition::compose([], $uses, $callback);
        $second = TraitComposition::compose(['RUN'], $uses, $callback);

        self::assertSame(['run' => ['Leaf', 'run', 'public', false]], self::snapshot($first));
        self::assertSame([], $second->methods);
        self::assertSame(['Left', 'Leaf', 'Right', 'Left', 'Leaf', 'Right'], $reads);
        self::assertSame([], $first->unread);
        self::assertSame([], $second->unread);
    }

    /** @return array<string, SourceSnapshot> */
    private static function snapshot(TraitCompositionResult $result): array
    {
        return array_map(static fn(TraitMethodSource $source): array => [$source->declaringTrait, $source->originalMethod, $source->visibility, $source->isAbstract], $result->methods);
    }
}
