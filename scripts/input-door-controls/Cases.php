<?php

declare(strict_types=1);

/**
 * The planted breakages of the input-door oracle, and what each must redden.
 *
 * A case is a triple: what to break, where the break must show, and what it
 * must show there. The point of the table is not that the stand goes red — a
 * blanket breakage does that — but that it goes red **in exactly its own
 * place**, which is what the run below checks by comparing the whole outcome
 * map against the green baseline.
 */

namespace Qualimetrix\InputDoorControls;

final class ControlCase
{
    public const string ENGINE_RECLASSIFY = 'reclassify';
    public const string ENGINE_LOADER = 'loader';
    public const string ENGINE_GENERATOR = 'generator';

    /**
     * @param array<string, mixed> $planting engine-specific description of the breakage
     * @param array<string, string> $expected grid key -> outcome the breakage must produce
     */
    public function __construct(
        public readonly string $id,
        public readonly string $engine,
        public readonly string $description,
        public readonly array $planting,
        public readonly array $expected = [],
        public readonly string $expectedMessage = '',
        /**
         * Whether the breakage must move only the door it names. A planting
         * that edits one declaration must; a planting that flips a classifier
         * knob or edits a fixture is global by construction, and its blast
         * radius is reported rather than gated — pretending otherwise would
         * force the case table to enumerate collateral it does not control.
         */
        public readonly bool $strict = true,
    ) {}
}

