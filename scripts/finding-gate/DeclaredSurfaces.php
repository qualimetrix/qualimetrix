<?php

declare(strict_types=1);

namespace QmxFindingGate;

use JsonException;

/** Introduced JSON publications select their exact cases and omit reference capture. */
final class DeclaredSurfaces
{
    public const array COLUMNS = ['change', 'surface', 'file', 'cases', 'reason'];

    public const string INDEX = 'declared-surfaces.tsv';

    public const string INTRODUCED = 'introduced';

    public const string NO_FILE = '-';

    /** @var array<string, true> */
    private array $credited = [];

    /**
     * @param array<string, string> $changes surface => introduced
     * @param array<string, list<string>|null> $cases surface => exact case names, or all cases
     */
    private function __construct(
        private readonly array $changes,
        private readonly array $cases,
    ) {}

    public static function load(string $root): self
    {
        $changes = [];
        $cases = [];

        foreach (DeclarationTable::rows($root, self::INDEX, self::COLUMNS) as $index => $row) {
            DeclarationTable::oneOf(self::INDEX, $index + 1, 'change', $row['change'], [self::INTRODUCED]);

            if (str_contains($row['surface'], '|')) {
                throw new GateError(\sprintf(
                    '%s row %d names "%s", an artifact of one scope. A surface is declared for every case at once, by'
                    . ' its class (e.g. format:metrics).',
                    self::INDEX,
                    $index + 1,
                    $row['surface'],
                ));
            }

            if (isset($changes[$row['surface']])) {
                throw new GateError(\sprintf('%s row %d declares "%s" a second time.', self::INDEX, $index + 1, $row['surface']));
            }

            $changes[$row['surface']] = $row['change'];
            $cases[$row['surface']] = self::caseNames($root, $row['cases']);

            if ($row['file'] !== self::NO_FILE) {
                throw new GateError(\sprintf('%s row %d: an introduced surface names no file; use "%s".', self::INDEX, $index + 1, self::NO_FILE));
            }

        }

        return new self($changes, $cases);
    }

    /** A declaration applies only to its named cases; null is a tree invocation. */
    public function changeFor(string $surface, ?string $case): ?string
    {
        if (!isset($this->changes[$surface])) {
            return null;
        }
        $cases = $this->cases[$surface];

        return $cases === null || ($case !== null && \in_array($case, $cases, true))
            ? $this->changes[$surface]
            : null;
    }

    /** @return list<string>|null */
    private static function caseNames(string $root, string $text): ?array
    {
        if ($text === '*') {
            return null;
        }
        try {
            $cases = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new GateError(self::INDEX . ': cases must be * or a nonempty JSON list of case names.', 0, $error);
        }
        if (!\is_array($cases) || !array_is_list($cases) || $cases === []) {
            throw new GateError(self::INDEX . ': cases must be * or a nonempty JSON list of case names.');
        }
        $seen = [];
        foreach ($cases as $case) {
            if (!\is_string($case) || $case === '' || $case === '.' || $case === '..'
                || str_contains($case, '/') || str_contains($case, '\\')
                || isset($seen[$case]) || !is_file($root . '/cases/' . $case . '/case.json')) {
                throw new GateError(self::INDEX . ': cases contains an unknown, invalid or duplicate case name.');
            }
            $seen[$case] = true;
        }

        return $cases;
    }

    public function count(): int
    {
        return \count($this->changes);
    }

    /** @param list<string> $candidateSurfaces
     * @return list<string>
     */
    public function referenceSurfaces(array $candidateSurfaces): array
    {
        foreach ($this->changes as $surface => $change) {
            if (!\in_array($surface, $candidateSurfaces, true)) {
                throw new GateError(self::INDEX . ' declares "' . $surface . '" introduced, and the candidate\'s list does not have it.');
            }
        }
        return array_values(array_filter($candidateSurfaces, fn(string $surface): bool => !isset($this->changes[$surface])));
    }

    /** @return array<string, string> surface => introduced */
    public function changes(): array
    {
        return $this->changes;
    }

    /** Records that the run observed this surface introduced as declared. */
    public function credit(string $surface): void
    {
        $this->credited[$surface] = true;
    }

    /** @return list<array{scope: string, detail: string}> */
    public function stale(): array
    {
        $stale = [];

        foreach ($this->changes as $surface => $change) {
            if (!isset($this->credited[$surface])) {
                $stale[] = ['scope' => $surface, 'detail' => \sprintf('The %s surface', $change)];
            }
        }

        return $stale;
    }
}
