<?php

declare(strict_types=1);

/**
 * The handwritten half of the stand: the eight forms and the envelopes that
 * make a placeholder path writable.
 *
 * Everything here is a declaration, never a measurement. A measurement that
 * disagrees with a declaration is reported; the declaration is not adjusted to
 * match it.
 */

namespace Qualimetrix\PromiseEffect;

final readonly class FormSpelling
{
    public function __construct(
        public string $name,
        public string $yamlWrite,
        public string $cliWrite,
        public string $collapseYaml,
        public string $collapseCli,
    ) {}

    /** The comparand as a writable spelling of its own, so one probe path serves both. */
    public function comparand(): self
    {
        return new self($this->name . '-comparand', $this->collapseYaml, $this->collapseCli, '', '');
    }
}

/**
 * The document a producer needs before its subject exists at all.
 *
 * Seven producers report on a configuration section; handed the empty document
 * of a plain witness run they have nothing to report on, and calling that
 * "unwitnessed" would state a property of the producer where the truth is a
 * property of the document. `channel` is the attribution guard for the one
 * envelope that has to enable a second rule beside the probed one.
 */
final readonly class WitnessEnvelope
{
    /**
     * @param array<string, mixed> $document
     * @param list<string> $arguments
     */
    public function __construct(
        public string $producer,
        public array $document,
        public array $arguments,
        public string $channel,
    ) {}
}

final readonly class Envelope
{
    /** @param array<string, mixed> $base */
    public function __construct(
        public string $ledgerPath,
        public string $writePath,
        public array $base,
        public bool $cacheOwned,
    ) {}
}

/**
 * The two values one axis-C dispute is written with, per slot.
 *
 * `low` and `high` name the SIDES of the dispute, not an ordering of the
 * numbers: which writer is the lower layer is the ledger row's business, and
 * this table only promises the two are different text.
 */
final readonly class Magnitude
{
    public function __construct(
        public string $slot,
        public string $side,
        public string $yamlWrite,
        public string $cliWrite,
    ) {}
}

final class Declarations
{
    /**
     * @param array<string, FormSpelling> $forms
     * @param array<string, Envelope> $envelopes
     * @param array<string, WitnessEnvelope> $witnessEnvelopes
     * @param array<string, string> $axisAHits option leaf => the hit literal
     * @param array<string, list<string>> $pairScopes pair kind => the coordinates it owes
     * @param array<string, Magnitude> $magnitudes `<slot>|<side>` => the two writings of it
     */
    private function __construct(
        public readonly array $forms,
        public readonly array $envelopes,
        public readonly array $witnessEnvelopes,
        public readonly array $axisAHits,
        public readonly array $pairScopes,
        public readonly array $magnitudes,
    ) {}

    /**
     * One side of one slot, refused rather than guessed when the table does
     * not declare it: a silent fallback would let axis C write the SAME value
     * on both sides and then read the row NOT OBSERVABLE, reporting the
     * missing declaration as a property of the product.
     */
    public function magnitude(string $slot, string $side): Magnitude
    {
        return $this->magnitudes[$slot . '|' . $side]
            ?? throw new LedgerError('composition-magnitudes.tsv declares no "' . $side . '" side for the slot "' . $slot . '"');
    }

    public static function load(string $root): self
    {
        $forms = [];

        foreach (self::rows($root . '/promise-effect/forms.tsv', 5) as $cells) {
            $forms[$cells[0]] = new FormSpelling($cells[0], $cells[1], $cells[2], $cells[3], $cells[4]);
        }

        if (\count($forms) !== 8) {
            throw new LedgerError('forms.tsv must name exactly the eight forms, found ' . \count($forms));
        }

        $envelopes = [];

        foreach (self::rows($root . '/promise-effect/axis-d-envelopes.tsv', 4) as $row) {
            /** @var mixed $base */
            $base = json_decode($row[2], true);

            if (!\is_array($base)) {
                throw new LedgerError('axis-d-envelopes.tsv: base of ' . $row[0] . ' is not a JSON object');
            }

            /** @var array<string, mixed> $base */
            $envelopes[$row[0]] = new Envelope($row[0], $row[1], $base, $row[3] === 'yes');
        }

        $witnesses = [];

        foreach (self::rows($root . '/promise-effect/witness-envelopes.tsv', 5) as $row) {
            /** @var mixed $document */
            $document = json_decode($row[1], true);

            if (!\is_array($document)) {
                throw new LedgerError('witness-envelopes.tsv: the document of ' . $row[0] . ' is not a JSON object');
            }

            /** @var array<string, mixed> $document */
            $witnesses[$row[0]] = new WitnessEnvelope(
                $row[0],
                $document,
                $row[2] === '' ? [] : array_values(array_map(trim(...), explode(',', $row[2]))),
                $row[3],
            );
        }

        $hits = [];

        foreach (self::rows($root . '/promise-effect/axis-a-hits.tsv', 3) as $row) {
            $hits[$row[0]] = $row[1];
        }

        $pairScopes = [];

        foreach (self::rows($root . '/promise-effect/pair-kind-scope.tsv', 3) as $row) {
            $pairScopes[$row[0]] = array_values(array_map(trim(...), explode(',', $row[1])));
        }

        $magnitudes = [];

        foreach (self::rows($root . '/promise-effect/composition-magnitudes.tsv', 4) as $row) {
            $magnitudes[$row[0] . '|' . $row[1]] = new Magnitude($row[0], $row[1], $row[2], $row[3]);
        }

        foreach ($magnitudes as $key => $magnitude) {
            if ($magnitude->side !== 'low') {
                continue;
            }

            $opposite = $magnitudes[$magnitude->slot . '|high'] ?? null;

            // The one property this table can be held to WITHOUT running the
            // product: two sides that are the same text can never be told
            // apart, and a run reporting that as NOT OBSERVABLE would be
            // reporting a typo here as a property of the product.
            if ($opposite === null || $opposite->yamlWrite === $magnitude->yamlWrite || $opposite->cliWrite === $magnitude->cliWrite) {
                throw new LedgerError('composition-magnitudes.tsv: the slot "' . $magnitude->slot . '" has no distinguishable high side (' . $key . ')');
            }
        }

        return new self($forms, $envelopes, $witnesses, $hits, $pairScopes, $magnitudes);
    }

    /** @return list<string> */
    public function formNames(): array
    {
        return array_keys($this->forms);
    }

    /** @return list<list<string>> */
    private static function rows(string $path, int $width): array
    {
        $lines = file($path, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . $path);
        }

        $rows = [];
        $seenHeader = false;

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!$seenHeader) {
                $seenHeader = true;

                continue;
            }

            $rows[] = array_pad(explode("\t", $line), $width, '');
        }

        return $rows;
    }
}
