<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Fs;
use QmxFindingGate\RaiseSites;

/**
 * What the scan of the gate's source enumerates, and what it refuses to read.
 */
final class RaiseSitesTest extends TestCase
{
    private string $directory;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->directory = Fs::temporaryDirectory('raise-sites-test-');
    }

    protected function tearDown(): void
    {
        Fs::removeRecursively($this->directory);
    }

    #[Test]
    public function itNamesEverySiteOncePerCallerFromAnotherClass(): void
    {
        $this->write('Check.php', <<<'PHP'
            <?php

            final class Check
            {
                public function outer(): void
                {
                    $this->inner();
                }

                private function inner(): void
                {
                    $this->report->fail(FailureClass::RUN_FAILED, 'a', 'b');
                    $this->report
                        ->fail(FailureClass::PATH_LEAK, 'a', 'b');
                }

                public static function alone($report): void
                {
                    $report->fail(FailureClass::MAP_STALE, 'a', 'b');
                }
            }
            PHP);
        $this->write('Entry.php', <<<'PHP'
            <?php

            final class Entry
            {
                public function first(): void
                {
                    $this->check->outer();
                }

                public function second(): void
                {
                    $this->check->outer();
                    Check::alone($this->report);
                }
            }
            PHP);
        $this->write('SelfTestCheck.php', "<?php\n\$r->fail(FailureClass::RUN_FAILED, 'a', 'b');\n");
        $this->write('tests/CheckTest.php', "<?php\n\$r->fail(FailureClass::RUN_FAILED, 'a', 'b');\n");

        $read = RaiseSites::of($this->directory);

        self::assertSame([], $read->problems);
        self::assertSame(
            [
                'Check::alone <- Entry::second' => ['map-stale', 19],
                'Check::inner#1 <- Entry::first' => ['run-failed', 12],
                'Check::inner#1 <- Entry::second' => ['run-failed', 12],
                'Check::inner#2 <- Entry::first' => ['path-leak', 14],
                'Check::inner#2 <- Entry::second' => ['path-leak', 14],
            ],
            array_map(static fn(array $site): array => [$site['class'], $site['line']], $read->sites),
        );
        self::assertSame('Check::inner#1 <- Entry::first', $read->identityOf('Check::inner#1', ['Check::inner', 'Check::outer', 'Entry::first']));
        self::assertSame('Check::inner#1', $read->identityOf('Check::inner#1', ['Check::inner', 'Witness::run']));
    }

    #[Test]
    public function itScansSubdirectories(): void
    {
        $this->write('Nested/Deep.php', "<?php\nfinal class Deep\n{\n    public function x(): void\n    {\n        \$this->report->fail(FailureClass::RUN_FAILED, 'a', 'b');\n    }\n}\n");

        self::assertSame(['Deep::x'], array_keys(RaiseSites::of($this->directory)->sites));
    }

    #[Test]
    public function itRefusesEveryFormItCannotRead(): void
    {
        $this->write('Check.php', <<<'PHP'
            <?php

            final class Check
            {
                public function forms(string $class, string $name): void
                {
                    $this->report->fail($class, 'a', 'b');
                    \call_user_func([$this->report, 'fail'], FailureClass::RUN_FAILED, 'a', 'b');
                    $this->report->{$name}(FailureClass::RUN_FAILED, 'a', 'b');
                    $this->report->$name(FailureClass::RUN_FAILED, 'a', 'b');
                    $raise = $this->report->fail(...);
                    $this->report->{$name};
                }

                public function fail(): void {}
            }
            PHP);
        $this->write('GateReport.php', "<?php\nclass GateReport\n{\n    public function fail(): void {}\n}\n");

        $problems = RaiseSites::of($this->directory)->problems;
        $lines = array_map(static fn(string $problem): string => (string) preg_replace('~^.*?\.php:(\d+) (.*?), which.*$~', '$1 $2', $problem), $problems);

        self::assertSame(
            [
                '7 raises a failure whose class is not a FailureClass constant, or reaches fail() another way',
                '8 calls a callable',
                '8 names "fail" as a string',
                '9 calls a method whose name is a variable',
                '10 calls a method whose name is a variable',
                '11 raises a failure whose class is not a FailureClass constant, or reaches fail() another way',
                '15 declares a method fail()',
                '1 declares a GateReport that can be extended, so fail() could be overridden',
            ],
            $lines,
        );
    }

    #[Test]
    public function itRefusesEveryCallerItCannotTieToAClass(): void
    {
        $this->write('Alpha.php', <<<'PHP'
            <?php

            namespace QmxFindingGate;

            use QmxFindingGate\Alpha as Renamed;

            final class Alpha
            {
                public static function check($report): void
                {
                    $report->fail(FailureClass::RUN_FAILED, 'a', 'b');
                }
            }
            PHP);
        $this->write('Beta.php', <<<'PHP'
            <?php

            final class Beta
            {
                public function callers(string $class): void
                {
                    Alpha::check($this->report);
                    \QmxFindingGate\Alpha::check($this->report);
                    $class::check($this->report);
                    \call_user_func([Alpha::class, 'check'], $this->report);
                    array_map('Alpha::check', []);
                }
            }
            PHP);
        $this->write('Nested/Alpha.php', "<?php\nfinal class Alpha\n{\n}\n");

        $read = RaiseSites::of($this->directory);
        $lines = array_map(
            static fn(string $problem): string => (string) preg_replace('~^.*?/([\w/]+\.php)(?::(\d+))? (.*?)(?:, which.*|\.)$~', '$1:$2 $3', $problem),
            $read->problems,
        );

        self::assertSame(['Alpha::check <- Beta::callers'], array_keys($read->sites));
        self::assertSame(
            [
                'Alpha.php:5 imports a class under another name',
                'Beta.php:8 calls a static method through a qualified class name',
                'Beta.php:9 calls a static method on a class held in a variable',
                'Beta.php:10 calls a callable',
                'Beta.php:10 builds a callable from a class and a method name',
                'Beta.php:11 names a static method as a string',
                'Nested/Alpha.php: declares a second class named Alpha, and callers are told apart by short name only',
            ],
            $lines,
        );
    }

    private function write(string $relative, string $content): void
    {
        Fs::write($this->directory . '/' . $relative, $content);
    }
}
