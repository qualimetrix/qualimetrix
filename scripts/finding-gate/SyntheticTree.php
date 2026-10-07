<?php

declare(strict_types=1);

namespace QmxFindingGate;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

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
 * overrides are written over it. The declarations (`maps`, `declaredDelta`,
 * `fieldMoves`, and any other file under `finding-gate/` named in
 * `declarations`) land in both, and only the candidate's are read.
 *
 * @phpstan-type CaptureAnswer array{stdout?:string,stderr?:string,exit?:int}
 * @phpstan-type Answer array{stdout?: string, stderr?: string, stderrOnce?: bool, exit?: int, file?: string, cache?: bool, env?: bool, missingFile?: bool,ranked?:CaptureAnswer,physical?:CaptureAnswer,summaryIssues?:list<string>}
 * @phpstan-type Finding array<string, mixed>
 * @phpstan-type Specification array{
 *     cases: array<string, list<string>>,
 *     findings: array<string, list<Finding>>,
 *     candidateFindings: array<string, list<Finding>>,
 *     truncated: list<string>,
 *     answers: array<string, Answer>,
 *     candidateAnswers: array<string, Answer>,
 *     tuple: list<string>,
 *     published: list<string>,
 *     normalization: list<array{0: string, 1: string, 2: string}>,
 *     static: array<string, list<string>>,
 *     fixture: array<string, list<string>>,
 *     levels: list<string>,
 *     maps: array<string, list<string>>,
 *     declaredDelta: array<string, string>,
 *     fieldMoves: list<array{0: string, 1: string, 2: string, 3: string}>,
 *     declarations: array<string, string>,
 *     candidateDeclarations?: array<string, string>,
 *     lock: string,
 *     candidateLock: string|null,
 * }
 */
final class SyntheticTree
{
    private const string TUPLE_SOURCE = 'src/Reporting/Formatter/Json/JsonFindingSection.php';

    private static ?string $gitTemplate = null;

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
        $fields = ['file', 'line', 'subject', 'symbol', 'channel', 'occurrence', 'edge', 'namespace', 'rule', 'code', 'severity', 'message', 'recommendation', 'metricValue', 'threshold', 'techDebtMinutes', 'acceptedLevel'];

