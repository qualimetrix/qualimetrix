<?php

declare(strict_types=1);

/**
 * The four sets of stage 01: the registry against the declaration.
 *
 * This is the round's central piece of evidence, and it needs two independent
 * witnesses to be evidence at all. The registry (`promise-ledger.tsv`) was
 * written by a package forbidden to read the declaration, because two authors
 * who consult each other agree by construction. The comparison itself is this
 * class, and it consults both — after they were written.
 *
 * The four sets:
 *
 *   - `ledger ∩ declaration`   — agreement;
 *   - `declaration \ ledger`   — split in two, and the split is the point:
 *       WIDER   the declaration accepts a form nothing promised. This is the
 *               hazard the whole construction exists for: a false green that
 *               moved out of the product and into the declaration;
 *       DEEPER  the declaration answers for a key the registry's denominator
 *               never reached. A known hole, named by a number;
 *   - `ledger \ declaration`   — promised and not declared, listed row by row.
 *
 * WHAT MAKES THE COMPARISON POSSIBLE AT ALL
 * Since the first cure package the declaration carries a FORM
 * (`RuleOptionShape`), and the registry carries `promised_forms` in the eight
 * names of 02 §5. The two vocabularies are bridged by
 * `promise-effect/door-normalization.tsv` — a DECLARED table, not code — which
 * says what value each door hands the declaration for each form. The shape is
 * then asked about that value in its own words, through `matches()`.
 *
 * The formulation, stated once and held to everywhere below:
 *
 *   the declaration, composed with its door's declared normalization, must
 *   accept exactly the `promised_forms` of that door's registry row.
 *
 * It is not the naive one. A naive comparison of "the declared form" against
 * `promised_forms` reports a disagreement on `string-number` for every CLI row
 * and on `null` for every YAML row of the same key — 205 paths at once — and
 * both halves would be the comparison's fault, not the product's.
 */

namespace Qualimetrix\PromiseEffect;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;

final readonly class CrossCheckReport
{
    /**
     * @param list<string> $wider one line per (row, form) the declaration accepts against a registry row that promises OTHER forms
     * @param list<string> $widerUnopposed the same, on a row whose `promised_forms` is empty: the registry made no claim here at all
     * @param list<string> $ledgerOnly one line per (row, form) promised and not accepted
     * @param list<string> $undeclaredKeys one line per registry row whose key no declaration answers for
     * @param list<string> $answeredByTheClass one line per registry row whose key is answered by the class itself
     * @param list<string> $formless one line per registry row whose key is a framework key with no declared form
     * @param list<string> $unowned one line per registry row no registered producer owns
     * @param list<string> $deeper one line per declared key the registry never names
     * @param array<string, int> $statuses registry status => rows the comparison covers
     */
    public function __construct(
        public int $agreed,
        public array $wider,
        public array $widerUnopposed,
        public array $ledgerOnly,
        public array $undeclaredKeys,
        public array $answeredByTheClass,
        public array $formless,
        public array $unowned,
        public array $deeper,
        public array $statuses,
        public int $comparedRows,
        public int $comparedCells,
    ) {}
}

final class CrossCheck
{
    private const string NORMALIZATION = 'promise-effect/door-normalization.tsv';

    /** @var array<string, array<string, mixed>> door => form => the value the declaration judges */
    private array $normalization;

    /** @var array<string, RuleOptionShape> "<rule>|<normalized key>" => a shape planted in place of the declared one */
    private array $planted = [];

    /**
     * @param array<string, string> $rules producer name => options class, as the product wires them
     */
    public function __construct(
        private readonly string $root,
        private readonly array $rules,
        private readonly Ledger $ledger,
    ) {
        $this->normalization = $this->loadNormalization();
    }

    /**
     * Replaces one declared shape in memory, for the controls.
     *
     * A control cannot plant into a copied tree here: the copy resolves PSR-4
     * back through `vendor/` into the original `src/`, so a planted options
     * class is loaded from the tree it was meant to replace — the false green
     * this repository has already been bitten by. So the plant is injected
     * into the declaration side of THIS comparison, and what it proves is
     * bounded accordingly: that a disagreement on the declaration side is
     * seen, not that a disagreement in `src/` would be.
     */
    public function plantShape(string $rule, string $key, RuleOptionShape $shape): void
    {
        $this->planted[$rule . '|' . ConfigKeySpelling::normalize($key)] = $shape;
    }

