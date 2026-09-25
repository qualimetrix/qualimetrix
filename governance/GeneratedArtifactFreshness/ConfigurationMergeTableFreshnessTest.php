<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\GeneratedArtifactFreshness;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * The table of how each configuration key combines across layers is generated
 * into the configuration page, English and Russian, from the section
 * declarations the document engine composes against
 * (`scripts/generate-configuration-merge-table.php`).
 *
 * One case checks the tracked pages; the other proves, on a copy, that the
 * check reddens on a hand-edited cell in either language — a freshness check
 * that compares the page with itself would pass both.
 */
final class ConfigurationMergeTableFreshnessTest extends TestCase
{
    private const string GENERATOR = 'scripts/generate-configuration-merge-table.php';

    /** @var list<string> */
    private const array PAGES = [
        'website/docs/getting-started/configuration.md',
        'website/docs/getting-started/configuration.ru.md',
    ];

    private ?string $scratch = null;

    protected function tearDown(): void
    {
        if ($this->scratch !== null) {
            foreach (self::PAGES as $page) {
                @unlink($this->scratch . '/' . $page);
            }

            @rmdir($this->scratch . '/website/docs/getting-started');
            @rmdir($this->scratch . '/website/docs');
            @rmdir($this->scratch . '/website');
            @rmdir($this->scratch);
        }
    }

    // Not in the `live-freshness` group its neighbours use: the generator
    // measures nothing and takes a fraction of a second, so the check runs
    // with every suite as well as in `check:artifacts`.
    #[Test]
    public function itMatchesWhatTheSectionDeclarationsGenerate(): void
    {
        [$exitCode, $output] = $this->generator('--check');

        self::assertSame(0, $exitCode, $output);
    }

    #[Test]
    public function itReddensOnAHandEditedCellInEitherLanguage(): void
    {
        $scratch = $this->scratchCopy();

        [$written, $writeOutput] = $this->generator('--root=' . $scratch);
        self::assertSame(0, $written, $writeOutput);
        [$fresh, $freshOutput] = $this->generator('--check', '--root=' . $scratch);
        self::assertSame(0, $fresh, $freshOutput);

        foreach (self::PAGES as $page) {
            $path = $scratch . '/' . $page;
            $original = file_get_contents($path);
            self::assertIsString($original);

            // The first key cell below the header and separator rows.
            $edited = preg_replace('/(<!-- generated:configuration-merge-table:begin[^\n]*\n\n(?:[^\n]*\n){2}\| `)/', '$1x', $original, 1, $count);
            self::assertSame(1, $count, $page . ' holds no generated row to edit');
            file_put_contents($path, $edited);

            [$stale, $staleOutput] = $this->generator('--check', '--root=' . $scratch);
            self::assertSame(1, $stale, $staleOutput);
            self::assertStringContainsString($page, $staleOutput);

            file_put_contents($path, $original);
        }
    }

    private function scratchCopy(): string
    {
        $this->scratch = sys_get_temp_dir() . '/qmx-merge-table-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch . '/website/docs/getting-started', 0o777, true));

        foreach (self::PAGES as $page) {
            self::assertTrue(copy($this->root() . '/' . $page, $this->scratch . '/' . $page));
        }

        return $this->scratch;
    }

    /** @return array{int, string} */
    private function generator(string ...$arguments): array
    {
        try {
            $result = ChildProcess::run([\PHP_BINARY, $this->root() . '/' . self::GENERATOR, ...array_values($arguments)], $this->root());
        } catch (RuntimeException $exception) {
            self::fail($exception->getMessage());
        }

        return [$result['exitCode'], $result['stdout'] . $result['stderr']];
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../..');
        self::assertIsString($root);

        return $root;
    }
}
