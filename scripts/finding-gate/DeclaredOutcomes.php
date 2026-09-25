<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Cases whose outcome a step changed: the reference analyses a case the
 * candidate now refuses, or — only for a case the reference tree does not
 * have — the other way round.
 *
 * The declaration is narrow on purpose. A row names the case, the transition
 * and a file under `declared-outcomes/` holding the refusal's normalized output
 * as a derive run measured it — standard error, and standard output per format,
 * JSON envelope included — which a run compares byte for byte, so a refusal for
 * another reason is not declared by this one. The case's other surfaces stay
 * under the ordinary forms. A row whose transition the run did not observe is
 * stale.
 */
final class DeclaredOutcomes
{
    public const array COLUMNS = ['case', 'transition', 'file', 'reason'];

    public const string INDEX = 'declared-outcomes.tsv';

    public const string DIRECTORY = 'declared-outcomes';

    public const string ANALYSIS_TO_REFUSAL = 'analysis->refusal';

    public const string REFUSAL_TO_ANALYSIS = 'refusal->analysis';

    /** @var array<string, true> case => credited */
    private array $credited = [];

    /** @param array<string, array{transition: string, file: string, output: string, reason: string}> $rows case => row */
    private function __construct(private readonly array $rows) {}

    public static function load(string $root): self
    {
        $rows = [];

        foreach (DeclarationTable::rows($root, self::INDEX, self::COLUMNS) as $index => $row) {
            DeclarationTable::oneOf(
                self::INDEX,
                $index + 1,
                'transition',
                $row['transition'],
                [self::ANALYSIS_TO_REFUSAL, self::REFUSAL_TO_ANALYSIS],
            );

            if (isset($rows[$row['case']])) {
                throw new GateError(\sprintf('%s row %d declares the outcome of "%s" a second time.', self::INDEX, $index + 1, $row['case']));
            }

            if (!str_starts_with($row['file'], self::DIRECTORY . '/') || str_contains($row['file'], '..')) {
                throw new GateError(\sprintf('%s row %d names "%s", which is not a file under %s/.', self::INDEX, $index + 1, $row['file'], self::DIRECTORY));
            }

            $path = $root . '/' . $row['file'];
            $output = is_file($path) ? Fs::read($path) : '';

            if ($output === '') {
                throw new GateError(\sprintf(
                    '%s row %d names %s, which is missing or empty, so it declares no refusal output to compare.',
                    self::INDEX,
                    $index + 1,
                    $row['file'],
                ));
            }

            $rows[$row['case']] = ['transition' => $row['transition'], 'file' => $row['file'], 'output' => $output, 'reason' => $row['reason']];
        }

        return new self($rows);
    }

    public function count(): int
    {
        return \count($this->rows);
    }

    /** @return array{transition: string, file: string, output: string, reason: string}|null */
    public function of(string $case): ?array
    {
        return $this->rows[$case] ?? null;
    }

    /** Records that the run observed the declared transition of this case. */
    public function credit(string $case): void
    {
        $this->credited[$case] = true;
    }

    /** @return list<array{scope: string, detail: string}> */
    public function stale(): array
    {
        $stale = [];

        foreach ($this->rows as $case => $row) {
            if (!isset($this->credited[$case])) {
                $stale[] = ['scope' => 'case:' . $case, 'detail' => \sprintf('The transition %s', $row['transition'])];
            }
        }

        return $stale;
    }
}
