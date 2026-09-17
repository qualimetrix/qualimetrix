<?php

declare(strict_types=1);

/**
 * The two option-key populations behind the rule-option recognition subject, as TSV.
 *
 * Table A — per rule Options class: which level slots it accepts and which keys
 * are allowed *inside* each slot. That inner set is what each slot's level
 * options class declares for itself and `RuleOptionsFactory` compares against.
 *
 * Table B — per rule Options class: the key set DECLARED to the product
 * (`acceptedOptionKeys()`, the class's own single statement), the key set
 * actually READ by `fromArray()`, and the two differences between them.
 *
 * Table C — what this script could not reduce to a literal key, per class. It is
 * printed rather than reasoned about afterwards: a blind spot counted by hand is
 * a blind spot.
 *
 * The declared side comes from the real container's rule registry plus each
 * class's own declaration, never a hand-typed list. The read side cannot come from
 * reflection at all — it lives inside a method body — so it comes from the AST.
 *
 * The read side is produced by {@see FromArrayKeyReader}, which lives with the
 * guard that shares it — `tests/Analysis/Finding/Support/` —
 * and reaches this script through composer's `autoload-dev`.
 *
 * Usage: php scripts/enumerate-rule-option-keys.php [--out-dir=DIR]
 */

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Tests\Analysis\Finding\Support\FromArrayKeyReader;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Prints Table A, Table B and the blind-spot table.
 */
final class RuleOptionKeyEnumeration
{
    /** Keys the factory strips before `fromArray()` ever sees them. */
    private const array FRAMEWORK_KEYS = ['suppressNamespaces', 'suppressNamespaceChannels', 'suppressPaths'];

