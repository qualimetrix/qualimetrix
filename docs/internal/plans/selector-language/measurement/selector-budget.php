<?php

declare(strict_types=1);

use Qualimetrix\Core\Pattern\SelectorDefinition;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

const PATTERN_DELIMITER = '~';
const MATCH_LIMIT = 100000;
const DEPTH_LIMIT = 1000;
const WARM_ITERATIONS = 200000;
const COLD_ITERATIONS = 500;
const ADVERSARIAL_ITERATIONS = 100;

/**
 * Reproduces the P0 census and PCRE probes from selector-budget.md.
 *
 * It deliberately scans only tracked qmx configuration documents and presets;
 * Markdown examples and arbitrary YAML used by CI are not product selector input.
 * Architecture's capture/binding `patterns` DSL is an explicitly separate
 * language and is therefore not part of the common-selector census.
 *
 * Run from the repository root:
 * php docs/internal/plans/selector-language/measurement/selector-budget.php
 */

function trackedConfigurationFiles(): array
{
    $output = shell_exec('git ls-files -z -- "*qmx.yaml" "src/Analysis/Configuration/Preset/*.yaml"');
    if ($output === null) {
        throw new RuntimeException('Unable to enumerate tracked configuration files.');
    }

    return array_values(array_filter(explode("\0", $output)));
}

/**
 * @param array<string, array{values: list<string>, surface: string}> $lists
 * @param list<string> $path
 */
function collectSelectors(mixed $node, string $file, array $path, array &$lists): void
{
    if (!is_array($node)) {
        return;
    }

    foreach ($node as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        $childPath = [...$path, $key];
        $pathText = implode('.', $childPath);

        if (in_array($key, ['suppress_paths', 'suppress_namespaces', 'framework_namespaces', 'include_namespaces'], true)
            || ($key === 'exclude' && $path === [])) {
            recordStringList($value, $file, $pathText, $lists);
            continue;
        }

        if ($key === 'suppress_namespace_channels' && is_array($value)) {
            foreach ($value as $channel => $channelValues) {
                if (is_string($channel)) {
                    recordStringList($channelValues, $file, $pathText . '.' . $channel, $lists);
                }
            }

            continue;
        }

        collectSelectors($value, $file, $childPath, $lists);
    }
}

/**
 * @param array<string, array{values: list<string>, surface: string}> $lists
 */
function recordStringList(mixed $value, string $file, string $surface, array &$lists): void
{
    if (!is_array($value)) {
        throw new RuntimeException(sprintf('%s:%s must be a selector list.', $file, $surface));
    }

    if (count($value) > SelectorDefinition::MAX_SELECTOR_COUNT) {
        throw new RuntimeException(sprintf(
            '%s:%s contains %d selectors; the limit is %d.',
            $file,
            $surface,
            count($value),
            SelectorDefinition::MAX_SELECTOR_COUNT,
        ));
    }

    $strings = [];
    foreach ($value as $index => $selector) {
        if (!is_array($selector) || count($selector) !== 1) {
            throw new RuntimeException(sprintf(
                '%s:%s[%s] must contain exactly one selector kind.',
                $file,
                $surface,
                (string) $index,
            ));
        }

        $kind = array_key_first($selector);
        $authoredValue = $selector[$kind];
        if (!is_string($kind)
            || !in_array($kind, ['exact', 'subtree', 'regex'], true)
            || !is_string($authoredValue)
            || $authoredValue === '') {
            throw new RuntimeException(sprintf(
                '%s:%s[%s] must be a non-empty exact, subtree, or regex selector.',
                $file,
                $surface,
                (string) $index,
            ));
        }

        if (strlen($authoredValue) > SelectorDefinition::MAX_PATTERN_LENGTH) {
            throw new RuntimeException(sprintf(
                '%s:%s[%s] is %d bytes; the limit is %d.',
                $file,
                $surface,
                (string) $index,
                strlen($authoredValue),
                SelectorDefinition::MAX_PATTERN_LENGTH,
            ));
        }

        $strings[] = $authoredValue;
    }

    $lists[$file . ':' . $surface] = [
        'values' => $strings,
        'surface' => $surface,
    ];
}

