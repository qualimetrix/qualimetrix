<?php

declare(strict_types=1);

namespace QmxFindingGate;

use WeakMap;
use WeakReference;

/** Exact added-field observations across every declared report publication. */
final class FieldValuesCheck implements RunCheck, Derivation
{
    /** @var WeakMap<RunContext,WeakReference<self>>|null */
    private static ?WeakMap $runs = null;

    /** @var list<list<string>> */
    private array $rows = [];

    private bool $deriving = false;

    private bool $measured = false;

    private function __construct(private readonly RunContext $run) {}

    public static function create(RunContext $run): static
    {
        self::$runs ??= new WeakMap();
        $check = isset(self::$runs[$run]) ? self::$runs[$run]->get() : null;
        if ($check instanceof self) {
            return $check;
        }
        $check = new self($run);
        self::$runs[$run] = WeakReference::create($check);
        return $check;
    }

    public function startDeriving(): void
    {
        $this->deriving = true;
    }

    public function trialCopy(RunContext $trial): self
    {
        $copy = self::create($trial);
        $copy->deriving = $this->deriving;
        return $copy;
    }

    public function checkRun(array $candidate, array $reference): void
    {
        $this->run->publicationForms->supply('candidate', $candidate);
        $this->run->publicationForms->supply('reference', $reference);
        $fields = $this->run->declarations->fields;
        $this->rows = [];
        $problems = [];
        foreach ($fields->views('json-document') as $view) {
            foreach ($this->run->corpus->cases as $case) {
                $invocation = null;
                foreach ($this->run->capturePlan->invocations() as $descriptor) {
                    if ($descriptor['scope'] === 'case:' . $case->id && ($descriptor['surface'] === $view || $descriptor['outputFileKind'] === $view)) {
                        $invocation = Surfaces::key($descriptor['scope'], $descriptor['surface']);
                        break;
                    }
                }
                if ($invocation === null || !$this->run->publicationForms->recordsExpected($invocation)) {
                    continue;
                }
                foreach (['candidate' => $candidate, 'reference' => $reference] as $side => $artifacts) {
                    if (!CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, CaseOutcome::of($case, $side))
                        || !$this->run->capturePlan->requiredOn($invocation, $side)) {
                        continue;
                    }
                    $text = $artifacts['case:' . $case->id . '|' . $view] ?? null;
                    if ($text === null) {
                        throw new GateError('A declared document member has no captured report.');
                    }
                    $document = ReportRecords::object($this->run->normalization->normalize($view, $text));
                    $fields->supply('json-document', $case->id, $view, $side, [['record' => '$', 'fields' => $document]]);
                }
            }
        }
        foreach (DeclaredFields::REPORTS as $report) {
            foreach ($fields->measurements($report, $this->run->publicationForms) as $publication) {
                $view = $publication['view'];
                if (!$this->run->publicationForms->schemaPair($publication['case'], $view)) {
                    continue;
                }
                $valid = true;
                foreach ($fields->changes($report, $view) as $field => $change) {
                    $present = ($change === DeclaredFields::ADDED) === ($publication['side'] === 'candidate');
                    foreach ($publication['records'] as $record) {
                        $hasField = \array_key_exists($field, $record['fields']);
                        if ($hasField !== $present) {
                            $valid = false;
                            $problems[] = $report . '/' . $view . '/' . $publication['case'] . '/' . $publication['side'] . ' publishes the wrong presence of ' . $field;
                            continue;
                        }
                        if (!$hasField) {
                            continue;
                        }
                        $fields->credit($report, $view, $field);
                        if ($change === DeclaredFields::ADDED) {
                            $this->rows[] = [$report, $view, $field, $publication['case'], $record['record'], self::value($record['fields'][$field])];
                        }
                    }
                }
                $this->run->report->sourceEvidence($publication['side'], 'case:' . $publication['case'] . '|' . $view, 'schema', $valid);
            }
        }
        $this->measured = true;
        if (!$this->deriving && ($this->rows !== [] || $fields->derivedText() !== '') && $this->rendered() !== $fields->derivedText()) {
            $problems[] = 'The measured field value multiset differs from the complete derived table.';
        }
        if ($problems !== []) {
            $this->run->report->fail(
                FailureClass::FIELD_VALUES_MISMATCH,
                DeclaredFields::DERIVED,
                implode("\n", $problems),
            );
        }
    }

    private function rendered(): string
    {
        $fields = $this->run->declarations->fields;
        $intents = [];
        foreach (DeclaredFields::REPORTS as $report) {
            foreach ($fields->views($report) as $view) {
                foreach ($fields->changes($report, $view) as $field => $change) {
                    if ($change === DeclaredFields::ADDED) {
                        $intents[] = $report . "\t" . $view . "\t" . $field;
                    }
                }
            }
        }
        return DerivedTable::render(DeclaredFields::DERIVED_COLUMNS, ['report', 'view', 'field'], $intents, $this->rows);
    }

    private static function value(mixed $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
    }

    public function rewriteDerived(): array
    {
        if (!$this->measured) {
            throw new GateError('Field values cannot be written before every required publication was measured.');
        }
        if (!$this->run->report->canDerive([FailureClass::FIELD_VALUES_MISMATCH, FailureClass::RECORD_PROJECTION_MISMATCH,
            FailureClass::NONDETERMINISM_UNDECLARED, FailureClass::PATH_LEAK]) || $this->run->declarations->fields->stale() !== []) {
            return [];
        }
        return DerivedTable::write($this->run->options->candidateRoot . '/finding-gate', DeclaredFields::DERIVED, $this->rendered(), \count($this->rows));
    }
}
