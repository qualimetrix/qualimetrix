<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The order a findings surface publishes its records in — asserted on each raw
 * side, and re-established on the reference once its names have been translated.
 *
 * A rename moves records. The product orders findings by an identity whose first
 * component is the channel code, so `complexity.cyclomatic -> complexity.ccn`
 * does not only rewrite a name: it moves that finding past
 * `complexity.cognitive`, and `design.inheritance -> design.dit` moves one past
 * `design.god-class`. {@see RenameMaps::forward()} rewrites the reference's
 * artifact **in place**, so a translated reference ends up carrying the new
 * vocabulary in the old order — which is not "the reference in the candidate's
 * dictionary", it is a translation done half way. Measured 2026-09-08 against
 * `ec1597d6` on `case:complexity|format:json` and `case:complexity|baseline-file`.
 *
 * A declared delta cannot express it: the diff pairs lines by position, so the
 * hunk reads as the compared field `channel` changing from one channel to
 * another, and `delta-overreach` refuses it. A row saying so would be a lie —
 * it would licence substituting one channel for another anywhere.
 *
 * The product already answers this question about itself. `baseline:rename-channels`
 * ({@see \Qualimetrix\Analysis\Policy\Baseline\BaselineChannelRenamer}) was
 * released for this very rename, and it translates the channel, recomputes the
 * ordering key and re-sorts. This step is the same answer applied to the
 * reference's artifacts.
 *
 * **What keeps the mechanism from weakening the gate.**
 *
 * - It is a no-op under an identity map. The keys do not move, and the product
 *   already emitted the records in that order, so the sort returns the input.
 * - The order stays under test. Each side is *asserted* to be in its own key's
 *   order before anything is translated, and a side that is not is
 *   `published-order-drift` — the run does not quietly sort it into shape. A
 *   product that stopped publishing findings in the order of its own sort key
 *   therefore still reddens the gate.
 * - The key is not a blind second copy of the product's. Its field set and the
 *   order of its components come from {@see Fingerprints::INPUT_FIELDS}, the
 *   published identity {@see Gate::checkTuple()} already holds against the
 *   tracked equivalence tuple, so there is one owner for "which fields make a
 *   finding's identity"; a field added there that this class cannot decompose is
 *   a refusal rather than a silent omission. And the per-run assertion above is
 *   the empirical half: if the product's key stops agreeing with this one, the
 *   candidate's own artifact stops being sorted by this one, and the run says so.
 */
final class PublishedOrder
{
    /**
     * The surface classes whose records are published in identity order.
     *
     * Measured, not assumed: `format:json` is the only formatter that goes
     * through {@see \Qualimetrix\Reporting\Formatter\Json\JsonFindingSection::sort()},
     * and the baseline file is laid out by
     * {@see \Qualimetrix\Analysis\Policy\Baseline\BaselineEntryOrder}. Under the
     * the observed six-name rename set, no other corpus surface moves a record
     * because none of their keys is the channel code. Checkstyle and the verbose text report
     * group by file; the default text report and the summary's top issues sort
     * by severity/impact; SARIF, GitLab Code Quality and the GitHub Actions
     * annotations format publish `$report->findings` in the rule engine's
     * execution order, which a rename does not reorder. A surface that starts
     * ordering by channel later is not silently handled here: it stands as an
     * undeclared difference and the run goes red, which is the direction this
     * has to fail in.
     *
     * @var list<string>
     */
    public const SURFACES = ['format:json', 'baseline-file'];

    /**
     * The identity fields this class knows how to turn into key components.
     *
     * Held against {@see Fingerprints::INPUT_FIELDS} on every record, so the two
     * cannot drift apart in silence.
     *
     * @var list<string>
     */
    private const DECOMPOSED = ['channel', 'subject', 'occurrence', 'edge'];

    public static function handles(string $surfaceClass): bool
    {
        return \in_array($surfaceClass, self::SURFACES, true);
    }

