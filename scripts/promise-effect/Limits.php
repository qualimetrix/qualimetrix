<?php

declare(strict_types=1);

/**
 * The observability limit: where the stand cannot put the row's question to
 * the product at all, declared in `promise-effect/observability-limits.tsv`.
 *
 * Two shapes of that, and they are different failures. A CLI door has no
 * syntax for `string-number`, `list` or `map` — argv is text, `7331` is the
 * only spelling of a number there, and quoting it writes six characters — so a
 * verdict on such a cell is about the stand's spelling, not about the form the
 * row is written on. And a key whose values are paths, namespaces or channel
 * selectors answers about the DOMAIN of the canonical magnitude, not about its
 * form: `suppress-paths: 7331` excludes nothing because 7331 is not a file.
 *
 * Read at JUDGEMENT, never at writing. That is the whole point: re-spelling
 * the probe would be a change of input, the frozen half is nailed to
 * `6a833ab8` and cannot be re-measured, and the 238 cells being argued about
 * would stop comparing across the pair. A rule consulted by the classifier
 * applies to both halves by construction.
 *
 * WHAT KEEPS THIS FROM BEING A SILENCER. The first version of the guard
 * refused a limit only where the unrestricted verdict was `OK`, which protects
 * the table from eating something GREEN and cannot, by construction, notice a
 * limit eating a DEFECT. Four rules now, and each one is checkable rather than
 * argued, all applied to both halves of the pair:
 *
 * - `OK` under any limit is refused: the effect was distinguishable and the
 *   producer reachable, so the question plainly was put to the product;
 * - `MALFORMED` under any limit is refused unless the SAME `(door, path)`
 *   publishes `MALFORMED` on a cell no limit covers. A crash or an unframed
 *   refusal proves the write reached the product and was mishandled, which no
 *   statement about the value's domain excuses; the exception is narrow and
 *   named because that defect is then on the report anyway, under another form
 *   of the same row;
 * - a kind claiming the answer is about the VALUE may not cover `COLLAPSED`:
 *   a coerced value is the product acting on the form, which is the one thing
 *   such a row says was not asked. Silence and a refusal are both left to it,
 *   deliberately — which of the two a key answers with is the product's choice
 *   of words, and this round's own cure moved several of them;
 * - the two door kinds have a machine basis, {@see self::basisProblems()}: a
 *   door that declares an array-valued flag writes a list by REPEATING the
 *   flag, so `door-cannot-express … list` is false for it and
 *   `stand-writes-one-where-the-door-repeats` is false for every other door.
 *   The basis is read off the product's own input definition, never asserted
 *   here.
 *
 * What is still not expressible: under `door-cannot-express`, a `REFUSES` or
 * `COLLAPSED` cannot be told apart from a refusal of the form itself — the
 * stand wrote characters the door took literally, and the product's answer is
 * about those characters. `promise-effect/README.md` carries that count.
 */

namespace Qualimetrix\PromiseEffect;

final readonly class LimitRow
{
    /** The door has no syntax for this form at all, so the stand's spelling is a third thing. */
    public const string DOOR_CANNOT_EXPRESS = 'door-cannot-express';

    /** The door carries the form, but the canonical magnitude names nothing this key could act on. */
    public const string GENERIC_WRITE_NAMES_NOTHING = 'generic-write-names-nothing';

    /**
     * The container form is fine and what is refused is the KEY written inside
     * it — a level slot handed `{a: 7331}` refuses `a`, not the block. Kept
     * apart from the kind above because the two predict opposite answers:
     * silence there, a refusal here, and a row that draws the other one is
     * declaring a reason it does not have.
     */
    public const string REFUSAL_ABOUT_THE_INNER_KEY = 'refusal-about-the-inner-key';

    /**
     * The door writes this form by REPEATING its flag and the stand writes one
     * flag: a limit about the stand, not about the door. Kept apart from
     * `door-cannot-express` because only one of the two can be true of a given
     * door, and {@see Limits::basisProblems()} decides which from the
     * product's own input definition.
     */
    public const string STAND_WRITES_ONE = 'stand-writes-one-where-the-door-repeats';

    public const array KINDS = [
        self::DOOR_CANNOT_EXPRESS,
        self::GENERIC_WRITE_NAMES_NOTHING,
        self::REFUSAL_ABOUT_THE_INNER_KEY,
        self::STAND_WRITES_ONE,
    ];

    /**
     * The kinds whose claim is that the answer is about the VALUE and not
     * about its form. Silence and a refusal are both such an answer — which
     * half of the pair a row reads depends on how the product chose to say
     * "this names nothing", and the cure of this very round turned several of
     * those from a crash into a framed refusal. `COLLAPSED` is not: it means
     * the value was COERCED, and coercion is the product acting on the form.
     *
     * @var list<string>
     */
    public const array ABOUT_THE_VALUE = [self::GENERIC_WRITE_NAMES_NOTHING, self::REFUSAL_ABOUT_THE_INNER_KEY];

    public function __construct(
        public string $kind,
        public string $door,
        public string $key,
        public string $form,
        public string $reason,
    ) {}

    public function covers(string $door, string $path, string $form): bool
    {
        if ($this->form !== $form) {
            return false;
        }

        if ($this->door !== '*' && $this->door !== $door) {
            return false;
        }

        return $this->key === '*' || $this->key === self::leafOf($path);
    }

    /**
     * The option leaf a path ends with — the unit the limit is keyed on, for
     * the same reason `axis-a-hits.tsv` is keyed on it: the three framework
     * keys repeat under every producer, and an enumeration of 162 paths would
     * be four statements written 162 times.
     */
    private static function leafOf(string $path): string
    {
        return str_contains($path, '.') ? substr($path, (int) strrpos($path, '.') + 1) : $path;
    }
}

