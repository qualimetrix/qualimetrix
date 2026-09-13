<?php

declare(strict_types=1);

/**
 * The fifth set of 02 §6: key literals a reading body READS that the
 * declaration behind it does not declare.
 *
 * The four sets of stage 01 reconcile the registry against the declaration.
 * None of them can see a key the code reads but nobody declared: such a key is
 * absent from the declaration and therefore absent from the denominator of
 * axis A as well — a circular denominator, blind exactly where it matters.
 *
 * IT USED TO BE PRINTED AND NOT COUNTED. Two reasons, and both are now gone:
 *
 *   1. 117 of the inventory's 455 form-deciding sites record the form of "any"
 *      key rather than a named one, so a set computed over the remainder was
 *      silent about the rest by construction. Those 117 are now dispositioned,
 *      one row each, in `measurement/form-deciding-sites-resolution.tsv`, and
 *      this class reads that file: 17 rows name keys against an
 *      `acceptedOptionKeys()` declaration, 5 name framework keys, 95 cannot
 *      produce a member of this set at all and say why.
 *   2. The set was narrower than its own definition in two independent places
 *      — a `method !== 'fromArray'` filter, and matching the inventory's class
 *      cell against SHORT class names. Of 22 comparable rows the stand reached
 *      11, and `LcomCollectionConfigurationResolver`, `RuleOptionsFactory` and
 *      `ChannelExclusionKeyValidator` fell out as "unresolvable" even with the
 *      first filter widened. Both are gone: a site is resolved through its
 *      FILE PATH, and every reading body counts, not only a factory's.
 *
 * THE TRAP THAT WAS WAITING IN THE COUNTS, named because a reader will meet it
 * in the inventory: the filter `in_factory=yes` and the filter
 * `method=fromArray` select DIFFERENT elevens whose intersection is empty. The
 * line this printer used to carry, "inside fromArray()", counted the second and
 * never the factory. It is replaced by a count in a unit the set actually uses:
 * sites whose class carries a declaration.
 *
 * THE DECLARATION SIDE IS A UNION, and that is a decision of the round rather
 * than a fact of the code: `acceptedOptionKeys()` plus the framework keys of
 * `RuleOptionKeyRecognition`. No options class declares `suppress-paths` and
 * none ever will — the factory takes those three out of the config before
 * `fromArray()` is called — so comparing a framework key against
 * `acceptedOptionKeys()` alone would manufacture 5 members that name nothing
 * wrong.
 */

namespace Qualimetrix\PromiseEffect;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;

final readonly class FifthSetReport
{
    /**
     * @param list<string> $undeclared one line per (class, key) a body reads and nothing declares
     * @param list<string> $unresolvable sites whose class no file path could be turned into
     * @param list<string> $unresolvedSpelling sites whose key cell names a PHP identifier this stand cannot turn into a key
     */
    public function __construct(
        public array $undeclared,
        public array $unresolvable,
        public array $unresolvedSpelling,
        public int $sites,
        public int $namedSites,
        public int $anyKeySites,
        public int $declaringSites,
        public int $comparedSites,
        public int $resolutionRows,
        public int $resolutionCandidates,
        public int $resolutionFrameworkKeys,
    ) {}
}

final class FifthSet
{
    private const string INVENTORY = 'promise-effect/form-deciding-sites.tsv';

    private const string RESOLUTION = 'promise-effect/form-deciding-sites-resolution.tsv';

    /** The inventory's own word for "this site decides the form of whatever key arrives". */
    private const string ANY_KEY = 'любой';

    /**
     * @param list<string> $optionsClasses every class the population knows, not only the ones a rule names
     * @param array<string, string> $rules producer name => options class, as the product wires them
     */
    public function __construct(
        private readonly string $root,
        private readonly array $optionsClasses,
        private readonly array $rules = [],
    ) {}