    /**
     * Why this artifact is not in the order its own key gives, or `null`.
     *
     * Read on the RAW artifact of each side, in that side's own vocabulary,
     * before any map touches it. The reference is in the reference's order and
     * the candidate in the candidate's; neither is asserted against the other.
     */
    public static function disorder(string $surfaceClass, string $text): ?string
    {
        foreach (self::blocks($surfaceClass, $text) as $block) {
            $keys = $block['keys'];

            for ($i = 1, $n = \count($keys); $i < $n; ++$i) {
                if (self::compare($keys[$i - 1], $keys[$i]) > 0) {
                    return \sprintf(
                        'Record %d of %s does not follow record %d in the order the product sorts by'
                        . ' (%s before %s). Either the surface stopped publishing in the order of its own sort key,'
                        . ' or that key is no longer the identity projection the gate reconstructs; both are'
                        . ' product facts the gate must not sort away.',
                        $i + 1,
                        $block['label'],
                        $i,
                        self::describe($keys[$i - 1]),
                        self::describe($keys[$i]),
                    );
                }
            }
        }

        return null;
    }

    /**
     * The same artifact with each block of records back in its key's order.
     *
     * Textual: the records are permuted as byte spans and the text between them
     * is left exactly where it was, so a comparison that is byte-exact stays
     * byte-exact. Re-encoding the document would move every byte of it and turn
     * one question into another.
     */
    public static function reorder(string $surfaceClass, string $text): string
    {
        $blocks = self::blocks($surfaceClass, $text);

        // Back to front, so an earlier block's spans are still valid offsets
        // after a later block has been rewritten.
        foreach (array_reverse($blocks) as $block) {
            $spans = $block['elements'];

            if (\count($spans) < 2) {
                continue;
            }

            $keyed = [];

            foreach ($block['keys'] as $index => $key) {
                $keyed[] = ['key' => $key, 'index' => $index];
            }

            // Stable, as the product's own `usort` is under PHP 8: records whose
            // keys are equal keep the order they were published in.
            usort($keyed, static fn(array $a, array $b): int => self::compare($a['key'], $b['key']));

            $rebuilt = '';

            foreach ($keyed as $position => $item) {
                $source = $spans[$item['index']];
                $rebuilt .= substr($text, $source[0], $source[1]);

                if ($position < \count($keyed) - 1) {
                    $target = $spans[$position];
                    $rebuilt .= substr(
                        $text,
                        $target[0] + $target[1],
                        $spans[$position + 1][0] - ($target[0] + $target[1]),
                    );
                }
            }

            $start = $spans[0][0];
            $end = $spans[\count($spans) - 1][0] + $spans[\count($spans) - 1][1];
            $text = substr($text, 0, $start) . $rebuilt . substr($text, $end);
        }

        return $text;
    }

    /**
     * The record blocks of one artifact: for `format:json` the `violations`
     * array, and for the baseline file the entry list under each subject key,
     * which is where {@see \Qualimetrix\Analysis\Policy\Baseline\BaselineWriter}
     * sorts. Subject keys themselves are not touched: they are sorted by
     * `ksort` and no rename in this vocabulary moves one.
     *
     * @return list<array{label: string, elements: list<array{0: int, 1: int}>, keys: list<list<mixed>|string>}>
     */
    private static function blocks(string $surfaceClass, string $text): array
    {
        if ($surfaceClass === 'format:json') {
            return self::jsonBlocks($text);
        }

        if ($surfaceClass !== 'baseline-file') {
            return [];
        }

        $open = self::valueBracket($text, '"entries"');

        if ($open === null) {
            return [];
        }

        $blocks = [];

        foreach (self::elements($text, $open) as $member) {
            $memberText = substr($text, $member[0], $member[1]);
            $colon = self::memberColon($memberText);

            if ($colon === null) {
                continue;
            }

            $valueAt = $member[0] + $colon + 1;

            while (($text[$valueAt] ?? '') !== '' && strpos(" \t\r\n", $text[$valueAt]) !== false) {
                ++$valueAt;
            }

            if (($text[$valueAt] ?? '') !== '[') {
                continue;
            }

            $label = 'the entries of ' . trim(substr($memberText, 0, $colon));
            $spans = self::elements($text, $valueAt);
            $blocks[] = [
                'label' => $label,
                'elements' => $spans,
                'keys' => array_map(
                    static fn(array $span): array|string => self::identityKey(
                        'baseline-file',
                        self::record($text, $span, $label),
                        $label,
                    ),
                    $spans,
                ),
            ];
        }

        return $blocks;
    }

