<?php

declare(strict_types=1);

/**
 * The population guard of 02 §7, the fifth set of 02 §6 and the input stamp
 * that lets a freshness check cost seconds instead of a re-measurement.
 *
 * The guard answers one question: is every member of a population the code
 * itself knows about carried by the grid? A grid that silently stops covering
 * a producer is the failure mode a denominator exists to prevent, and no
 * amount of green cells inside the grid detects it.
 *
 * Two registries are deliberately NOT consulted —
 * `RuleThresholdKeyGroupRegistry` and `RuleOptionsRegistry` — because the
 * round by axis C is entitled to delete them. The consequence is named rather
 * than hidden: a missing or wrong entry in the group registry is invisible to
 * this guard, and finding it is that round's work.
 */

namespace Qualimetrix\PromiseEffect;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** One member of a population, and what would make the grid cover it. */
final readonly class Member
{
    public function __construct(
        public string $population,
        public string $name,
        public string $source,
    ) {}
}

final class Population
{
    /**
     * Producer names and options classes have two enumerators, and the guard
     * takes their UNION. The container is authoritative — it is what the
     * product actually runs — and the source scan is the one a control can
     * plant into, since no configurator registers a file dropped into a copied
     * tree. Taking the union means a disagreement enlarges the population
     * rather than shrinking it, which is the direction "not knowing yields the
     * worse verdict" points.
     *
     * @param array<string, string> $runtimeRules producer name => options class
     */
    public function __construct(
        private readonly string $root,
        private readonly array $runtimeRules,
    ) {}

    /** @var array<string, string>|null */
    private ?array $sourceFiles = null;

    /** @return list<Member> */
    public function all(): array
    {
        return [
            ...$this->producers(),
            ...$this->optionsClasses(),
            ...$this->configPaths(),
            ...$this->samePairs(),
        ];
    }

    /**
     * Members the grid does not carry.
     *
     * @param list<string> $gridKeys
     *
     * @return list<string>
     */
    public function uncovered(array $gridKeys): array
    {
        $axisA = [];
        $axisD = [];
        $axisB = [];

        foreach ($gridKeys as $key) {
            $parts = explode('|', $key);

            if ($parts[0] === 'pair') {
                // `pair|<rule>|<a>|<b>|<scope>|<kind>`. The member is keyed on
                // the kind too, so a `key-pairs.tsv` row whose kind the grid
                // stopped carrying is uncovered rather than covered by its
                // namesake.
                $axisB[$parts[1] . '|' . $parts[2] . '|' . $parts[3] . '|' . ($parts[5] ?? '')] = true;

                continue;
            }

            if ($parts[0] !== 'form' || !isset($parts[2])) {
                continue;
            }

            if (str_starts_with($parts[2], 'rules.')) {
                $axisA[substr($parts[2], \strlen('rules.'))] = true;

                continue;
            }

            $axisD[$parts[2]] = true;
        }

        $producerCovered = [];

        foreach (array_keys($axisA) as $path) {
            foreach ($this->producerNames() as $producer) {
                if ($path === $producer || str_starts_with($path, $producer . '.')) {
                    $producerCovered[$producer] = true;
                }
            }
        }

        $missing = [];

        foreach ($this->all() as $member) {
            $covered = match ($member->population) {
                'producer' => isset($producerCovered[$member->name]),
                'options-class' => $this->optionsClassCovered($member->name, $producerCovered),
                'config-path' => isset($axisD[$member->name]),
                'same-source-pair' => isset($axisB[$member->name]),
                default => false,
            };

            if (!$covered) {
                $missing[] = $member->population . ' ' . $member->name . ' (' . $member->source . ') is not carried by the grid';
            }
        }

        return $missing;
    }

    /** @return list<string> */
    public function producerNames(): array
    {
        $names = [];

        foreach ($this->producers() as $member) {
            $names[] = $member->name;
        }

        return $names;
    }

    /** @return list<Member> */
    private function producers(): array
    {
        $names = [];

        foreach (array_keys($this->runtimeRules) as $name) {
            $names[$name] = 'the container';
        }

        foreach ($this->sourceFiles() as $file => $text) {
            if (!str_ends_with($file, 'Rule.php')) {
                continue;
            }

            if (preg_match("/const string NAME = '([^']+)'/", $text, $match) !== 1) {
                continue;
            }

            $names[$match[1]] ??= 'a source scan of ' . $file;
        }

        ksort($names);
        $members = [];

        foreach ($names as $name => $source) {
            $members[] = new Member('producer', (string) $name, $source);
        }

        return $members;
    }

    /** @return list<string> */
    public function optionsClassNames(): array
    {
        $names = [];

        foreach ($this->optionsClasses() as $member) {
            $names[] = $member->name;
        }

        return $names;
    }