    /**
     * @param list<string> $arguments
     */
    public static function main(array $arguments): int
    {
        $outDir = null;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--out-dir=')) {
                $outDir = substr($argument, strlen('--out-dir='));
            }
        }

        $enumeration = new self();
        $optionsClasses = $enumeration->optionsClassesFromContainer();
        $independent = $enumeration->optionsClassesFromSource();

        $reader = new FromArrayKeyReader();

        $tableA = [implode("\t", [
            'options_class', 'rules', 'hierarchical', 'level_slot_keys',
            'keys_allowed_inside_each_slot', 'slot_key_set_defined_at', 'slot_names_defined_at',
        ])];
        $tableB = [implode("\t", [
            'options_class', 'rules', 'declared', 'declared_from',
            'read_unguarded', 'read_branch_guarded', 'read_not_declared', 'declared_not_read',
        ])];
        $tableC = [implode("\t", ['options_class', 'blind_spot', 'sites', 'where'])];
        $tableD = [implode("\t", [
            'level_class', 'owning_options_class', 'slot', 'declared', 'declared_from',
            'read_unguarded', 'read_branch_guarded', 'read_not_declared', 'declared_not_read',
            'blind_spots',
        ])];
        $classesPerBlindSpot = [];
        $classesWithGuardedOnlyKeys = 0;
        $filesWithSeveralClasses = 0;

        foreach ($optionsClasses as $optionsClass => $rules) {
            if (!is_a($optionsClass, RuleOptionsInterface::class, true)) {
                continue;
            }

            $reading = $reader->read($optionsClass);
            $read = array_keys($reading->keys);
            sort($read);
            $declared = $enumeration->declaredKeys($optionsClass, $read);
            $unguarded = array_keys(array_filter($reading->keys));
            sort($unguarded);
            $guardedOnly = array_values(array_diff($read, $unguarded));
            if ($guardedOnly !== []) {
                ++$classesWithGuardedOnlyKeys;
            }
            if ($reader->classDeclarationsInFileOf($optionsClass) > 1) {
                ++$filesWithSeveralClasses;
            }

            $declaredNames = array_keys($declared);
            sort($declaredNames);

            $tableB[] = implode("\t", [
                $optionsClass,
                implode(',', $rules),
                self::set($declaredNames),
                self::set(array_map(static fn(string $key): string => $key . ':' . $declared[$key], $declaredNames)),
                self::set($unguarded),
                self::set($guardedOnly),
                self::set(array_values(array_diff($read, $declaredNames))),
                self::set(array_values(array_diff($declaredNames, $read))),
            ]);

            $tableA[] = $enumeration->rowA($optionsClass, $rules, $reader);
            foreach ($enumeration->rowsD($optionsClass, $reader) as $levelRow) {
                $tableD[] = $levelRow;
            }

            foreach ($reading->unresolved as $kind => $count) {
                if ($count === 0) {
                    continue;
                }
                $where = array_values(array_filter(
                    $reading->unresolvedDetail,
                    static fn(string $detail): bool => str_starts_with($detail, $kind . '@'),
                ));
                $classesPerBlindSpot[$kind] = ($classesPerBlindSpot[$kind] ?? 0) + 1;
                $tableC[] = implode("\t", [$optionsClass, $kind, (string) $count, implode('; ', $where)]);
            }
        }

        $sections = [
            'table-a.tsv' => $tableA,
            'table-b.tsv' => $tableB,
            'blind-spots.tsv' => $tableC,
            'level-declared-vs-read.tsv' => $tableD,
        ];

        foreach ($sections as $name => $rows) {
            if ($outDir !== null) {
                file_put_contents(rtrim($outDir, '/') . '/' . $name, implode("\n", $rows) . "\n");
            }
            echo '## ', $name, "\n", implode("\n", $rows), "\n\n";
        }

        $fromContainer = array_keys($optionsClasses);
        sort($fromContainer);
        sort($independent);

        echo '## counts', "\n";
        echo 'options_classes_via_container', "\t", count($fromContainer), "\n";
        echo 'options_classes_via_source_scan', "\t", count($independent), "\n";
        echo 'in_source_scan_only', "\t", self::set(array_values(array_diff($independent, $fromContainer))), "\n";
        echo 'in_container_only', "\t", self::set(array_values(array_diff($fromContainer, $independent))), "\n";
        echo "\n## blind-spot reach (classes affected, out of ", count($fromContainer), ")\n";
        foreach (['dynamic-key', 'opaque-sink', 'spread', 'iteration', 'nested-delegation'] as $kind) {
            echo $kind, "\t", $classesPerBlindSpot[$kind] ?? 0, "\n";
        }
        echo 'branch-guarded-only-keys', "\t", $classesWithGuardedOnlyKeys, "\n";
        echo 'files-holding-more-than-one-class', "\t", $filesWithSeveralClasses, "\n";

        return 0;
    }

    /**
     * The same declared-versus-read question as table B, asked of the level
     * classes table B never reaches: a hierarchical wrapper hands its slot's
     * sub-array to a level class, and that class — not the wrapper — decides
     * which keys are legal inside the slot.
     *
     * @param class-string<RuleOptionsInterface> $optionsClass
     *
     * @return list<string>
     */
    private function rowsD(string $optionsClass, FromArrayKeyReader $reader): array
    {
        $options = $optionsClass::fromArray([]);
        if (!$options instanceof HierarchicalRuleOptionsInterface) {
            return [];
        }

        $rows = [];
        foreach ($options->getSupportedLevels() as $level) {
            $levelClass = $options->forLevel($level)::class;
            $reading = $reader->read($levelClass);
            $read = array_keys($reading->keys);
            sort($read);
            $declared = $this->declaredKeys($levelClass, $read);
            $unguarded = array_keys(array_filter($reading->keys));
            sort($unguarded);
            $guardedOnly = array_values(array_diff($read, $unguarded));
            $declaredNames = array_keys($declared);
            sort($declaredNames);

            $blind = [];
            foreach ($reading->unresolved as $kind => $count) {
                if ($count > 0) {
                    $blind[] = $kind . ':' . $count;
                }
            }

            $rows[] = implode("\t", [
                $levelClass,
                $optionsClass,
                $level->value,
                self::set($declaredNames),
                self::set(array_map(static fn(string $key): string => $key . ':' . $declared[$key], $declaredNames)),
                self::set($unguarded),
                self::set($guardedOnly),
                self::set(array_values(array_diff($read, $declaredNames))),
                self::set(array_values(array_diff($declaredNames, $read))),
                self::set($blind),
            ]);
        }

        return $rows;
    }

    /**
     * @param class-string<RuleOptionsInterface> $optionsClass
     * @param list<string> $rules
     */
    private function rowA(string $optionsClass, array $rules, FromArrayKeyReader $reader): string
    {
        $options = $optionsClass::fromArray([]);

        if (!$options instanceof HierarchicalRuleOptionsInterface) {
            return implode("\t", [$optionsClass, implode(',', $rules), 'no', '-', '-', '-', '-']);
        }

        $slots = [];
        $sets = [];
        $sources = [];

        foreach ($options->getSupportedLevels() as $level) {
            $levelOptions = $options->forLevel($level);
            $levelClass = $levelOptions::class;
            $reading = $reader->read($levelClass);

            $keys = array_values(array_diff(array_keys($reading->keys), self::FRAMEWORK_KEYS));
            sort($keys);

            $slots[] = $level->value;
            $sets[] = $level->value . '={' . implode(',', $keys) . '}';
            $sources[] = $level->value . '=' . self::sourceOf($levelClass);
        }

        return implode("\t", [
            $optionsClass,
            implode(',', $rules),
            'yes',
            implode(',', $slots),
            implode('|', $sets),
            implode('|', $sources),
            self::sourceOf($optionsClass),
        ]);
    }

    /**
     * Declared = what the factory compares a user key against, asked of the
     * class instead of reconstructed from its constructor.
     *
     * Two of the key set's three states are declarations, and only one of them
     * can be listed: `acceptedForDisplay()` enumerates the accepted half, while
     * the answered-by-the-class half is deliberately not a list anyone may
     * write from — so it is recovered here by asking `knows()` about the keys
     * the AST saw being read. A key the class answers for and no longer reads
     * is therefore invisible to this table; that is the contract's shape, not
     * an omission of the walk.
     *
     * @param class-string<RuleOptionsInterface|LevelOptionsInterface> $optionsClass
     * @param list<string> $read keys the AST saw `fromArray()` read
     *
     * @return array<string, string> canonical key => which half declares it
     */
    private function declaredKeys(string $optionsClass, array $read): array
    {
        $keySet = $optionsClass::acceptedOptionKeys();

        $declared = [];
        foreach ($keySet->acceptedForDisplay() as $key) {
            $declared[ConfigKeySpelling::normalize($key)] = 'accepted';
        }

        foreach ($read as $key) {
            $normalized = ConfigKeySpelling::normalize($key);
            if (!isset($declared[$normalized]) && $keySet->knows($normalized)) {
                $declared[$normalized] = 'answered';
            }
        }

        foreach (self::FRAMEWORK_KEYS as $frameworkKey) {
            unset($declared[$frameworkKey]);
        }

        return $declared;
    }

    /**
     * @return array<class-string<RuleOptionsInterface>, list<string>>
     */
    private function optionsClassesFromContainer(): array
    {
        $container = (new ContainerFactory())->create();
        $registry = $container->get(RuleRegistryInterface::class);
        assert($registry instanceof RuleRegistryInterface);

        $classes = [];
        foreach ($registry->getClasses() as $ruleClass) {
            $classes[$ruleClass::getOptionsClass()][] = RuleNameReader::read($ruleClass);
        }

        ksort($classes);

        return $classes;
    }

    /**
     * The independent count: every concrete class under `src/` that implements
     * the interface, found by walking files rather than by asking the container.
     *
     * @return list<string>
     */
    private function optionsClassesFromSource(): array
    {
        $root = dirname(__DIR__) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        $found = [];
        foreach ($files as $file) {
            assert($file instanceof \SplFileInfo);
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            if (
                preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace) !== 1
                || preg_match('/^(?:final\s+)?(?:readonly\s+)?(?:abstract\s+)?class\s+(\w+)/m', $contents, $class) !== 1
            ) {
                continue;
            }

            $fqcn = trim($namespace[1]) . '\\' . $class[1];
            if (!class_exists($fqcn)) {
                continue;
            }

            $reflection = new \ReflectionClass($fqcn);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(RuleOptionsInterface::class)) {
                continue;
            }

            $found[] = $fqcn;
        }

        return array_values(array_unique($found));
    }

    /**
     * @param class-string $class
     */
    private static function sourceOf(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $file = $reflection->getFileName();
        $method = $reflection->hasMethod('fromArray') ? $reflection->getMethod('fromArray') : null;

        if ($file === false || $method === null) {
            return '-';
        }

        $relative = str_replace(dirname(__DIR__) . '/', '', $file);

        return $relative . ':' . $method->getStartLine();
    }

    /**
     * @param list<string> $values
     */
    private static function set(array $values): string
    {
        sort($values);

        return $values === [] ? '-' : implode(',', $values);
    }
}

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];

exit(RuleOptionKeyEnumeration::main($argv));
