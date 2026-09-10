<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Formatter\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\Formatter\FormatOptionKeysInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Keeps a `--format-opt` declaration next to the code that reads the key.
 *
 * The declaration lives on the formatter while three of the six keys are read by
 * a renderer one directory below it, so nothing in the type system ties the two
 * together: a renderer can start reading a key, or stop, and the declaration --
 * hence the refusal in FormatterContextFactory -- would drift in silence. This
 * test is that tie, and it is deliberately built from the source text rather
 * than from the declarations, so a key nobody declared still shows up.
 *
 * What the source scan does not see: a key assembled from a variable or a
 * concatenation, a reader outside `src/Reporting/`, and the HTML report's JS
 * template. The first is caught indirectly -- an undeclared literal key is
 * refused at runtime, so a variable key would have to name a declared one
 * anyway; the last two are named remainders.
 *
 * One more assumption, true of all four owners today: a formatter reading keys
 * lives in its own subdirectory. A top-level formatter that started reading a
 * key would fail the orphan check below with a message about ownership rather
 * than about the key, and one declaring a key nothing reads would go unchecked.
 */
#[CoversClass(FormatOptionKeysInterface::class)]
final class FormatOptionKeyDeclarationTest extends TestCase
{
    /** Its `getOption()`/`$options[]` sites take the key as a parameter; it reads no key of its own. */
    private const string ACCESSOR = 'src/Reporting/FormatterContext.php';

    #[Test]
    public function itDeclaresEveryFormatOptionKeyItsOwnFormatterTreeReads(): void
    {
        foreach ($this->readKeysByFormatterDirectory() as $directory => $keys) {
            $declared = $this->declaredKeysOf($directory);

            self::assertSame(
                [],
                array_values(array_diff($keys, $declared)),
                \sprintf('Keys read under src/Reporting/Formatter/%s/ but not declared by its formatter', $directory),
            );
        }
    }

    #[Test]
    public function itDeclaresNoFormatOptionKeyItsOwnFormatterTreeNeverReads(): void
    {
        $readByDirectory = $this->readKeysByFormatterDirectory();

        foreach ($this->formatterDirectories() as $directory) {
            $declared = $this->declaredKeysOf($directory);
            if ($declared === []) {
                continue;
            }

            self::assertSame(
                [],
                array_values(array_diff($declared, $readByDirectory[$directory] ?? [])),
                \sprintf('Keys declared by the formatter in src/Reporting/Formatter/%s/ that nothing there reads', $directory),
            );
        }
    }

    #[Test]
    public function itPlacesEveryFormatOptionReaderUnderAFormatterDirectory(): void
    {
        $orphans = [];
        foreach ($this->readSites() as $relativePath => $keys) {
            if ($this->formatterDirectoryOf($relativePath) === null) {
                $orphans[$relativePath] = $keys;
            }
        }

        self::assertSame(
            [],
            $orphans,
            'A --format-opt key is read outside src/Reporting/Formatter/<Formatter>/, where no formatter owns its declaration',
        );
    }

    /**
     * Keys read per first-level directory under `src/Reporting/Formatter/`.
     *
     * @return array<string, list<string>>
     */
    private function readKeysByFormatterDirectory(): array
    {
        $byDirectory = [];
        foreach ($this->readSites() as $relativePath => $keys) {
            $directory = $this->formatterDirectoryOf($relativePath);
            if ($directory === null) {
                continue;
            }

            foreach ($keys as $key) {
                $byDirectory[$directory][$key] = true;
            }
        }

        $result = [];
        foreach ($byDirectory as $directory => $keys) {
            $names = array_keys($keys);
            sort($names);
            $result[$directory] = $names;
        }

        return $result;
    }

    /**
     * Every `--format-opt` key literal read in `src/Reporting/`, by file.
     *
     * @return array<string, list<string>>
     */
    private function readSites(): array
    {
        $sites = [];
        foreach ($this->sourceFiles() as $relativePath => $absolutePath) {
            if ($relativePath === self::ACCESSOR) {
                continue;
            }

            $source = (string) file_get_contents($absolutePath);
            $keys = [];
            // Both reading shapes the codebase uses; a key built from a variable
            // is invisible to either, and is a named remainder above.
            foreach (["/->getOption\\(\\s*'([^']+)'/", "/->options\\[\\s*'([^']+)'\\s*\\]/"] as $pattern) {
                preg_match_all($pattern, $source, $matches);
                foreach ($matches[1] as $key) {
                    $keys[$key] = true;
                }
            }

            if ($keys !== []) {
                $names = array_keys($keys);
                sort($names);
                $sites[$relativePath] = $names;
            }
        }

        ksort($sites);

        return $sites;
    }

    /** @return list<string> */
    private function declaredKeysOf(string $directory): array
    {
        $keys = [];
        foreach ($this->sourceFiles() as $relativePath => $absolutePath) {
            if ($this->formatterDirectoryOf($relativePath) !== $directory) {
                continue;
            }

            $class = $this->classOf($relativePath);
            if (!class_exists($class) || !is_a($class, FormatOptionKeysInterface::class, true)) {
                continue;
            }

            // The declaration is a constant list; no constructor collaborator takes part in it.
            /** @var FormatOptionKeysInterface $formatter */
            $formatter = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            foreach ($formatter->formatOptionKeys() as $key) {
                $keys[$key] = true;
            }
        }

        $names = array_keys($keys);
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function formatterDirectories(): array
    {
        $directories = [];
        foreach (new FilesystemIterator($this->projectRoot() . '/src/Reporting/Formatter') as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isDir()) {
                $directories[] = $entry->getFilename();
            }
        }

        sort($directories);

        return $directories;
    }

    private function formatterDirectoryOf(string $relativePath): ?string
    {
        $prefix = 'src/Reporting/Formatter/';
        if (!str_starts_with($relativePath, $prefix)) {
            return null;
        }

        $tail = substr($relativePath, \strlen($prefix));
        $slash = strpos($tail, '/');

        return $slash === false ? null : substr($tail, 0, $slash);
    }

    private function classOf(string $relativePath): string
    {
        return 'Qualimetrix\\' . str_replace('/', '\\', substr($relativePath, \strlen('src/'), -\strlen('.php')));
    }

    /**
     * PHP sources under `src/Reporting/`, excluding the HTML report's asset tree.
     *
     * @return array<string, string> relative path => absolute path
     */
    private function sourceFiles(): array
    {
        $root = $this->projectRoot();
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src/Reporting', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), \strlen($root) + 1);
            if (str_contains($relativePath, '/Template/')) {
                continue;
            }

            $files[$relativePath] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }

    private function projectRoot(): string
    {
        return \dirname(__DIR__, 4);
    }
}