        return [
            'cases' => ['alpha' => ['replay.alpha@callable']],
            'findings' => ['alpha' => [self::finding($fields, 'replay.alpha', 'declaration:callable:Replay\Alpha::run@src/Alpha.php')]],
            'candidateFindings' => [],
            'truncated' => [],
            'answers' => [],
            'candidateAnswers' => [],
            'tuple' => $fields,
            'published' => $fields,
            'normalization' => [['stderr:check:output', '~^(Report written to ).*()$~m', NormalizationRule::KIND_LINE_REGEX]],
            'static' => ['replay.alpha' => ['callable']],
            'fixture' => ['replay.alpha' => ['callable']],
            'levels' => SubjectLevel::levels(),
            'maps' => [],
            'declaredDelta' => [],
            'fieldMoves' => [],
            'declarations' => [],
            'lock' => "{\"replay\": \"lock\"}\n",
            'candidateLock' => null,
        ];
    }

    /**
     * A populated case for every capture descriptor, including distinct debug and worker invocations.
     *
     * @return Specification
     */
    public static function captureFixture(): array
    {
        $tree = self::clean();
        $definition = ['id' => 'alpha', 'description' => 'Every capture invocation.', 'paths' => ['src'], 'config' => 'qmx.yaml', 'channels' => ['replay.alpha@callable'], 'explainSubjects' => ['file:src/Alpha.php'], 'layerAssignmentSubjects' => ['Replay\Alpha', 'Replay\Beta'], 'renameChannelsMap' => 'channels.tsv'];
        $tree['declarations']['cases/alpha/case.json'] = self::json($definition);
        $tree['declarations']['cases/alpha/channels.tsv'] = "old\tnew\treason\nreplay.alpha\treplay.beta\treplayed\n";
        $tree['declarations']['cases/alpha/baseline-src/src/Alpha.php'] = "<?php\n";
        for ($index = 0; $index < 101; ++$index) {
            $tree['declarations']['cases/alpha/src/Shard' . $index . '.php'] = "<?php\n";
        }

        return $tree;
    }

    /**
     * @param list<string> $fields
     *
     * @return Finding
     */
    public static function finding(array $fields, string $channel, string $subject): array
    {
        $file = str_contains($subject, '@') ? substr($subject, (int) strrpos($subject, '@') + 1) : 'src/Alpha.php';
        $symbol = preg_replace('/^declaration:(?:callable|class):/', '', explode('@', $subject)[0]);
        $values = ['file' => $file, 'line' => 1, 'subject' => $subject, 'symbol' => $symbol, 'channel' => $channel, 'occurrence' => null, 'edge' => null, 'namespace' => 'Replay', 'rule' => $channel, 'code' => $channel, 'severity' => 'error', 'message' => 'replayed', 'recommendation' => null, 'metricValue' => 1, 'threshold' => 0, 'techDebtMinutes' => 15, 'acceptedLevel' => null];
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
    public static function create(array $specification, bool $versioned = true, bool $candidate = true): string
    {
        $root = Fs::temporaryDirectory('self-test-synthetic-tree-');
        $reference = self::files($specification, $specification['findings'], $specification['answers'], $specification['lock']);

        foreach ($reference as $path => $content) {
            Fs::write($root . '/' . $path, $content);
        }

        if ($versioned) {
            if (self::$gitTemplate === null) {
                self::$gitTemplate = Fs::temporaryDirectory('synthetic-git-template-');
                self::git(['git', 'init', '--quiet'], self::$gitTemplate);
            }
            $git = self::$gitTemplate . '/.git';
            mkdir($root . '/.git/objects', 0o700, true);
            mkdir($root . '/.git/refs', 0o700, true);
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($git, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    Fs::write($root . '/.git/' . substr($file->getPathname(), \strlen($git) + 1), Fs::read($file->getPathname()));
                }
            }
            self::git(['git', 'add', '--all'], $root);
            self::git(['git', '-c', 'user.email=self-test@qmx', '-c', 'user.name=self-test', 'commit', '--quiet', '--message', 'reference'], $root);
        }
        if (!$candidate) {
            return $root;
        }

        $candidateSpecification = $specification;
        $candidateSpecification['declarations'] = [...$specification['declarations'], ...($specification['candidateDeclarations'] ?? [])];
        $candidate = self::files(
            $candidateSpecification,
            [...$specification['findings'], ...$specification['candidateFindings']],
            [...$specification['answers'], ...$specification['candidateAnswers']],
            $specification['candidateLock'] ?? $specification['lock'],
        );

        foreach ($candidate as $path => $content) {
            if ($content !== ($reference[$path] ?? null)) {
                Fs::write($root . '/' . $path, $content);
            }
        }

        return $root;
    }

    /** @param Specification $specification */
    public static function fixture(array $specification, bool $candidate = true): string
    {
        return self::create($specification, versioned: false, candidate: $candidate);
    }

    /** @param list<string> $command */
    private static function git(array $command, string $root): void
    {
        $result = Process::run($command, $root);
        if ($result['exit'] !== 0) {
            Fs::removeRecursively($root);
            throw new GateError(\sprintf("Cannot build the synthetic tree:\n%s", $result['stderr']));
        }
    }

    public static function remove(string $root): void
    {
        Fs::removeRecursively($root);
    }

    /**
     * @param Specification $specification
     * @param array<string, list<Finding>> $findings
     * @param array<string, Answer> $overrides
     *
     * @return array<string, string> path => content
     */
    private static function files(array $specification, array $findings, array $overrides, string $lock): array
    {
        $answers = ['tree|rules' => ['stdout' => "replayed rules\n"], 'tree|graph:export' => ['stdout' => "No files found to analyze\n", 'exit' => 1]];

        foreach ($specification['cases'] as $id => $claims) {
            $definition = json_decode($specification['declarations']['cases/' . $id . '/case.json'] ?? '{}', true, 512, \JSON_THROW_ON_ERROR);
            $answers += self::caseAnswers($id, $findings[$id] ?? [], \in_array($id, $specification['truncated'], true), $definition);
        }
        $rawPublications = [];
        foreach ($overrides as $key => $override) {
            foreach (['stdout', 'file'] as $field) {
                if (\array_key_exists($field, $override)) {
                    $rawPublications[$key][] = $field;
                }
            }
            foreach (['ranked', 'physical'] as $slot) {
                if (isset($override[$slot]) && \array_key_exists('stdout', $override[$slot])) {
                    $rawPublications[$key][] = $slot;
                }
            }
            $original = $answers[$key] ?? [];
            $answers[$key] = array_replace($original, $override);
            if (\array_key_exists('stdout', $override) && !\array_key_exists('summaryIssues', $override)) {
                unset($answers[$key]['summaryIssues']);
            }
            foreach (['ranked', 'physical'] as $slot) {
                if (isset($override[$slot], $original[$slot])) {
                    $answers[$key][$slot] = array_replace($original[$slot], $override[$slot]);
                }
            }
        }

        $files = [
            'bin/qmx' => self::replayingBinary(),
            'src/Infrastructure/Console/Refusal/RefusalPresenter.php' => '<?php final class RefusalPresenter { private function writeEnvelope() { return json_encode(["error" => "replayed", "exit_code" => 3, "position" => null]); } }',
            'replay/raw-publications.json' => self::json($rawPublications),
            self::ANSWERS => json_encode($answers, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n",
            'vendor/autoload.php' => self::probedProduct($specification['static'], $specification['levels']),
            'composer.lock' => $lock,
            'qmx.yaml' => "# replayed\n",
            'src/Analysis/Evidence/Measurement/Contract/AggregationStrategy.php' => "<?php\n\nenum AggregationStrategy: string\n{\n    case Sum = 'sum';\n}\n",
            'src/Analysis/Evidence/Measurement/Contract/MetricName.php' => "<?php\n\nfinal class MetricName\n{\n    public const string CCN = 'ccn';\n}\n",
            self::TUPLE_SOURCE => self::publishingSource($specification['published']),
            'src/Reporting/Formatter/Json/JsonFormatter.php' => self::rankingSource(),
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
            $files['finding-gate/maps/' . $map . '.tsv'] = "old\tnew\treason\n"
                . implode('', array_map(static fn(string $row): string => $row . "\n", $specification['maps'][$map] ?? []));
        }

        if ($specification['declaredDelta'] !== []) {
            $rows = [];

            foreach ($specification['declaredDelta'] as $surface => $diff) {
                $file = DeclaredDelta::DIRECTORY . '/' . md5($surface) . '.diff';
                $files['finding-gate/' . $file] = $diff;
                $rows[] = [$surface, $file, 'self-test'];
            }

            $files['finding-gate/' . DeclaredDelta::INDEX] = Tsv::render(DeclaredDelta::COLUMNS, $rows);
        }

        if ($specification['fieldMoves'] !== []) {
            $files['finding-gate/' . DeclaredFieldMoves::INDEX] = Tsv::render(
                DeclaredFieldMoves::COLUMNS,
                array_map(static fn(array $move): array => [...$move, 'self-test'], $specification['fieldMoves']),
            );
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
            $files['finding-gate/cases/' . $id . '/src/' . ucfirst($id) . '.php'] = "<?php\n";
        }

        // Last, so a declared file may also stand in for one this tree writes itself, such as a `case.json`.
        foreach ($specification['declarations'] as $path => $content) {
            $files['finding-gate/' . $path] = $content;
        }

        return $files;
    }

    /**
     * Every surface a case run captures, readable and agreeing with the findings.
     *
     * @param list<Finding> $findings
     * @param array<string,mixed> $definition
     *
     * @return array<string, Answer>
     */
    public static function caseAnswers(string $id, array $findings, bool $truncated, array $definition): array
    {
        $scope = 'case:' . $id;
        $expected = Fingerprints::expected($findings);
        $answers = [];

        foreach (Surfaces::FORMATS as $format) {
            $answers[Surfaces::key($scope, 'format:' . $format)] = ['stdout' => \sprintf("replayed %s of %s\n", $format, $id)];
        }

        $full = self::findingPublication($findings);
        $published = $full;
        if ($truncated && $findings !== []) {
            $published['violations'] = \array_slice($findings, 0, \count($findings) - 1);
            $published['violationsMeta']['shown'] = \count($published['violations']);
            $published['violationsMeta']['truncated'] = true;
        }
        $findingAnswer = ['stdout' => self::json($published), 'ranked' => ['stdout' => self::json($published)], 'physical' => ['stdout' => self::json($full)]];
        $answers[Surfaces::key($scope, 'format:json')] = $findingAnswer;
        $answers[Surfaces::key($scope, 'format:summary')] = ['stdout' => '', 'summaryIssues' => self::summaryIssues($full['topIssues'], $findings)];
        $sarif = [];
        $gitlab = [];
        $html = [];
        $checkstyle = '<?xml version="1.0"?><checkstyle>';
        $prose = '';
        $github = '';
        $codes = array_values(array_unique(array_map(static fn(array $finding): string => (string) ($finding['code'] ?? $finding['channel'] ?? 'replay.alpha'), $findings)));
        sort($codes);
        $ruleIndexes = array_flip($codes);
        $rules = array_map(static fn(string $code): array => ['id' => $code], $codes);
        foreach ($findings as $index => $finding) {
            $finding += self::finding(self::clean()['tuple'], (string) ($finding['channel'] ?? 'replay.alpha'), (string) ($finding['subject'] ?? 'file:src/Alpha.php'));
            $file = $finding['file'];
            $line = $finding['line'];
            $message = $finding['message'];
            $suffix = '';
            if ($finding['acceptedLevel'] !== null) {
                $suffix = ' (accepted at ' . $finding['acceptedLevel']['describe'];
                if ($finding['acceptedLevel']['shape'] === 'magnitude' && (\is_int($finding['metricValue']) || (\is_float($finding['metricValue']) && is_finite($finding['metricValue'])))) {
                    $suffix .= ', now ' . (\is_int($finding['metricValue']) ? (string) $finding['metricValue'] : rtrim(rtrim(\sprintf('%.6F', $finding['metricValue']), '0'), '.'));
                }
                $suffix .= ')';
            }
            $annotatedMessage = $message . $suffix;
            $code = $finding['code'];
            $sarif[] = ['ruleId' => $code, 'ruleIndex' => $ruleIndexes[$code], 'level' => $finding['severity'] === 'info' ? 'note' : $finding['severity'], 'message' => ['text' => $annotatedMessage], 'partialFingerprints' => ['primaryLocationLineHash' => $expected[$index]], 'locations' => [['physicalLocation' => ['artifactLocation' => ['uri' => $file], 'region' => ['startLine' => $line]]]]];
            $gitlab[] = ['description' => $annotatedMessage, 'check_name' => $code, 'severity' => match ($finding['severity']) {
                'error' => 'critical', 'warning' => 'major', default => 'info',
            }, 'fingerprint' => md5($expected[$index]), 'location' => ['path' => $file ?? '_project', 'lines' => ['begin' => $line]]];
            $html[] = ['subject' => $finding['subject'], 'ruleName' => $finding['rule'], 'violationCode' => $code, 'message' => $message, 'recommendation' => $finding['recommendation'], 'severity' => $finding['severity'], 'metricValue' => $finding['metricValue'], 'symbolPath' => $finding['symbol'], 'occurrence' => $finding['occurrence'], 'file' => $file, 'line' => $line];
            $checkstyle .= '<file name="' . htmlspecialchars((string) $file, \ENT_XML1) . '"><error line="' . $line . '" severity="' . $finding['severity'] . '" source="qmx.' . $code . '" message="' . htmlspecialchars($annotatedMessage, \ENT_XML1) . '"/></file>';
            $brief = (string) $finding['symbol'];
            $separator = strrpos($brief, '\\');
            if ($separator !== false) {
                $brief = substr($brief, $separator + 1);
            }
            if (\in_array(SubjectLevel::of((string) $finding['subject']), ['file', 'project', 'namespace'], true) || $finding['symbol'] === $file) {
                $brief = '';
            }
            $advice = ($finding['recommendation'] ?? $message) . $suffix;
            $severity = match ($finding['severity']) {
                'error' => 'ERROR', 'warning' => 'WARN', default => 'INFO',
            };
            $prose .= '  ' . $severity . ' ' . $file . ':' . $line . ($brief === '' ? '' : '  ' . $brief) . "\n    " . $advice . '  [' . $code . "]\n";
            $escape = static fn(string $value): string => strtr($value, ['%' => '%25', "\r" => '%0D', "\n" => '%0A', ':' => '%3A', ',' => '%2C']);
            $github .= '::' . ($finding['severity'] === 'info' ? 'notice' : $finding['severity']) . ' file=' . $escape((string) $file) . ',line=' . $line . ',title=' . $escape((string) $code) . '::' . strtr($annotatedMessage, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']) . "\n";
        }
        $answers[Surfaces::key($scope, 'format:sarif')] = ['stdout' => self::json(['runs' => [['tool' => ['driver' => ['rules' => $rules]], 'results' => $sarif]]])];
        $answers[Surfaces::key($scope, 'format:gitlab')] = ['stdout' => self::json($gitlab)];
        $answers[Surfaces::key($scope, 'format:html')] = ['stdout' => '<html><script type="application/json" id="report-data">' . self::json(['violations' => $html]) . '</script></html>' . "\n"];
        $answers[Surfaces::key($scope, 'format:checkstyle')] = ['stdout' => $checkstyle . '</checkstyle>'];
        $answers[Surfaces::key($scope, 'format:text')] = ['stdout' => $prose === '' ? "No findings\n" : $prose];
        $answers[Surfaces::key($scope, 'format:github')] = ['stdout' => $github === '' ? "No findings\n" : $github];
        $answers[Surfaces::key($scope, 'format:text-detail')] = $answers[Surfaces::key($scope, 'format:text')];
        $answers[Surfaces::key($scope, 'format:text-verbose')] = $answers[Surfaces::key($scope, 'format:text')];
        $answers[Surfaces::key($scope, 'format:suppressed')] = ['stdout' => self::json(['suppressed' => [], 'byMechanism' => [], 'neverMatched' => []])];
        $answers[Surfaces::key($scope, 'show-suppressed')] = $answers[Surfaces::key($scope, 'format:text')];
        $json = $answers[Surfaces::key($scope, 'format:json')]['stdout'];
        $answers[Surfaces::key($scope, 'format:metrics')] = ['stdout' => self::json(['symbols' => [['type' => 'method', 'name' => 'Replay\\' . ucfirst($id) . '::run', 'file' => 'src/' . ucfirst($id) . '.php', 'line' => 1, 'metrics' => ['ccn' => 1]]]])];
        $answers[Surfaces::key($scope, 'directives')] = ['stdout' => self::json(['directives' => [['file' => 'src/' . ucfirst($id) . '.php', 'line' => 1, 'form' => 'symbol', 'target' => 'replay.alpha', 'effect' => 'applied', 'reason' => 'replayed', 'masked_by' => null, 'boundary_observable' => true, 'refusals' => []]], 'exit_code' => 0])];
        $answers[Surfaces::key($scope, 'graph:export')] = ['stdout' => "digraph replay { A -> B; }\n"];
        $answers[Surfaces::key($scope, 'rules')] = ['stdout' => "replayed rules\n"];
        $baselineEntries = [];
        foreach ($findings as $finding) {
            $entry = ['channel' => $finding['channel'], 'count' => 1];
            if (($finding['metricValue'] ?? null) !== null) {
                unset($entry['count']);
                $entry['magnitudes'] = [round((float) $finding['metricValue'], 6)];
            }
            foreach (['occurrence', 'edge'] as $identity) {
                if (($finding[$identity] ?? null) !== null) {
                    $entry[$identity] = $finding[$identity];
                }
            }
            $baselineEntries[(string) $finding['subject']][] = $entry;
        }
        $baseline = self::json(['version' => 13, 'scope' => ['src'], 'entries' => $baselineEntries]);
        $answers[Surfaces::key($scope, 'baseline-file')] = ['stdout' => $baseline, 'file' => $baseline];
        $answers[Surfaces::key($scope, 'check:output')] = ['stdout' => '', 'file' => $json, 'stderr' => "Report written to {{output}}\n"];
        $empty = self::json(self::findingPublication([]));
        $answers[Surfaces::key($scope, 'check:baseline')] = ['stdout' => $empty, 'ranked' => ['stdout' => $empty], 'physical' => ['stdout' => $empty]];
        $answers[Surfaces::key($scope, 'check:baseline-source')] = $findingAnswer;
        $answers[Surfaces::key($scope, 'check:parallel')] = ['stdout' => $json];
        foreach ($definition['explainSubjects'] ?? [] as $subject) {
            $answers[Surfaces::key($scope, 'explain:' . $subject)] = ['stdout' => "replayed boundary\n"];
        }
        foreach ($definition['layerAssignmentSubjects'] ?? [] as $subject) {
            $answers[Surfaces::key($scope, 'debug:layer-assignment:' . $subject)] = ['stdout' => self::json(['fqn' => $subject, 'assigned' => ['layer' => 'replayed']])];
        }
        foreach (['baseline:update', 'baseline:cleanup', 'baseline:rename-channels'] as $command) {
            $answers[Surfaces::key($scope, $command)] = ['stdout' => $command === 'baseline:cleanup' ? "  abcdef123456  replayed stale entry\n" : "replayed baseline operation\n", 'file' => $baseline];
        }

        return $answers;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array{violations:list<Finding>,violationsMeta:array{total:int,shown:int,limit:null,truncated:bool,byRule:array<string,int>},topIssues:list<array<string,mixed>>}
     */
    private static function findingPublication(array $findings): array
    {
        $issues = [];
        $counts = [];
        foreach ($findings as $finding) {
            $finding += self::finding(self::clean()['tuple'], (string) ($finding['channel'] ?? 'replay.alpha'), (string) ($finding['subject'] ?? 'file:src/Alpha.php'));
            $rule = (string) $finding['rule'];
            $counts[$rule] = ($counts[$rule] ?? 0) + 1;
            $weight = match ($finding['severity']) {
                'error' => 3, 'warning' => 1, default => 0,
            };
            $issues[] = [
                'rank' => 0,
                'file' => $finding['file'],
                'line' => $finding['line'],
                'symbol' => $finding['symbol'],
                'rule' => $finding['rule'],
                'severity' => $finding['severity'],
                'message' => $finding['message'],
                'recommendation' => $finding['recommendation'],
                'impactScore' => (float) ($weight * (int) $finding['techDebtMinutes']),
                'coupling.class-rank' => 1.0,
                'debtMinutes' => $finding['techDebtMinutes'],
            ];
        }
        usort($issues, static function (array $a, array $b): int {
            $score = $b['impactScore'] <=> $a['impactScore'];
            if ($score !== 0) {
                return $score;
            }
            $file = ($a['file'] ?? '') <=> ($b['file'] ?? '');
            return $file !== 0 ? $file : (($a['line'] ?? 0) <=> ($b['line'] ?? 0));
        });
        foreach ($issues as $index => &$issue) {
            $issue['rank'] = $index + 1;
        }
        unset($issue);
        ksort($counts);
        return ['violations' => $findings, 'violationsMeta' => ['total' => \count($findings), 'shown' => \count($findings), 'limit' => null, 'truncated' => false, 'byRule' => $counts], 'topIssues' => $issues];
    }

    /**
     * @param list<array<string,mixed>> $issues
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private static function summaryIssues(array $issues, array $findings): array
    {
        $lines = [];
        foreach ($issues as $issue) {
            $finding = null;
            foreach ($findings as $index => $candidate) {
                $candidate += self::finding(self::clean()['tuple'], (string) ($candidate['channel'] ?? 'replay.alpha'), (string) ($candidate['subject'] ?? 'file:src/Alpha.php'));
                if (array_intersect_key($issue, array_flip(['file', 'line', 'symbol', 'rule', 'severity', 'message', 'recommendation'])) === array_intersect_key($candidate, array_flip(['file', 'line', 'symbol', 'rule', 'severity', 'message', 'recommendation']))
                    && $issue['debtMinutes'] === ($candidate['techDebtMinutes'] ?? null)) {
                    $finding = $candidate;
                    unset($findings[$index]);
                    break;
                }
            }
            if ($finding === null) {
                throw new GateError('A synthetic ranking has no physical summary finding.');
            }
            $score = (float) $issue['impactScore'];
            $tag = match ($issue['severity']) {
                'error' => 'ERR', 'warning' => 'WRN', default => 'INF',
            };
            $location = $issue['file'] === null ? '[project]' : (string) $issue['file'] . ($issue['line'] === null ? '' : ':' . $issue['line']);
            $minutes = (int) $issue['debtMinutes'];
            $debt = [];
            if ($minutes >= 480) {
                $debt[] = intdiv($minutes, 480) . 'd';
            }
            if ($minutes % 480 >= 60) {
                $debt[] = intdiv($minutes % 480, 60) . 'h';
            }
            if ($minutes % 60 > 0) {
                $debt[] = $minutes % 60 . 'min';
            }
            $symbol = (string) $finding['symbol'];
            $level = SubjectLevel::of((string) $finding['subject']);
            if (\in_array($level, ['class', 'file', 'project'], true) || $symbol === $finding['file']) {
                $symbol = '';
            } elseif ($level === 'namespace') {
                $symbol = $symbol === '' ? '' : 'namespace: ' . $symbol;
            } else {
                $symbol = substr($symbol, (int) strrpos('\\' . $symbol, '\\'));
            }
            $lines[] = '  ' . $issue['rank'] . '. [' . $tag . '] ' . \sprintf($score >= 100 ? '%.0f' : ($score >= 10 ? '%.1f' : '%.2f'), $score) . '  ' . $location . '  [' . ($debt === [] ? '0min' : implode(' ', $debt)) . "]\n"
                . str_repeat(' ', \strlen((string) $issue['rank']) + 8) . $finding['code'] . ': ' . ReportRecords::message($finding, true) . ($symbol === '' ? '' : ' (' . $symbol . ')') . "\n";
        }
        return $lines;
    }

    private static function rankingSource(): string
    {
        $fields = ['rank', 'file', 'line', 'symbol', 'rule', 'severity', 'message', 'recommendation', 'impactScore', 'coupling.class-rank', 'debtMinutes'];
        $members = implode('', array_map(static fn(string $field): string => "                '" . $field . "' => null,\n", $fields));
        return "<?php\nfinal class JsonFormatter\n{\n    private function formatTopIssues(): array\n    {\n        \$result = [];\n        foreach ([] as \$issue) {\n            \$result[] = [\n" . $members . "            ];\n        }\n        return \$result;\n    }\n}\n";
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
            $rawPublications = json_decode((string) file_get_contents($tree . '/replay/raw-publications.json'), true);
            $arguments = array_slice($argv, 1);
            $command = $arguments[0] ?? '';
            $key = getenv('QMX_GATE_INVOCATION');
            if (!is_string($key) || $key === '' || !str_contains($key, '|')) {
                fwrite(STDERR, "replay: no exact invocation key\n");
                exit(70);
            }
            $answer = $answers[$key] ?? null;

            if (!is_array($answer)) {
                fwrite(STDERR, "replay: no answer for {$key}\n");
                exit(70);
            }

            $capture = getenv('QMX_GATE_CAPTURE');
            if ($capture !== false && $capture !== '') {
                if (!in_array($capture, ['ranked', 'physical'], true) || !is_array($answer[$capture] ?? null)) {
                    fwrite(STDERR, "replay: no private capture answer for {$key}\n");
                    exit(70);
                }
                $answer = array_replace($answer, $answer[$capture]);
            }

            $top = 10;
            $detail = null;
            $cap = null;
            for ($index = 0; $index < count($arguments); ++$index) {
                $argument = $arguments[$index];
                if ($argument === '--top') {
                    $top = (int) ($arguments[++$index] ?? 0);
                } elseif (str_starts_with($argument, '--top=')) {
                    $top = (int) substr($argument, 6);
                } elseif ($argument === '--all') {
                    $detail = 0;
                    $cap = 0;
                } elseif ($argument === '--detail' || str_starts_with($argument, '--detail=')) {
                    $value = $argument === '--detail' ? null : substr($argument, 9);
                    if ($argument === '--detail' && isset($arguments[$index + 1]) && !str_starts_with($arguments[$index + 1], '-')) {
                        $value = $arguments[++$index];
                    }
                    $detail = $value === null ? 200 : ($value === 'all' ? 0 : (int) $value);
                } elseif ($argument === '--format-opt' || str_starts_with($argument, '--format-opt=')) {
                    $pair = $argument === '--format-opt' ? ($arguments[++$index] ?? '') : substr($argument, 13);
                    [$name, $value] = explode('=', $pair, 2) + ['', ''];
                    if (in_array($name, ['violations', 'limit'], true)) {
                        $cap = $value === 'all' ? 0 : (int) $value;
                    }
                }
            }

            $stdout = strtr((string) ($answer['stdout'] ?? ''), [
                '{{tree}}' => dirname($argv[0], 2),
                '{{random}}' => bin2hex(random_bytes(8)),
            ]);
            $valueEnd = static function (string $text, int $start): int {
                if ($text[$start] === '"') {
                    for ($at = $start + 1; $at < strlen($text); ++$at) {
                        if ($text[$at] === '\\') {
                            ++$at;
                        } elseif ($text[$at] === '"') {
                            return $at + 1;
                        }
                    }
                } elseif (in_array($text[$start], ['[', '{'], true)) {
                    $depth = 0;
                    $quoted = false;
                    for ($at = $start; $at < strlen($text); ++$at) {
                        $character = $text[$at];
                        if ($quoted) {
                            if ($character === '\\') { ++$at; }
                            elseif ($character === '"') { $quoted = false; }
                        } elseif ($character === '"') {
                            $quoted = true;
                        } elseif ($character === '[' || $character === '{') {
                            ++$depth;
                        } elseif ($character === ']' || $character === '}') {
                            if (--$depth === 0) { return $at + 1; }
                        }
                    }
                } else {
                    return $start + strcspn($text, ",]} \t\r\n", $start);
                }
                throw new RuntimeException('A replay JSON value has no closing delimiter.');
            };
            $sliceList = static function (string $text, string $field, int $limit) use ($valueEnd): string {
                $at = strspn($text, " \t\r\n") + 1;
                while (isset($text[$at])) {
                    $at += strspn($text, " \t\r\n", $at);
                    if ($text[$at] === '}') { break; }
                    $keyEnd = $valueEnd($text, $at);
                    $key = json_decode(substr($text, $at, $keyEnd - $at), true);
                    $start = $keyEnd + strspn($text, " \t\r\n", $keyEnd) + 1;
                    $start += strspn($text, " \t\r\n", $start);
                    $end = $valueEnd($text, $start);
                    if ($key === $field && $text[$start] === '[') {
                        $spans = [];
                        $element = $start + 1;
                        while (true) {
                            $element += strspn($text, " \t\r\n", $element);
                            if ($text[$element] === ']') { break; }
                            $elementEnd = $valueEnd($text, $element);
                            $spans[] = [$element, $elementEnd];
                            $element = $elementEnd + strspn($text, " \t\r\n", $elementEnd);
                            if ($text[$element] === ']') { break; }
                            ++$element;
                        }
                        if (count($spans) <= $limit) { return $text; }
                        $replacement = $limit === 0 ? '[]' : substr($text, $start, $spans[$limit - 1][1] - $start)
                            . substr($text, $spans[count($spans) - 1][1], $end - $spans[count($spans) - 1][1]);
                        return substr($text, 0, $start) . $replacement . substr($text, $end);
                    }
                    $at = $end + strspn($text, " \t\r\n", $end);
                    if ($text[$at] === '}') { break; }
                    ++$at;
                }
                return $text;
            };
            $renderPayload = static function (string $written, bool $raw) use ($top, $cap, $detail, $sliceList): string {
                $payload = json_decode($written, true);
                if (!is_array($payload) || !is_array($payload['topIssues'] ?? null)) {
                    return $written;
                }
                $limit = $cap ?? $detail;
                if (!$raw && $limit !== null && $limit > 0 && is_array($payload['violations'] ?? null) && count($payload['violations']) > $limit) {
                    $payload['violations'] = array_slice($payload['violations'], 0, $limit);
                    $payload['violationsMeta']['shown'] = count($payload['violations']);
                    $payload['violationsMeta']['limit'] = $limit;
                    $payload['violationsMeta']['truncated'] = true;
                    $written = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                }
                return $sliceList($written, 'topIssues', max(0, $top));
            };
            $rawSlots = $rawPublications[$key] ?? [];
            $stdout = $renderPayload($stdout, in_array($capture === false || $capture === '' ? 'stdout' : $capture, $rawSlots, true));
            $payload = json_decode($stdout, true);
            if (is_array($answer['summaryIssues'] ?? null)) {
                $rows = array_slice($answer['summaryIssues'], 0, max(0, $top));
                $stdout = "Analysis complete\n" . ($rows === [] ? '' : "\nTop issues by impact\n" . implode('', $rows));
            }
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

            if (($answer['env'] ?? false) === true) {
                $environment = ['invocation' => $key, 'cache' => getenv('XDG_CACHE_HOME'), 'locale' => getenv('LC_ALL'), 'timezone' => getenv('TZ'), 'argv' => $arguments, 'cwd' => getcwd()];
                $stdout = json_encode(is_array($payload) && array_key_exists('violationsMeta', $payload) ? array_replace($payload, $environment) : $environment) . "\n";
            }
            if ($command === 'baseline:generate') {
                @mkdir(getenv('XDG_CACHE_HOME') . '/qmx', 0o700, true);
            }
            if (($answer['cache'] ?? true) === true && $command === 'baseline:generate') {
                $cache = getenv('XDG_CACHE_HOME') . '/qmx/replay/record';
                @mkdir(dirname($cache), 0o700, true);
                file_put_contents($cache, 'cached parser record');
            }
            $target = null;
            if (str_starts_with($command, 'baseline:') && $command !== 'baseline:explain') {
                $target = $arguments[1] ?? null;
            }
            foreach ($arguments as $argument) {
                if (str_starts_with($argument, '--output=')) {
                    $target = substr($argument, 9);
                }
            }
            if ($command === 'check' && $target !== null) {
                $stderr = str_replace('{{output}}', $target, (string) ($answer['stderr'] ?? 'Report written to {{output}}' . "\n"));
            }
            if ($target !== null && ($answer['missingFile'] ?? false) !== true) {
                @mkdir(dirname($target), 0o700, true);
                // Existing stdout overrides remain authoritative for the historical baseline surface.
                file_put_contents($target, $command === 'baseline:generate' ? $stdout : $renderPayload((string) ($answer['file'] ?? $stdout), in_array('file', $rawSlots, true)));
            }
            if ($command !== 'baseline:generate') {
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
                final class RelativePath
                {
                    public static function fromString(string \$value): self { return new self(\$value); }
                    public function __construct(public readonly string \$value) {}
                }

                final class AbsolutePath
                {
                    public static function fromString(string \$path): self
                    {
                        return new self();
                    }
                }
            }

            namespace Qualimetrix\\Core\\Symbol {
                final class SymbolPath
                {
                    public static function forFile(\\Qualimetrix\\Core\\Path\\RelativePath \$path): self { return new self(\$path); }
                    public function __construct(public readonly \\Qualimetrix\\Core\\Path\\RelativePath \$path) {}
                    public function toCanonical(): string { return 'file:' . \$this->path->value; }
                }
                final class MetricSubject
                {
                    public static function aggregate(SymbolPath \$path): self { return new self(\$path); }
                    public function __construct(private readonly SymbolPath \$path) {}
                    public function toCanonical(): string { return \$this->path->toCanonical(); }
                    public function toSymbolPath(): SymbolPath { return \$this->path; }
                }
            }

            namespace Qualimetrix\\Core\\Time {
                final class SystemClock {}
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
                interface ChannelUniverseInterface extends ChannelDeclarationRegistryInterface {}
                final class Location
                {
                    public static function none(): self { return new self(); }
                }
                enum Severity: string { case Error = 'error'; }
                final class Finding
                {
                    public function __construct(
                        public Location \$location,
                        public \\Qualimetrix\\Core\\Symbol\\MetricSubject \$subject,
                        public \\Qualimetrix\\Core\\Symbol\\SymbolPath \$symbolPath,
                        public string \$ruleName,
                        public string \$code,
                        public string \$message,
                        public Severity \$severity,
                        public int|float|null \$metricValue,
                    ) {}
                }
            }

            namespace Qualimetrix\\Infrastructure\\Rule\\Contract {
                interface RuleChannelSnapshotFactoryInterface
                {
                    public function snapshot(object \$definitions): \\Qualimetrix\\Analysis\\Finding\\Contract\\ChannelUniverseInterface;
                }
            }

            namespace Qualimetrix\\Analysis\\Policy\\Baseline {
                final class BaselineGenerator
                {
                    public function __construct(object \$channels, object \$clock) {}
                    public function generate(array \$findings, array \$scope): object
                    {
                        \$entries = [];
                        foreach (\$findings as \$finding) {
                            \$entries[] = (object) ['identity' => (object) ['subjectKey' => \$finding->subject->toCanonical()]];
                        }
                        return (object) ['baseline' => (object) ['entries' => \$entries], 'uncaptured' => []];
                    }
                }
            }

            namespace Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Configuration {
                interface ComputedMetricConfiguratorInterface
                {
                    public function resolve(mixed \$document): object;
                }
            }

            namespace Symfony\\Component\\Console {
                final class Application {}
            }

            namespace Symfony\\Component\\Console\\Command {
                final class Command
                {
                    public function __construct(string \$name) {}
                    public function getDefinition(): object { return new \stdClass(); }
                    public function setApplication(\Symfony\\Component\\Console\\Application \$application): void {}
                    public function mergeApplicationDefinition(bool \$mergeArguments): void {}
                }
            }

            namespace Symfony\\Component\\Console\\Input {
                final class ArgvInput
                {
                    public function __construct(public readonly array \$tokens, object \$definition) {}
                }
            }

            namespace Qualimetrix\\Infrastructure\\Rule {
                interface RuleRegistryInterface {}
            }

            namespace Qualimetrix\\Infrastructure\\Console {
                use Qualimetrix\\Analysis\\Configuration\\Contract\\Pipeline\\ConfigurationPipelineInterface;
                use Qualimetrix\\Analysis\\Configuration\\Contract\\Pipeline\\ConfigurationResolutionRequest;
                use Qualimetrix\\Infrastructure\\Rule\\RuleRegistryInterface;
                use Symfony\\Component\\Console\\Command\\Command;
                use Symfony\\Component\\Console\\Input\\ArgvInput;

                final class CheckCommandDefinition
                {
                    public static function addOptions(Command \$command, RuleRegistryInterface \$rules): array { return []; }
                }

                final class ErrorStream {}

                final class ConfigurationInputAdapter
                {
                    public function __construct(ConfigurationPipelineInterface \$pipeline, ErrorStream \$errors) {}
                    public function adapt(ArgvInput \$input, string \$directory): ConfigurationResolutionRequest
                    {
                        if (\$input->tokens === []) { throw new \RuntimeException('A replay probe requires its complete argv.'); }
                        return new ConfigurationResolutionRequest(\$directory, \$input->tokens);
                    }
                }
            }

            namespace Qualimetrix\\Infrastructure\\DependencyInjection {
                use Qualimetrix\\Analysis\\Configuration\\Contract\\Pipeline\\ConfigurationPipelineInterface;
                use Qualimetrix\\Analysis\\Configuration\\Contract\\Pipeline\\ConfigurationResolutionRequest;
                use Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Configuration\\ComputedMetricConfiguratorInterface;
                use Qualimetrix\\Analysis\\Finding\\Contract\\ChannelDeclarationRegistryInterface;
                use Qualimetrix\\Analysis\\Finding\\Contract\\ChannelUniverseInterface;
                use Qualimetrix\\Core\\Symbol\\SymbolLevel;
                use Qualimetrix\\Infrastructure\\Rule\\RuleRegistryInterface;

                final class ContainerFactory
                {
                    public function create(): object
                    {
                        \$services = [
                            RuleRegistryInterface::class => new class implements RuleRegistryInterface {},
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
                            ChannelUniverseInterface::class => new class implements ChannelUniverseInterface, \\Qualimetrix\\Infrastructure\\Rule\\Contract\\RuleChannelSnapshotFactoryInterface {
                                public function staticDeclarations(): array { return []; }
                                public function snapshot(object \$definitions): ChannelUniverseInterface { return \$this; }
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
