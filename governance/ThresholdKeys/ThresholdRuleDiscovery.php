<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ThresholdKeys;

use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionValueForm;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/** Discovers real options readers and their declared threshold bands for the two independent controls. */
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

        foreach ($optionsClass::levelOptionsClasses() as $slot => $levelClass) {
            $paths[$slot] = self::fileNameOf($levelClass);
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
     * Derives bands through the declaration authority without constructing options.
     *
     * @return array<string, array<string, list<array{warning: list<string>, error: list<string>, threshold: list<string>, form: RuleOptionValueForm}>>>
     */
    public static function registeredGroups(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $groups = [];
        foreach (self::ruleNameToOptionsClass() as $producer => $rootClass) {
            if (!is_a($rootClass, RuleOptionsInterface::class, true)) {
                throw new RuntimeException('A registered producer has no rule options contract.');
            }
            $classes = ['' => $rootClass];
            if (is_a($rootClass, HierarchicalRuleOptionsInterface::class, true)) {
                $classes += $rootClass::levelOptionsClasses();
            }
            foreach ($classes as $path => $class) {
                if (!is_a($class, RuleOptionsInterface::class, true) && !is_a($class, LevelOptionsInterface::class, true)) {
                    throw new RuntimeException('A declared level has no options contract.');
                }
                $set = RuleOptionSurface::declaredFor($class);
                foreach ($set->bands() as $band) {
                    $shape = $set->shapeOf(\Qualimetrix\Analysis\Configuration\ConfigKeySpelling::normalize($band->shorthand));
                    if ($shape === null) {
                        throw new RuntimeException('A band shorthand has no numeric form.');
                    }
                    $groups[$producer][$path][] = [
                        'warning' => \Qualimetrix\Analysis\Configuration\ConfigKeySpelling::acceptedSpellings($band->warning),
                        'error' => \Qualimetrix\Analysis\Configuration\ConfigKeySpelling::acceptedSpellings($band->error),
                        'threshold' => \Qualimetrix\Analysis\Configuration\ConfigKeySpelling::acceptedSpellings($band->shorthand),
                        'form' => $shape->matches(1.5) ? RuleOptionValueForm::Number : RuleOptionValueForm::WholeNumber,
                    ];
                }
            }
        }
        return $cache = $groups;
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
