<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ModularOwnership;

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

final class ModularArchitectureGovernanceIntegrationTest extends TestCase
{
    #[Group('live-freshness')]
    #[Test]
    public function itChecksEveryGeneratedProjectionWithoutWriting(): void
    {
        [$exitCode, $output] = $this->runProcess([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture.php',
            '--check',
        ]);

        self::assertSame(0, $exitCode, $output);
    }

    #[Test]
    public function itRoutesFreshnessOraclesExactlyOnceThroughAggregateCheck(): void
    {
        $this->assertFreshnessScriptGraph($this->composer());
    }

    /** @param array{scripts: array<string, string|list<string>>} $composer */
    private function assertFreshnessScriptGraph(array $composer): void
    {
        $scripts = $composer['scripts'];
        $expectedLeaves = [
            'check-leaks',
            'cs-check',
            'test:aggregate',
            'dangling-names',
            'test:js',
            'test:cross-tool',
            'phpstan',
            'docs:check',
            'architecture:check',
            'enumeration:directives:check',
            'suppression-snapshot:check',
            'html-bundle:check',
            'configuration:merge-table:check',
            'selfcheck:analysis',
            'directives:audit',
        ];
        $leafCounts = array_count_values($this->expandedLeafScripts($scripts, 'check'));
        foreach ($expectedLeaves as $leaf) {
            self::assertSame(1, $leafCounts[$leaf] ?? 0, \sprintf('%s must run exactly once through aggregate check.', $leaf));
        }
        foreach ($leafCounts as $leaf => $count) {
            self::assertSame(1, $count, \sprintf('%s must not run twice through aggregate check.', $leaf));
        }

        $checkSteps = $this->scriptSteps($scripts, 'check');
        self::assertContains('@check:code:tests', $checkSteps);
        self::assertContains('@check:parallel', $checkSteps);
        self::assertLessThan(
            array_search('@check:parallel', $checkSteps, true),
            array_search('@check:code:tests', $checkSteps, true),
            'Cheap code tests must precede parallel checks.',
        );

        foreach ([
            'test',
            'test:aggregate',
            'benchmark:check',
            'benchmark:update',
            'gate',
            'gate:phar',
            'directives:narrow-control',
            'check:gate',
            'check:parallel',
            'check:remaining',
        ] as $script) {
            self::assertSame('Composer\\Config::disableProcessTimeout', $this->scriptSteps($scripts, $script)[0]);
        }

        $selfcheckSteps = $this->scriptSteps($scripts, 'selfcheck');
        self::assertContains('@architecture:check', $selfcheckSteps);
        self::assertContains('@selfcheck:analysis', $selfcheckSteps);
        self::assertLessThan(
            array_search('@selfcheck:analysis', $selfcheckSteps, true),
            array_search('@architecture:check', $selfcheckSteps, true),
            'Architecture freshness must precede self-analysis.',
        );

        self::assertSame(
            ['Composer\\Config::disableProcessTimeout', 'phpunit --no-coverage --exclude-group=benchmark'],
            $scripts['test'],
        );
        self::assertSame(
            'php bin/qmx check src/ --baseline=qmx-baseline.json --fail-on=warning --memory-limit=512M',
            $scripts['selfcheck:analysis'],
        );
        self::assertContains(
            "python3 -m unittest discover -s scripts/phpunit-aggregate/tests -p 'test_*.py'",
            $this->scriptSteps($scripts, 'test:cross-tool'),
        );
        self::assertContains(
            "python3 -m unittest discover -s scripts/check-code-parallel/tests -p 'test_*.py'",
            $this->scriptSteps($scripts, 'test:cross-tool'),
        );
        self::assertSame(
            [
                'Composer\\Config::disableProcessTimeout',
                'phpunit --configuration=phpunit.xml.dist --no-coverage --group=finding-gate-e2e',
            ],
            $scripts['check:gate'],
        );
    }

    /**
     * @param array<string, string|list<string>> $scripts
     * @param list<string> $ancestors
     *
     * @return list<string>
     */
    private function expandedLeafScripts(array $scripts, string $name, array $ancestors = []): array
    {
        self::assertNotContains($name, $ancestors, 'Composer script graph must not cycle.');
        $ancestors[] = $name;
        $children = [];
        foreach ($this->scriptSteps($scripts, $name) as $step) {
            if (str_starts_with($step, '@')) {
                $children[] = substr($step, 1);
            } elseif ($step === 'python3 scripts/check-code-parallel/check-code-parallel.py') {
                array_push($children, ...$this->coordinatorScripts());
            }
        }

        if ($children === []) {
            return [$name];
        }

        $leaves = [];
        foreach ($children as $child) {
            array_push($leaves, ...$this->expandedLeafScripts($scripts, $child, $ancestors));
        }

        return $leaves;
    }

