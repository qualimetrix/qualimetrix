<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use RuntimeException;

/**
 * The byte form of a baseline file: one entry per line, inside a single
 * valid JSON document, with the float representation pinned at the call.
 *
 * Separate from {@see BaselineWriter} because two producers now render this
 * document. The writer renders a {@see Baseline} it assembled from typed
 * entries; {@see BaselineChannelRenamer} renders a decoded envelope it never
 * turned into objects at all. A layout each of them spelled for itself would
 * be two files claiming to be the same format, and the difference would show
 * up as a diff in a user's baseline rather than as a failing test.
 */
final class BaselineDocumentLayout
{
    /**
     * Indentation of the canonical layout. Two spaces rather than the four
     * `JSON_PRETTY_PRINT` emits: the file is nested three levels deep at the
     * entry line, so the width is paid on every line of the largest block.
     */
    private const string INDENT = '  ';

    private const string SUBJECT_INDENT = self::INDENT . self::INDENT;

    private const string ENTRY_INDENT = self::INDENT . self::INDENT . self::INDENT;

    /**
     * Encodes with the float representation pinned at this call, not
     * inherited from the environment.
     *
     * Six-decimal normalisation alone does not make the bytes independent of
     * the reader's ini, and assuming it did would have been a bet rather
     * than a guarantee: `0.1` has no exact binary form, so at
     * `serialize_precision=17` PHP writes `0.10000000000000001` and at `15`
     * it writes `0.1` — the same value, two files. `-1` selects the shortest
     * representation that round-trips to the identical double, which is both
     * stable across every ambient setting and lossless. The setting is
     * process-global, so it is restored immediately.
     *
     * `JSON_PRESERVE_ZERO_FRACTION` is deliberately not passed: a normalised
     * `40.0` is written as `40` and reloads as an `int`, which is harmless
     * for a numeric comparison and stable from the first write.
     *
     * A pin that did not take is treated as a failed write rather than as a
     * quietly degraded one: `ini_set` answers with the previous value on
     * success and `false` on failure, so the two are distinguishable — and
     * the guarantee above is worth nothing if its failure looks exactly like
     * its success. Using that return value also means the restore puts back
     * precisely what was there, instead of guessing a default for a setting
     * that was never successfully read.
     *
     * @param array<string, mixed> $envelope the document's fields in the order they are
     *                                       written, `entries` among them
     * @param array<string, list<mixed>> $entries subject key => the payloads under it, each
     *                                            payload written on its own line
     */
    public static function render(array $envelope, array $entries): string
    {
        $previous = ini_set('serialize_precision', '-1');

        if ($previous === false) {
            throw new RuntimeException(
                'Failed to pin serialize_precision for the baseline write; the file would not be '
                . 'reproducible across environments.',
            );
        }

        try {
            return self::layout($envelope, $entries);
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }

    /**
     * Renders the canonical layout: one entry per line, inside a single valid
     * JSON document.
     *
     * The document is assembled from per-value `json_encode` calls rather than
     * one whole-document call, because `JSON_PRETTY_PRINT` has no line policy
     * to configure — it explodes every array and object, which is what put a
     * three-field entry on nine lines. Splitting the call is what buys both
     * the size and the reviewable diff: an entry is a unit of acceptance, so
     * it is a line, and a subject key labels the block above the lines it owns.
     *
     * This stays inside the `serialize_precision` pin for the same reason the
     * single call needed it: the pin governs how PHP renders doubles, and
     * per-value encoding makes exactly the same number of float decisions.
     *
     * The envelope is written key by key in the order the caller produced, so
     * this method never has to know which fields an envelope has — only that
     * `entries` is the one that expands, and that it is written last.
     *
     * @param array<string, mixed> $envelope
     * @param array<string, list<mixed>> $entries
     */
    private static function layout(array $envelope, array $entries): string
    {
        $document = "{\n";

        foreach ($envelope as $key => $value) {
            $document .= self::INDENT . self::encode($key) . ': ' . self::encode($value) . ",\n";
        }

        $blocks = [];

        foreach ($entries as $subjectKey => $payloads) {
            $lines = [];

            foreach ($payloads as $payload) {
                $lines[] = self::ENTRY_INDENT . self::encode($payload);
            }

            $blocks[] = self::SUBJECT_INDENT . self::encode((string) $subjectKey) . ": [\n"
                . implode(",\n", $lines) . "\n" . self::SUBJECT_INDENT . ']';
        }

        if ($blocks === []) {
            return $document . self::INDENT . '"entries": {}' . "\n}\n";
        }

        return $document . self::INDENT . '"entries": {' . "\n"
            . implode(",\n", $blocks) . "\n" . self::INDENT . "}\n}\n";
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
