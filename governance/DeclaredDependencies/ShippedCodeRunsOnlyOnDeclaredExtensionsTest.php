<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DeclaredDependencies;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionFunction;

/**
 * Whether the PHP a consumer installs can call into an extension the install
 * is not guaranteed to have.
 *
 * The sibling control asks the same question about composer packages. This one
 * exists because the answer for extensions is reached differently and was
 * missing entirely: `composer.json` declared no `ext-*` at all while the
 * shipped tree called `token_get_all()`, `mb_strlen()`, `ctype_digit()`,
 * `filter_var()` and imported `XMLWriter`. A PHP built without mbstring,
 * ctype, tokenizer, xmlwriter or filter is a conforming PHP -- mbstring is not
 * even built by default -- so each of those is the same defect shape as the
 * undeclared `symfony/process`: code that works here and dies where it ships.
 * `tokenizer` carries duplication detection and LOC counting; `xmlwriter`
 * carries `--format=checkstyle`.
 *
 * Two channels, because an extension enters code two ways:
 *
 * - a global class, reached through `use XMLWriter;` or a bare reference;
 * - a call into an internal function, which no import records at all. This is
 *   the channel `mb_strlen()` travels, and an import sweep cannot see it.
 *
 * PHP itself is asked which extension owns a name, rather than a list being
 * kept here. A list would be one more thing that falls quietly behind the code
 * it describes, and the failure would look like a green control.
 *
 * An extension named under `suggest` is allowed: those are optional by design
 * and reached behind `extension_loaded()`, so requiring them would be wrong.
 * `ext-igbinary` is the live example. Removing that entry from `suggest`
 * without adding it to `require` reddens this control rather than passing
 * silently.
 */
final class ShippedCodeRunsOnlyOnDeclaredExtensionsTest extends TestCase
{
    /**
     * Extensions that cannot be compiled out, so `require` need not name them.
     *
     * Everything else is an extension a conforming PHP may lack.
     */
    private const ALWAYS_COMPILED_IN = ['Core', 'standard', 'SPL', 'pcre', 'date', 'Reflection'];

    #[Test]
    public function itCallsIntoNoExtensionTheInstallDoesNotGuarantee(): void
    {
        $root = self::repositoryRoot();
        $manifest = ShippedTree::manifest($root);

        $required = $manifest['require'] ?? [];
        $suggested = $manifest['suggest'] ?? [];

        self::assertIsArray($required);
        self::assertIsArray($suggested);

        $used = self::extensionsReached($root);

        self::assertNotEmpty(
            $used,
            'No extension use was detected at all, which means the scan is broken rather than the tree clean.',
        );

        $undeclared = [];

        foreach ($used as $extension => $names) {
            if (!isset($required[$extension]) && !isset($suggested[$extension])) {
                $undeclared[$extension] = $names;
            }
        }

        self::assertSame([], $undeclared, self::describe($undeclared));
    }

    #[Test]
    public function itRefusesADeclarationForAnExtensionNothingReaches(): void
    {
        $root = self::repositoryRoot();
        $manifest = ShippedTree::manifest($root);
        $required = $manifest['require'] ?? [];

        self::assertIsArray($required);

        $used = self::extensionsReached($root);
        $stale = [];

        foreach (array_keys($required) as $entry) {
            if (\is_string($entry) && str_starts_with($entry, 'ext-') && !isset($used[$entry])) {
                $stale[] = $entry;
            }
        }

        self::assertSame(
            [],
            $stale,
            'composer.json requires extensions the shipped code never reaches: ' . implode(', ', $stale)
                . \PHP_EOL . 'A requirement nobody needs narrows where this installs for nothing.',
        );
    }

    /**
     * @param array<string, list<string>> $undeclared
     */
    private static function describe(array $undeclared): string
    {
        if ($undeclared === []) {
            return '';
        }

        $lines = [];

        foreach ($undeclared as $extension => $names) {
            $lines[] = '  ' . $extension . ' — reached through ' . implode(', ', $names);
        }

        return 'The shipped code calls into extensions composer.json neither requires nor suggests:' . \PHP_EOL
            . implode(\PHP_EOL, $lines) . \PHP_EOL
            . 'Add each to require, or, if it is optional and guarded by extension_loaded(), to suggest.';
    }

    /**
     * Extension requirements the shipped tree reaches, by `require` entry.
     *
     * @return array<string, list<string>>
     */
    private static function extensionsReached(string $root): array
    {
        $reached = [];

        foreach (ShippedTree::files($root) as $file) {
            $contents = (string) file_get_contents($file);

            foreach (self::internalFunctionsCalled($contents) as $name) {
                $extension = (new ReflectionFunction($name))->getExtensionName();
                self::record($reached, $extension, $name . '()');
            }

            foreach (self::globalClassesNamed($contents) as $class) {
                $extension = (new ReflectionClass($class))->getExtensionName();
                self::record($reached, $extension, $class);
            }
        }

        foreach ($reached as $entry => $names) {
            $unique = array_values(array_unique($names));
            sort($unique);
            $reached[$entry] = $unique;
        }

        ksort($reached);

        return $reached;
    }

    /**
     * @param array<string, list<string>> $reached
     */
    private static function record(array &$reached, string|false $extension, string $name): void
    {
        if ($extension === false || $extension === '' || \in_array($extension, self::ALWAYS_COMPILED_IN, true)) {
            return;
        }

        $reached['ext-' . strtolower($extension)][] = $name;
    }

    /**
     * Names called as functions that PHP reports as internal.
     *
     * Anything preceded by `->`, `::`, `$` or a namespace separator is excluded
     * before PHP is asked, so a method or a project function whose name
     * collides with an internal one cannot enter. Whatever survives that and is
     * still reported internal is a genuine call into PHP's own surface.
     *
     * @return list<string>
     */
    private static function internalFunctionsCalled(string $contents): array
    {
        preg_match_all('/(?<![\$>:\\\\a-zA-Z0-9_])([a-z_][a-z0-9_]*)\s*\(/', $contents, $matches);

        $names = [];

        foreach (array_unique($matches[1]) as $name) {
            if (\function_exists($name) && (new ReflectionFunction($name))->isInternal()) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Global class names the file imports or references, that PHP reports as
     * internal.
     *
     * @return list<class-string>
     */
    private static function globalClassesNamed(string $contents): array
    {
        preg_match_all('/^use\s+([A-Za-z_][A-Za-z0-9_]*)\s*;/m', $contents, $imported);
        preg_match_all('/(?<![a-zA-Z0-9_\\\\])\\\\([A-Z][A-Za-z0-9_]*)\s*(?:::|\(|\$|\s)/', $contents, $qualified);

        $classes = [];

        foreach (array_unique([...$imported[1], ...$qualified[1]]) as $class) {
            if (!class_exists($class) && !interface_exists($class)) {
                continue;
            }

            if ((new ReflectionClass($class))->isInternal()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