    public function compute(): CrossCheckReport
    {
        $agreed = 0;
        $wider = [];
        $widerUnopposed = [];
        $ledgerOnly = [];
        $undeclaredKeys = [];
        $answeredByTheClass = [];
        $formless = [];
        $unowned = [];
        $statuses = [];
        $comparedRows = 0;
        $comparedCells = 0;
        $named = [];

        foreach ($this->ledger->forms as $row) {
            if (!str_starts_with($row->path, 'rules.')) {
                // Axis D has no `acceptedOptionKeys()` behind it: the roots
                // outside `rules:` are read by resolvers that declare nothing
                // of the kind. Their side of the comparison does not exist,
                // and inventing one would be the comparison agreeing with
                // itself.
                continue;
            }

            $split = $this->split($row->path);

            if ($split === null) {
                $unowned[] = $row->key() . ': no registered producer owns this path';

                continue;
            }

            [$rule, $option] = $split;
            $normalizedOption = ConfigKeySpelling::normalize($option);
            $named[$rule . '|' . $normalizedOption] = true;
            $planted = $this->planted[$rule . '|' . $normalizedOption] ?? null;
            $slot = $planted === null ? $this->slotOf($rule, $option) : null;
            $shape = null;
            $described = '';

            if ($slot !== null) {
                // A level SLOT is not judged by a shape at all: the key walk
                // branches on the slot before it ever asks the parent's
                // declaration, accepts `null` outright ("an empty level block
                // means what an omitted one means"), refuses anything that is
                // not an array, and hands the keys inside to the slot's own
                // declaration — which the depth-2 registry rows are about.
                // Asking the parent's `block()` here would compare against a
                // declaration the product does not consult.
                $described = 'a block of that level\'s options, or null';
            } else {
                $resolved = $planted === null ? $this->resolveKey($rule, $option) : null;
                $framework = $planted === null && $resolved === null
                    ? $this->frameworkShapeOf($normalizedOption)
                    : null;

                if ($planted === null && $resolved === null && $framework === null) {
                    if (\in_array($normalizedOption, self::frameworkKeys(), true)) {
                        // A framework key the framework itself answers for in
                        // its own words: `RuleOptionKeyRecognition` judges the
                        // form of `suppress-paths` and deliberately leaves the
                        // two namespace keys to the provider that reads them.
                        // Declared, and with no declared FORM — a bucket, not
                        // an agreement.
                        $formless[] = $row->key() . ': "' . $option . '" is a framework key whose form no declaration states';

                        continue;
                    }

                    $undeclaredKeys[] = $row->key() . ': nothing at that depth declares "' . $option . '"';

                    continue;
                }

                $shape = $planted ?? $framework ?? ($resolved === null ? null : $resolved[0]->shapeOf($resolved[1]));

                if ($shape === null) {
                    // Known to the class and not accepted by it: the class
                    // answers for the key in its own words and declares no
                    // form. A bucket of its own — folding it into agreement
                    // would let a key with no declared form count as declared.
                    $answeredByTheClass[] = $row->key() . ': "' . $option . '" is answered by the class itself, and carries no declared form';

                    continue;
                }

                $described = $shape->describe();
            }

            ++$comparedRows;
            $statuses[$row->status] = ($statuses[$row->status] ?? 0) + 1;
            $promised = $row->promisedForms;

            foreach (array_keys($this->formsOf($row->door)) as $form) {
                ++$comparedCells;
                $accepts = $shape === null
                    ? \in_array($form, ['null', 'map'], true)
                    : $this->accepts($shape, $row->door, $form);
                $isPromised = \in_array($form, $promised, true);

                if ($accepts && $isPromised) {
                    ++$agreed;

                    continue;
                }

                if ($accepts) {
                    $line = $row->key() . '|' . $form . ': declared ' . $described . ', and no promise names this form';
                    // A row that promises NOTHING is not contradicted by the
                    // declaration; it was never a claim. Counted apart, or the
                    // hazard this set exists to show would be diluted by 51
                    // rows the registry left open.
                    $promised === [] ? $widerUnopposed[] = $line : $wider[] = $line;

                    continue;
                }

                if ($isPromised) {
                    $ledgerOnly[] = $row->key() . '|' . $form . ': promised, and the declaration (' . $described . ') does not accept it';
                }
            }
        }

        return new CrossCheckReport(
            $agreed,
            self::sorted($wider),
            self::sorted($widerUnopposed),
            self::sorted($ledgerOnly),
            self::sorted($undeclaredKeys),
            self::sorted($answeredByTheClass),
            self::sorted($formless),
            self::sorted($unowned),
            $this->deeper($named),
            $statuses,
            $comparedRows,
            $comparedCells,
        );
    }

