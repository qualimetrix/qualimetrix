<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The cases the gate runs.
 *
 * The corpus always comes from the candidate tree: only product code may differ
 * between the two sides, so a reference-side corpus would move the gate's input
 * with the very step it measures.
 */
final class Corpus
{
    /** @param list<CaseDefinition> $cases */
    private function __construct(public readonly array $cases) {}

    /** @param list<string> $only */
    public static function load(string $candidateRoot, array $only = []): self
    {
        $root = $candidateRoot . '/finding-gate/cases';
        $entries = @scandir($root);

        if ($entries === false) {
            throw new GateError(\sprintf('No corpus at %s.', $root));
        }

        $cases = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_file($root . '/' . $entry . '/case.json')) {
                continue;
            }

            if ($only !== [] && !\in_array($entry, $only, true)) {
                continue;
            }

            $cases[] = CaseDefinition::load($root . '/' . $entry);
        }

        if ($cases === []) {
            throw new GateError(\sprintf('No case selected under %s.', $root));
        }

        return new self($cases);
    }
}
