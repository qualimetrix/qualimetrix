<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CheckWitnesses;
use QmxFindingGate\FailureClass;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\Process;
use QmxFindingGate\Wiring;

/**
 * Each declaration form registers itself in its own wiring file, and every
 * place that reads the forms reads all of them.
 */
final class WiringTest extends TestCase
{
    private string $directory;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->directory = Fs::temporaryDirectory('wiring-test-');

        foreach (array_keys(Wiring::FILES) as $form) {
            Fs::write(Wiring::fileOf($this->directory, $form), "<?php\n\nreturn [];\n");
        }
    }

    protected function tearDown(): void
    {
        Fs::removeRecursively($this->directory);
    }

    #[Test]
    public function itLoadsAClassAFormListsInItsOwnFile(): void
    {
        $class = 'WiringProbe' . bin2hex(random_bytes(4));
        Fs::write(
            $this->directory . '/' . $class . '.php',
            "<?php\n\nnamespace QmxFindingGate;\n\nfinal class " . $class . " {}\n",
        );
        Fs::write(Wiring::fileOf($this->directory, 'records'), "<?php\n\nreturn ['classes' => ['" . $class . "']];\n");

        self::assertFalse(class_exists('QmxFindingGate\\' . $class, false));

        Wiring::of($this->directory)->load();

        self::assertTrue(class_exists('QmxFindingGate\\' . $class, false));
        self::assertSame([$class], Wiring::of($this->directory)->list('classes'));
    }

    #[Test]
    public function itLoadsAFormsClassesThroughTheLoadersEveryEntryPointRequires(): void
    {
        $scripts = $this->directory . '/scripts';

        foreach (['finding-gate', 'finding-gate-controls'] as $directory) {
            $files = glob(\dirname(__DIR__, 2) . '/' . $directory . '/*.php');

            foreach ($files === false ? [] : $files as $file) {
                Fs::write($scripts . '/' . $directory . '/' . basename($file), Fs::read($file));
            }
        }

        Fs::write($scripts . '/finding-gate/RecordProbe.php', "<?php\n\nnamespace QmxFindingGate;\n\nfinal class RecordProbe {}\n");
        Fs::write($scripts . '/finding-gate-controls/RecordProbeControls.php', "<?php\n\nnamespace QmxFindingGateControls;\n\nfinal class RecordProbeControls {}\n");
        Fs::write(
            $scripts . '/finding-gate/wiring-records.php',
            "<?php\n\nreturn ['classes' => ['RecordProbe'], 'controlClasses' => ['RecordProbeControls']];\n",
        );

        $loaded = Process::run([
            \PHP_BINARY,
            '-r',
            'require $argv[1] . "/finding-gate/classes.php"; require $argv[1] . "/finding-gate-controls/classes.php";'
            . ' echo json_encode([class_exists("QmxFindingGate\\RecordProbe", false),'
            . ' class_exists("QmxFindingGateControls\\RecordProbeControls", false)]);',
            $scripts,
        ], $scripts);

        self::assertSame(0, $loaded['exit'], $loaded['stderr']);
        self::assertSame('[true,true]', $loaded['stdout']);
    }

    #[Test]
    public function itRefusesAWitnessOfAnUnknownScenario(): void
    {
        $class = 'WitnessProbe' . bin2hex(random_bytes(4));
        Fs::write(
            $this->directory . '/' . $class . '.php',
            "<?php\n\nnamespace QmxFindingGate;\n\nfinal class " . $class . " {\n    public static function all(): array\n    {\n"
            . "        return [CheckWitnesses::witness('probe', 'nowhere', static fn(array \$tree): array => \$tree, [])];\n    }\n}\n",
        );
        Fs::write(
            Wiring::fileOf($this->directory, 'records'),
            "<?php\n\nreturn ['classes' => ['" . $class . "'], 'witnesses' => ['" . $class . "::all']];\n",
        );
        $wiring = Wiring::of($this->directory);
        $wiring->load();

        $this->expectException(GateError::class);
        $this->expectExceptionMessage('no scenario of CheckWitnesses::MODES');

        CheckWitnesses::registered($wiring);
    }

    #[Test]
    public function itReadsEveryFormsEntriesInTheOrderOfTheFixedList(): void
    {
        Fs::write(Wiring::fileOf($this->directory, 'maps'), "<?php\n\nreturn ['controls' => ['MapControls::stale']];\n");
        Fs::write(Wiring::fileOf($this->directory, 'records'), "<?php\n\nreturn ['controls' => ['RecordControls::withdrawn']];\n");

        self::assertSame(
            [['Probe\\RecordControls', 'withdrawn'], ['Probe\\MapControls', 'stale']],
            Wiring::of($this->directory)->methods('controls', 'Probe'),
        );
    }

    #[Test]
    public function itRefusesAWiringFileNoFormOwns(): void
    {
        Fs::write($this->directory . '/wiring-extra.php', "<?php\n\nreturn [];\n");

        $this->assertRefused('no form owns');
    }

    #[Test]
    public function itRefusesAFormWhoseFileIsMissing(): void
    {
        Fs::removeRecursively(Wiring::fileOf($this->directory, 'tuple'));

        $this->assertRefused('does not exist');
    }

    #[Test]
    public function itRefusesAnUnknownKey(): void
    {
        Fs::write(Wiring::fileOf($this->directory, 'capture'), "<?php\n\nreturn ['checks' => []];\n");

        $this->assertRefused('unknown key(s): checks');
    }

    #[Test]
    public function itRefusesARegisteredCheckItDoesNotLoad(): void
    {
        Fs::write(Wiring::fileOf($this->directory, 'capture'), "<?php\n\nreturn ['runChecks' => ['CaptureCheck']];\n");

        $this->assertRefused('without listing it under "classes"');
    }

    #[Test]
    public function itRefusesAPendingRowOfAnotherPackage(): void
    {
        Fs::write(
            Wiring::fileOf($this->directory, 'maps'),
            "<?php\n\nreturn ['pending' => ['" . FailureClass::MAP_STALE . "' => ['pending: S01b/P2', 'why']]];\n",
        );

        $this->assertRefused('excuses only its own package');
    }

    #[Test]
    public function itHoldsThePendingRowsOfEveryForm(): void
    {
        Fs::write(
            Wiring::fileOf($this->directory, 'outcomes'),
            "<?php\n\nreturn ['pending' => ['" . FailureClass::CORPUS_INVALID . "' => ['pending: S01b/P5', 'why']]];\n",
        );

        self::assertSame(
            [FailureClass::CORPUS_INVALID => ['pending: S01b/P5', 'why']],
            Wiring::of($this->directory)->pending,
        );
    }

    private function assertRefused(string $reason): void
    {
        try {
            Wiring::of($this->directory);
        } catch (GateError $error) {
            self::assertStringContainsString($reason, $error->getMessage());

            return;
        }

        self::fail('The wiring was accepted.');
    }
}