    /**
     * The two blocks of a JSON report: the findings themselves, and the
     * per-rule tally beside them.
     *
     * `violationsMeta.byRule` is a projection of the same order and moves with
     * it. {@see \Qualimetrix\Reporting\Formatter\Json\JsonFindingSection::countByRule()}
     * counts over the sorted findings and `arsort`s the tally, and `arsort` is
     * stable, so two rules with an equal count are published in the order their
     * findings appear — which a rename moves exactly as it moves the findings.
     * Measured 2026-09-08: with the findings put back in order and the tally left
     * alone, `case:complexity|format:json` still differed on
     * `"complexity.ccn": 4` and `"complexity.cognitive": 4` swapping places.
     *
     * @return list<array{label: string, elements: list<array{0: int, 1: int}>, keys: list<list<mixed>|string>}>
     */
    private static function jsonBlocks(string $text): array
    {
        $open = self::valueBracket($text, '"violations"');

        if ($open === null) {
            return [];
        }

        $label = 'the violations section';
        $spans = self::elements($text, $open);
        $records = array_map(static fn(array $span): array => self::record($text, $span, $label), $spans);
        $keys = array_map(
            static fn(array $record): array|string => self::identityKey('format:json', $record, $label),
            $records,
        );

        $blocks = [['label' => $label, 'elements' => $spans, 'keys' => $keys]];
        $tally = self::valueBracket($text, '"byRule"');

        if ($tally === null) {
            return $blocks;
        }

        $tallyLabel = 'the per-rule tally';
        $tallySpans = self::elements($text, $tally);
        $ranks = self::ruleRanks($records, $keys, $text);
        $blocks[] = [
            'label' => $tallyLabel,
            'elements' => $tallySpans,
            'keys' => array_map(
                static fn(array $span): array => self::tallyKey($text, $span, $ranks, $tallyLabel),
                $tallySpans,
            ),
        ];

        return $blocks;
    }

    /**
     * Each rule name against the position of its first finding once the findings
     * are in key order — the tie-break `arsort` inherits from the order the
     * tally was built in.
     *
     * A truncated `violations` section is refused rather than ranked: the tally
     * counts every finding while the section shows a prefix, so a rule the
     * prefix does not reach has no position to be ranked by, and inventing one
     * would put the tally in an order the product never publishes.
     *
     * @param list<array<string, mixed>> $records
     * @param list<list<mixed>|string> $keys
     *
     * @return array<string, int>
     */
    private static function ruleRanks(array $records, array $keys, string $text): array
    {
        if (str_contains($text, '"truncated": true') || str_contains($text, '"truncated":true')) {
            throw new GateError(
                'The JSON surface truncated its findings, so the per-rule tally cannot be put in the order the'
                . ' product publishes it in. Add --format-opt=violations=all to the case arguments.',
            );
        }

        $order = array_keys($keys);
        usort($order, static fn(int $a, int $b): int => self::compare($keys[$a], $keys[$b]));

        $ranks = [];

        foreach ($order as $position => $index) {
            $rule = $records[$index]['rule'] ?? null;

            if (\is_string($rule) && !isset($ranks[$rule])) {
                $ranks[$rule] = $position;
            }
        }

        return $ranks;
    }

