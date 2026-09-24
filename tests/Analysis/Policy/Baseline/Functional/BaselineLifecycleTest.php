<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Fixtures\BaselineCliFixture;
use Symfony\Component\Console\Command\Command;

/**
 * End-to-end baseline lifecycle checks over repository-shaped projects.
 *
 * Unit tests cover individual ceiling decisions. These cases cross the real
 * CLI, configuration, analysis and persisted baseline seams where a correct
 * local predicate can still measure or address the wrong thing.
 */
final class BaselineLifecycleTest extends TestCase
{
    #[Test]
    public function itRoundTripsARepositoryShapedProjectWithAnIgnoredGroupMember(): void
    {
        $project = BaselineCliFixture::from('dogfood');

        try {
            $paths = [$project->root . '/src'];
            $bare = $project->check($paths);
            self::assertStringContainsString('1 warning', $bare->getDisplay());

            $generated = $project->generate($paths);
            self::assertSame(Command::SUCCESS, $generated->getStatusCode(), $generated->getDisplay());

            $entries = self::entries($project->baselinePath);
            self::assertSame([1], array_column($entries, 'count'));

            $checked = $project->checkWithSeparatedDiagnostics($paths, ['--baseline' => $project->baselinePath]);
            self::assertSame(Command::SUCCESS, $checked->getStatusCode(), $checked->getDisplay());
            self::assertStringContainsString('No violations found', $checked->getDisplay());
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function itKeepsADuplicateAcceptedWhenThePrimaryCopyChanges(): void
    {
        $project = BaselineCliFixture::from('duplication');

        try {
            $paths = [$project->root];
            $generated = $project->generate($paths);
            self::assertSame(Command::SUCCESS, $generated->getStatusCode(), $generated->getDisplay());

            file_put_contents(
                $project->root . '/A.php',
                <<<'PHP'
                <?php

                namespace BaselineFixture\Duplication;

                final class Alpha
                {
                    public function unique(int $value): int
                    {
                        return $value * $value;
                    }
                }
                PHP,
            );

            $checked = $project->checkWithSeparatedDiagnostics($paths, ['--baseline' => $project->baselinePath]);
            self::assertSame(Command::SUCCESS, $checked->getStatusCode(), $checked->getDisplay());
            self::assertStringNotContainsString('baseline entries did not appear in this run', $checked->getDisplay());
            self::assertStringContainsString('No violations found', $checked->getDisplay());
        } finally {
            $project->remove();
        }
    }

    /**
     * The block's identity is its whole matched token sequence, so an edit
     * that lengthens the match in every copy is a new block: the accepted
     * entry goes stale and each copy reports afresh.
     */
    #[Test]
    public function itReKeysADuplicateWhoseMatchedTokensChangeInEveryCopy(): void
    {
        $project = BaselineCliFixture::from('duplication');

        try {
            $paths = [$project->root];
            $generated = $project->generate($paths);
            self::assertSame(Command::SUCCESS, $generated->getStatusCode(), $generated->getDisplay());

            foreach (['A.php', 'B.php', 'C.php'] as $file) {
                $path = $project->root . '/' . $file;
                $source = (string) file_get_contents($path);
                $grown = str_replace(
                    "        return \$fourth * 5;\n",
                    "        \$fifth = \$fourth - 6;\n        return \$fifth * 5;\n",
                    $source,
                );
                self::assertNotSame($source, $grown);
                file_put_contents($path, $grown);
            }

            $checked = $project->checkWithSeparatedDiagnostics($paths, ['--baseline' => $project->baselinePath]);
            self::assertStringContainsString('3 violations (3 warnings)', $checked->getDisplay());
            self::assertStringContainsString('1 baseline entries did not appear in this run', $checked->getErrorOutput());
        } finally {
            $project->remove();
        }
    }

    /**
     * A copy-paste of an accepted block is more of the same debt, and the
     * ceiling has to see it: an accepted block of three copies that gains a
     * fourth is a breach naming the new copy, not an acceptance.
     */
    #[Test]
    public function itReportsANewCopyOfAnAcceptedDuplicateBlock(): void
    {
        $project = BaselineCliFixture::from('duplication');

        try {
            $paths = [$project->root];
            $generated = $project->generate($paths);
            self::assertSame(Command::SUCCESS, $generated->getStatusCode(), $generated->getDisplay());

            file_put_contents(
                $project->root . '/D.php',
                str_replace('class Beta', 'class Delta', (string) file_get_contents($project->root . '/B.php')),
            );

            $checked = $project->checkWithSeparatedDiagnostics($paths, [
                '--baseline' => $project->baselinePath,
                '--format' => 'json',
            ]);
            self::assertSame(2, $checked->getStatusCode(), $checked->getDisplay());

            /** @var array{violations: list<array{rule: string, file: string, severity: string, message: string}>} $report */
            $report = json_decode($checked->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
            $files = array_column($report['violations'], 'file');
            sort($files);
            self::assertSame(['A.php', 'B.php', 'C.php', 'D.php'], array_map('basename', $files));
            self::assertSame(['duplication.clone'], array_values(array_unique(array_column($report['violations'], 'rule'))));
            self::assertSame(['error'], array_values(array_unique(array_column($report['violations'], 'severity'))));
            self::assertStringContainsString('4 occurrences', $report['violations'][0]['message']);
        } finally {
            $project->remove();
        }
    }

    /**
     * A normal repair removes one of two copies. It must not turn a clean
     * baseline run red merely because a group became smaller.
     */
    #[Test]
    public function itKeepsTheBuildGreenAfterDeletingOneOfTwoBaselinedDuplicateBlocks(): void
    {
        $project = BaselineCliFixture::from('duplication-repair');

        try {
            $paths = [$project->root];
            $generated = $project->generate($paths);
            self::assertSame(Command::SUCCESS, $generated->getStatusCode(), $generated->getDisplay());

            $captured = self::aggregateForChannel($project->baselinePath, 'duplication.clone');
            self::assertSame(4, $captured['count']);
            self::assertSame([12, 12, 15, 15], $captured['magnitudes']);

            file_put_contents($project->root . '/Three.php', self::uniqueClass('Three'));

            $currentBaseline = $project->root . '/after-repair.json';
            $measured = $project->generateAt($currentBaseline, $paths);
            self::assertSame(Command::SUCCESS, $measured->getStatusCode(), $measured->getDisplay());
            $survivor = self::aggregateForChannel($currentBaseline, 'duplication.clone');
            self::assertSame(2, $survivor['count']);
            self::assertSame([15, 15], $survivor['magnitudes']);

            $checked = $project->check($paths, ['--baseline' => $project->baselinePath]);
            self::assertSame(Command::SUCCESS, $checked->getStatusCode(), $checked->getDisplay());
            self::assertStringNotContainsString('baseline entries did not appear in this run', $checked->getDisplay());
            self::assertStringContainsString('No violations found', $checked->getDisplay());
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function itListsSeveralManualRepairsAndRemovesOnlyTheirNamedSelectors(): void
    {
        $project = BaselineCliFixture::from('cleanup');

        try {
            $paths = [$project->root];
            $generated = $project->generate($paths);
            self::assertSame(Command::SUCCESS, $generated->getStatusCode(), $generated->getDisplay());

            file_put_contents($project->root . '/First.php', self::repairedClass('First'));
            file_put_contents($project->root . '/Second.php', self::repairedClass('Second'));

            $bytesBeforeListing = (string) file_get_contents($project->baselinePath);
            $before = self::entriesBySubject($project->baselinePath);
            $cleanup = $project->cleanup($paths);
            self::assertSame(Command::SUCCESS, $cleanup->getStatusCode(), $cleanup->getDisplay());
            self::assertSame($bytesBeforeListing, file_get_contents($project->baselinePath));
            self::assertSame($before, self::entriesBySubject($project->baselinePath));
            $firstSelector = self::selectorFor($cleanup->getDisplay(), 'First.php');
            $secondSelector = self::selectorFor($cleanup->getDisplay(), 'Second.php');
            self::assertStringNotContainsString('Kept.php', $cleanup->getDisplay());

            $removed = $project->cleanup($paths, [
                '--remove' => [$firstSelector, $secondSelector],
            ]);
            self::assertSame(Command::SUCCESS, $removed->getStatusCode(), $removed->getDisplay());
            self::assertStringContainsString('Removed 2 entries; 1 remains', $removed->getDisplay());
            self::assertStringContainsString($firstSelector, $removed->getDisplay());
            self::assertStringContainsString($secondSelector, $removed->getDisplay());

            $remaining = self::entriesBySubject($project->baselinePath);
            self::assertCount(1, $remaining);
            self::assertStringContainsString('/Kept.php', (string) array_key_first($remaining));
        } finally {
            $project->remove();
        }
    }

    /**
     * A selector narrowed to one level leaves the rule running everywhere
     * else, and the entry's own level decides whether its absence was
     * measured. The deleted class is the legitimate neighbour: its class-level
     * entries were measured and are gone, and must still read so.
     */
    #[Test]
    public function itSaysAnEntryWasNotMeasuredWhenASelectorSwitchedItsLevelOff(): void
    {
        $project = BaselineCliFixture::from('level-narrowed');

        try {
            $paths = [$project->root];
            $generated = $project->generate($paths);
            self::assertSame(Command::SUCCESS, $generated->getStatusCode(), $generated->getDisplay());

            self::removeTree($project->root . '/Gone');

            $cleanup = $project->cleanup($paths, ['--disable-rule' => ['health.maintainability:namespace']]);
            self::assertSame(Command::SUCCESS, $cleanup->getStatusCode(), $cleanup->getDisplay());

            self::assertSame([
                'declaration:callable:LevelFixture\Gone\Third::pick@Gone/Third.php complexity.ccn' => self::NOTHING_REPORTED,
                'declaration:class:LevelFixture\Gone\Third@Gone/Third.php complexity.ccn' => self::NOTHING_REPORTED,
                'declaration:class:LevelFixture\Gone\Third@Gone/Third.php health.maintainability' => self::NOTHING_REPORTED,
                'ns:LevelFixture health.maintainability' => self::NOT_MEASURED,
                'ns:LevelFixture\Gone health.maintainability' => self::NOT_MEASURED,
                'ns:LevelFixture\Kept health.maintainability' => self::NOT_MEASURED,
            ], self::candidateReasons($cleanup->getDisplay()));

            $projectNarrowed = $project->cleanup($paths, ['--disable-rule' => ['health.maintainability:project']]);
            self::assertSame(Command::SUCCESS, $projectNarrowed->getStatusCode(), $projectNarrowed->getDisplay());

            self::assertSame([
                'declaration:callable:LevelFixture\Gone\Third::pick@Gone/Third.php complexity.ccn' => self::NOTHING_REPORTED,
                'declaration:class:LevelFixture\Gone\Third@Gone/Third.php complexity.ccn' => self::NOTHING_REPORTED,
                'declaration:class:LevelFixture\Gone\Third@Gone/Third.php health.maintainability' => self::NOTHING_REPORTED,
                'ns:LevelFixture\Gone health.maintainability' => self::NOTHING_REPORTED,
                'project: health.maintainability' => self::NOT_MEASURED,
            ], self::candidateReasons($projectNarrowed->getDisplay()));
        } finally {
            $project->remove();
        }
    }

    /**
     * The same question when configuration, not a selector, switched the
     * level off: `class: { enabled: false }` leaves the callable level
     * running and still reporting.
     */
    #[Test]
    public function itSaysAnEntryWasNotMeasuredWhenConfigurationSwitchedItsLevelOff(): void
    {
        $project = BaselineCliFixture::from('level-narrowed');

        try {
            $paths = [$project->root];
            $generated = $project->generate($paths);
            self::assertSame(Command::SUCCESS, $generated->getStatusCode(), $generated->getDisplay());

            $config = $project->root . '/qmx.yaml';
            file_put_contents($config, str_replace(
                "      max_error: 99\n",
                "      max_error: 99\n      enabled: false\n",
                (string) file_get_contents($config),
            ));

            $cleanup = $project->cleanup($paths);
            self::assertSame(Command::SUCCESS, $cleanup->getStatusCode(), $cleanup->getDisplay());

            self::assertSame([
                'declaration:class:LevelFixture\Gone\Third@Gone/Third.php complexity.ccn' => self::NOT_MEASURED,
                'declaration:class:LevelFixture\Kept\First@Kept/First.php complexity.ccn' => self::NOT_MEASURED,
                'declaration:class:LevelFixture\Kept\Second@Kept/Second.php complexity.ccn' => self::NOT_MEASURED,
            ], self::candidateReasons($cleanup->getDisplay()));
        } finally {
            $project->remove();
        }
    }

    private const string NOTHING_REPORTED = 'nothing reported for this identity';
    private const string NOT_MEASURED = 'not measured: this invocation did not run the rule for this channel at this level';

    /**
     * Each listed candidate's description mapped to the reason it is listed
     * under, in listing order. A declaration subject names its file relative
     * to the working directory, so the temporary root is cut off it.
     *
     * @return array<string, string>
     */
    private static function candidateReasons(string $display): array
    {
        preg_match_all('~^\s+[0-9a-f]{12}\s+(.+?)\s+\((.+)\)$~m', $display, $matches, \PREG_SET_ORDER);

        $reasons = [];
        foreach ($matches as $match) {
            $reasons[(string) preg_replace('~@\S*?/((?:Gone|Kept)/)~', '@$1', $match[1])] = $match[2];
        }

        return $reasons;
    }

    private static function removeTree(string $directory): void
    {
        foreach ((array) glob($directory . '/*') as $file) {
            unlink((string) $file);
        }

        rmdir($directory);
    }

    /**
     * @return list<array{count?: int, channel: string, magnitudes?: list<int|float>}>
     */
    private static function entries(string $baselinePath): array
    {
        /** @var array{entries: array<string, list<array{count?: int, channel: string, magnitudes?: list<int|float>}>>} $baseline */
        $baseline = json_decode((string) file_get_contents($baselinePath), true, flags: \JSON_THROW_ON_ERROR);

        return array_merge(...array_values($baseline['entries']));
    }

    /**
     * @return array<string, array{count: int, channel: string}>
     */
    private static function entriesBySubject(string $baselinePath): array
    {
        /** @var array<string, array{count: int, channel: string}> $bySubject */
        $bySubject = [];

        /** @var array{entries: array<string, list<array{count: int, channel: string}>>} $baseline */
        $baseline = json_decode((string) file_get_contents($baselinePath), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($baseline['entries'] as $subject => $entries) {
            $bySubject[$subject] = $entries[0];
        }

        ksort($bySubject);

        return $bySubject;
    }

    private static function selectorFor(string $display, string $file): string
    {
        preg_match('~([0-9a-f]{12}).*' . preg_quote($file, '~') . '~', $display, $matches);

        self::assertArrayHasKey(1, $matches, $display);

        return $matches[1];
    }

    /**
     * @return array{count: int, channel: string, magnitudes: list<int|float>}
     */
    private static function aggregateForChannel(string $baselinePath, string $channel): array
    {
        $count = 0;
        $magnitudes = [];

        foreach (self::entries($baselinePath) as $entry) {
            if ($entry['channel'] === $channel && isset($entry['magnitudes'])) {
                // A magnitude-shaped entry does not write "count" — its
                // count is the length of the list it already carries.
                $count += \count($entry['magnitudes']);
                $magnitudes = [...$magnitudes, ...$entry['magnitudes']];
            }
        }

        if ($magnitudes === []) {
            self::fail("No captured entry for {$channel}.");
        }

        sort($magnitudes, \SORT_NUMERIC);

        return ['count' => $count, 'channel' => $channel, 'magnitudes' => $magnitudes];
    }

    private static function uniqueClass(string $class): string
    {
        return str_replace('{{class}}', $class, <<<'PHP'
            <?php

            namespace BaselineFixture\DuplicationRepair;

            final class {{class}}
            {
                public function unique(int $value): int
                {
                    return $value * $value;
                }
            }
            PHP);
    }

    private static function repairedClass(string $class): string
    {
        return <<<PHP
            <?php

            namespace BaselineFixture\\Cleanup;

            final class {$class}
            {
                public function execute(): void
                {
                }
            }
            PHP;
    }
}