    /** @return list<string> */
    private function coordinatorScripts(): array
    {
        $parser = <<<'PY'
import ast
import json
import sys

with open(sys.argv[1], encoding='utf-8') as source:
    tree = ast.parse(source.read())
assignments = [
    node.value
    for node in tree.body
    if isinstance(node, ast.Assign)
    and any(isinstance(target, ast.Name) and target.id == 'COMMANDS' for target in node.targets)
]
if len(assignments) != 1:
    raise ValueError('Expected one literal COMMANDS assignment')
commands = ast.literal_eval(assignments[0])
scripts = []
for _, command in commands:
    if len(command) != 2 or command[0] != 'composer':
        raise ValueError('Expected a composer script command')
    scripts.append(command[1])
print(json.dumps(scripts))
PY;
        [$exitCode, $output] = $this->runProcess([
            'python3',
            '-c',
            $parser,
            $this->root() . '/scripts/check-code-parallel/check-code-parallel.py',
        ]);
        self::assertSame(0, $exitCode, $output);
        $decoded = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return array_values(array_map(
            static function (mixed $script): string {
                self::assertIsString($script);

                return $script;
            },
            $decoded,
        ));
    }

    #[Test]
    public function itPublishesOnlyExactCompositionBindingsForDiInternals(): void
    {
        $manifest = $this->manifest();
        self::assertSame(4, $manifest['version']);
        self::assertArrayNotHasKey('temporary_internal_grants', $manifest);

        $bindings = [];
        foreach ($manifest['declarations'] as $target => $declaration) {
            foreach ($declaration['consumers'] as $consumer) {
                if (($consumer['relation'] ?? 'import') !== 'composition_binding') {
                    continue;
                }
                self::assertSame('internal', $declaration['visibility']);
                self::assertSame('Infrastructure.DependencyInjection', $consumer['owner']);
                self::assertNotEmpty($consumer['operations']);
                self::assertArrayHasKey($consumer['source_fqcn'], $manifest['declarations']);
                self::assertNotSame($consumer['source_fqcn'], $target);
                $bindings[$consumer['source_fqcn'] . "\0" . $target] = true;
            }
        }
        self::assertNotEmpty($bindings);

        $rows = $this->tsv('production-composition-bindings.tsv');
        self::assertCount(\count($bindings), $rows);
        foreach ($rows as $row) {
            self::assertSame('used', $row['behavioral_verdict']);
            self::assertNotSame('', $row['qmx_projection']);
            self::assertSame($row['declared_operations'], $row['observed_operations']);
            self::assertNotSame('', $row['observed_operations']);
        }
        self::assertContains('service_alias,service_reference,service_registration', array_column($rows, 'observed_operations'));
        self::assertContains('conditional_service_reference', array_column($rows, 'observed_operations'));
        self::assertContains('definition_argument_mutation', array_column($rows, 'observed_operations'));
    }

    #[Test]
    public function itPublishesTheReviewedTopologyEvidenceAndRejectsProductionToTestImports(): void
    {
        self::assertCount(28, $this->tsv('test-orphan-dispositions.tsv'));
        self::assertCount(5, $this->tsv('test-system-support-owners.tsv'));
        self::assertSame([], $this->tsv('production-to-test-imports.tsv'));
        self::assertNotEmpty($this->tsv('production-public-imports.tsv'));
        self::assertNotEmpty($this->tsv('production-module-fan-in.tsv'));
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $contents = file_get_contents($this->root() . '/docs/internal/modular-architecture-manifest.json');
        self::assertIsString($contents);
        $manifest = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }

    /** @return array{scripts: array<string, string|list<string>>} */
    private function composer(): array
    {
        $contents = file_get_contents($this->root() . '/composer.json');
        self::assertIsString($contents);
        $composer = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertArrayHasKey('scripts', $composer);
        self::assertIsArray($composer['scripts']);

        return $composer;
    }

    /**
     * @param array<string, string|list<string>> $scripts
     *
     * @return list<string>
     */
    private function scriptSteps(array $scripts, string $name): array
    {
        self::assertArrayHasKey($name, $scripts);
        $steps = $scripts[$name];

        return \is_string($steps) ? [$steps] : $steps;
    }

    /** @return list<array<string, string>> */
    private function tsv(string $name): array
    {
        $lines = file($this->root() . '/docs/internal/generated/modular-architecture/' . $name, \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $headerLine = array_shift($lines);
        self::assertIsString($headerLine);
        $header = array_map(
            static function (?string $column): string {
                if (!\is_string($column)) {
                    throw new LogicException('TSV header contains a non-string column.');
                }

                return $column;
            },
            str_getcsv($headerLine, "\t", '"', '\\'),
        );

        return array_map(
            static function (string $line) use ($header): array {
                $row = array_combine($header, str_getcsv($line, "\t", '"', '\\'));

                return array_map(static fn(?string $value): string => $value ?? '', $row);
            },
            array_values(array_filter($lines, static fn(string $line): bool => $line !== '')),
        );
    }

    /** @param list<string> $command
     * @return array{int, string}
     */
    private function runProcess(array $command): array
    {
        try {
            $result = ChildProcess::run($command, $this->root());
        } catch (RuntimeException $exception) {
            self::fail($exception->getMessage());
        }

        return [$result['exitCode'], $result['stdout'] . $result['stderr']];
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../../');
        self::assertIsString($root);

        return $root;
    }
}
