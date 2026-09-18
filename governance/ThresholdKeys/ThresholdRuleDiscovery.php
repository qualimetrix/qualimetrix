<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ThresholdKeys;

use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionValueForm;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleThresholdKeyGroupRegistry;
use ReflectionClass;
use ReflectionClassConstant;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * How the two {@see RuleThresholdKeyGroupRegistry} controls in this group find
 * what they are judging.
 *
 * {@see RuleThresholdKeyGroupRegistryCompletenessTest} and
 * {@see RuleThresholdKeyGroupRegistryDriftTest} ask different questions of the
 * registry, and each carried its own copy of the same discovery: scan `src/`
 * for `*Rule.php`, map each rule name to its Options class, locate the source
 * file behind every level of a hierarchical Options class, and read the
 * private `GROUPS` constant. Two copies meant the scan ran twice per process
 * and had two places to keep true.
 *
 * The scans are cached per process, which is the point: the second control now
 * reads what the first already measured.
 */
final class ThresholdRuleDiscovery
{
    /**
     * Scans the whole of `src/` for `*Rule.php` files and derives each one's
     * FQN mechanically from its path via the project's PSR-4 root
     * (`Qualimetrix\` => `src/`) — never from a hand-typed list of "the
     * directories rules live in", which can omit a real one silently.
     *
     * @return list<class-string<RuleDefinitionInterface>>
     */
    public static function ruleClasses(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $classes = [];
        $finder = (new Finder())->files()->in(self::srcDir())->name('*Rule.php');

        foreach ($finder as $file) {
            $class = self::classFromSourcePath(self::realOrPathname($file));

            if (!class_exists($class) || !is_a($class, RuleInterface::class, true)) {
                continue;
            }

            // Matches the service-registration Abstract*.php exclusion,
            // generalized via reflection so nested abstract bases are
            // skipped without a second name catalog.
            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            /** @var class-string<RuleInterface> $class */
            $classes[] = $class;
        }

        return $cache = $classes;
    }

    /**
     * @return array<string, class-string>
     */
    public static function ruleNameToOptionsClass(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $map = [];

        foreach (self::ruleClasses() as $ruleClass) {
            $map[RuleNameReader::read($ruleClass)] = $ruleClass::getOptionsClass();
        }

        return $cache = $map;
    }

    /**
     * @param class-string $optionsClass
     *
     * @return array<string, string> path => absolute source file path
     */
    public static function sourceFilesByPath(string $optionsClass): array
    {
        $paths = ['' => self::fileNameOf($optionsClass)];

        if (!is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true)) {
            return $paths;
        }

        /** @var HierarchicalRuleOptionsInterface $instance */
        $instance = new $optionsClass();

        foreach ($instance->getSupportedLevels() as $level) {
            $levelObject = $instance->forLevel($level);
            $paths[$level->value] = self::fileNameOf($levelObject::class);
        }

        return $paths;
    }

    /**
     * @param class-string $class
     */
    public static function fileNameOf(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();

        if ($file === false) {
            throw new RuntimeException(\sprintf('Could not locate the source file for %s.', $class));
        }

        return $file;
    }

    /**
     * Read through {@see ReflectionClassConstant} rather than by making the
     * constant public just for a control to see it.
     *
     * @return array<string, array<string, list<array{warning: list<string>, error: list<string>, threshold: list<string>, form: RuleOptionValueForm}>>>
     */
    public static function registeredGroups(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        /** @var array<string, array<string, list<array{warning: list<string>, error: list<string>, threshold: list<string>, form: RuleOptionValueForm}>>> $value */
        $value = new ReflectionClassConstant(RuleThresholdKeyGroupRegistry::class, 'GROUPS')->getValue();

        return $cache = $value;
    }

    /**
     * `SplFileInfo::getRealPath()` returns `false` only when the path cannot
     * be resolved (a dangling symlink, a file removed mid-scan) — never for
     * a real file `Finder` just found, but the return type carries the
     * possibility regardless.
     */
    public static function realOrPathname(SplFileInfo $file): string
    {
        $real = $file->getRealPath();

        return $real !== false ? $real : $file->getPathname();
    }

    /**
     * Derives a class's FQN from its absolute source path under `src/`,
     * using the project's single PSR-4 root (`Qualimetrix\` => `src/`,
     * `composer.json`'s `autoload.psr-4`) — the same mapping Composer's own
     * autoloader uses, not a re-declared copy of it.
     */
    private static function classFromSourcePath(string $absolutePath): string
    {
        $srcDir = self::srcDir();
        $relative = str_starts_with($absolutePath, $srcDir . '/')
            ? substr($absolutePath, \strlen($srcDir) + 1)
            : throw new RuntimeException(\sprintf('%s is not under %s.', $absolutePath, $srcDir));

        return 'Qualimetrix\\' . str_replace('/', '\\', substr($relative, 0, -4));
    }

    public static function srcDir(): string
    {
        return \dirname(__DIR__, 2) . '/src';
    }
}
