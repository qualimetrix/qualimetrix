<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * No channel code carries a level at all: a level is a coordinate beside the
 * name, read off the subject of each finding.
 *
 * **What an earlier version of this guard got wrong, because it matters.**
 * It counted syntax: `Concat` nodes with a `SymbolLevel` `->value` operand,
 * asserting there was exactly one. Three reviewers found the same hole
 * independently — a second assembly written with `sprintf()`, with string
 * interpolation, or through a local variable passed unnoticed. Widening the
 * count to every `->value` read then measured 90 places, and every one of
 * them was legitimate: a level's string is the key of an aggregation map and
 * a token in exception messages all over Measurement. So "how many places
 * spell a level" is both hard to count and not the question.
 *
 * Ш5c answers it by removing the question. A channel's name and its declared
 * level cannot disagree once the name carries no level — `CboRule`'s
 * historical `$level === Namespace_ ? '.namespace' : '.class'`, which would
 * have labelled a third level `.class`, has nothing left to be wrong about.
 * So this guard asserts the absence: no statically declared code ends in a
 * level, and none carries the level separator either, which would put the
 * pair *into* a name instead of beside it.
 *
 * That closes one side of a triangle. The other two are
 * {@see ChannelLevelDeclarationDriftTest}, which compares the declaration
 * against what the product is observed emitting, and the literal check
 * below, which keeps a level segment from being written out by hand at all.
 * The level vocabulary is read from {@see SymbolLevel::cases()} rather than
 * spelled out here: a sixth level is covered the day it is added.
 *
 * The detector is itself held against a positive example, because a guard
 * asserting an empty set is green when it stops recognising anything.
 */
#[CoversClass(FindingChannel::class)]
final class ChannelLevelAssemblyTopologyTest extends TestCase
{
    #[Test]
    public function noProductionSourceSpellsALevelSuffixAsALiteral(): void
    {
        $suffixes = array_map(static fn(SymbolLevel $level): string => '.' . $level->value, SymbolLevel::cases());
        $offenders = [];

        foreach (self::productionFiles() as $file) {
            $finder = new NodeFinder();
            /** @var list<String_> $strings */
            $strings = $finder->findInstanceOf(self::parse($file), String_::class);

            foreach ($strings as $string) {
                if (\in_array($string->value, $suffixes, true)) {
                    $offenders[] = \sprintf('%s:%d "%s"', self::relative($file), $string->getStartLine(), $string->value);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'A level suffix written as a string literal is a second spelling of ' . SymbolLevel::class
            . '. A level belongs on the finding\'s subject, never in a channel name.',
        );
    }

    #[Test]
    public function noDeclaredChannelCodeCarriesALevel(): void
    {
        $declarations = self::staticDeclarations();
        self::assertNotEmpty($declarations, 'No channel is declared — this guard is measuring nothing.');

        $offenders = [];

        foreach (array_keys($declarations) as $key) {
            if (self::levelSegmentOf($key) !== null || str_contains($key, ChannelLevelSelector::LEVEL_SEPARATOR)) {
                $offenders[] = $key;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'A channel name ending in a level is the level written twice: once in the name and once in the subject'
            . ' every finding on that channel already carries. Declare the level instead, and let the name stand'
            . ' for the judgement alone.',
        );
    }

    /**
     * The detector, against a name that *does* carry a level. Without it the
     * assertion above is green the day `levelSegmentOf()` stops recognising
     * anything — the failure mode of every guard whose subject is an empty
     * set.
     */
    #[Test]
    public function theLevelDetectorRecognisesARetiredLevelBearingName(): void
    {
        self::assertSame(SymbolLevel::Class_, self::levelSegmentOf('coupling.cbo.class'));
        self::assertSame(SymbolLevel::Namespace_, self::levelSegmentOf('coupling.cbo.namespace'));
        self::assertNull(self::levelSegmentOf('coupling.cbo'));
        self::assertNull(self::levelSegmentOf('design.param-type-coverage'));
    }

    /**
     * The {@see SymbolLevel} a channel name carries as its last segment, or
     * `null` when it carries none. A last segment that is not a level (an
     * aspect, as `design.type-coverage.param` used to name before ADR 0030
     * split it into three rules) is not this guard's business.
     *
     * Read off the name itself rather than off what the name is prefixed by: a
     * channel is one name now, so there is no rule half to subtract from it.
     */
    private static function levelSegmentOf(string $channelName): ?SymbolLevel
    {
        $lastDot = strrpos($channelName, '.');

        if ($lastDot === false) {
            return null;
        }

        return SymbolLevel::tryFrom(substr($channelName, $lastDot + 1));
    }

    /**
     * @return array<string, \Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration>
     */
    private static function staticDeclarations(): array
    {
        $registry = (new ContainerFactory())->create()->get(ChannelDeclarationRegistryInterface::class);
        \assert($registry instanceof ChannelDeclarationRegistryInterface);

        return $registry->staticDeclarations();
    }

    /**
     * @return list<string>
     */
    private static function productionFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::sourceRoot(), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<Node>
     */
    private static function parse(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $file));
        }

        return (new ParserFactory())->createForHostVersion()->parse($contents) ?? [];
    }

    private static function sourceRoot(): string
    {
        return \dirname(__DIR__, 4) . '/src';
    }

    private static function relative(string $file): string
    {
        return 'src' . substr($file, \strlen(self::sourceRoot()));
    }
}
