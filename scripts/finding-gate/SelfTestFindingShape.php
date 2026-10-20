<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The shape of a published finding and the exact values of declared fields.
 *
 * @phpstan-import-type Witness from CheckWitnesses
 */
final class SelfTestFindingShape extends SelfTestGroup
{
    public function tuple(): void
    {
        $derived = EquivalenceTuple::derive($this->candidateRoot);
        $tracked = EquivalenceTuple::load($this->candidateRoot);
        $this->assert($derived->fields !== [], 'the tuple derivation reads fields out of the publishing code');
        $this->assert($tracked->equals($derived), 'the tracked tuple is what the publishing code publishes');
        $this->tupleProvenanceOnLoad();
    }

    /**
     * The `source` column named a deleted publisher for a whole step, and every
     * run stayed green, because nothing resolved it. Written as a probe on a
     * fabricated tree rather than on the tracked file, so the refusal is proved
     * without a rename having to happen first.
     */
    private function tupleProvenanceOnLoad(): void
    {
        $root = Fs::temporaryDirectory('self-test-tuple-');
        $write = static function (string $source) use ($root): void {
            Fs::write(
                $root . '/' . EquivalenceTuple::TRACKED_PATH,
                Tsv::render(EquivalenceTuple::COLUMNS, [['channel', $source]]),
            );
        };
        Fs::write($root . '/src/Publisher.php', '<?php class Publisher { private function formatFinding(): array {} }');

        $write('src/Publisher.php::formatFinding');
        $this->assert(
            !self::throws(static fn(): mixed => EquivalenceTuple::load($root)),
            'a tuple whose source names a file and a method that exist loads',
        );

        $write('src/Gone.php::formatFinding');
        $this->assert(
            self::throws(static fn(): mixed => EquivalenceTuple::load($root)),
            'a tuple whose source names a file the tree no longer has is refused',
        );

        $write('src/Publisher.php::formatViolation');
        $this->assert(
            self::throws(static fn(): mixed => EquivalenceTuple::load($root)),
            'a tuple whose source names a method the publisher no longer declares is refused',
        );

        $write('src/Publisher.php');
        $this->assert(
            self::throws(static fn(): mixed => EquivalenceTuple::load($root)),
            'a source cell that is not "<file>::<method>" is refused rather than read as a caption',
        );

        Fs::removeRecursively($root);
    }

    /**
     * @return list<Witness>
     */
    public static function fieldValuesWitnesses(): array
    {
        return [CheckWitnesses::witness(
            'field-values-publication',
            CheckWitnesses::WHOLE_RUN,
            static function (array $tree): array {
                $record = ['file' => 'src/Alpha.php', 'line' => 1, 'form' => 'symbol', 'target' => 'replay.alpha', 'effect' => 'applied', 'reason' => 'replayed', 'masked_by' => null, 'boundary_observable' => true, 'refusals' => []];
                $base = json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
                $record['probe'] = 1;
                $tree['candidateAnswers']['case:alpha|directives'] = ['stdout' => json_encode(['directives' => [$record], 'exit_code' => 0], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n"];
                $tree['declarations'][DeclaredFields::INDEX] = Tsv::render(DeclaredFields::COLUMNS, [['added', 'directives', 'directives', 'probe', 'a new observed directive field']]);
                $tree['declarations'][DeclaredFields::DERIVED] = Tsv::render(DeclaredFields::DERIVED_COLUMNS, [['directives', 'directives', 'probe', 'alpha', $base, '2']]);
                return $tree;
            },
            [[FailureClass::FIELD_VALUES_MISMATCH, DeclaredFields::DERIVED]],
        )];
    }

}