    /**
     * Whether the declaration accepts this door's write of this form.
     *
     * `list` and `map` are asked about the CONTAINER, which is the axis the
     * registry row is written on ("element forms inside a list" are out of the
     * round's claim). So the container is offered filled with each scalar the
     * same door declares, and one filling the declaration accepts is enough:
     * a shape of `listOf(nonEmptyText())` accepts the `list` FORM even though
     * it refuses the canonical list of the magnitude 7331, and reporting that
     * refusal as a disagreement would be this comparison arguing with the
     * round's own boundary.
     */
    private function accepts(RuleOptionShape $shape, string $door, string $form): bool
    {
        $written = $this->formsOf($door);
        $value = $written[$form] ?? null;

        if ($shape->matches($value)) {
            return true;
        }

        if ($form !== 'list' && $form !== 'map' || !\is_array($value)) {
            return false;
        }

        foreach ($written as $elementForm => $element) {
            if ($elementForm === 'list' || $elementForm === 'map') {
                continue;
            }

            $filled = $form === 'list' ? [$element] : ['a' => $element];

            if ($shape->matches($filled)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The declaration behind a level slot, when the option names one.
     *
     * Returns the slot's own class, or null when the path is an ordinary key.
     */
    private function slotOf(string $rule, string $option): ?string
    {
        if (str_contains($option, '.')) {
            return null;
        }

        $class = $this->rules[$rule] ?? null;

        if ($class === null || !is_a($class, RuleOptionsInterface::class, true)) {
            return null;
        }

        return $this->levelSlots($class)[ConfigKeySpelling::normalize($option)] ?? null;
    }

    /**
     * The form the FRAMEWORK declares for a framework key.
     *
     * The declaration side of these three keys is not `acceptedOptionKeys()`
     * and never will be — the factory takes them out of the config before
     * `fromArray()` is called, and no options class declares them. It is
     * `RuleOptionKeyRecognition`, which states a shape for `suppress-paths`
     * and deliberately none for the two namespace keys, so that the provider
     * reading those can answer about them in its own words.
     *
     * Read off the product's own private declaration rather than copied into
     * a table here: a copy would go out of step in silence.
     */
    private function frameworkShapeOf(string $normalizedKey): ?RuleOptionShape
    {
        if (!\in_array($normalizedKey, self::frameworkKeys(), true)) {
            return null;
        }

        $method = new ReflectionMethod(
            \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionKeyRecognition::class,
            'frameworkKeyShapes',
        );
        /** @var mixed $shapes */
        $shapes = $method->invoke(null);

        if (!$shapes instanceof RuleOptionKeySet) {
            throw new LedgerError('RuleOptionKeyRecognition::frameworkKeyShapes() no longer yields a key set');
        }

        return $shapes->shapeOf($normalizedKey);
    }

    /**
     * Keys the declaration answers for that no registry row names — the second
     * half of `declaration \ ledger`, and the one that is a number rather than
     * a list of arguments: the registry's denominator was built from the
     * documentation and the code it could see, and a key it never reached is a
     * hole in the denominator, not a disagreement about a promise.
     *
     * @param array<string, bool> $named "<rule>|<normalized key>" the registry names
     *
     * @return list<string>
     */
    private function deeper(array $named): array
    {
        $deeper = [];

        foreach ($this->rules as $rule => $class) {
            if (!is_a($class, RuleOptionsInterface::class, true)) {
                continue;
            }

            foreach ($class::acceptedOptionKeys()->acceptedForDisplay() as $key) {
                $normalized = ConfigKeySpelling::normalize($key);

                if (!isset($named[$rule . '|' . $normalized])) {
                    $deeper[] = 'rules.' . $rule . '.' . $key;
                }
            }

            foreach ($this->levelSlots($class) as $slot => $levelClass) {
                foreach ($levelClass::acceptedOptionKeys()->acceptedForDisplay() as $key) {
                    $normalized = ConfigKeySpelling::normalize($slot . '.' . $key);

                    if (!isset($named[$rule . '|' . $normalized])) {
                        $deeper[] = 'rules.' . $rule . '.' . $slot . '.' . $key;
                    }
                }
            }
        }

        return self::sorted($deeper);
    }

    /**
     * The key set that answers at the depth this path is written at, and the
     * normalized key to ask it about.
     *
     * Two depths, exactly as `RuleOptionKeyRecognition` walks them: the rule's
     * own declaration, and — when the first segment names a level slot — that
     * slot's own. There is no third, and a path deeper than two segments below
     * the rule is not a key any declaration answers for.
     *
     * @return array{RuleOptionKeySet, string}|null
     */
    private function resolveKey(string $rule, string $option): ?array
    {
        $class = $this->rules[$rule] ?? null;

        if ($class === null || !is_a($class, RuleOptionsInterface::class, true)) {
            return null;
        }

        $segments = explode('.', $option);
        $slots = $this->levelSlots($class);
        $head = ConfigKeySpelling::normalize($segments[0]);

        if (\count($segments) > 1 && isset($slots[$head])) {
            $inside = ConfigKeySpelling::normalize(implode('.', \array_slice($segments, 1)));
            $levelSet = $slots[$head]::acceptedOptionKeys();

            return $levelSet->knows($inside) ? [$levelSet, $inside] : null;
        }

        $set = $class::acceptedOptionKeys();
        $normalized = ConfigKeySpelling::normalize($option);

        return $set->knows($normalized) ? [$set, $normalized] : null;
    }

    /**
     * @param class-string<RuleOptionsInterface> $class
     *
     * @return array<string, class-string<\Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface>>
     */
    private function levelSlots(string $class): array
    {
        return is_a($class, HierarchicalRuleOptionsInterface::class, true) ? $class::levelOptionsClasses() : [];
    }

    /**
     * The longest registered producer prefix, as `Stand::split()` reads it:
     * rule names contain dots, so `rules.coupling.cbo.class.scope` is the rule
     * `coupling.cbo` and the option `class.scope`, and the split cannot be
     * done by counting segments.
     *
     * @return array{0: string, 1: string}|null
     */
    private function split(string $path): ?array
    {
        $rest = substr($path, \strlen('rules.'));
        $best = null;

        foreach (array_keys($this->rules) as $producer) {
            if (str_starts_with($rest, $producer . '.') && ($best === null || \strlen($producer) > \strlen($best))) {
                $best = $producer;
            }
        }

        return $best === null ? null : [$best, substr($rest, \strlen($best) + 1)];
    }

    /** @return array<string, mixed> form => the value this door hands the declaration */
    private function formsOf(string $door): array
    {
        return $this->normalization[$door]
            ?? throw new LedgerError('no door normalization is declared for the door "' . $door . '"');
    }

    /** @return array<string, array<string, mixed>> */
    private function loadNormalization(): array
    {
        $lines = file($this->root . '/' . self::NORMALIZATION, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . self::NORMALIZATION);
        }

        $table = [];
        $header = false;

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!$header) {
                $header = true;

                continue;
            }

            $cells = array_pad(explode("\t", $line), 4, '');
            $table[$cells[0]][$cells[1]] = Yaml::parse($cells[2]);
        }

        return $table;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function sorted(array $lines): array
    {
        sort($lines, \SORT_STRING);

        return $lines;
    }

    /**
     * The framework keys, read off the product's own list rather than copied
     * into a table here: a copy would go out of step in silence, and the list
     * is a statement of the product, not of this stand. It is private, so the
     * read is explicit — and it fails loudly if the constant is renamed.
     *
     * @return list<string> normalized spellings
     */
    public static function frameworkKeys(): array
    {
        $reflection = new ReflectionClass(\Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionKeyRecognition::class);
        $constants = $reflection->getConstants();

        if (!isset($constants['FRAMEWORK_KEYS']) || !\is_array($constants['FRAMEWORK_KEYS'])) {
            throw new LedgerError('RuleOptionKeyRecognition no longer declares FRAMEWORK_KEYS: the fifth set has lost its declaration side');
        }

        return array_values(array_map(
            static fn(mixed $key): string => ConfigKeySpelling::normalize((string) $key),
            $constants['FRAMEWORK_KEYS'],
        ));
    }
}
