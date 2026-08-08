<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Tests\Integration\BaselineCeiling\BaselineCliFixture;
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
    public function itReportsARekeyedDuplicateAfterTheAlphabeticallyFirstCopyWasRemoved(): void
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
            self::assertStringContainsString('baseline entries did not appear in this run', $checked->getErrorOutput());
            self::assertStringContainsString('1 violation (1 warning)', $checked->getDisplay());
        } finally {
            $project->remove();
        }
    }

    /**
     * Round 3's CRITICAL: a normal repair removes one of two copies. It must
     * not turn a clean baseline run red merely because a group became
     * smaller.
     */
    #[Test]
    public function itKeepsTheBuildGreenAfterDeletingOneOfTwoBaselinedDuplicateBlocks(): void
    {
        $project = BaselineCliFixture::from('duplication-repair');

        try {
            $paths = [$project->root];
            $generated = $project->generate($paths);
            self::assertSame(Command::SUCCESS, $generated->getStatusCode(), $generated->getDisplay());

            $captured = self::entryForChannel($project->baselinePath, 'duplication.code-duplication#duplication.code-duplication');
            self::assertSame(2, $captured['count']);
            self::assertSame([11, 15], $captured['magnitudes']);

            file_put_contents($project->root . '/Three.php', self::uniqueClass('Three'));

            $currentBaseline = $project->root . '/after-repair.json';
            $measured = $project->generateAt($currentBaseline, $paths);
            self::assertSame(Command::SUCCESS, $measured->getStatusCode(), $measured->getDisplay());
            $survivor = self::entryForChannel($currentBaseline, 'duplication.code-duplication#duplication.code-duplication');
            self::assertSame(1, $survivor['count']);
            self::assertSame([15], $survivor['magnitudes']);

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
            $before = self::entriesByFile($project->baselinePath);
            $cleanup = $project->cleanup($paths);
            self::assertSame(Command::SUCCESS, $cleanup->getStatusCode(), $cleanup->getDisplay());
            self::assertSame($bytesBeforeListing, file_get_contents($project->baselinePath));
            self::assertSame($before, self::entriesByFile($project->baselinePath));
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

            self::assertSame(['Kept.php'], array_keys(self::entriesByFile($project->baselinePath)));
        } finally {
            $project->remove();
        }
    }

    /**
     * @return list<array{count: int, channel: string, magnitudes?: list<int|float>}>
     */
    private static function entries(string $baselinePath): array
    {
        /** @var array{entries: array<string, list<array{count: int, channel: string, magnitudes?: list<int|float>}>>} $baseline */
        $baseline = json_decode((string) file_get_contents($baselinePath), true, flags: \JSON_THROW_ON_ERROR);

        return array_merge(...array_values($baseline['entries']));
    }

    /**
     * @return array<string, array{count: int, channel: string}>
     */
    private static function entriesByFile(string $baselinePath): array
    {
        /** @var array<string, array{count: int, channel: string}> $byFile */
        $byFile = [];

        /** @var array{entries: array<string, list<array{count: int, channel: string}>>} $baseline */
        $baseline = json_decode((string) file_get_contents($baselinePath), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($baseline['entries'] as $symbol => $entries) {
            $file = basename($symbol);
            $byFile[$file] = $entries[0];
        }

        ksort($byFile);

        return $byFile;
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
    private static function entryForChannel(string $baselinePath, string $channel): array
    {
        foreach (self::entries($baselinePath) as $entry) {
            if ($entry['channel'] === $channel && isset($entry['magnitudes'])) {
                return [
                    'count' => $entry['count'],
                    'channel' => $entry['channel'],
                    'magnitudes' => $entry['magnitudes'],
                ];
            }
        }

        self::fail("No captured entry for {$channel}.");
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
