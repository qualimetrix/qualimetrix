<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * A candidate repository the whole gate can be run against, with no product in it.
 *
 * Its `bin/qmx` replays the answers written beside it, and its
 * `vendor/autoload.php` declares the few product types the channel probe asks
 * for, so a run is cheap and every artifact is chosen. That is what lets a
 * check be observed through {@see Gate::compare()} — the one entry point that
 * survives the checks being moved between files — instead of through the
 * private method a refactoring may drop.
 *
 * The committed state is the reference and the working tree is the candidate:
 * the specification is written, committed, and then only the `candidate*`
 * overrides are written over it.
 *
 * @phpstan-type Answer array{stdout?: string, stderr?: string, stderrOnce?: bool, exit?: int}
 * @phpstan-type Finding array<string, mixed>
 * @phpstan-type Specification array{
 *     cases: array<string, list<string>>,
 *     findings: array<string, list<Finding>>,
 *     answers: array<string, Answer>,
 *     candidateAnswers: array<string, Answer>,
 *     tuple: list<string>,
 *     published: list<string>,
 *     normalization: list<array{0: string, 1: string, 2: string}>,
 *     static: array<string, list<string>>,
 *     fixture: array<string, list<string>>,
 *     levels: list<string>,
 *     lock: string,
 *     candidateLock: string|null,
 * }
 */
final class SyntheticTree
{
    private const string TUPLE_SOURCE = 'src/Reporting/Formatter/Json/JsonFindingSection.php';

    private const string ANSWERS = 'replay/answers.json';

    /**
     * Placeholders the replaying binary expands at run time: the tree it was
     * invoked from, as the gate spelled it, and a value no two runs share.
     */
    public const string TREE = '{{tree}}';

    public const string RANDOM = '{{random}}';

    /**
     * A specification whose run the gate calls GREEN: one case, one finding,
     * every surface readable and every declaration agreeing with what fires.
     *
     * @return Specification
     */
    public static function clean(): array
    {
        $fields = ['subject', 'channel', 'occurrence', 'edge', 'rule', 'message'];

        return [
            'cases' => ['alpha' => ['replay.alpha@callable']],
            'findings' => ['alpha' => [self::finding($fields, 'replay.alpha', 'declaration:callable:Replay\Alpha::run@src/Alpha.php')]],
            'answers' => [],
            'candidateAnswers' => [],
            'tuple' => $fields,
            'published' => $fields,
            'normalization' => [],
            'static' => ['replay.alpha' => ['callable']],
            'fixture' => ['replay.alpha' => ['callable']],
            'levels' => SubjectLevel::levels(),
            'lock' => "{\"replay\": \"lock\"}\n",
            'candidateLock' => null,
        ];
    }

    /**
     * @param list<string> $fields
     *
     * @return Finding
     */
    public static function finding(array $fields, string $channel, string $subject): array
    {
        $values = ['subject' => $subject, 'channel' => $channel, 'occurrence' => null, 'edge' => null, 'rule' => $channel, 'message' => 'replayed'];
        $finding = [];

        foreach ($fields as $field) {
            $finding[$field] = $values[$field] ?? null;
        }

        return $finding;
    }

    /**
     * Builds the repository and returns its root.
     *
     * @param Specification $specification
     */
    public static function create(array $specification): string
    {
        $root = Fs::temporaryDirectory('self-test-synthetic-tree-');
        $reference = self::files($specification, $specification['answers'], $specification['lock']);

        foreach ($reference as $path => $content) {
            Fs::write($root . '/' . $path, $content);
        }

        foreach ([
            ['git', 'init', '--quiet'],
            ['git', 'add', '--all'],
            ['git', '-c', 'user.email=self-test@qmx', '-c', 'user.name=self-test', 'commit', '--quiet', '--message', 'reference'],
        ] as $command) {
            $result = Process::run($command, $root);

            if ($result['exit'] !== 0) {
                Fs::removeRecursively($root);

                throw new GateError(\sprintf("Cannot build the synthetic tree:\n%s", $result['stderr']));
            }
        }

        $candidate = self::files(
            $specification,
            [...$specification['answers'], ...$specification['candidateAnswers']],
            $specification['candidateLock'] ?? $specification['lock'],
        );

        foreach ($candidate as $path => $content) {
            if ($content !== $reference[$path]) {
                Fs::write($root . '/' . $path, $content);
            }
        }

        return $root;
    }

    public static function remove(string $root): void
    {
        Fs::removeRecursively($root);
    }