function rendered(string $body): string
{
    return PATTERN_DELIMITER
        . '(*LIMIT_MATCH=' . MATCH_LIMIT . ')'
        . '(*LIMIT_DEPTH=' . DEPTH_LIMIT . ')'
        . '\\A(?:' . $body . ')\\z'
        . PATTERN_DELIMITER;
}

/** @return array{result: int|false, error: int, message: string, span: array{0: string, 1: int}|null, elapsedMs: float} */
function matchReport(string $pattern, string $subject): array
{
    $startedAt = hrtime(true);
    $result = preg_match($pattern, $subject, $matches, PREG_OFFSET_CAPTURE);

    return [
        'result' => $result,
        'error' => preg_last_error(),
        'message' => preg_last_error_msg(),
        'span' => $matches[0] ?? null,
        'elapsedMs' => (hrtime(true) - $startedAt) / 1_000_000,
    ];
}

function warmNanosecondsPerOperation(string $pattern, string $subject): float
{
    preg_match($pattern, $subject, $ignored, PREG_OFFSET_CAPTURE);
    $startedAt = hrtime(true);

    for ($index = 0; $index < WARM_ITERATIONS; $index++) {
        preg_match($pattern, $subject, $ignored, PREG_OFFSET_CAPTURE);
    }

    return (hrtime(true) - $startedAt) / WARM_ITERATIONS;
}

function coldMicrosecondsPerOperation(string $body, string $subject): float
{
    $startedAt = hrtime(true);

    for ($index = 0; $index < COLD_ITERATIONS; $index++) {
        // A unique PCRE comment defeats PHP's pattern cache without changing matches.
        preg_match(rendered($body . '(?#cold-' . $index . ')'), $subject, $ignored, PREG_OFFSET_CAPTURE);
    }

    return (hrtime(true) - $startedAt) / COLD_ITERATIONS / 1_000;
}

/** @return array{min: float, median: float, p95: float, max: float, errors: array<int, int>} */
function adversarialDistribution(string $pattern, string $subject): array
{
    $elapsed = [];
    $errors = [];

    for ($index = 0; $index < ADVERSARIAL_ITERATIONS; $index++) {
        $report = matchReport($pattern, $subject);
        $elapsed[] = $report['elapsedMs'];
        $errors[$report['error']] = ($errors[$report['error']] ?? 0) + 1;
    }

    sort($elapsed);

    return [
        'min' => $elapsed[0],
        'median' => $elapsed[(int) floor((count($elapsed) - 1) / 2)],
        'p95' => $elapsed[(int) floor((count($elapsed) - 1) * 0.95)],
        'max' => $elapsed[array_key_last($elapsed)],
        'errors' => $errors,
    ];
}

$lists = [];
$files = trackedConfigurationFiles();
foreach ($files as $file) {
    collectSelectors(Yaml::parseFile($file), $file, [], $lists);
}

$values = [];
foreach ($lists as $list) {
    foreach ($list['values'] as $value) {
        $values[] = $value;
    }
}

if ($values === []) {
    throw new RuntimeException('Selector census found no authored values; the configured surfaces were not decoded.');
}

$longest = '';
$longestSource = '';
foreach ($lists as $source => $list) {
    foreach ($list['values'] as $value) {
        if (strlen($value) > strlen($longest)) {
            $longest = $value;
            $longestSource = $source;
        }
    }
}

$largestList = [];
$largestListSource = '';
foreach ($lists as $source => $list) {
    if (count($list['values']) > count($largestList)) {
        $largestList = $list['values'];
        $largestListSource = $source;
    }
}

$exactValue = 'src/Analysis/Evidence/DependencyModel/Contract/DependencyGraphInterface.php';
$subtreeValue = 'Qualimetrix\\Analysis\\Evidence\\Coupling';
$regexBody = 'Qualimetrix\\\\(?:Analysis|Infrastructure)\\\\(?:[A-Za-z][A-Za-z0-9_]*\\\\)*[A-Za-z][A-Za-z0-9_]*';
$exactBody = preg_quote($exactValue, PATTERN_DELIMITER);
$subtreeBody = preg_quote($subtreeValue, PATTERN_DELIMITER) . '(?:\\\\.+)?';

$benchmarks = [
    'exact' => [$exactBody, $exactValue],
    'subtree' => [$subtreeBody, $subtreeValue . '\\DistanceRule'],
    'representative-regex' => [$regexBody, 'Qualimetrix\\Analysis\\Evidence\\Coupling\\DistanceRule'],
];