    public function compute(): FifthSetReport
    {
        $sites = 0;
        $named = 0;
        $anyKey = 0;
        $declaring = 0;
        $compared = 0;
        $undeclared = [];
        $unresolvable = [];
        $unresolvedSpelling = [];

        foreach ($this->rows(self::INVENTORY, 12) as $cells) {
            ++$sites;
            $keyCell = $cells[4];

            if ($keyCell === self::ANY_KEY) {
                ++$anyKey;

                continue;
            }

            ++$named;
            $class = self::classOfFile($cells[0]);

            if ($class === null) {
                $unresolvable[] = $cells[2] . ' (' . $cells[0] . ':' . $cells[1] . ')';

                continue;
            }

            if (!self::carriesADeclaration($class)) {
                // A site in a class that declares no option keys — a document
                // reader, a CLI parser, a policy factory. It cannot produce a
                // member of `consumer \ declaration` however precisely its key
                // is named, because no declaration of that kind covers its
                // door. Not a failure to resolve: a site outside the question.
                continue;
            }

            ++$declaring;
            $spellings = $this->spellings($keyCell, $class, $cells, $unresolvedSpelling);

            if ($spellings === []) {
                continue;
            }

            ++$compared;

            foreach ($spellings as $key) {
                if (self::declares($class, $key)) {
                    continue 2;
                }
            }

            $undeclared[] = $class . ' reads ' . implode('/', $spellings) . ' at ' . $cells[0] . ':' . $cells[1] . ', and declares no spelling of it';
        }

        [$resolutionRows, $candidates, $frameworkRows, $fromResolution] = $this->fromResolution();
        $undeclared = [...$undeclared, ...$fromResolution];
        $compared += $candidates + $frameworkRows;

        sort($undeclared, \SORT_STRING);
        $unresolvable = array_values(array_unique($unresolvable));
        sort($unresolvable, \SORT_STRING);
        sort($unresolvedSpelling, \SORT_STRING);

        return new FifthSetReport(
            array_values(array_unique($undeclared)),
            $unresolvable,
            $unresolvedSpelling,
            $sites,
            $named,
            $anyKey,
            $declaring,
            $compared,
            $resolutionRows,
            $candidates,
            $frameworkRows,
        );
    }

    /**
     * The 117 «любой» sites, as their disposition file resolves them.
     *
     * The keys there are written as whole config PATHS in the spelling a user
     * types (`cohesion.lcom.exclude_methods`), which is what makes the three
     * readers-on-behalf-of-a-declaration comparable at all: the declaration is
     * found through the RULE named in the path, not through the class the site
     * happens to live in. `LcomCollectionConfigurationResolver` declares
     * nothing and never will — it reads `cohesion.lcom`'s keys on that rule's
     * behalf.
     *
     * @return array{int, int, int, list<string>}
     */
    private function fromResolution(): array
    {
        $rows = 0;
        $candidates = 0;
        $frameworkRows = 0;
        $members = [];

        foreach ($this->rows(self::RESOLUTION, 9) as $cells) {
            ++$rows;
            $disposition = $cells[7];

            if ($disposition !== 'yes' && $disposition !== 'framework-key') {
                continue;
            }

            $disposition === 'yes' ? ++$candidates : ++$frameworkRows;

            foreach (explode(',', $cells[5]) as $path) {
                $path = trim($path);

                if ($path === '') {
                    continue;
                }

                $member = $this->undeclaredPath($path, $cells[0] . ':' . $cells[1]);

                if ($member !== null) {
                    $members[] = $member;
                }
            }
        }

        return [$rows, $candidates, $frameworkRows, $members];
    }

    /**
     * One config path of the disposition file against the declaration behind
     * it, or null when the declaration answers for it.
     *
     * A path whose rule segment is a placeholder (`rules.{rule}.suppress_paths`)
     * is a statement about every registered rule at once, so the framework
     * union is asked and, failing that, every rule the product wires.
     */
    private function undeclaredPath(string $path, string $anchor): ?string
    {
        $segments = explode('.', $path);
        $key = (string) array_pop($segments);

        // A trailing placeholder is a segment the USER authors — the selector
        // inside `suppress_namespace_channels.{selector}`, the layer inside
        // `architecture.allow.{layer}`. It is not a key anyone declares, and
        // taking it for one would enrol four members that name a user's own
        // word as an undeclared option.
        while (str_starts_with($key, '{') && $segments !== []) {
            $key = (string) array_pop($segments);
        }

        if (\in_array(ConfigKeySpelling::normalize($key), CrossCheck::frameworkKeys(), true)) {
            return null;
        }

        if ($segments !== [] && $segments[0] === 'rules') {
            array_shift($segments);
        }

        $rule = implode('.', $segments);

        if (str_contains($rule, '{')) {
            // A placeholder rule: the site reads the key under every producer.
            foreach (array_keys($this->rules) as $candidate) {
                if (self::declares($this->rules[$candidate], $key)) {
                    return null;
                }
            }

            return 'the site at ' . $anchor . ' reads ' . $path . ', and no declaration names it';
        }

        $class = $this->rules[$rule] ?? null;

        if ($class === null) {
            // The path's leading segments are the rule and a level slot, or the
            // rule is spelled with the slot inside it; walk it back one
            // segment at a time rather than guess.
            while ($segments !== [] && $class === null) {
                $key = array_pop($segments) . '.' . $key;
                $class = $this->rules[implode('.', $segments)] ?? null;
            }
        }

        if ($class === null) {
            return 'the site at ' . $anchor . ' reads ' . $path . ', and no registered producer owns that path';
        }

        return self::declares($class, $key)
            ? null
            : $class . ' reads ' . $path . ' at ' . $anchor . ', and declares no spelling of it';
    }