    /**
     * @param Specification $specification
     * @param array<string, Answer> $overrides
     *
     * @return array<string, string> path => content
     */
    private static function files(array $specification, array $overrides, string $lock): array
    {
        $answers = ['tree|rules' => ['stdout' => "replayed rules\n"]];

        foreach ($specification['cases'] as $id => $claims) {
            $answers += self::caseAnswers($id, $specification['findings'][$id] ?? []);
        }

        $files = [
            'bin/qmx' => self::replayingBinary(),
            self::ANSWERS => json_encode([...$answers, ...$overrides], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n",
            'vendor/autoload.php' => self::probedProduct($specification['static'], $specification['levels']),
            'composer.lock' => $lock,
            'qmx.yaml' => "# replayed\n",
            'src/Analysis/Evidence/Measurement/Contract/AggregationStrategy.php' => "<?php\n\nenum AggregationStrategy: string\n{\n    case Sum = 'sum';\n}\n",
            'src/Analysis/Evidence/Measurement/Contract/MetricName.php' => "<?php\n\nfinal class MetricName\n{\n    public const string CCN = 'ccn';\n}\n",
            self::TUPLE_SOURCE => self::publishingSource($specification['published']),
            EquivalenceTuple::TRACKED_PATH => Tsv::render(
                EquivalenceTuple::COLUMNS,
                array_map(static fn(string $field): array => [$field, EquivalenceTuple::source()], $specification['tuple']),
            ),
            'finding-gate/normalization.tsv' => Tsv::render(
                Normalization::COLUMNS,
                array_map(static fn(array $row): array => [...$row, 'self-test'], $specification['normalization']),
            ),
            'governance/Channel/Fixtures/declared.txt' => implode('', array_map(
                static fn(string $channel, array $levels): string => \sprintf("%s reports %s\n", $channel, implode(',', $levels)),
                array_keys($specification['fixture']),
                $specification['fixture'],
            )),
        ];

        foreach (['channels', 'inputs', 'metric-keys', 'report-values', 'symbols'] as $map) {
            $files['finding-gate/maps/' . $map . '.tsv'] = "old\tnew\treason\n";
        }

        foreach ($specification['cases'] as $id => $claims) {
            $files['finding-gate/cases/' . $id . '/case.json'] = json_encode([
                'id' => $id,
                'description' => 'A replayed case.',
                'paths' => ['src'],
                'config' => 'qmx.yaml',
                'channels' => $claims,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
            $files['finding-gate/cases/' . $id . '/qmx.yaml'] = "# replayed\n";
        }

        return $files;
    }

    /**
     * Every surface a case run captures, readable and agreeing with the findings.
     *
     * @param list<Finding> $findings
     *
     * @return array<string, Answer>
     */
    private static function caseAnswers(string $id, array $findings): array
    {
        $scope = 'case:' . $id;
        $expected = Fingerprints::expected($findings);
        $answers = [];

        foreach (Surfaces::FORMATS as $format) {
            $answers[Surfaces::key($scope, 'format:' . $format)] = ['stdout' => \sprintf("replayed %s of %s\n", $format, $id)];
        }

        $answers[Surfaces::key($scope, 'format:json')] = ['stdout' => self::json(['violations' => $findings])];
        $answers[Surfaces::key($scope, 'format:sarif')] = ['stdout' => self::json(['runs' => [[
            'results' => array_map(
                static fn(string $preimage): array => ['partialFingerprints' => ['primaryLocationLineHash' => $preimage]],
                $expected,
            ),
        ]]])];
        $answers[Surfaces::key($scope, 'format:gitlab')] = ['stdout' => self::json(array_map(
            static fn(string $hash): array => ['check_name' => 'replayed', 'fingerprint' => $hash],
            Fingerprints::md5Of($expected),
        ))];
        $answers[Surfaces::key($scope, 'format:html')] = [
            'stdout' => '<html><script type="application/json" id="report-data">{"replayed": true}</script></html>' . "\n",
        ];
        $answers[Surfaces::key($scope, 'show-suppressed')] = ['stdout' => 'replayed show-suppressed of ' . $id . "\n"];
        $answers[Surfaces::key($scope, 'baseline-file')] = ['stdout' => self::json(['replayed' => $id])];

        return $answers;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param list<string> $fields */
    private static function publishingSource(array $fields): string
    {
        $lines = array_map(static fn(string $field): string => \sprintf("            '%s' => null,\n", $field), $fields);

        return "<?php\n\nfinal class JsonFindingSection\n{\n    private function formatFinding(): array\n    {\n"
            . "        return [\n" . implode('', $lines) . "        ];\n    }\n}\n";
    }

    /**
     * The binary both trees run: it answers from its own tree's answer file.
     *
     * `baseline:generate` writes its answer to the path it was given and leaves
     * a cache directory behind, because {@see TreeRun} refuses a successful run
     * that did not. A `stderrOnce` answer reaches stderr on the first
     * invocation in a tree only, which is the shape of a surface one run of the
     * candidate produces and the next does not.
     */
    private static function replayingBinary(): string
    {
        return <<<'PHP'
            <?php

            $tree = dirname(__DIR__);
            $answers = json_decode((string) file_get_contents($tree . '/replay/answers.json'), true);
            $arguments = array_slice($argv, 1);
            $command = $arguments[0] ?? '';
            $format = array_search('-f', $arguments, true);
            $surface = match (true) {
                $command === 'rules' => 'rules',
                $command === 'baseline:generate' => 'baseline-file',
                in_array('--show-suppressed', $arguments, true) => 'show-suppressed',
                $command === 'check' && $format !== false => 'format:' . $arguments[$format + 1],
                default => null,
            };

            if ($surface === null) {
                fwrite(STDERR, "replay: no surface for this invocation\n");
                exit(70);
            }

            $key = ($command === 'rules' ? 'tree' : 'case:' . basename((string) getcwd())) . '|' . $surface;
            $answer = $answers[$key] ?? null;

            if (!is_array($answer)) {
                fwrite(STDERR, "replay: no answer for {$key}\n");
                exit(70);
            }

            $stdout = strtr((string) ($answer['stdout'] ?? ''), [
                '{{tree}}' => dirname($argv[0], 2),
                '{{random}}' => bin2hex(random_bytes(8)),
            ]);
            $stderr = (string) ($answer['stderr'] ?? '');

            if ($stderr !== '' && ($answer['stderrOnce'] ?? false) === true) {
                $marker = $tree . '/replay/state/' . md5($key);

                if (is_file($marker)) {
                    $stderr = '';
                } else {
                    @mkdir(dirname($marker), 0o777, true);
                    touch($marker);
                }
            }

            if ($command === 'baseline:generate') {
                @mkdir('.qmx-cache');
                file_put_contents($arguments[1], $stdout);
            } else {
                echo $stdout;
            }

            fwrite(STDERR, $stderr);
            exit((int) ($answer['exit'] ?? 0));

            PHP;
    }

    /**
     * The product types `probe-channels.php` reads, declaring exactly the
     * static channels and the level vocabulary the specification names.
     *
     * Written at run time rather than tracked: a tracked file declaring these
     * names would collide with the real product's classes in every tool that
     * loads both.
     *
     * @param array<string, list<string>> $static
     * @param list<string> $levels
     */
    private static function probedProduct(array $static, array $levels): string
    {
        $cases = '';

        foreach (array_values($levels) as $index => $level) {
            $cases .= \sprintf("        case Level%d = %s;\n", $index, var_export($level, true));
        }

        $declarations = var_export($static, true);

        return <<<PHP
            <?php

            namespace Qualimetrix\\Core\\Symbol {
                enum SymbolLevel: string
                {
            {$cases}    }
            }

            namespace Qualimetrix\\Core\\Path {
                final class AbsolutePath
                {
                    public static function fromString(string \$path): self
                    {
                        return new self();
                    }
                }
            }

            namespace Qualimetrix\\Analysis\\Configuration\\Contract\\Pipeline {
                final class ConfigurationResolutionRequest
                {
                    public function __construct(mixed ...\$arguments) {}
                }

                interface ConfigurationPipelineInterface
                {
                    public function resolve(ConfigurationResolutionRequest \$request): mixed;
                }
            }

            namespace Qualimetrix\\Analysis\\Finding\\Contract {
                interface ChannelDeclarationRegistryInterface
                {
                    public function staticDeclarations(): array;
                }
            }

            namespace Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Configuration {
                interface ComputedMetricConfiguratorInterface
                {
                    public function resolve(mixed \$document): object;
                }
            }

            namespace Qualimetrix\\Infrastructure\\DependencyInjection {
                use Qualimetrix\\Analysis\\Configuration\\Contract\\Pipeline\\ConfigurationPipelineInterface;
                use Qualimetrix\\Analysis\\Configuration\\Contract\\Pipeline\\ConfigurationResolutionRequest;
                use Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Configuration\\ComputedMetricConfiguratorInterface;
                use Qualimetrix\\Analysis\\Finding\\Contract\\ChannelDeclarationRegistryInterface;
                use Qualimetrix\\Core\\Symbol\\SymbolLevel;

                final class ContainerFactory
                {
                    public function create(): object
                    {
                        \$services = [
                            ChannelDeclarationRegistryInterface::class => new class implements ChannelDeclarationRegistryInterface {
                                public function staticDeclarations(): array
                                {
                                    \$declarations = [];

                                    foreach ({$declarations} as \$channel => \$levels) {
                                        \$declarations[\$channel] = (object) ['levels' => array_map(SymbolLevel::from(...), \$levels)];
                                    }

                                    return \$declarations;
                                }
                            },
                            ConfigurationPipelineInterface::class => new class implements ConfigurationPipelineInterface {
                                public function resolve(ConfigurationResolutionRequest \$request): mixed
                                {
                                    return null;
                                }
                            },
                            ComputedMetricConfiguratorInterface::class => new class implements ComputedMetricConfiguratorInterface {
                                public function resolve(mixed \$document): object
                                {
                                    return new class {
                                        public function all(): array
                                        {
                                            return [];
                                        }
                                    };
                                }
                            },
                        ];

                        return new class (\$services) {
                            public function __construct(private readonly array \$services) {}

                            public function get(string \$id): object
                            {
                                return \$this->services[\$id];
                            }
                        };
                    }
                }
            }

            PHP;
    }
}
