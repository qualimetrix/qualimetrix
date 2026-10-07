<?php

declare(strict_types=1);

namespace QmxFindingGate;

use JsonException;

/**
 * Surfaces a step introduced or withdrew, which makes the list of compared
 * surfaces a property of each side.
 *
 * The candidate's list is the gate's own; the reference's is that list minus
 * the introduced surfaces plus the withdrawn ones. A withdrawn surface is still
 * requested from both sides in its named cases with the command that used to produce it: the
 * reference produces it, and the candidate has to refuse — the removal becomes
 * something the run observes rather than a line deleted from a list. The row
 * of a withdrawn surface names a file under `declared-surfaces/` holding that
 * refusal's normalized JSON envelope (`stdout`, `stderr`, string `exit`) as a
 * derive run measured it, compared byte for
 * byte in every named case, so a refusal for another reason is not the declared one;
 * an introduced surface names none (`-`). A row whose surface was not
 * introduced or withdrawn as declared is stale.
 */
final class DeclaredSurfaces
{
    public const array COLUMNS = ['change', 'surface', 'file', 'cases', 'reason'];

    public const string INDEX = 'declared-surfaces.tsv';

    public const string INTRODUCED = 'introduced';

    public const string WITHDRAWN = 'withdrawn';

    public const string DIRECTORY = 'declared-surfaces';

    public const string NO_FILE = '-';

    /** @var array<string, true> */
    private array $credited = [];

    /**
     * @param array<string, string> $changes surface => introduced|withdrawn
     * @param array<string, string> $refusals withdrawn surface => the declared refusal output
     * @param array<string, list<string>|null> $cases surface => exact case names, or all cases
     */
    private function __construct(
        private readonly array $changes,
        private readonly array $refusals,
        private readonly array $cases,
    ) {}

    public static function load(string $root): self
    {
        $changes = [];
        $refusals = [];
        $cases = [];

        foreach (DeclarationTable::rows($root, self::INDEX, self::COLUMNS) as $index => $row) {
            DeclarationTable::oneOf(self::INDEX, $index + 1, 'change', $row['change'], [self::INTRODUCED, self::WITHDRAWN]);

            if (str_contains($row['surface'], '|')) {
                throw new GateError(\sprintf(
                    '%s row %d names "%s", an artifact of one scope. A surface is declared for every case at once, by'
                    . ' its class (e.g. format:text-verbose).',
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

            if ($row['change'] === self::INTRODUCED) {
                if ($row['file'] !== self::NO_FILE) {
                    throw new GateError(\sprintf(
                        '%s row %d names a refusal file for the introduced surface "%s"; an introduced surface is refused'
                        . ' by nothing, so its file is "%s".',
                        self::INDEX,
                        $index + 1,
                        $row['surface'],
                        self::NO_FILE,
                    ));
                }

                continue;
            }

            if (!str_starts_with($row['file'], self::DIRECTORY . '/') || str_contains($row['file'], '..')) {
                throw new GateError(\sprintf('%s row %d names "%s", which is not a file under %s/.', self::INDEX, $index + 1, $row['file'], self::DIRECTORY));
            }

            $path = $root . '/' . $row['file'];
            $refusal = is_file($path) ? Fs::read($path) : '';

            if ($refusal === '') {
                throw new GateError(\sprintf(
                    '%s row %d names %s, which is missing or empty, so it declares no refusal the candidate could be'
                    . ' held to.',
                    self::INDEX,
                    $index + 1,
                    $row['file'],
                ));
            }

            $refusals[$row['surface']] = $refusal;
        }

        return new self($changes, $refusals, $cases);
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

    /**
     * The surfaces the reference is asked for, given the candidate's.
     *
     * @param list<string> $candidateSurfaces
     *
     * @return list<string>
     */
    public function referenceSurfaces(array $candidateSurfaces): array
    {
        foreach ($this->changes as $surface => $change) {
            $listed = \in_array($surface, $candidateSurfaces, true);

            if ($change === self::INTRODUCED ? !$listed : $listed) {
                throw new GateError(\sprintf(
                    '%s declares "%s" %s, and the candidate\'s list %s it.',
                    self::INDEX,
                    $surface,
                    $change,
                    $listed ? 'still has' : 'does not have',
                ));
            }
        }

        $surfaces = [];

        foreach ($candidateSurfaces as $surface) {
            if (($this->changes[$surface] ?? null) !== self::INTRODUCED) {
                $surfaces[] = $surface;
            }
        }

        foreach ($this->changes as $surface => $change) {
            if ($change === self::WITHDRAWN) {
                $surfaces[] = $surface;
            }
        }

        return $surfaces;
    }

    /** @return array<string, string> surface => introduced|withdrawn */
    public function changes(): array
    {
        return $this->changes;
    }

    /** The declared refusal output of a withdrawn surface, or null. */
    public function refusalOf(string $surface): ?string
    {
        return $this->refusals[$surface] ?? null;
    }

    /** Records that the run observed this surface introduced or withdrawn as declared. */
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
