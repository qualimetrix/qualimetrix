<?php

declare(strict_types=1);

namespace QmxFindingGate;

use WeakMap;
use WeakReference;

/** Exact value measurements shared by comparison and derivation of one run. */
final class ValueCheck implements RunCheck
{
    /** @var WeakMap<RunContext,WeakReference<self>>|null */
    private static ?WeakMap $runs = null;
    /** @var array<string,list<string>> */
    private array $rows = [];
    private bool $deriving = false;

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

    public function measure(string $kind, string $key, string $subject, string $level, mixed $from, mixed $to): bool
    {
        if ($from === $to && $kind !== DeclaredValues::ORDER) {
            return false;
        }
        $licensed = false;
        foreach ($this->run->declarations->values->intents() as $intent) {
            if ($intent['kind'] === $kind && $intent['key'] === $key && \in_array($intent['level'], ['*', $level], true)) {
                $licensed = true;
                break;
            }
        }
        if (!$licensed) {
            $this->run->report->fail(FailureClass::VALUE_MISMATCH, $subject, 'An exact published value moved outside its declared kind, key or level: ' . $kind . ' / ' . $key);
            return false;
        }
        $row = [$kind, $key, $subject, self::value($from), self::value($to)];
        $identity = implode("\0", [$kind, $key, $subject]);
        if (isset($this->rows[$identity]) && $this->rows[$identity] !== $row) {
            throw new GateError('One exact value subject measured two different transitions.');
        }
        $this->rows[$identity] = $row;
        $this->run->declarations->values->credit($kind, $key);
        return true;
    }

    public static function value(mixed $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
    }

    public function referenceExitFor(string $command, string $invocation, string $candidate): ?string
    {
        $row = $this->rows[implode("\0", [DeclaredValues::EXIT, $command, $invocation])] ?? null;
        if ($row === null || $row[4] !== $candidate
            || (!$this->deriving && $this->rendered() !== $this->run->declarations->values->derivedText())) {
            return null;
        }
        $reference = json_decode($row[3], true, flags: \JSON_THROW_ON_ERROR);
        return \is_int($reference) ? (string) $reference : null;
    }

    /**
     * @param array<string,string> $candidate
     * @param array<string,string> $reference
     */
    public function checkRun(array $candidate, array $reference): void
    {
        if ($this->deriving) {
            foreach ($this->run->declarations->values->stale() as $stale) {
                $this->run->report->fail(FailureClass::VALUE_STALE, $stale['scope'], $stale['detail'] . ' measured no value transition.');
            }
        }
        if (!$this->deriving && $this->rendered() !== $this->run->declarations->values->derivedText()
            && ($this->rows !== [] || $this->run->declarations->values->derivedText() !== '')) {
            $this->run->report->fail(FailureClass::VALUE_MISMATCH, DeclaredValues::DERIVED, 'The measured value multiset does not equal the exact derived value table.');
        }
    }

    private function rendered(): string
    {
        $intents = array_map(static fn(array $row): string => $row['kind'] . "\t" . $row['key'], $this->run->declarations->values->intents());
        return DerivedTable::render(DeclaredValues::DERIVED_COLUMNS, ['kind', 'key'], $intents, array_values($this->rows));
    }

    /** @return list<string> */
    public function rewriteDerived(): array
    {
        if (!$this->run->report->canDerive([FailureClass::VALUE_MISMATCH, FailureClass::VALUE_STALE,
            FailureClass::RECORD_PROJECTION_MISMATCH, FailureClass::NONDETERMINISM_UNDECLARED, FailureClass::PATH_LEAK])) {
            return [];
        }
        return DerivedTable::write($this->run->options->candidateRoot . '/finding-gate', DeclaredValues::DERIVED, $this->rendered(), \count($this->rows));
    }
}
