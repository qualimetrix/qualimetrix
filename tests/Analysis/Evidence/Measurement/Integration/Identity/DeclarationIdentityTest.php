<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Integration\Identity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a declaration key survives, and what it deliberately does not.
 *
 * These run the real executable over a written project, because the property
 * under test is the key a user's baseline stores, not the value one producer
 * computed.
 */
final class DeclarationIdentityTest extends TestCase
{
    private const string CONFIG = <<<'YAML'
        onlyRules: ['complexity.cyclomatic']
        rules:
          complexity.cyclomatic:
            callable:
              warning: 1
              error: 1
            class:
              enabled: false
        YAML;

    private const string EVERY_RULE = <<<'YAML'
        rules: {}
        YAML;

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-identity-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/qmx.yaml', self::CONFIG);
    }

    protected function tearDown(): void
    {
        self::removeRecursively($this->root);
    }

    private static function removeRecursively(string $path): void
    {
        if (is_dir($path)) {
            $entries = scandir($path);
            foreach ($entries === false ? [] : $entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeRecursively($path . '/' . $entry);
                }
            }
            rmdir($path);

            return;
        }

        if (is_file($path)) {
            unlink($path);
        }
    }

    #[Test]
    public function itDiscriminatesTwoDeclarationsOfOneLogicalIdentityByOrdinal(): void
    {
        $this->write('DupClass.php', self::duplicateClass());

        self::assertSame([
            'declaration:callable:Fixture\Dup\Greeter::greet@src/DupClass.php',
            'declaration:callable:Fixture\Dup\Greeter::greet@src/DupClass.php#1',
        ], $this->subjects());
    }

    #[Test]
    public function itKeepsEveryKeyWhenTextAboveTheDeclarationsChanges(): void
    {
        $this->write('DupClass.php', self::duplicateClass());
        $before = $this->subjects();

        $this->write('DupClass.php', str_replace("<?php\n", "<?php\n\n", self::duplicateClass()));

        self::assertSame($before, $this->subjects());
    }

    #[Test]
    public function itKeepsAnAnonymousClassMemberKeyWhenTextAboveItChanges(): void
    {
        $this->write('Anon.php', self::anonymousClass());
        $before = $this->subjects();

        $this->write('Anon.php', str_replace("<?php\n", "<?php\n\n", self::anonymousClass()));

        self::assertSame($before, $this->subjects());
        self::assertContains('declaration:callable:Fixture\Anon\{anonymous#0}::hidden@src/Anon.php', $before);
    }

    #[Test]
    public function itRenumbersAnAnonymousClassWhenAnotherIsDeclaredAboveIt(): void
    {
        $this->write('Anon.php', self::anonymousClass());
        $this->write('Anon.php', str_replace(
            '    public function make(): object',
            self::earlierAnonymousClass() . '    public function make(): object',
            self::anonymousClass(),
        ));

        self::assertContains('declaration:callable:Fixture\Anon\{anonymous#1}::hidden@src/Anon.php', $this->subjects());
    }

    #[Test]
    public function itNumbersEachFileOnItsOwnAcrossWorkerCounts(): void
    {
        $this->write('First.php', self::sharedIdentity('First'));
        $this->write('Second.php', self::sharedIdentity('Second'));

        $sequential = $this->subjects(0);

        self::assertSame([
            'declaration:callable:Fixture\Shared\Greeter::greet@src/First.php',
            'declaration:callable:Fixture\Shared\Greeter::greet@src/Second.php',
        ], $sequential);
        self::assertSame($sequential, $this->subjects(2));
    }

    #[Test]
    public function itAssignsTheSameNonZeroOrdinalInAWorker(): void
    {
        $this->write('DupClass.php', self::duplicateClass());

        $sequential = $this->subjects(0);

        self::assertContains('declaration:callable:Fixture\Dup\Greeter::greet@src/DupClass.php#1', $sequential);
        self::assertSame($sequential, $this->subjects(2));
    }

    /**
     * Runs the whole rule set, not the single rule the other cases pin.
     *
     * A file with two braced namespaces used to kill the run with
     * `Required metric "classCount.sum" not found in MetricBag` — from a global
     * collector, which is why the crash needs neither a wide rule set nor a
     * class inside the braces to reproduce (checked against 372d8315: the two
     * function-only files below already kill it under the single-rule config).
     * The wide run is not what catches that one; it is what puts every other
     * rule on these namespace forms, and the smelly file below is what keeps
     * "wide" from quietly becoming a claim about a config that narrowed.
     */
    #[Test]
    public function itAnalysesIrregularNamespaceFormsUnderEveryRule(): void
    {
        $this->write('Braced.php', <<<'PHP'
            <?php

            namespace Fixture\Braced {
                function braced(int $a): int
                {
                    return $a > 1 ? 1 : 2;
                }
            }

            namespace Fixture\Other {
                function other(int $a): int
                {
                    return $a > 1 ? 1 : 2;
                }
            }
            PHP);
        $this->write('BracedClasses.php', <<<'PHP'
            <?php

            namespace Fixture\BracedFirst {
                class First
                {
                    public function first(int $a): int
                    {
                        return $a > 1 ? 1 : 2;
                    }
                }
            }

            namespace Fixture\BracedSecond {
                interface Second
                {
                }
            }
            PHP);
        $this->write('BracedGlobal.php', <<<'PHP'
            <?php

            namespace {
                class GlobalClass
                {
                    public function act(int $a): int
                    {
                        return $a > 1 ? 1 : 2;
                    }
                }
            }

            namespace Fixture\Empty_ {
            }
            PHP);
        $this->write('Global.php', <<<'PHP'
            <?php

            function globalFunction(int $a): int
            {
                return $a > 1 ? 1 : 2;
            }
            PHP);

        $this->write('Smelly.php', <<<'PHP'
            <?php

            namespace Fixture\Smelly {
                class Smelly
                {
                    public function show(int $a): void
                    {
                        var_dump($a);
                        $this->toggle(true);
                    }

                    public function toggle(bool $flag): void
                    {
                        if ($flag) {
                            echo 'on';
                        }
                    }
                }
            }
            PHP);

        $report = $this->report(config: self::EVERY_RULE);
        $rules = array_unique(array_map(
            static fn(array $finding): string => (string) $finding['rule'],
            $report['violations'],
        ));

        self::assertSame(0, $report['coverage']['failed'], (string) json_encode($report['coverage']));
        self::assertSame(5, $report['coverage']['analyzed']);
        // Both fire inside a braced namespace and neither is reachable under
        // the single-rule config the other cases use.
        self::assertContains('code-smell.debug-code', $rules);
        self::assertContains('code-smell.boolean-argument', $rules);
    }

    #[Test]
    public function itKeepsARealAcceptedFindingAcceptedWhenTextAboveItChanges(): void
    {
        $file = 'src/Analysis/Evidence/Cohesion/LcomOptions.php';
        $repository = \dirname(__DIR__, 6);
        // Default thresholds, because the ceiling under test was recorded under them.
        unlink($this->root . '/qmx.yaml');
        mkdir($this->root . '/src/Analysis/Evidence/Cohesion', 0o777, true);
        copy($repository . '/' . $file, $this->root . '/' . $file);
        copy($repository . '/qmx-baseline.json', $this->root . '/qmx-baseline.json');

        self::assertSame(0, $this->ratchet(), 'The accepted finding must start out accepted');

        file_put_contents(
            $this->root . '/' . $file,
            str_replace("<?php\n", "<?php\n\n", (string) file_get_contents($this->root . '/' . $file)),
        );

        self::assertSame(0, $this->ratchet(), 'A blank line above the declaration must not unaccept its finding');
    }

    private function ratchet(): int
    {
        $command = \sprintf(
            'cd %s && %s %s check src --only-rule=complexity.cyclomatic --baseline=qmx-baseline.json'
                . ' --fail-on=warning --no-progress --workers=0 > /dev/null 2>&1; echo $?',
            escapeshellarg($this->root),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 6) . '/bin/qmx'),
        );

        return (int) trim((string) shell_exec($command));
    }

    /** @return list<string> */
    private function subjects(int $workers = 0): array
    {
        $subjects = array_map(
            static fn(array $finding): string => (string) $finding['subject'],
            $this->report($workers)['violations'],
        );
        sort($subjects);

        return $subjects;
    }

    /** @return array<string, mixed> */
    private function report(int $workers = 0, string $config = self::CONFIG): array
    {
        file_put_contents($this->root . '/qmx.yaml', $config);
        $command = \sprintf(
            'cd %s && %s %s check src --config=qmx.yaml --format=json --no-progress --workers=%d 2>/dev/null',
            escapeshellarg($this->root),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 6) . '/bin/qmx'),
            $workers,
        );
        $output = shell_exec($command);
        self::assertIsString($output, 'The analysis produced no output');
        $report = json_decode($output, true);
        self::assertIsArray($report, $output);

        return $report;
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->root . '/src/' . $name, $contents);
    }

    private static function duplicateClass(): string
    {
        return <<<'PHP'
            <?php

            namespace Fixture\Dup;

            if (\PHP_VERSION_ID > 80000) {
                class Greeter
                {
                    public function greet(int $a): int
                    {
                        return $a > 1 ? 1 : 2;
                    }
                }
            } else {
                class Greeter
                {
                    public function greet(int $a): int
                    {
                        return $a < 1 ? 1 : 2;
                    }
                }
            }
            PHP;
    }

    private static function anonymousClass(): string
    {
        return <<<'PHP'
            <?php

            namespace Fixture\Anon;

            final class Factory
            {
                public function make(): object
                {
                    return new class {
                        public function hidden(int $a): int
                        {
                            return $a > 1 ? 1 : 2;
                        }
                    };
                }
            }
            PHP;
    }

    private static function earlierAnonymousClass(): string
    {
        return <<<'PHP'
                public function earlier(): object
                {
                    return new class {
                        public function noop(int $a): int
                        {
                            return $a > 1 ? 1 : 2;
                        }
                    };
                }

            PHP;
    }

    private static function sharedIdentity(string $file): string
    {
        return <<<PHP
            <?php

            namespace Fixture\\Shared;

            class Greeter
            {
                public function greet(int \$a): int
                {
                    return \$a > 1 ? 1 : 2; // {$file}
                }
            }
            PHP;
    }
}