    /** @return list<Member> */
    private function optionsClasses(): array
    {
        $classes = [];

        foreach ($this->runtimeRules as $class) {
            $classes[$class] = 'the container';
        }

        foreach ($this->sourceFiles() as $file => $text) {
            if (preg_match('/^namespace ([^;]+);/m', $text, $namespace) !== 1) {
                continue;
            }

            // Anchored on the class declaration itself: a looser match walks
            // over a docblock and enrols a factory that merely mentions the
            // interface.
            if (preg_match('/^(?:final |abstract |readonly )*class (\w+)(?: extends [\w\\\\]+)? implements ([^{]+)\{/m', $text, $declaration) !== 1) {
                continue;
            }

            foreach (explode(',', $declaration[2]) as $implemented) {
                if (trim($implemented) !== 'RuleOptionsInterface') {
                    continue;
                }

                $classes[$namespace[1] . '\\' . $declaration[1]] ??= 'a source scan of ' . $file;
            }
        }

        ksort($classes);
        $members = [];

        foreach ($classes as $class => $source) {
            $members[] = new Member('options-class', (string) $class, $source);
        }

        return $members;
    }

    /** @return list<Member> */
    private function configPaths(): array
    {
        $members = [];

        foreach (self::rows($this->root . '/promise-effect/config-paths.tsv') as $row) {
            $members[] = new Member('config-path', $row[0], 'config-paths.tsv');
        }

        return $members;
    }

    /**
     * `key-pairs.tsv` carries no `source_scope` column, though 02 §5 says the
     * mapping is fixed by one. The artifact is the frozen product of stage 01
     * and is not edited here; the mapping lives in
     * `promise-effect/pair-kind-scope.tsv` instead, and
     * {@see self::pairScopeProblems()} holds the ledger to it row by row.
     *
     * The member carries the kind, because the pair cell key does.
     *
     * @return list<Member>
     */
    private function samePairs(): array
    {
        $members = [];

        foreach (self::rows($this->root . '/promise-effect/key-pairs.tsv') as $row) {
            $members[] = new Member('same-source-pair', $row[0] . '|' . $row[3] . '|' . $row[4] . '|' . $row[5], 'key-pairs.tsv');
        }

        return $members;
    }

    /**
     * The declared kind-to-coordinate mapping, checked against the ledger.
     *
     * Before this the coordinate was reconstructed inside the guard from the
     * arithmetic 465 + 108 = 573, and a changed interpretation of which kinds
     * are two-coordinate would have moved the guard's population while the sum
     * still added up. The check here is exact rather than arithmetical: for
     * every `key-pairs.tsv` row the ledger carries one pair row at each scope
     * the table declares for that row's kind, and no pair row exists that no
     * `key-pairs.tsv` row accounts for.
     *
     * @param list<PairRow> $pairs
     * @param array<string, list<string>> $declared pair kind => coordinates
     *
     * @return list<string>
     */
    public function pairScopeProblems(array $pairs, array $declared): array
    {
        $owed = [];

        foreach (self::rows($this->root . '/promise-effect/key-pairs.tsv') as $row) {
            $kind = $row[5];

            if (!isset($declared[$kind])) {
                $owed['?' . $kind] = true;

                continue;
            }

            foreach ($declared[$kind] as $scope) {
                $owed[$row[0] . '|' . $row[3] . '|' . $row[4] . '|' . $kind . '|' . $scope] = true;
            }
        }

        $problems = [];
        $seen = [];

        foreach ($pairs as $pair) {
            $identity = $pair->rule . '|' . $pair->keyA . '|' . $pair->keyB . '|' . $pair->kind . '|' . $pair->sourceScope;
            $seen[$identity] = true;

            if (!isset($owed[$identity])) {
                $problems[] = 'the ledger carries the pair ' . $identity
                    . ', which pair-kind-scope.tsv does not account for';
            }
        }

        foreach (array_keys($owed) as $identity) {
            if (str_starts_with($identity, '?')) {
                $problems[] = 'key-pairs.tsv uses the kind "' . substr($identity, 1)
                    . '", which pair-kind-scope.tsv does not classify';

                continue;
            }

            if (!isset($seen[$identity])) {
                $problems[] = 'pair-kind-scope.tsv owes the ledger a pair row ' . $identity . ', and there is none';
            }
        }

        sort($problems, \SORT_STRING);

        return $problems;
    }

    /** @param array<string, bool> $producerCovered */
    private function optionsClassCovered(string $class, array $producerCovered): bool
    {
        foreach ($this->runtimeRules as $producer => $owned) {
            if ($owned === $class && isset($producerCovered[$producer])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> file path relative to the root => its text */
    private function sourceFiles(): array
    {
        // Held per instance, never statically: a control plants into a copied
        // tree that reuses the same path, and a static cache would answer the
        // next case with the previous case's planting.
        if ($this->sourceFiles !== null) {
            return $this->sourceFiles;
        }

        $files = [];
        $directory = $this->root . '/src';

        if (is_dir($directory)) {
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            ) as $item) {
                if (!$item instanceof SplFileInfo || !$item->isFile() || $item->getExtension() !== 'php') {
                    continue;
                }

                $files[substr($item->getPathname(), \strlen($this->root) + 1)] = (string) file_get_contents($item->getPathname());
            }
        }

        ksort($files);
        $this->sourceFiles = $files;

        return $files;
    }

    /** @return list<list<string>> */
    private static function rows(string $path): array
    {
        $lines = file($path, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . $path);
        }

        $rows = [];
        $header = false;

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!$header) {
                $header = true;

                continue;
            }

            $rows[] = array_pad(explode("\t", $line), 12, '');
        }

        return $rows;
    }
}
