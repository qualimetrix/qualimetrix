<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Surfaces as the comparison sees them: their classes and the order their records are published in.
 */
final class SelfTestSurfaces extends SelfTestGroup
{
    /**
     * The reordering step, on the two shapes it handles and the three claims it
     * has to keep: a no-op under an identity map, a refusal when a side is not
     * in its own key's order, and a permutation that moves records rather than
     * re-encoding the document.
     */
    public function publishedOrder(): void
    {
        $finding = static fn(string $channel, string $message): string => <<<JSON
                {
                        "file": "src/A.php",
                        "subject": "declaration:class:Corpus\\\\A@src/A.php",
                        "channel": "{$channel}",
                        "occurrence": null,
                        "edge": null,
                        "rule": "{$channel}",
                        "message": "{$message}"
                    }
            JSON;

        // Deliberately prose that carries the punctuation a bracket-counting
        // scanner would trip over: a record is a byte span, and finding its end
        // means knowing where a string literal ends.
        $hostile = 'A message with {braces}, [brackets], a \\"quote\\" and a trailing backslash-quote \\\\';

        $document = static fn(string $first, string $second, string $tally): string => <<<JSON
            {
                "violations": [
                    {$first},
                    {$second}
                ],
                "violationsMeta": {
                    "truncated": false,
                    "byRule": {
                        {$tally}
                    }
                }
            }
            JSON;

        $ordered = $document(
            $finding('complexity.ccn', $hostile),
            $finding('complexity.cognitive', 'Cognitive complexity is 6'),
            '"complexity.ccn": 1,' . "\n" . '                    "complexity.cognitive": 1',
        );

        $this->same(null, PublishedOrder::disorder('format:json', $ordered), 'a JSON report in its key order is accepted');
        $this->same(
            $ordered,
            PublishedOrder::reorder('format:json', $ordered),
            'reordering a JSON report already in its key order returns the same bytes — the identity-map no-op',
        );

        // Property 1, stated as the pipeline states it: under the identity map
        // the whole step — translate, then reorder — returns the reference's own
        // bytes. Nothing it does can weaken a run that renames nothing.
        $identity = RenameMaps::fromPairs([]);
        $this->same(
            $ordered,
            PublishedOrder::reorder('format:json', $identity->forward($ordered, 'format:json')),
            'under an identity map the reordering step returns the reference artifact unchanged',
        );
        // What the reference looks like once its names are translated in place:
        // the new vocabulary in the old order. `ccn` sorts before `cognitive`
        // and the tally follows the findings, so both blocks move.
        $translated = $document(
            $finding('complexity.cognitive', 'Cognitive complexity is 6'),
            $finding('complexity.ccn', $hostile),
            '"complexity.cognitive": 1,' . "\n" . '                    "complexity.ccn": 1',
        );

        $this->same(
            $ordered,
            PublishedOrder::reorder('format:json', $translated),
            'a translated reference is put back into the order its new names give, findings and tally alike',
        );
        $this->assert(
            PublishedOrder::disorder('format:json', $translated) !== null,
            'a JSON report out of its key order is refused rather than sorted in silence',
        );

        $entry = static fn(string $channel, int $magnitude): string
            => '{"channel":"' . $channel . '","magnitudes":[' . $magnitude . ']}';
        $baseline = static fn(string $first, string $second): string => <<<JSON
            {
                "version": 13,
                "entries": {
                    "declaration:class:Corpus\\\\A@src/A.php": [
                        {$first},
                        {$second}
                    ],
                    "declaration:class:Corpus\\\\B@src/B.php": [
                        {"channel":"design.dit","count":1}
                    ]
                }
            }
            JSON;

        $ccn = $entry('complexity.ccn', 18);
        $cognitive = $entry('complexity.cognitive', 29);
        $orderedBaseline = $baseline($ccn, $cognitive);

        $this->same(null, PublishedOrder::disorder('baseline-file', $orderedBaseline), 'a baseline in its key order is accepted');
        $this->same(
            $orderedBaseline,
            PublishedOrder::reorder('baseline-file', $identity->forward($orderedBaseline, 'baseline-file')),
            'under an identity map the reordering step returns the reference baseline unchanged',
        );
        $this->same(
            $orderedBaseline,
            PublishedOrder::reorder('baseline-file', $baseline($cognitive, $ccn)),
            'a baseline block is re-sorted within its subject key, and each magnitude travels with its own channel',
        );
        $this->assert(
            PublishedOrder::disorder('baseline-file', $baseline($cognitive, $ccn)) !== null,
            'a baseline out of its key order is refused rather than sorted in silence',
        );

        // The surface list is a claim about which producers order by identity,
        // and a surface outside it is left exactly as it is rather than being
        // quietly sorted by a key its producer never used.
        $this->assert(!PublishedOrder::handles('format:sarif'), 'a surface that does not order by identity is not handled');
        $this->same(
            Fingerprints::INPUT_FIELDS,
            ['channel', 'subject', 'occurrence', 'edge'],
            'the key reads the published identity, whose field set has one owner',
        );

        $this->assert(
            self::throws(static fn(): mixed => PublishedOrder::disorder('format:json', $document(
                $finding('complexity.ccn', 'a'),
                $finding('complexity.cognitive', 'b'),
                '"complexity.npath": 1',
            ))),
            'a tally naming a rule no published finding carries is refused rather than ranked',
        );
    }

    public function surfaces(): void
    {
        $this->same('format:json', Surfaces::surfaceClass('case:smells|format:json'), 'a format is its own surface class');
        $this->same('explain', Surfaces::surfaceClass('case:smells|explain:declaration:class:A@a.php'), 'every explained subject shares one surface class');
        $this->same('stderr', Surfaces::surfaceClass('case:smells|stderr:format:json'), 'every stream of errors shares one surface class');
        $this->same(\count(FailureClass::ALL), \count(array_unique(FailureClass::ALL)), 'the failure vocabulary has no duplicate');
    }
}