final class Limits
{
    private const string PATH = 'promise-effect/observability-limits.tsv';

    /** @param list<LimitRow> $rows */
    private function __construct(public readonly array $rows) {}

    public static function load(string $root): self
    {
        $lines = file($root . '/' . self::PATH, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . self::PATH);
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

            $cells = array_pad(explode("\t", $line), 5, '');

            if (!\in_array($cells[0], LimitRow::KINDS, true)) {
                // A kind nobody judges is a limit with no rule behind it: the
                // guards below are keyed on the kind, and an unknown one would
                // silence cells while answering to nothing.
                throw new LedgerError('observability-limits.tsv: unknown kind "' . $cells[0] . '"');
            }

            $rows[] = new LimitRow($cells[0], $cells[1], $cells[2], $cells[3], $cells[4]);
        }

        return new self($rows);
    }

    /** The row covering this cell, or null when the cell can be asked. */
    public function rowFor(string $door, string $path, string $form): ?LimitRow
    {
        foreach ($this->rows as $row) {
            if ($row->covers($door, $path, $form)) {
                return $row;
            }
        }

        return null;
    }

    /** The declared reason this cell cannot be asked, or null when it can. */
    public function reasonFor(string $door, string $path, string $form): ?string
    {
        return $this->rowFor($door, $path, $form)?->reason;
    }

    /**
     * Every cell a limit covers whose unrestricted verdict the row is not
     * entitled to cover — a limit that deletes evidence instead of admitting
     * blindness. The four rules are stated on the class.
     *
     * The whole judged grid is taken beside the covered cells because one of
     * the rules is about a SIBLING: a crash under a limit is tolerated exactly
     * when the same row publishes that crash on a form no limit covers, and
     * that fact cannot be read off the covered cells alone.
     *
     * @param list<array{string, string, string}> $covered cell key, the verdict it carries without the limit, the covering row's kind
     * @param array<string, string> $judged every cell of the run, as the grid publishes it
     *
     * @return list<string>
     */
    public function conflicts(array $covered, array $judged): array
    {
        $conflicts = [];
        $coveredKeys = [];

        foreach ($covered as [$key]) {
            $coveredKeys[$key] = true;
        }

        foreach ($covered as [$key, $verdict, $kind]) {
            if ($verdict === Verdict::OK) {
                $conflicts[] = $key . ': a declared observability limit covers a cell whose effect the stand DOES observe';

                continue;
            }

            if ($verdict === Verdict::MALFORMED && !self::crashPublishedElsewhere($key, $coveredKeys, $judged)) {
                $conflicts[] = $key . ': a declared observability limit covers a crash or an unframed refusal, and no uncovered form of the same row publishes it';

                continue;
            }

            if ($verdict === Verdict::COLLAPSED && \in_array($kind, LimitRow::ABOUT_THE_VALUE, true)) {
                $conflicts[] = $key . ': `' . $kind . '` claims the answer is about the value, and the value was coerced into the canonical write of another form';
            }
        }

        return $conflicts;
    }

    /**
     * Whether the same `(door, path)` carries a `MALFORMED` cell that no limit
     * covers — the one case in which a limit over a crash hides nothing,
     * because the report names that crash under another form of the row.
     *
     * @param array<string, true> $coveredKeys
     * @param array<string, string> $judged
     */
    private static function crashPublishedElsewhere(string $key, array $coveredKeys, array $judged): bool
    {
        $prefix = substr($key, 0, (int) strrpos($key, '|') + 1);

        foreach ($judged as $other => $verdict) {
            if ($other === $key || isset($coveredKeys[$other]) || !str_starts_with($other, $prefix)) {
                continue;
            }

            if ($verdict === Verdict::MALFORMED) {
                return true;
            }
        }

        return false;
    }

    /**
     * The two door kinds judged against the product's own input definition.
     *
     * A door that declares an array-valued flag writes a list by repeating the
     * flag, not by brackets — so "this door has no list syntax" is false of it,
     * and the honest limit there is the one about the stand writing a single
     * flag. Both directions are refused, which is what makes this a basis and
     * not a preference: neither kind may be declared where the door says the
     * other.
     *
     * A `null` reading — the definition does not know that flag at all — is
     * judged by the caller, which is where the ledger row naming it lives. It
     * is deliberately NOT treated as "does not repeat" here: a limit passing
     * because nobody could look is the shape of failure this guard exists for.
     *
     * @param array<string, bool|null> $repeatable `door|path` => the door writes this key by repeating its flag
     *
     * @return list<string>
     */
    public function basisProblems(array $repeatable): array
    {
        $problems = [];

        foreach ($repeatable as $cell => $repeats) {
            if ($repeats === null) {
                continue;
            }

            [$door, $path] = array_pad(explode('|', $cell, 2), 2, '');
            $row = $this->rowFor($door, $path, 'list');

            if ($row === null) {
                continue;
            }

            if ($repeats && $row->kind === LimitRow::DOOR_CANNOT_EXPRESS) {
                $problems[] = $cell . ': the door declares this flag array-valued, so it writes a list by repetition and `'
                    . LimitRow::DOOR_CANNOT_EXPRESS . '` is false of it';
            }

            if (!$repeats && $row->kind === LimitRow::STAND_WRITES_ONE) {
                $problems[] = $cell . ': the door declares no array-valued flag here, so there is no repetition for the stand to fall short of';
            }
        }

        return $problems;
    }
}
