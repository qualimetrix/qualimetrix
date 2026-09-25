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

        $read = RaiseSites::of($this->directory, []);

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

        self::assertSame(['Deep::x'], array_keys(RaiseSites::of($this->directory, [])->sites));
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

        $problems = RaiseSites::of($this->directory, [])->problems;
        $lines = array_map(static fn(string $problem): string => (string) preg_replace('~^.*?\.php:(\d+) (.*?), which.*$~', '$1 $2', $problem), $problems);

        self::assertSame(
            [
                '7 raises a failure whose class is not a FailureClass constant, or reaches fail() another way',
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
    public function itRefusesEveryOccurrenceOfALeadingNameThatIsNotADeclarationOrADirectCall(): void
    {
        $this->write('Alpha.php', <<<'PHP'
            <?php

            namespace QmxFindingGate;

            class Alpha
            {
                public const string NAME = 'check';

                public function check($report): void
                {
                    $report->fail(FailureClass::RUN_FAILED, 'a', 'b');
                }

                public static function run($report): void
                {
                    $report->fail(FailureClass::MAP_STALE, 'a', 'b');
                }

                public static function helper(): void {}
            }
            PHP);
        $this->write('Beta.php', <<<'PHP'
            <?php

            namespace QmxFindingGate;

            use QmxFindingGate\Alpha as Renamed;

            final class Beta extends Alpha
            {
                public function forms(string $class, string $name): void
                {
                    array_map([
                        $this->alpha,
                        'check',
                    ], []);
                    array_map(array(1 => 'CHECK', 0 => $this->alpha), []);
                    array_map([self::class, Alpha::NAME], []);
                    \Closure::fromCallable([$this->alpha, $name]);
                    array_map("QmxFindingGate\\Alpha::Run", []);
                    $first = $this->alpha->check(...);
                    $second = namespace\Alpha::run(...);
                    $third = parent::CHECK(...);
                    $class::run($this->report);
                    Renamed::run($this->report);
                    Unscanned::check($this->report);
                    $this->check;
                }
            }
            PHP);

        self::assertSame(
            [
                'Alpha.php:7',
                'Beta.php:13',
                'Beta.php:15',
                'Beta.php:16',
                'Beta.php:18',
                'Beta.php:19',
                'Beta.php:20',
                'Beta.php:21',
                'Beta.php:22',
                'Beta.php:24',
            ],
            array_map(
                static fn(string $problem): string => (string) preg_replace('~^.*?/(\w+\.php):(\d+) names .*$~', '$1:$2', $problem),
                RaiseSites::of($this->directory, [])->problems,
            ),
        );
    }

    #[Test]
    public function itFollowsEveryDirectCallWhateverItsCaseOrQualification(): void
    {
        $this->write('Alpha.php', <<<'PHP'
            <?php

            namespace QmxFindingGate;

            class Alpha
            {
                public function check($report): void
                {
                    $report->fail(FailureClass::RUN_FAILED, 'a', 'b');
                }

                public static function run($report): void
                {
                    $report->fail(FailureClass::MAP_STALE, 'a', 'b');
                }

                public function viaSelf($report): void
                {
                    self::RUN($report);
                }
            }
            PHP);
        $this->write('Beta.php', <<<'PHP'
            <?php

            namespace QmxFindingGate;

            final class Beta extends Alpha
            {
                public function viaParent(): void
                {
                    parent::check($this->report);
                }

                public function viaInstance(): void
                {
                    $this->alpha->Check($this->report);
                }

                public function viaRelative(): void
                {
                    namespace\ALPHA::run($this->report);
                }

                public function viaQualified(): void
                {
                    \QmxFindingGate\Alpha::run($this->report);
                }

                public function viaInherited(): void
                {
                    $this->viaSelf($this->report);
                }
            }
            PHP);

        $read = RaiseSites::of($this->directory, []);

        self::assertSame([], $read->problems);
        self::assertSame(
            [
                'Alpha::check <- Beta::viaInstance',
                'Alpha::check <- Beta::viaParent',
                'Alpha::run <- Beta::viaInherited',
                'Alpha::run <- Beta::viaQualified',
                'Alpha::run <- Beta::viaRelative',
            ],
            array_keys($read->sites),
        );
    }

    #[Test]
    public function itAcceptsADeclaredLineOnceAndRefusesOneThatMatchesNoneOrMore(): void
    {
        $this->write('Alpha.php', <<<'PHP'
            <?php

            final class Alpha
            {
                public const string MODE = 'check';

                public function check($report): void
                {
                    $report->fail(FailureClass::RUN_FAILED, 'a', 'b');
                }

                public function data(): array
                {
                    return [['check', 1], ['check', 1]];
                }
            }
            PHP);

        $declared = [
            ['Alpha.php', "public const string MODE = 'check';", 'the name of a mode'],
            ['Alpha.php', "return [['check', 1], ['check', 1]];", 'a pair of data'],
            ['Alpha.php', 'nothing like this line', 'stale'],
            ['Alpha.php', "public const string MODE = 'check';", ' '],
        ];
        $problems = RaiseSites::of($this->directory, $declared)->problems;

        self::assertSame(
            [
                '/Alpha.php:5 is declared 2 times in RaiseSites::DECLARED_NAMES; declare it once.',
                'the declared name at Alpha.php "return [[\'check\', 1], [\'check\', 1]];" matches 2 occurrence(s)',
                'the declared name at Alpha.php "nothing like this line" matches 0 occurrence(s)',
                'the declared name at Alpha.php "public const string MODE = \'check\';" gives no reason.',
            ],
            array_map(
                static fn(string $problem): string => (string) preg_replace(
                    ['~^witness registry: (.*?)(?: of a leading.*)?$~', '~^.*(/Alpha\.php:)~'],
                    ['$1', '$1'],
                    $problem,
                ),
                $problems,
            ),
        );
        self::assertSame(
            ['Alpha.php:14', 'Alpha.php:14'],
            array_map(
                static fn(string $problem): string => (string) preg_replace('~^.*?/(\w+\.php):(\d+) names .*$~', '$1:$2', $problem),
                RaiseSites::of($this->directory, \array_slice($declared, 0, 1))->problems,
            ),
        );
    }

    #[Test]
    public function itRefusesTwoScannedClassesWithOneShortNameInAnyCase(): void
    {
        $this->write('Alpha.php', "<?php\nfinal class Alpha\n{\n}\n");
        $this->write('Nested/alpha.php', "<?php\nfinal class alpha\n{\n}\n");

        $problems = RaiseSites::of($this->directory, [])->problems;

        self::assertCount(1, $problems);
        self::assertStringContainsString('declares a second class named alpha', $problems[0]);
    }

    #[Test]
    public function itReadsAStringsValueAsPhpDoes(): void
    {
        $this->write('Alpha.php', <<<'PHP'
            <?php

            final class Alpha
            {
                public function check($report): void
                {
                    $report->fail(FailureClass::RUN_FAILED, 'a', 'b');
                }
            }
            PHP);
        $this->write('Beta.php', <<<'PHP'
            <?php

            final class Beta
            {
                public function forms($x): void
                {
                    $a = b'check';
                    $b = "ch\u{65}ck";
                    $c = "\x63heck";
                    $d = "\143heck";
                    $e = B"Alpha::CHECK";
                    $f = <<<E
                        Alpha::ch\x65ck
                        E;
                    $g = <<<'E'
                        check
                        E;
                    $h = "{$x}::ch\u{65}ck";
                    $i = <<<E
                        {$x} Alpha::ch\x65ck
                        E;
                    $j = "f\x61il";
                    $k = b'FAIL';
                    $l = "chec";
                    $m = 'check ';
                }
            }
            PHP);

        self::assertSame(
            [
                'Beta.php:22 names "fail" as a string',
                'Beta.php:23 names "fail" as a string',
                'Beta.php:7 names Alpha::check',
                'Beta.php:8 names Alpha::check',
                'Beta.php:9 names Alpha::check',
                'Beta.php:10 names Alpha::check',
                'Beta.php:11 names Alpha::check',
                'Beta.php:13 names Alpha::check',
                'Beta.php:16 names Alpha::check',
                'Beta.php:18 names Alpha::check',
                'Beta.php:20 names Alpha::check',
            ],
            self::briefly(RaiseSites::of($this->directory, [])->problems),
        );
    }

    #[Test]
    public function itRefusesEveryUseOfAConstantCarryingALeadingName(): void
    {
        $this->write('Alpha.php', <<<'PHP'
            <?php

            namespace QmxFindingGate;

            const GLOBAL_NAME = 'check';
            \define('DEFINED_NAME', 'Alpha::check');

            class Alpha
            {
                public const string NAME = 'check';
                public const int OTHER = 1;

                public function check($report): void
                {
                    $report->fail(FailureClass::RUN_FAILED, 'a', 'b');
                }
            }
            PHP);
        $this->write('Beta.php', <<<'PHP'
            <?php

            namespace QmxFindingGate;

            final class Beta extends Alpha
            {
                public function uses($x): array
                {
                    return [
                        [$x, Alpha::NAME],
                        [$x, self::NAME],
                        [$x, static::NAME],
                        [$x, \QmxFindingGate\Alpha::NAME],
                        [$x, parent::NAME],
                        \constant('QmxFindingGate\Alpha::NAME'),
                        [$x, GLOBAL_NAME],
                        [$x, DEFINED_NAME],
                        Alpha::OTHER,
                        Unscanned::NAME,
                    ];
                }
            }
            PHP);

        $declared = [
            ['Alpha.php', "const GLOBAL_NAME = 'check';", 'data'],
            ['Alpha.php', "\\define('DEFINED_NAME', 'Alpha::check');", 'data'],
            ['Alpha.php', "public const string NAME = 'check';", 'data'],
        ];

        self::assertSame(
            [
                'Beta.php:10 names Alpha::check',
                'Beta.php:11 names Alpha::check',
                'Beta.php:12 names Alpha::check',
                'Beta.php:13 names Alpha::check',
                'Beta.php:14 names Alpha::check',
                'Beta.php:15 names Alpha::check',
                'Beta.php:16 names Alpha::check',
                'Beta.php:17 names Alpha::check',
                'Beta.php:19 names Alpha::check',
            ],
            self::briefly(RaiseSites::of($this->directory, $declared)->problems),
        );
    }

    #[Test]
    public function itResolvesAnImportedClassAndRefusesAGroupImport(): void
    {
        $this->write('Coverage.php', <<<'PHP'
            <?php

            namespace QmxFindingGate;

            final class Coverage
            {
                public static function check($report): void
                {
                    $report->fail(FailureClass::RUN_FAILED, 'a', 'b');
                }
            }
            PHP);
        $this->write('Diff.php', "<?php\nnamespace QmxFindingGate;\nfinal class Diff\n{\n}\n");
        $this->write('Beta.php', <<<'PHP'
            <?php

            namespace QmxFindingGate;

            use QmxFindingGate\Coverage as Diff;
            use Vendor\Elsewhere as Unknown;

            final class Beta
            {
                public function aliased(): void
                {
                    Diff::check($this->report);
                }

                public function elsewhere(): void
                {
                    Unknown::check($this->report);
                }
            }
            PHP);
        $this->write('Gamma.php', "<?php\nnamespace QmxFindingGate;\nuse Vendor\\{A, B};\nfinal class Gamma\n{\n}\n");

        $read = RaiseSites::of($this->directory, []);

        self::assertSame(['Coverage::check <- Beta::aliased'], array_keys($read->sites));
        self::assertSame(
            ['Gamma.php:3 imports through a group `use`', 'Beta.php:17 names Coverage::check'],
            self::briefly($read->problems),
        );
    }

    #[Test]
    public function itKeepsARaiseWithItsMethodPastAnAnonymousClass(): void
    {
        $this->write('Alpha.php', <<<'PHP'
            <?php

            final class Alpha
            {
                public function outer($report): void
                {
                    $helper = new class {
                        public function inner(): void {}
                    };
                    $report->fail(FailureClass::RUN_FAILED, 'a', 'b');
                }
            }
            PHP);

        self::assertSame(['Alpha::outer'], array_keys(RaiseSites::of($this->directory, [])->sites));
    }

    #[Test]
    public function itTellsACallFromAPropertyANamedArgumentAndAPhantomReceiver(): void
    {
        $this->write('Alpha.php', <<<'PHP'
            <?php

            final class Alpha
            {
                public function compare($report): void
                {
                    $report->fail(FailureClass::RUN_FAILED, 'a', 'b');
                }
            }
            PHP);
        $this->write('Beta.php', <<<'PHP'
            <?php

            final class Beta
            {
                public function spread(array $args): void
                {
                    $this->alpha->compare(...$args);
                    $read = $this->alpha->compare;
                    $this->format(compare: 1);
                }

                public function collator(\Collator $collator): int
                {
                    return $collator->compare('a', 'b');
                }

                public function function(): void
                {
                    \Vendor\compare($this->report);
                }
            }
            PHP);

        $declared = [['Beta.php', "return \$collator->compare('a', 'b');", 'a Collator, not a scanned class']];
        $read = RaiseSites::of($this->directory, $declared);

        self::assertSame(['Alpha::compare <- Beta::spread'], array_keys($read->sites));
        self::assertSame(['Beta.php:19 names Alpha::compare'], self::briefly($read->problems));
        self::assertSame(
            ['Alpha::compare <- Beta::collator', 'Alpha::compare <- Beta::spread'],
            array_keys(RaiseSites::of($this->directory, [])->sites),
        );
    }

    #[Test]
    public function itRefusesAFileThatDoesNotParse(): void
    {
        $this->write('Broken.php', "<?php\nfinal class Broken\n{\n    public function (\n}\n");

        $problems = RaiseSites::of($this->directory, [])->problems;

        self::assertCount(1, $problems);
        self::assertStringContainsString('Broken.php does not parse', $problems[0]);
    }

    /**
     * @param list<string> $problems
     *
     * @return list<string>
     */
    private static function briefly(array $problems): array
    {
        return array_map(
            static fn(string $problem): string => (string) preg_replace(
                '~^witness registry: .*?/(\w+\.php):(\d+) (names (?:"fail" as a string|[\w:]+)|imports through a group `use`).*$~s',
                '$1:$2 $3',
                $problem,
            ),
            $problems,
        );
    }

    private function write(string $relative, string $content): void
    {
        Fs::write($this->directory . '/' . $relative, $content);
    }
}
