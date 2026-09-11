<?php

declare(strict_types=1);

/**
 * The fifth set of 02 §6: key literals a `fromArray()` body READS that the
 * class's own `acceptedOptionKeys()` does not declare.
 *
 * The four sets of stage 01 reconcile the ledger against the declaration. None
 * of them can see a key the code reads but nobody declared: such a key is
 * absent from the declaration and therefore absent from the denominator of
 * axis A as well — a circular denominator, blind exactly where it matters.
 *
 * It is printed and **it is not evidence**. The inventory marks 117 of its 455
 * sites as deciding the form of "any" key rather than a named one, so a set
 * computed today is computed over the remainder and stays silent about the
 * rest by construction. Resolving those sites is the first action of the cure
 * package (03 §P1), not of this stand. Until then the count travels with the
 * set, so nobody can read the number as a completeness claim.
 */

namespace Qualimetrix\PromiseEffect;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;

final readonly class FifthSetReport
{
    /**
     * @param list<string> $undeclared one line per (class, key) the body reads and the class does not declare
     * @param list<string> $unresolvable classes a site names that no options class could be matched to
     * @param list<string> $unresolvedSpelling sites whose key cell names a PHP identifier this stand cannot turn into a key
     */
    public function __construct(
        public array $undeclared,
        public array $unresolvable,
        public array $unresolvedSpelling,
        public int $sites,
        public int $namedSites,
        public int $anyKeySites,
        public int $factorySites,
        public int $comparedSites,
    ) {}
}

final class FifthSet
{
    private const string INVENTORY = 'docs/internal/plans/promise-effect/measurement/form-deciding-sites.tsv';

    /** The inventory's own word for "this site decides the form of whatever key arrives". */
    private const string ANY_KEY = 'любой';

    /** @param list<string> $optionsClasses every class the population knows, not only the ones a rule names */
    public function __construct(
        private readonly string $root,
        private readonly array $optionsClasses,
    ) {}

    public function compute(): FifthSetReport
    {
        $lines = file($this->root . '/' . self::INVENTORY, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . self::INVENTORY);
        }

        $shortNames = [];

        foreach ($this->optionsClasses as $class) {
            $shortNames[substr((string) strrchr('\\' . $class, '\\'), 1)] = $class;
        }

        $sites = 0;
        $named = 0;
        $anyKey = 0;
        $factory = 0;
        $compared = 0;
        $undeclared = [];
        $unresolvable = [];
        $unresolvedSpelling = [];
        $header = false;

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!$header) {
                $header = true;

                continue;
            }

            $cells = array_pad(explode("\t", $line), 12, '');
            ++$sites;
            $keyCell = $cells[4];

            if ($keyCell === self::ANY_KEY) {
                ++$anyKey;
            } else {
                ++$named;
            }

            if ($cells[3] !== 'fromArray') {
                continue;
            }

            ++$factory;

            if ($keyCell === self::ANY_KEY || $keyCell === '') {
                continue;
            }

            $class = $shortNames[$cells[2]] ?? null;

            if ($class === null) {
                $unresolvable[] = $cells[2] . ' (' . $cells[0] . ':' . $cells[1] . ')';

                continue;
            }

            if (!is_subclass_of($class, RuleOptionsInterface::class)) {
                $unresolvable[] = $class . ' does not implement the options contract';

                continue;
            }

            $declared = $class::acceptedOptionKeys();
            $spellings = [];

            foreach (explode(',', $keyCell) as $literal) {
                $key = trim($literal);

                if ($key === '' || str_contains($key, ' ')) {
                    // A prose cell ("the rule's own root", an inline directive
                    // spelling) is not a key literal, and guessing at one
                    // would manufacture members of the set.
                    continue;
                }

                // The inventory records what the SOURCE writes, and that is
                // not always a key a user could type. Two shapes are resolved
                // rather than guessed at, and a third is refused.
                $resolved = self::resolveConstant($key, $class);

                if ($resolved !== null) {
                    $spellings[] = $resolved;

                    continue;
                }

                // A local variable holding the key (`$callableKey`) names
                // nothing this stand can fold: enrolling it would put a PHP
                // identifier into a set of user-facing keys, which is exactly
                // the kind of invented member 02 §6 warns about.
                if (str_contains($cells[11], '$' . $key)) {
                    $unresolvedSpelling[] = $key . ' at ' . $cells[0] . ':' . $cells[1] . ' is a PHP variable, not a key literal';

                    continue;
                }

                $spellings[] = $key;
            }

            if ($spellings === []) {
                continue;
            }

            ++$compared;

            // A comma cell lists the SPELLINGS one site reads for one key —
            // `enabled,ENABLED` is the key and the constant that names it, not
            // two keys. So the site is a member only when no spelling it
            // carries is declared; reporting per spelling would enrol every
            // constant reference in the tree.
            foreach ($spellings as $key) {
                // Both sides are folded through the product's own spelling
                // rule: a `fromArray()` body reads the post-normalization
                // camel spelling while the class declares the kebab one, and
                // an unfolded diff would report every key in the tree.
                if ($declared->knows(ConfigKeySpelling::normalize($key))) {
                    continue 2;
                }
            }

            $undeclared[] = $class . ' reads ' . implode('/', $spellings) . ' at ' . $cells[0] . ':' . $cells[1] . ', and declares no spelling of it';
        }

        sort($undeclared, \SORT_STRING);
        $unresolvable = array_values(array_unique($unresolvable));
        sort($unresolvable, \SORT_STRING);
        sort($unresolvedSpelling, \SORT_STRING);

        return new FifthSetReport($undeclared, $unresolvable, $unresolvedSpelling, $sites, $named, $anyKey, $factory, $compared);
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
}
