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

        $files = glob(\dirname(__DIR__, 2) . '/finding-gate/*.php');

        foreach ($files === false ? [] : $files as $file) {
            Fs::write($scripts . '/finding-gate/' . basename($file), Fs::read($file));
        }
        Fs::write(
            $scripts . '/subprocess/GitProcessEnvironment.php',
            Fs::read(\dirname(__DIR__, 2) . '/subprocess/GitProcessEnvironment.php'),
        );

        Fs::write($scripts . '/finding-gate/RecordProbe.php', "<?php\n\nnamespace QmxFindingGate;\n\nfinal class RecordProbe {}\n");
        Fs::write(
            $scripts . '/finding-gate/wiring-records.php',
            "<?php\n\nreturn ['classes' => ['RecordProbe']];\n",
        );

        $loaded = Process::run([
            \PHP_BINARY,
            '-r',
            'require $argv[1] . "/finding-gate/classes.php";'
            . ' echo json_encode(class_exists("QmxFindingGate\\RecordProbe", false));',
            $scripts,
        ], $scripts);

        self::assertSame(0, $loaded['exit'], $loaded['stderr']);
        self::assertSame('true', $loaded['stdout']);
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
        Fs::write(Wiring::fileOf($this->directory, 'maps'), "<?php\n\nreturn ['witnesses' => ['MapProbe::stale']];\n");
        Fs::write(Wiring::fileOf($this->directory, 'records'), "<?php\n\nreturn ['witnesses' => ['RecordProbe::withdrawn']];\n");

        self::assertSame(
            [['Probe\\RecordProbe', 'withdrawn'], ['Probe\\MapProbe', 'stale']],
            Wiring::of($this->directory)->methods('witnesses', 'Probe'),
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
    public function itRefusesTheRetiredPendingKeyEvenWhenItIsEmpty(): void
    {
        foreach ([[], [FailureClass::CORPUS_INVALID => ['pending: outcomes', 'why']]] as $entries) {
            Fs::write(
                Wiring::fileOf($this->directory, 'outcomes'),
                "<?php\n\nreturn ['pending' => " . var_export($entries, true) . "];\n",
            );

            $this->assertRefused('unknown key(s): pending');
        }
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
