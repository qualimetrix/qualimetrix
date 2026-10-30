<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Loader;

use Closure;
use Generator;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Loader\ImportLinearization;

#[CoversClass(ImportLinearization::class)]
final class ImportLinearizationTest extends TestCase
{
    /** @param array<string, list<string|list<string>>> $graph */
    #[Test]
    #[DataProvider('provideAcceptedGraphs')]
    public function itOrdersTheGraphLowestFirstWithTheRootLast(string $root, array $graph, string $expected): void
    {
        self::assertSame($expected, implode(' ', self::linearize($root, $graph)));
    }

    /** @return Generator<string, array{string, array<string, list<string|list<string>>>, string}> */
    public static function provideAcceptedGraphs(): Generator
    {
        yield 'a root alone' => ['F', ['F' => []], 'F'];
        yield 'two entries' => ['F', ['F' => ['A', 'B'], 'A' => [], 'B' => []], 'A B F'];
        yield 'diamond' => ['F', ['F' => ['A', 'B'], 'A' => ['D'], 'B' => ['D'], 'D' => []], 'D A B F'];
        yield 'a base listed before the file importing it' => ['F', ['F' => ['D', 'A'], 'A' => ['D'], 'D' => []], 'D A F'];
        yield 'uneven chains' => ['F', ['F' => ['A', 'B'], 'A' => ['D'], 'B' => ['C', 'D'], 'C' => [], 'D' => []], 'C D A B F'];
        yield 'a chain' => ['F', ['F' => ['A'], 'A' => ['B'], 'B' => ['C'], 'C' => []], 'C B A F'];
        yield 'a nested file listed last wins over a plain one' => [
            'G',
            ['G' => ['H', 'B'], 'H' => ['Q'], 'B' => ['X', 'Y'], 'Y' => ['Q'], 'Q' => [], 'X' => []],
            'X Q H Y B G',
        ];
        yield 'a group with nothing between its members is in byte order, the later name higher' => ['F', ['F' => [['a', 'b', 'c']], 'a' => [], 'b' => [], 'c' => []], 'a b c F'];
        yield 'a group whose member imports a byte-later member' => ['F', ['F' => [['a', 'b']], 'a' => ['b'], 'b' => []], 'b a F'];
        yield 'a member imported through a derived order among the matches' => [
            'F',
            ['F' => [['a', 'b', 'c']], 'c' => ['b', 'd'], 'd' => ['a'], 'a' => [], 'b' => []],
            'b a d c F',
        ];
        yield 'an explicit entry after a group orders two of its members' => [
            'F',
            ['F' => [['a', 'b'], 'e'], 'e' => ['b', 'a'], 'a' => [], 'b' => []],
            'b a e F',
        ];
        yield 'a file importing the others of its own group' => [
            'root',
            ['root' => [['architecture', 'base', 'team']], 'team' => ['base', 'architecture'], 'base' => [], 'architecture' => []],
            'base architecture team root',
        ];
        yield 'a later group above an earlier one' => [
            'F',
            ['F' => [['b', 'c'], ['a', 'd']], 'a' => [], 'b' => [], 'c' => [], 'd' => []],
            'b c a d F',
        ];
    }

    #[Test]
    public function itKeepsTheLinearizationOfAFileAsASubsequenceOfAnImporterOfIt(): void
    {
        $graph = ['G' => ['H', 'B'], 'H' => ['Q'], 'B' => ['X', 'Y'], 'Y' => ['Q'], 'Q' => [], 'X' => []];

        self::assertSame(['X', 'Q', 'Y', 'B'], self::linearize('B', $graph));
        self::assertSame(['X', 'Q', 'H', 'Y', 'B', 'G'], self::linearize('G', $graph));
    }