printf("PHP %s; PCRE %s; pcre.jit=%s; ini backtrack=%s; ini recursion=%s\n", PHP_VERSION, PCRE_VERSION, ini_get('pcre.jit'), ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit'));
printf("Census: %d configuration files, %d selector lists, %d non-empty string values\n", count($files), count($lists), count($values));
printf("Longest value: %d bytes at %s: %s\n", strlen($longest), $longestSource, $longest);
printf("Largest list: %d values at %s\n", count($largestList), $largestListSource);

foreach ($benchmarks as $name => [$body, $subject]) {
    printf(
        "%s: cold=%.3f us/op; warm=%.1f ns/op\n",
        $name,
        coldMicrosecondsPerOperation($body, $subject),
        warmNanosecondsPerOperation(rendered($body), $subject),
    );
}

$currentExactStartedAt = hrtime(true);
for ($index = 0; $index < WARM_ITERATIONS; $index++) {
    $currentExact = $exactValue === $exactValue;
}
$currentExactNs = (hrtime(true) - $currentExactStartedAt) / WARM_ITERATIONS;

$currentSubtreeSubject = $subtreeValue . '\\DistanceRule';
$currentSubtreeStartedAt = hrtime(true);
for ($index = 0; $index < WARM_ITERATIONS; $index++) {
    $currentSubtree = $currentSubtreeSubject === $subtreeValue || str_starts_with($currentSubtreeSubject, $subtreeValue . '\\');
}
$currentSubtreeNs = (hrtime(true) - $currentSubtreeStartedAt) / WARM_ITERATIONS;

$currentGlob = 'Qualimetrix\\Analysis\\*';
$currentGlobSubject = 'Qualimetrix\\Analysis\\Evidence\\Coupling\\DistanceRule';
$currentGlobStartedAt = hrtime(true);
for ($index = 0; $index < WARM_ITERATIONS; $index++) {
    $currentGlobMatch = fnmatch($currentGlob, $currentGlobSubject, FNM_NOESCAPE);
}
$currentGlobNs = (hrtime(true) - $currentGlobStartedAt) / WARM_ITERATIONS;
printf("Current controls: exact=%.1f ns/op; boundary-subtree=%.1f ns/op; fnmatch-glob=%.1f ns/op\n", $currentExactNs, $currentSubtreeNs, $currentGlobNs);

$adversarial = adversarialDistribution(rendered('(a+)+'), str_repeat('a', 4096) . '!');
printf(
    "Nested quantifier (4096 a + !): min=%.3f ms; median=%.3f ms; p95=%.3f ms; max=%.3f ms; errors=%s\n",
    $adversarial['min'],
    $adversarial['median'],
    $adversarial['p95'],
    $adversarial['max'],
    json_encode($adversarial['errors'], JSON_THROW_ON_ERROR),
);

// JIT does not exercise PCRE's interpreter recursion accounting. This separate
// fallback probe verifies that the same inline depth verb is effective when a
// supported runtime has JIT disabled.
$depthFallback = matchReport(
    '~(*NO_JIT)(*LIMIT_MATCH=' . MATCH_LIMIT . ')(*LIMIT_DEPTH=' . DEPTH_LIMIT . ')\\A(?:(?<r>a(?&r)?b))\\z~',
    str_repeat('a', 2048) . str_repeat('b', 2048),
);
printf(
    "Depth fallback (JIT disabled, 2048 recursive pairs): result=%s; error=%d (%s); elapsed=%.3f ms\n",
    var_export($depthFallback['result'], true),
    $depthFallback['error'],
    $depthFallback['message'],
    $depthFallback['elapsedMs'],
);

foreach (['accept' => '(*ACCEPT)', 'reset-start' => 'a\\Kbc'] as $name => $body) {
    $report = matchReport(rendered($body), 'abc');
    printf(
        "%s: result=%s; error=%d (%s); span=%s; full-span=%s\n",
        $name,
        var_export($report['result'], true),
        $report['error'],
        $report['message'],
        json_encode($report['span'], JSON_THROW_ON_ERROR),
        $report['span'] === ['abc', 0] ? 'yes' : 'no',
    );
}