final class Cases
{
    /** @return list<ControlCase> */
    public static function all(): array
    {
        return [
            // --- classifier: the false-green direction ---------------------
            new ControlCase(
                'K1',
                ControlCase::ENGINE_RECLASSIFY,
                'the classifier falls back to a text diff where no signal is declared',
                ['options' => ['textDiffFallback' => true], 'edits' => [['cli|--namespace|whole-value', 'signal', 'none'], ['cli|--namespace|whole-value', 'reason', 'planted']]],
                ['cli|check|--namespace|whole-value' => 'SPEAKS'],
                strict: false,
            ),
            new ControlCase(
                'K2',
                ControlCase::ENGINE_RECLASSIFY,
                'echo excision is switched off, so the echoed value alone makes a miss look answered',
                ['options' => ['echoExcision' => false, 'textDiffFallback' => true], 'edits' => [['cli|--class|whole-value', 'signal', 'none'], ['cli|--class|whole-value', 'reason', 'planted']]],
                ['cli|check|--class|whole-value' => 'SPEAKS'],
                strict: false,
            ),
            new ControlCase(
                'K2b',
                ControlCase::ENGINE_RECLASSIFY,
                'normalization is switched off, so a timestamp alone makes a miss look answered',
                ['normalization' => false, 'options' => ['textDiffFallback' => true], 'edits' => [['cli|--exclude|whole-value', 'signal', 'none']]],
                ['cli|check|--exclude|whole-value' => 'SPEAKS'],
                strict: false,
            ),
            new ControlCase(
                'K5',
                ControlCase::ENGINE_RECLASSIFY,
                'the H side of a probe was never taken',
                ['drop' => ['cli|check|--namespace|whole-value' => 'H']],
                ['cli|check|--namespace|whole-value' => 'INCOMPLETE TRIPLE'],
            ),
            new ControlCase(
                'K10',
                ControlCase::ENGINE_RECLASSIFY,
                'the signal is written so that it fires on the hit as well',
                ['edits' => [['cli|--namespace|whole-value', 'signal', 'stdout:~violations~']]],
                ['cli|check|--namespace|whole-value' => 'SIGNAL NOT SPECIFIC'],
            ),
            new ControlCase(
                'K13',
                ControlCase::ENGINE_RECLASSIFY,
                'a finding signal is declared over an observable that carries no findings',
                ['edits' => [['cli|--namespace|whole-value', 'signal', 'finding:complexity.ccn@summary']]],
                ['cli|check|--namespace|whole-value' => 'SIGNAL/OBSERVABLE MISMATCH'],
            ),
            new ControlCase(
                'K14',
                ControlCase::ENGINE_RECLASSIFY,
                'a non-text door is judged by the text rule',
                ['edits' => [['cli|--cache-dir|whole-value', 'signal', 'stdout:~cache~']]],
                ['cli|check|--cache-dir|whole-value' => 'SIGNAL/OBSERVABLE MISMATCH'],
            ),
            new ControlCase(
                'K18',
                ControlCase::ENGINE_RECLASSIFY,
                'the signal is written from the text of a miss on the "more than intended" axis, where the miss equals the baseline',
                ['edits' => [['cli|--exclude|whole-value', 'signal', 'stdout:~violations~']]],
                ['cli|check|--exclude|whole-value' => 'SIGNAL NOT SPECIFIC'],
            ),
            new ControlCase(
                'K20',
                ControlCase::ENGINE_RECLASSIFY,
                'observability is checked before refusal, so a door that demonstrably refuses is lost',
                ['options' => ['refusalBeforeObservability' => false]],
                ['cli|check|--memory-limit|whole-value' => 'NOT OBSERVABLE'],
                strict: false,
            ),
            new ControlCase(
                'K26',
                ControlCase::ENGINE_RECLASSIFY,
                'the empty-hit side is dropped from the specificity check, so a neutral addition passes as a signal',
                [
                    'options' => ['specificitySides' => ['H', 'A']],
                    'edits' => [['cli|--namespace|whole-value', 'signal', 'stdout:~"techDebtMinutes": 0~']],
                ],
                ['cli|check|--namespace|whole-value' => 'SPEAKS'],
                strict: false,
            ),

            // --- freeze: the shot must answer its own declaration ----------
            new ControlCase(
                'K16',
                ControlCase::ENGINE_RECLASSIFY,
                'a probe is answered by no frozen observation, which is what a probe added after the freeze looks like',
                ['unfreeze' => ['cli|check|--group-by|whole-value']],
                ['cli|check|--group-by|whole-value' => 'NO BEFORE'],
            ),
            new ControlCase(
                'K21',
                ControlCase::ENGINE_RECLASSIFY,
                'the miss value is edited while the row key stays the same',
                ['edits' => [['cli|--namespace|whole-value', 'miss', 'Fixture\\OtherGhost']]],
                ['cli|check|--namespace|whole-value' => 'BEFORE UNDER OTHER DECLARATION'],
            ),
            new ControlCase(
                'K22',
                ControlCase::ENGINE_RECLASSIFY,
                'a fixture file is edited while the probe row stays the same',
                ['fixture' => ['main', 'src/Quiet/QuietClass.php']],
                ['cli|check|--namespace|whole-value' => 'BEFORE UNDER OTHER DECLARATION'],
                strict: false,
            ),

            // --- declarations that accept an incomplete claim --------------
            new ControlCase('K3', ControlCase::ENGINE_RECLASSIFY, 'a probe row is deleted', ['remove' => ['cli|--namespace|whole-value']], ['cli|check|--namespace|whole-value' => 'NOT PROBED']),
            new ControlCase('K4', ControlCase::ENGINE_RECLASSIFY, 'a probe names a row the grid does not carry', ['append' => ['cli', '--stand-invented-door', 'whole-value']], ['stand|(none)|cli|--stand-invented-door|whole-value|(none)' => 'STALE PROBE']),
            new ControlCase('K7', ControlCase::ENGINE_GENERATOR, 'referential=no without a reason', ['annotation' => ['cli', '*', '--baseline', 'no', '', '', '', '']], expectedMessage: 'referential=no without a reason'),
            new ControlCase('K7b', ControlCase::ENGINE_GENERATOR, 'an annotation row on a door reflection no longer sees', ['annotation' => ['cli', '*', '--departed-door', 'no', 'gone', '', '', '']], expectedMessage: 'STALE ANNOTATION'),
            new ControlCase('K11', ControlCase::ENGINE_LOADER, 'signal=none without a reason', ['edits' => [['cli|--exclude|whole-value', 'reason', '']]], expectedMessage: 'signal=none without a reason'),
            new ControlCase('K12', ControlCase::ENGINE_GENERATOR, 'a door the measurement calls split declares one site', ['annotation' => ['cli', '*', '--rule-opt', 'yes', '', 'whole-value', 'one site', '']], expectedMessage: 'SITES UNDERDECLARED'),
            new ControlCase('K24', ControlCase::ENGINE_RECLASSIFY, 'the echo claim is inverted, which is what a broken substitution order looks like', ['options' => ['echoClaimChecked' => true], 'edits' => [['cli|--config|whole-value', 'echoes', 'no']]], ['cli|check|--config|whole-value' => 'ECHO NOT FOUND'], strict: false),

            // --- the denominator ------------------------------------------
            new ControlCase('K8', ControlCase::ENGINE_GENERATOR, 'the generator reads ENTRIES alone and loses the document roots', ['entries_only' => true], expectedMessage: 'DOCUMENT_ROOTS must be read alongside ENTRIES'),
            new ControlCase('K15', ControlCase::ENGINE_RECLASSIFY, 'a cure row has no probe on its own grid key', ['remove' => ['config|exclude|whole-value']], ['config|check|exclude|whole-value' => 'NOT PROBED']),
            new ControlCase('K25', ControlCase::ENGINE_LOADER, 'a cure row names a grid row that does not exist', ['cure' => ['config', 'check', 'nosuch-root', 'whole-value']], expectedMessage: 'STALE CURE SITE'),
            new ControlCase('K17', ControlCase::ENGINE_LOADER, 'a configuration door is not multiplied over the commands', ['grid_placeholder' => true], expectedMessage: 'STALE CURE SITE'),

            // --- the run environment --------------------------------------
            new ControlCase('K9', ControlCase::ENGINE_LOADER, 'the supplement overrides a gate-owned surface without a reason', ['supplement' => ['format:json', 'meta.timestamp', 'json-path', 'yes', 'because']], expectedMessage: 'gate-owned surface'),
            new ControlCase(
                'K19',
                ControlCase::ENGINE_RECLASSIFY,
                'a cure row declares a signal that the pre-cure product already fired',
                [
                    'cure_check' => 'cli|debug:layer-assignment|fqn|whole-value',
                    'edits' => [['cli|fqn|whole-value', 'signal', 'stdout:~no layer~']],
                ],
                ['cli|debug:layer-assignment|fqn|whole-value' => 'SPEAKS'],
            ),
        ];
    }
}
