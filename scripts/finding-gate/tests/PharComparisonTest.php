<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Declarations;
use QmxFindingGate\DeclaredDelta;
use QmxFindingGate\DeclaredFieldMoves;
use QmxFindingGate\DeclaredFields;
use QmxFindingGate\Fs;
use QmxFindingGate\MetricVocabulary;
use QmxFindingGate\Process;
use QmxFindingGate\RenameMaps;
use QmxFindingGate\Tsv;

final class PharComparisonTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itDoesNotApplyProductTransitionsToArchiveEquivalence(): void
    {
        $tree = Fs::temporaryDirectory('phar-comparison-test-');
        $gate = $tree . '/finding-gate';

        try {
            Fs::write($gate . '/declared-delta.tsv', Tsv::render(DeclaredDelta::COLUMNS, [['case:fixture|format:json', 'declared-delta/change.diff', 'Changed refusal wording.']]));
            Fs::write($gate . '/declared-delta/change.diff', "-old\n+new\n");
            Fs::write($gate . '/declared-field-moves.tsv', Tsv::render(DeclaredFieldMoves::COLUMNS, [['case:fixture|format:json', 'message', 'old', 'new', 'Changed refusal wording.']]));
            Fs::write($gate . '/declared-fields.tsv', Tsv::render(DeclaredFields::COLUMNS, [['added', 'json-document', 'format:json', 'configurationDiagnostics', 'Published configuration diagnostics.']]));
            Fs::write($gate . '/declared-fields.derived.tsv', Tsv::render(DeclaredFields::DERIVED_COLUMNS, [['json-document', 'format:json', 'configurationDiagnostics', 'fixture', '@document', '[]']]));

            foreach ([RenameMaps::CHANNELS, RenameMaps::SYMBOLS, RenameMaps::METRIC_KEYS, RenameMaps::INPUTS, RenameMaps::REPORT_VALUES] as $file) {
                Fs::write($gate . '/maps/' . $file, "old\tnew\treason\n");
            }

            Fs::write($gate . '/maps/symbols.tsv', "old\tnew\treason\nApp\\Before\tApp\\After\tRenamed the product symbol.\n");
            $before = Declarations::load($tree);
            self::assertSame(1, $before->delta->count());
            self::assertSame(1, $before->fieldMoves->count());
            self::assertSame(1, $before->fields->count());
            self::assertFalse(RenameMaps::load($gate . '/maps', MetricVocabulary::none())->isIdentity());
            $preserved = [
                'finding-gate/normalization.tsv' => "normalization remains fixed\n",
                'finding-gate/equivalence-tuple.tsv' => "configurationDiagnostics remains compared\n",
                'finding-gate/cases/fixture/case.json' => '{"name":"fixture"}',
                'src/Publisher.php' => '<?php echo "published";',
            ];

            foreach ($preserved as $path => $bytes) {
                Fs::write($tree . '/' . $path, $bytes);
            }

            $script = <<<'PYTHON'
                import importlib.util
                from pathlib import Path
                import sys
                spec = importlib.util.spec_from_file_location('phar_comparison', sys.argv[1])
                module = importlib.util.module_from_spec(spec)
                spec.loader.exec_module(module)
                module.prepare_identity_comparison(Path(sys.argv[2]))
                PYTHON;
            $run = Process::run(['python3', '-c', $script, \dirname(__DIR__, 2) . '/finding-gate-phar.py', $tree], __DIR__);
            self::assertSame(0, $run['exit'], $run['stderr']);
            $after = Declarations::load($tree);
            self::assertSame(0, $after->delta->count());
            self::assertSame(0, $after->fieldMoves->count());
            self::assertSame(array_fill_keys(array_keys($before->counts()), 0), $after->counts());
            self::assertTrue(RenameMaps::load($gate . '/maps', MetricVocabulary::none())->isIdentity());

            foreach ($preserved as $path => $bytes) {
                self::assertSame($bytes, Fs::read($tree . '/' . $path));
            }
        } finally {
            Fs::removeRecursively($tree);
        }
    }
}
