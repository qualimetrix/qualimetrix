<?php

declare(strict_types=1);

namespace QmxTautologyControls;

use QmxFindingGateControls\Scratch;
use QmxFindingGateControls\Shell;
use RuntimeException;

/**
 * The files carrying the repaired assertions, and what one run said about each
 * case in them.
 *
 * Only these files run. A breakage planted for one repair may well redden
 * something else in the project, and that would be evidence about the project
 * rather than about the repair — so the population the harness compares against
 * is exactly the population the repairs live in.
 *
 * Read from JUnit XML rather than from the human-readable report, because the
 * harness needs each case's name and outcome as data.
 */
final readonly class Suite
{
    /** @var list<string> */
    public const array FILES = [
        'governance/Channel/ProjectScopedChannelRollCallTest.php',
        'tests/Analysis/Evidence/ComputedMetrics/Health/Unit/DecompositionItemTest.php',
        'tests/Analysis/Evidence/ComputedMetrics/Health/Unit/HealthScoreTest.php',
        'tests/Analysis/Evidence/Coupling/Unit/NamespaceInstabilityOptionsTest.php',
        'tests/Analysis/Evidence/DependencyModel/Unit/DependencyTest.php',
        'tests/Analysis/Evidence/DependencyModel/Unit/EmptyDependencyGraphTest.php',
        'tests/Analysis/Finding/Unit/LocationTest.php',
        'tests/Analysis/Policy/Baseline/Unit/ChannelRenameMapTest.php',
        'tests/Core/Symbol/Unit/CallableKindTest.php',
        'tests/Core/Symbol/Unit/SymbolInfoTest.php',
        'tests/Infrastructure/DependencyInjection/Integration/ContainerFactoryTest.php',
        'tests/Infrastructure/DependencyInjection/Unit/CompilerPass/RuleCompilerPassTest.php',
        'tests/Infrastructure/Parallel/Unit/Strategy/AmphpParallelStrategyTest.php',
        'tests/Infrastructure/Rule/Unit/RuleRegistryTest.php',
        'tests/Reporting/Unit/FindingProjection/DeclaredChannelFileScopeTest.php',
    ];

    /**
     * @param array<string, bool> $cases case name => whether it passed
     */
    private function __construct(
        public array $cases,
        public int $exit,
    ) {}

    /** Runs the suite inside a clone and reads the log it wrote. */
    public static function runIn(Scratch $scratch): self
    {
        $log = $scratch->tree . '/.tautology-controls.xml';

        // The child gets its own temp directory, beside the clone rather than
        // inside it: a fixture that builds a scratch path under the system
        // temp directory is outside the tree under test, and two runs handed
        // the same one is a defect this repository has measured before.
        $child = Shell::start([
            'vendor/bin/phpunit',
            '--no-coverage',
            '--do-not-cache-result',
            '--log-junit',
            $log,
            ...self::FILES,
        ], $scratch->tree, ['TMPDIR' => $scratch->beside('tmp')]);

        while (!$child->settled()) {
            Shell::poll();
        }

        $result = $child->result();

        if (!is_file($log)) {
            throw new RuntimeException(\sprintf(
                "PHPUnit wrote no JUnit log in the scratch tree, so this run says nothing.\nstdout: %s\nstderr: %s",
                $result['stdout'],
                $result['stderr'],
            ));
        }

        return self::fromJUnit(Shell::read($log), $result['exit']);
    }

    /**
     * A case is red when PHPUnit recorded a failure or an error against it.
     *
     * A skip is deliberately *not* red here, which is the opposite of what the
     * directive audit's bench does and costs something: a breakage that turns
     * a case into a skip would go unnoticed. The reason is that two cases in
     * this population skip by design — the parallel fallbacks that cannot be
     * reached on a machine where parallel is available — so counting a skip as
     * red would make those two red in every run, including the positive one,
     * and no control here could then say anything. Nothing in this population
     * skips conditionally on the code the controls break.
     */
    private static function fromJUnit(string $xml, int $exit): self
    {
        $document = @simplexml_load_string($xml);

        if ($document === false) {
            throw new RuntimeException('The JUnit log is not readable XML.');
        }

        $cases = [];

        foreach ($document->xpath('//testcase') ?? [] as $case) {
            $name = (string) $case['name'];

            if ($name === '') {
                continue;
            }

            // Keyed by class as well as method: two classes with a same-named
            // method are indistinguishable under the method alone, and a red
            // in one would read as a red in both.
            $cases[(string) $case['classname'] . '::' . $name] =
                !isset($case->failure) && !isset($case->error);
        }

        if ($cases === []) {
            throw new RuntimeException(
                'The JUnit log records no cases at all. A run that executes nothing is not a green run.',
            );
        }

        ksort($cases);

        return new self($cases, $exit);
    }

    /** @return list<string> */
    public function red(): array
    {
        return array_keys(array_filter($this->cases, static fn(bool $passed): bool => !$passed));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->cases);
    }
}