    /**
     * One tally member's key: the count descending, then the rank of the rule's
     * first finding — which is what a stable `arsort` over a sorted list leaves.
     *
     * @param array<string, int> $ranks
     * @param array{0: int, 1: int} $span
     *
     * @return array{0: int, 1: int}
     */
    private static function tallyKey(string $text, array $span, array $ranks, string $label): array
    {
        $member = substr($text, $span[0], $span[1]);
        $decoded = json_decode('{' . $member . '}', true);

        if (!\is_array($decoded) || \count($decoded) !== 1) {
            throw new GateError(\sprintf('A member of %s does not parse as JSON.', $label));
        }

        $rule = array_key_first($decoded);
        $count = $decoded[$rule];

        if (!\is_string($rule) || !\is_int($count) || !isset($ranks[$rule])) {
            throw new GateError(\sprintf(
                '%s tallies "%s", which no published finding carries, so the tally cannot be put in the order the'
                . ' product publishes it in.',
                $label,
                \is_string($rule) ? $rule : get_debug_type($rule),
            ));
        }

        return [-$count, $ranks[$rule]];
    }

    /**
     * @param array{0: int, 1: int} $span
     *
     * @return array<string, mixed>
     */
    private static function record(string $text, array $span, string $label): array
    {
        $decoded = json_decode(substr($text, $span[0], $span[1]), true);

        if (!\is_array($decoded)) {
            throw new GateError(\sprintf('A record of %s does not parse as JSON.', $label));
        }

        return $decoded;
    }

    /**
     * The offset of the `[` or `{` that opens the value of a top-level member.
     *
     * `"violations"` is matched with its quotes and followed to its colon, so
     * the neighbouring `"violationsMeta"` — which a prefix search would find
     * first on some documents — cannot be taken for it.
     */
    private static function valueBracket(string $text, string $quotedName): ?int
    {
        $at = strpos($text, $quotedName . ':');

        if ($at === false) {
            $at = strpos($text, $quotedName . ' :');
        }

        if ($at === false) {
            return null;
        }

        $cursor = (int) strpos($text, ':', $at) + 1;

        while (($text[$cursor] ?? '') !== '' && strpos(" \t\r\n", $text[$cursor]) !== false) {
            ++$cursor;
        }

        return \in_array($text[$cursor] ?? '', ['[', '{'], true) ? $cursor : null;
    }