    /**
     * Whether the declaration behind $class answers for $key — at its own
     * depth, inside one of its level slots, or as a framework key.
     */
    private static function declares(string $class, string $key): bool
    {
        $normalized = ConfigKeySpelling::normalize($key);

        if (\in_array($normalized, CrossCheck::frameworkKeys(), true)) {
            return true;
        }

        if (!self::carriesADeclaration($class)) {
            return false;
        }

        if ($class::acceptedOptionKeys()->knows($normalized)) {
            return true;
        }

        if (!is_a($class, HierarchicalRuleOptionsInterface::class, true)) {
            return false;
        }

        $segments = explode('.', $normalized);
        $slots = $class::levelOptionsClasses();

        if (\count($segments) > 1 && isset($slots[$segments[0]])) {
            return $slots[$segments[0]]::acceptedOptionKeys()->knows(implode('.', \array_slice($segments, 1)));
        }

        return isset($slots[$normalized]);
    }

    /** @param class-string|string $class */
    private static function carriesADeclaration(string $class): bool
    {
        return is_a($class, RuleOptionsInterface::class, true) || is_a($class, LevelOptionsInterface::class, true);
    }

    /**
     * The class a site's FILE holds, by PSR-4 — never by matching the
     * inventory's class cell against short names, which is how three readers
     * used to fall out of the set: a short name that no options class carries
     * is indistinguishable from a class the stand simply failed to look up.
     */
    private static function classOfFile(string $file): ?string
    {
        if (!str_starts_with($file, 'src/') || !str_ends_with($file, '.php')) {
            return null;
        }

        $class = 'Qualimetrix\\' . str_replace('/', '\\', substr($file, \strlen('src/'), -\strlen('.php')));

        return class_exists($class) || interface_exists($class) ? $class : null;
    }

    /**
     * The key spellings one inventory cell stands for.
     *
     * A comma cell lists the SPELLINGS one site reads for one key —
     * `enabled,ENABLED` is the key and the constant that names it, not two
     * keys — so the site is a member only when no spelling it carries is
     * declared.
     *
     * @param list<string> $cells
     * @param list<string> $unresolvedSpelling
     *
     * @return list<string>
     */
    private function spellings(string $keyCell, string $class, array $cells, array &$unresolvedSpelling): array
    {
        $spellings = [];

        foreach (explode(',', $keyCell) as $literal) {
            $key = trim($literal);

            if ($key === '' || str_contains($key, ' ')) {
                // A prose cell ("the rule's own root", an inline directive
                // spelling) is not a key literal, and guessing at one would
                // manufacture members of the set.
                continue;
            }

            $resolved = self::resolveConstant($key, $class);

            if ($resolved !== null) {
                $spellings[] = $resolved;

                continue;
            }

            // A local variable holding the key (`$callableKey`) names nothing
            // this stand can fold: enrolling it would put a PHP identifier
            // into a set of user-facing keys, which is exactly the kind of
            // invented member 02 §6 warns about.
            if (str_contains($cells[11] ?? '', '$' . $key)) {
                $unresolvedSpelling[] = $key . ' at ' . $cells[0] . ':' . $cells[1] . ' is a PHP variable, not a key literal';

                continue;
            }

            $spellings[] = $key;
        }

        return $spellings;
    }

    /**
     * An ALL-CAPS token names the constant that holds the key. The product
     * keeps those on `RuleOptionKey`, and a class may add its own; both are
     * asked, in that order, and a token neither answers is not resolved into
     * anything.
     */
    private static function resolveConstant(string $token, string $class): ?string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $token) !== 1) {
            return null;
        }

        foreach ([RuleOptionKey::class . '::' . $token, $class . '::' . $token] as $candidate) {
            if (!\defined($candidate)) {
                continue;
            }

            /** @var mixed $value */
            $value = \constant($candidate);

            if (\is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<list<string>> every data row of a tab-separated declaration, padded to $width
     */
    private function rows(string $relative, int $width): array
    {
        $lines = file($this->root . '/' . $relative, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . $relative);
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

            $rows[] = array_pad(explode("\t", $line), $width, '');
        }

        return $rows;
    }

    /** @return list<string> the classes the population knows, kept for the report's denominator */
    public function population(): array
    {
        return $this->optionsClasses;
    }
}