    /** @param array<string, list<string|list<string>>> $graph */
    #[Test]
    #[DataProvider('provideRefusedGraphs')]
    public function itRefusesAGraphWhoseMergeHasNoAdmissibleHead(array $graph, string $stuckFile, string $expectedFragment): void
    {
        try {
            self::linearize('F', $graph);
            self::fail('The graph must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('f/' . $stuckFile . '.yaml', $refusal->sources()[0]->locator());
            self::assertStringContainsString($expectedFragment, $refusal->summary());
            self::assertStringContainsString('Reorder the entries or drop one of them', $refusal->summary());
            self::assertSame(['imports'], $refusal->position()?->segments);
        }
    }

    /** @return Generator<string, array{array<string, list<string|list<string>>>, string, string}> */
    public static function provideRefusedGraphs(): Generator
    {
        yield 'a base listed after a file that imports it' => [
            ['F' => ['A', 'D'], 'A' => ['D'], 'D' => []],
            'F',
            '"f/D.yaml" above "f/A.yaml", as "f/F.yaml" writes imports[1] after imports[0]',
        ];
        yield 'an explicit entry above a file of an earlier group that imports it' => [
            ['F' => [['a', 'b'], 'c'], 'a' => ['c'], 'b' => [], 'c' => []],
            'F',
            '"f/c.yaml" above "f/a.yaml", as "f/F.yaml" writes imports[1] after imports[0]',
        ];
        yield 'a pair derived through a third file' => [
            ['F' => ['a', 'b', 'c'], 'c' => ['b', 'd'], 'd' => ['a'], 'a' => [], 'b' => []],
            'F',
            'derived from the merge order of "f/c.yaml" ("f/b.yaml" "f/a.yaml" "f/d.yaml" "f/c.yaml")',
        ];
        yield 'the stuck merge sits in an inner file' => [
            ['F' => ['X'], 'X' => ['A', 'D'], 'A' => ['D'], 'D' => []],
            'X',
            'The imports of "f/X.yaml"',
        ];
    }

    #[Test]
    public function itNamesAConstraintCycleOfThreeFilesMixingWrittenAndDerivedEdges(): void
    {
        // F writes b above a and c above b; g's linearization puts a above c.
        $graph = ['F' => ['a', 'b', 'c', 'g'], 'g' => ['c', 'a'], 'a' => [], 'b' => [], 'c' => []];

        try {
            self::linearize('F', $graph);
            self::fail('The graph must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            $summary = $refusal->summary();
            self::assertSame(3, substr_count($summary, ' above '), $summary);
            foreach (['"f/a.yaml" above "f/c.yaml", derived from the merge order of "f/g.yaml"', '"f/b.yaml" above "f/a.yaml", as "f/F.yaml" writes imports[1] after imports[0]', '"f/c.yaml" above "f/b.yaml", as "f/F.yaml" writes imports[2] after imports[1]'] as $edge) {
                self::assertStringContainsString($edge, $summary);
            }
        }
    }

    #[Test]
    public function itTreatsACycleReachingItAsAProgrammerError(): void
    {
        $this->expectException(LogicException::class);

        self::linearize('F', ['F' => ['A'], 'A' => ['F']]);
    }

    #[Test]
    public function itTreatsAMissingGraphEntryAsAProgrammerError(): void
    {
        $this->expectException(LogicException::class);

        self::linearize('F', ['F' => ['A']]);
    }

    #[Test]
    public function itKeepsTheConstraintsOfEveryAcceptedGraphOfUpToFourFiles(): void
    {
        $accepted = 0;
        $refused = 0;
        foreach (self::graphs(['F', 'a', 'b', 'c']) as $graph) {
            try {
                $order = self::linearize('F', $graph);
            } catch (ConfigurationRefusal) {
                ++$refused;
                continue;
            }
            ++$accepted;

            $position = array_flip($order);
            self::assertCount(\count($order), $position, 'a file is placed once');
            self::assertEqualsCanonicalizing(array_keys(self::reachable('F', $graph)), $order, 'exactly the reachable files are placed');
            foreach (self::reachable('F', $graph) as $file => $_) {
                $previous = null;
                foreach ($graph[$file] as $entry) {
                    self::assertGreaterThan($position[$entry], $position[$file], \sprintf('%s above its entry %s in %s', $file, $entry, json_encode($graph)));
                    if ($previous !== null) {
                        self::assertGreaterThan($position[$previous], $position[$entry], \sprintf('later entry %s above %s in %s', $entry, $previous, json_encode($graph)));
                    }
                    $previous = $entry;
                }

                $own = self::linearize($file, $graph);
                $cursor = 0;
                foreach ($order as $placed) {
                    if (($own[$cursor] ?? null) === $placed) {
                        ++$cursor;
                    }
                }
                self::assertSame(\count($own), $cursor, \sprintf('lin(%s) is a subsequence of lin(F) in %s', $file, json_encode($graph)));
            }
        }

        self::assertGreaterThan(0, $accepted);
        self::assertGreaterThan(0, $refused);
    }

    #[Test]
    public function itNamesHowEachFileOfTheCycleWasReached(): void
    {
        $root = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'f/F.yaml');
        $middle = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'f/m.yaml')->importedThrough($root);
        $origins = [
            'F' => $root,
            'm' => $middle,
            'a' => ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'f/a.yaml')->importedThrough($middle),
            'd' => ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'f/d.yaml')->importedThrough($root),
        ];

        try {
            self::linearize('F', ['F' => ['m', 'd'], 'm' => ['a', 'd'], 'a' => ['d'], 'd' => []], static fn(string $key): ConfigurationOrigin => $origins[$key]);
            self::fail('The graph must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            $summary = $refusal->summary();
            self::assertStringContainsString('configuration file "f/a.yaml" (imported by configuration file "f/m.yaml" (imported by configuration file "f/F.yaml"))', $summary);
            self::assertStringContainsString('configuration file "f/d.yaml" (imported by configuration file "f/F.yaml")', $summary);
        }
    }

    #[Test]
    public function itKeepsTheConstraintsOfEveryGroupedGraphOfUpToFourFiles(): void
    {
        $grouped = 0;
        $refused = 0;
        foreach (self::graphs(['F', 'a', 'b', 'c']) as $plain) {
            foreach (self::groupings($plain) as $graph) {
                $singletons = self::allSingletons($graph);
                try {
                    $order = self::linearize('F', $graph);
                } catch (ConfigurationRefusal) {
                    ++$refused;
                    self::assertFalse($singletons && self::standardC3('F', $plain) !== null, 'plain lists are refused exactly as standard C3 refuses them');
                    continue;
                }

                if (!$singletons) {
                    ++$grouped;
                }
                $position = array_flip($order);
                $label = json_encode($graph);
                foreach (self::reachable('F', $plain) as $file => $_) {
                    $previous = [];
                    foreach ($graph[$file] as $entry) {
                        foreach ((array) $entry as $member) {
                            self::assertGreaterThan($position[$member], $position[$file], \sprintf('%s above %s in %s', $file, $member, $label));
                            foreach ($previous as $earlier) {
                                self::assertGreaterThan($position[$earlier], $position[$member], \sprintf('later entry %s above %s in %s', $member, $earlier, $label));
                            }
                        }
                        $previous = (array) $entry;
                    }
                }
                if ($singletons) {
                    self::assertSame(self::standardC3('F', $plain), $order, 'plain lists order exactly as standard C3 does: ' . $label);
                }
            }
        }

        self::assertGreaterThan(0, $grouped);
        self::assertGreaterThan(0, $refused);
    }

    /**
     * Every way to cut each file's entry list into consecutive groups, a group's members in byte order.
     *
     * @param array<string, list<string>> $plain
     *
     * @return Generator<array<string, list<string|list<string>>>>
     */
    private static function groupings(array $plain): Generator
    {
        $files = array_keys($plain);
        $options = [];
        foreach ($files as $file) {
            $options[$file] = [];
            $count = \count($plain[$file]);
            for ($mask = 0; $mask < (1 << max(0, $count - 1)); ++$mask) {
                $entries = [];
                $group = [];
                foreach ($plain[$file] as $index => $member) {
                    $group[] = $member;
                    if ($index === $count - 1 || ($mask >> $index & 1) === 1) {
                        sort($group, \SORT_STRING);
                        $entries[] = \count($group) === 1 ? $group[0] : $group;
                        $group = [];
                    }
                }
                $options[$file][] = $entries;
            }
        }

        $build = static function (int $at, array $chosen) use (&$build, $files, $options): Generator {
            if ($at === \count($files)) {
                yield $chosen;

                return;
            }
            foreach ($options[$files[$at]] as $entries) {
                yield from $build($at + 1, $chosen + [$files[$at] => $entries]);
            }
        };

        yield from $build(0, []);
    }

    /** @param array<string, list<string|list<string>>> $graph */
    private static function allSingletons(array $graph): bool
    {
        foreach ($graph as $entries) {
            foreach ($entries as $entry) {
                if (\is_array($entry)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The textbook C3 over plain lists, lowest first; null when it has no admissible head.
     *
     * @param array<string, list<string>> $graph
     *
     * @return ?list<string>
     */
    private static function standardC3(string $root, array $graph): ?array
    {
        $lin = static function (string $file) use (&$lin, $graph): ?array {
            $bases = array_reverse($graph[$file]);
            $sequences = [];
            foreach ($bases as $base) {
                $sequences[] = $lin($base) ?? throw new LogicException('refused below');
            }
            $sequences[] = $bases;
            $out = [$file];
            while (($sequences = array_values(array_filter($sequences))) !== []) {
                $head = null;
                foreach ($sequences as $sequence) {
                    foreach ($sequences as $other) {
                        if (\in_array($sequence[0], \array_slice($other, 1), true)) {
                            continue 2;
                        }
                    }
                    $head = $sequence[0];

                    break;
                }
                if ($head === null) {
                    return null;
                }
                $out[] = $head;
                $sequences = array_map(static fn(array $s): array => array_values(array_diff($s, [$head])), $sequences);
            }

            return $out;
        };

        try {
            $highestFirst = $lin($root);
        } catch (LogicException) {
            return null;
        }

        return $highestFirst === null ? null : array_reverse($highestFirst);
    }

    /**
     * @param array<string, list<string|list<string>>> $graph an entry is a file, or a group of files in byte order
     * @param ?Closure(string): ConfigurationOrigin $origin
     *
     * @return list<string>
     */
    private static function linearize(string $root, array $graph, ?Closure $origin = null): array
    {
        $groups = array_map(
            static fn(array $entries): array => array_map(static fn(string|array $entry): array => (array) $entry, $entries),
            $graph,
        );

        return ImportLinearization::of(
            $root,
            $groups,
            $origin ?? static fn(string $key): ConfigurationOrigin => ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'f/' . $key . '.yaml'),
            static function (string $importer, string $imported) use ($groups): string {
                foreach ($groups[$importer] as $index => $group) {
                    if (\in_array($imported, $group, true)) {
                        return 'imports[' . $index . ']';
                    }
                }

                throw new LogicException('not imported');
            },
        );
    }

    /**
     * Every graph over the files where a file imports an ordered selection of the files after it in the given order (acyclic by construction).
     *
     * @param list<string> $files
     *
     * @return Generator<array<string, list<string>>>
     */
    private static function graphs(array $files, int $from = 0): Generator
    {
        if ($from === \count($files)) {
            yield [];

            return;
        }

        $later = \array_slice($files, $from + 1);
        foreach (self::orderedSelections($later) as $selection) {
            foreach (self::graphs($files, $from + 1) as $rest) {
                yield [$files[$from] => $selection] + $rest;
            }
        }
    }

    /**
     * @param list<string> $items
     *
     * @return Generator<list<string>>
     */
    private static function orderedSelections(array $items): Generator
    {
        yield [];
        foreach ($items as $index => $item) {
            $rest = $items;
            unset($rest[$index]);
            foreach (self::orderedSelections(array_values($rest)) as $tail) {
                yield [$item, ...$tail];
            }
        }
    }

    /**
     * @param array<string, list<string>> $graph
     *
     * @return array<string, true>
     */
    private static function reachable(string $root, array $graph): array
    {
        $seen = [$root => true];
        foreach ($graph[$root] as $entry) {
            $seen += self::reachable($entry, $graph);
        }

        return $seen;
    }
}
