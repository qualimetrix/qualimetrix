<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Integration\Visitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\CallableToClassAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Visitor\VisitorLexicalScope;

/**
 * A closure's name says where it is, and naming it changes nothing about who
 * owns its measurements.
 *
 * Run through the real executable, because the collision guards of the
 * collection merge and the class and namespace roll-ups only meet there.
 */
#[CoversClass(VisitorLexicalScope::class)]
#[CoversClass(CallableToClassAggregator::class)]
final class AnonymousCallableIdentityTest extends TestCase
{
    private const string CONFIG = <<<'YAML'
        onlyRules: ['complexity.ccn']
        rules:
          complexity.ccn:
            callable:
              warning: 1
              error: 1
            class:
              enabled: false
        YAML;

    private const string SOURCE = <<<'PHP'
        <?php
        namespace App;

        final class A
        {
            public function rows(array $r): array
            {
                return array_map(static function ($x) { return $x > 1 ? 1 : 0; }, $r);
            }
        }

        final class B
        {
            public function f(): callable { return static fn($y) => $y > 1 ? 1 : 0; }
        }

        function top(): callable { return fn($z) => $z ? 1 : 2; }

        $x = fn($q) => $q ? 1 : 2;
        PHP;

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-closure-identity-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/qmx.yaml', self::CONFIG);
        file_put_contents($this->root . '/src/A.php', self::SOURCE);
    }

    protected function tearDown(): void
    {
        foreach (['/src/A.php', '/qmx.yaml'] as $file) {
            if (is_file($this->root . $file)) {
                unlink($this->root . $file);
            }
        }
        rmdir($this->root . '/src');
        rmdir($this->root);
    }

    #[Test]
    public function itStoresClosuresUnderTheirEnclosingNames(): void
    {
        $report = $this->analyse(['--format=json']);
        $subjects = array_map(static fn(array $finding): string => (string) $finding['subject'], $report['violations']);
        sort($subjects);

        self::assertSame(0, $report['coverage']['failed'], (string) json_encode($report['coverage']));
        self::assertSame([
            'declaration:callable:App\A::rows@src/A.php',
            'declaration:callable:App\B::f@src/A.php',
            'declaration:func:App::A::rows::{closure#1}@src/A.php',
            'declaration:func:App::B::f::{closure#1}@src/A.php',
            'declaration:func:App::top::{closure#1}@src/A.php',
            'declaration:func:App::top@src/A.php',
            'declaration:func:App::{closure#1}@src/A.php',
        ], $subjects);
    }

    #[Test]
    public function itRollsClosuresUpIntoTheNamespaceButNotIntoTheirClass(): void
    {
        $symbols = [];
        foreach ($this->analyse(['--format=metrics'])['symbols'] as $symbol) {
            $symbols[$symbol['type'] . ' ' . $symbol['name']] = $symbol['metrics'];
        }

        self::assertSame(2, $symbols['function App\A::rows::{closure#1}']['complexity.ccn']);
        self::assertSame(1, $symbols['class App\A']['complexity.wmc'], 'the closure must stay out of WMC');
        self::assertSame(1, $symbols['class App\A']['complexity.ccn.count'], 'the closure must stay out of the class roll-up');
        self::assertSame(7, $symbols['namespace App']['complexity.ccn.count'], 'every closure must reach the namespace');
        self::assertSame(11, $symbols['namespace App']['complexity.ccn.sum']);
    }

    #[Test]
    public function itLeavesClosuresOutOfAClassSelection(): void
    {
        $report = $this->analyse(['--format=json', '--class=App\A']);
        $subjects = array_map(static fn(array $finding): string => (string) $finding['subject'], $report['violations']);

        self::assertSame(['declaration:callable:App\A::rows@src/A.php'], $subjects);
    }

    /**
     * @param list<string> $options
     *
     * @return array<string, mixed>
     */
    private function analyse(array $options): array
    {
        $command = \sprintf(
            'cd %s && %s %s check src --config=qmx.yaml --no-progress --no-cache --workers=0 %s 2>/dev/null',
            escapeshellarg($this->root),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 6) . '/bin/qmx'),
            implode(' ', array_map('escapeshellarg', $options)),
        );
        $output = shell_exec($command);
        self::assertIsString($output, 'The analysis produced no output');
        $report = json_decode($output, true);
        self::assertIsArray($report, $output);

        return $report;
    }
}