    /** The offset of the colon separating an object member's name from its value. */
    private static function memberColon(string $member): ?int
    {
        $inString = false;

        for ($i = 0, $n = \strlen($member); $i < $n; ++$i) {
            $char = $member[$i];

            if ($inString) {
                if ($char === '\\') {
                    ++$i;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === ':') {
                return $i;
            }
        }

        return null;
    }

    /**
     * The byte span of every element of the array or object opening at `$open`,
     * trimmed of the whitespace around it.
     *
     * String literals are skipped as literals rather than scanned for brackets:
     * a finding's `message` and `recommendation` carry `{`, `[`, `"` and `\\`
     * freely, and a depth counter that did not know that would cut a record in
     * the middle of a sentence.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function elements(string $text, int $open): array
    {
        $closer = $text[$open] === '[' ? ']' : '}';
        $depth = 0;
        $inString = false;
        $spans = [];
        $start = null;

        for ($i = $open, $n = \strlen($text); $i < $n; ++$i) {
            $char = $text[$i];

            if ($inString) {
                if ($char === '\\') {
                    ++$i;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                if ($depth === 1 && $start === null) {
                    $start = $i;
                }

                $inString = true;

                continue;
            }

            if (\in_array($char, ['[', '{'], true)) {
                ++$depth;

                if ($depth === 2 && $start === null) {
                    $start = $i;
                }

                continue;
            }

            if (\in_array($char, [']', '}'], true)) {
                --$depth;

                if ($depth === 0) {
                    if ($char !== $closer) {
                        throw new GateError('A findings artifact does not parse: mismatched brackets.');
                    }

                    if ($start !== null) {
                        $spans[] = [$start, self::trimmedEnd($text, $start, $i) - $start];
                    }

                    return $spans;
                }

                continue;
            }

            if ($depth === 1 && $char === ',') {
                if ($start !== null) {
                    $spans[] = [$start, self::trimmedEnd($text, $start, $i) - $start];
                    $start = null;
                }

                continue;
            }

            if ($depth === 1 && $start === null && strpos(" \t\r\n", $char) === false) {
                $start = $i;
            }
        }

        throw new GateError('A findings artifact does not parse: an unterminated array.');
    }

    private static function trimmedEnd(string $text, int $start, int $end): int
    {
        while ($end > $start && strpos(" \t\r\n", $text[$end - 1]) !== false) {
            --$end;
        }

        return $end;
    }

    /**
     * One record's sort key, in the shape the surface's producer uses.
     *
     * `format:json` reproduces
     * {@see \Qualimetrix\Reporting\Formatter\Json\JsonFindingSection::identitySortKey()}:
     * a tuple compared with `<=>`, keeping PHP's own comparison rather than a
     * string one, because that is the comparison the product performs.
     *
     * The baseline reproduces
     * {@see \Qualimetrix\Analysis\Policy\Baseline\BaselineEntryOrder::forComponents()}:
     * one string, compared with `strcmp`, and the `0` prefix that keeps entries
     * with a channel ahead of the ones whose channel could not be read. An entry
     * without a readable channel has no key here at all — the gate refuses
     * rather than guessing which of the producer's two branches laid it out, and
     * a corpus baseline has never carried one.
     *
     * @param array<string, mixed> $decoded
     *
     * @return list<mixed>|string
     */
    private static function identityKey(string $surfaceClass, array $decoded, string $label): array|string
    {
        // The field set belongs to the published identity rather than to this
        // class: `INPUT_FIELDS` is that identity, in that order, and
        // checkTuple() already holds it against the tracked equivalence tuple.
        // A field added there that has no decomposition here is a refusal, not a
        // component silently left out of the key.
        foreach (self::identityFields() as $field) {
            if (!\in_array($field, self::DECOMPOSED, true)) {
                throw new GateError(\sprintf(
                    'The published identity gained the field "%s", and the gate does not know where it sits in the'
                    . ' order the product publishes records in. Teach %s before the surface is compared: a key'
                    . ' missing a component silently accepts a move the product performs.',
                    $field,
                    self::class,
                ));
            }
        }

        $channel = $decoded['channel'] ?? null;

        if (!\is_string($channel)) {
            throw new GateError(\sprintf('A record of %s publishes no channel to sort on.', $label));
        }

        $subject = $decoded['subject'] ?? null;
        $occurrence = $decoded['occurrence'] ?? null;
        $edge = $decoded['edge'] ?? null;
        $subject = \is_string($subject) ? $subject : '';
        $occurrence = \is_string($occurrence) ? $occurrence : '';
        $type = \is_array($edge) && \is_string($edge['type'] ?? null) ? $edge['type'] : '';
        $target = \is_array($edge) && \is_string($edge['target'] ?? null) ? $edge['target'] : '';

        if ($surfaceClass === 'baseline-file') {
            return '0' . $channel . "\x1F" . $occurrence . "\x1F"
                . (\is_array($edge) ? $target . '|' . $type : '');
        }

        return [$channel, $subject, $occurrence, \is_array($edge) ? 1 : 0, $type, $target];
    }

    /**
     * The published identity's fields, as a plain list of names.
     *
     * Read through a method so that the guard above is a guard: the constant is
     * a literal list, and reading it directly would let the check be folded away
     * as one that can never fire — which is exactly the check that has to fire
     * the day the identity grows a field.
     *
     * @return list<string>
     */
    private static function identityFields(): array
    {
        return Fingerprints::INPUT_FIELDS;
    }

    /**
     * @param list<mixed>|string $left
     * @param list<mixed>|string $right
     */
    private static function compare(array|string $left, array|string $right): int
    {
        if (\is_string($left) && \is_string($right)) {
            return strcmp($left, $right);
        }

        return $left <=> $right;
    }

    /** @param list<mixed>|string $key */
    private static function describe(array|string $key): string
    {
        return \is_string($key)
            ? '"' . str_replace("\x1F", '/', $key) . '"'
            : '"' . (\is_string($key[0]) ? $key[0] : '') . '"';
    }
}
