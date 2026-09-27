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
        $outcomes = DeclaredOutcomes::load($candidateRoot . '/finding-gate');
        try {
            $directories = self::directories($candidateRoot);
        } catch (GateError $error) {
            throw new CorpusInvalid($error->getMessage(), 0, $error);
        }

        $selected = $only === [] ? $directories : array_values(array_filter(
            $directories,
            static fn(string $directory): bool => \in_array(basename($directory), $only, true),
        ));
        $root = $candidateRoot . '/finding-gate/cases';
        if ($selected === []) {
            throw new GateError(\sprintf('No case selected under %s.', $root));
        }
        $unmatched = array_values(array_diff($only, array_map(basename(...), $directories)));
        if ($unmatched !== []) {
            throw new GateError(\sprintf('--cases names no case under %s: %s.', $root, implode(', ', $unmatched)));
        }

        try {
            return self::loadCases($selected, $outcomes);
        } catch (GateError $error) {
            throw new CorpusInvalid($error->getMessage(), 0, $error);
        }
    }

    /** @return list<string> */
    private static function directories(string $candidateRoot): array
    {
        $root = $candidateRoot . '/finding-gate/cases';
        $entries = @scandir($root);
        if ($entries === false) {
            throw new GateError(\sprintf('No corpus at %s.', $root));
        }
        $directories = [];
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($root . '/' . $entry . '/case.json')) {
                $directories[] = $root . '/' . $entry;
            }
        }
        if ($directories === []) {
            throw new GateError(\sprintf('No case selected under %s.', $root));
        }
        return $directories;
    }

    /** @param list<string> $directories */
    private static function loadCases(array $directories, DeclaredOutcomes $outcomes): self
    {
        $cases = [];
        foreach ($directories as $directory) {
            $cases[] = CaseDefinition::load($directory, $outcomes->of(basename($directory))['transition'] ?? null);
        }
        return new self($cases);
    }
}
