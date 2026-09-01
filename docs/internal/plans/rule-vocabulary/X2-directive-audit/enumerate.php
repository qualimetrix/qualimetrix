<?php

declare(strict_types=1);

/**
 * One instrument, three artifacts, for the Х2 plan:
 *
 *   enumeration-override-read-channels.tsv  — where a threshold override is read or applied
 *   enumeration-execution-state.tsv         — `$this->` writes outside the constructor, in rules
 *   enumeration-rule-collaborators.tsv      — constructor-injected collaborators of rules
 *   enumeration-boundary-publication.tsv    — which rules publish a boundary in the finding
 *
 * Token-level, not regex over source text. Two earlier regex editions were both
 * wrong in ways only a tokenizer avoids: one keyed classes by short name and
 * let the docblock example in `CliAlias.php` overwrite the real
 * `ComplexityRule`, so the real file was never scanned; the next one matched
 * `class SymbolPath` inside a string literal. Comments and strings are not code,
 * and `token_get_all()` is what knows the difference.
 *
 * Usage: php docs/internal/plans/rule-vocabulary/X2-directive-audit/enumerate.php
 *
 * Blind spots this instrument still has, named because a table nobody can
 * challenge is not evidence: it reads declarations, not reachability, so a call
 * made through reflection or a variable method name is invisible; the rule set
 * is built from `extends`/`implements` names resolved within the project, so a
 * rule reaching the contract through a vendor class would be missed; parameter
 * types are read as written, so a union type contributes its first name only.
 */

const PLAN_DIR = 'docs/internal/plans/rule-vocabulary/X2-directive-audit/';

$root = getcwd();

if ($root === false) {
    fwrite(STDERR, "the working directory is unreadable\n");

    exit(1);
}

/** @return list<string> */
function phpFiles(string $directory): array
{
    $files = [];
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/** Significant tokens only: comments and whitespace carry example code, not code. */
function significantTokens(string $source): array
{
    $tokens = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $tokens[] = $token;
    }

    return $tokens;
}

/**
 * Every class declared in the tree, keyed by FQCN.
 *
 * @return array<string, array{file: string, name: string, parent: ?string, interfaces: list<string>, tokens: array}>
 */
function classIndex(array $files, string $root): array
{
    $index = [];

    foreach ($files as $path) {
        $source = file_get_contents($path);
        if ($source === false) {
            continue;
        }
        $relative = substr($path, strlen($root) + 1);
        $tokens = significantTokens($source);
        $namespace = '';

        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < count($tokens); ++$j) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace = $tokens[$j][1];
                    }

                    break;
                }
            }

            if (!is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }
            // `Foo::class` and anonymous classes are not declarations.
            $previous = $tokens[$i - 1] ?? null;
            if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if (!is_array($next) || $next[0] !== T_STRING) {
                continue;
            }

            $name = $next[1];
            $parent = null;
            $interfaces = [];
            $mode = null;
            for ($j = $i + 2; $j < count($tokens); ++$j) {
                $t = $tokens[$j];
                if ($t === '{') {
                    break;
                }
                if (is_array($t) && $t[0] === T_EXTENDS) {
                    $mode = 'extends';

                    continue;
                }
                if (is_array($t) && $t[0] === T_IMPLEMENTS) {
                    $mode = 'implements';

                    continue;
                }
                if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $short = substr((string) strrchr('\\' . $t[1], '\\'), 1);
                    if ($mode === 'extends' && $parent === null) {
                        $parent = $short;
                    } elseif ($mode === 'implements') {
                        $interfaces[] = $short;
                    }
                }
            }

            $fqcn = $namespace === '' ? $name : $namespace . '\\' . $name;
            if (isset($index[$fqcn])) {
                fwrite(STDERR, sprintf("duplicate class %s in %s and %s\n", $fqcn, $relative, $index[$fqcn]['file']));

                exit(1);
            }

            $index[$fqcn] = [
                'file' => $relative,
                'name' => $name,
                'parent' => $parent,
                'interfaces' => $interfaces,
                'tokens' => $tokens,
            ];
        }
    }

    return $index;
}

/** @param array<string, array> $index */
function shortNameMap(array $index): array
{
    $map = [];
    foreach ($index as $fqcn => $entry) {
        $map[$entry['name']][] = $fqcn;
    }

    return $map;
}

/**
 * Transitive: a class is a rule when it implements RuleInterface or reaches
 * AbstractRule through any chain of parents resolved inside the project.
 */
function isRule(string $fqcn, array $index, array $shortNames): bool
{
    $seen = [];
    while ($fqcn !== '' && !isset($seen[$fqcn]) && isset($index[$fqcn])) {
        $seen[$fqcn] = true;
        $entry = $index[$fqcn];
        if ($entry['name'] === 'AbstractRule' || in_array('RuleInterface', $entry['interfaces'], true)) {
            return true;
        }
        $parent = $entry['parent'];
        if ($parent === null) {
            return false;
        }
        $candidates = $shortNames[$parent] ?? [];
        if (count($candidates) !== 1) {
            if (count($candidates) > 1) {
                fwrite(STDERR, sprintf("ambiguous parent %s of %s\n", $parent, $fqcn));

                exit(1);
            }

            return false;
        }
        $fqcn = $candidates[0];
    }

    return false;
}

function isValidator(string $fqcn, array $index): bool
{
    return in_array('ConfigurationValidatorInterface', $index[$fqcn]['interfaces'], true);
}

/** Token offsets of the constructor body, or null. @return ?array{int, int} */
function constructorBody(array $tokens): ?array
{
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $next = $tokens[$i + 1] ?? null;
        if (!is_array($next) || strtolower($next[1]) !== '__construct') {
            continue;
        }
        $depth = 0;
        $start = null;
        for ($j = $i + 2; $j < count($tokens); ++$j) {
            if ($tokens[$j] === '{') {
                $depth++;
                $start ??= $j;
            } elseif ($tokens[$j] === '}') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    return [$start, $j];
                }
            } elseif ($tokens[$j] === ';' && $start === null) {
                return null; // abstract or interface method
            }
        }
    }

    return null;
}

/** @return list<string> constructor parameter type names */
function constructorParameterTypes(array $tokens): array
{
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $next = $tokens[$i + 1] ?? null;
        if (!is_array($next) || strtolower($next[1]) !== '__construct') {
            continue;
        }
        $types = [];
        $pending = null;
        $depth = 0;
        for ($j = $i + 2; $j < count($tokens); ++$j) {
            $t = $tokens[$j];
            if ($t === '(') {
                $depth++;

                continue;
            }
            if ($t === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }

                continue;
            }
            if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $pending ??= substr((string) strrchr('\\' . $t[1], '\\'), 1);
            }
            if (is_array($t) && $t[0] === T_VARIABLE) {
                if ($pending !== null) {
                    $types[] = $pending;
                }
                $pending = null;
            }
            if ($t === ',') {
                $pending = null;
            }
        }

        return $types;
    }

    return [];
}

/** @return list<array{int, string}> line and property of each `$this->x =` outside the constructor */
function propertyWrites(array $tokens, ?array $ctor): array
{
    $writes = [];
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$this') {
            continue;
        }
        if ($ctor !== null && $i > $ctor[0] && $i < $ctor[1]) {
            continue;
        }
        $arrow = $tokens[$i + 1] ?? null;
        $property = $tokens[$i + 2] ?? null;
        if (!is_array($arrow) || $arrow[0] !== T_OBJECT_OPERATOR || !is_array($property) || $property[0] !== T_STRING) {
            continue;
        }
        $j = $i + 3;
        if (($tokens[$j] ?? null) === '[') {
            $depth = 0;
            for (; $j < count($tokens); ++$j) {
                if ($tokens[$j] === '[') {
                    $depth++;
                } elseif ($tokens[$j] === ']') {
                    $depth--;
                    if ($depth === 0) {
                        ++$j;

                        break;
                    }
                }
            }
        }
        $assign = $tokens[$j] ?? null;
        $isAssignment = $assign === '='
            || (is_array($assign) && in_array($assign[0], [T_PLUS_EQUAL, T_CONCAT_EQUAL, T_MINUS_EQUAL], true));
        if ($isAssignment) {
            $writes[] = [$property[2], $property[1]];
        }
    }

    return $writes;
}

// ------------------------------------------------------------------ artifacts

$files = phpFiles($root . '/src');
$index = classIndex($files, $root);
$shortNames = shortNameMap($index);

$rules = [];
$validators = [];
foreach ($index as $fqcn => $entry) {
    if (isRule($fqcn, $index, $shortNames)) {
        $rules[$fqcn] = $entry;
    } elseif (isValidator($fqcn, $index)) {
        $validators[$fqcn] = $entry;
    }
}

$stateRows = [];
foreach ($rules as $fqcn => $entry) {
    foreach (propertyWrites($entry['tokens'], constructorBody($entry['tokens'])) as [$line, $property]) {
        $stateRows[] = sprintf("%s\t%d\t%s\t%s", $entry['file'], $line, $entry['name'], $property);
    }
}

$collaboratorRows = [];
foreach ([['rule', $rules], ['validator', $validators]] as [$kind, $set]) {
    foreach ($set as $fqcn => $entry) {
        $types = constructorParameterTypes($entry['tokens']);
        if ($types === []) {
            $collaboratorRows[] = sprintf("%s\t%s\t%s\t(no constructor)", $kind, $entry['name'], $entry['file']);

            continue;
        }
        foreach ($types as $type) {
            $collaboratorRows[] = sprintf("%s\t%s\t%s\t%s", $kind, $entry['name'], $entry['file'], $type);
        }
    }
}

$channelPatterns = [
    'ctx-read' => 'getThresholdOverride',
    'options-apply' => 'withOverride',
    'options-apply-vo' => 'withVoOverride',
    'helper-options' => 'getEffectiveOptions',
    'helper-severity' => 'getEffectiveSeverity',
];
$channelRows = [];
foreach ($files as $path) {
    $source = file_get_contents($path);
    if ($source === false) {
        continue;
    }
    $relative = substr($path, strlen($root) + 1);
    $tokens = significantTokens($source);
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        $channel = array_search($token[1], $channelPatterns, true);
        if ($channel === false || ($tokens[$i + 1] ?? null) !== '(') {
            continue;
        }
        $isDeclaration = is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_FUNCTION;
        $channelRows[] = sprintf(
            "%s\t%s\t%d\t%s",
            $channel,
            $relative,
            $token[2],
            $isDeclaration ? 'declaration' : 'call',
        );
    }
}

// The boundary a finding publishes decides whether `Overrun` is observable at
// all: the verdict is "severity unchanged, boundary moved", and a producer that
// never puts a boundary in the finding cannot show the second half.
$shapeRows = [];
foreach ($files as $path) {
    if (!str_ends_with($path, 'Rule.php')) {
        continue;
    }
    $source = file_get_contents($path);
    if ($source === false) {
        continue;
    }
    $relative = substr($path, strlen($root) + 1);
    $tokens = significantTokens($source);
    $constructsFinding = false;
    $publishesThreshold = false;
    $supportsOverride = null;
    foreach ($tokens as $i => $token) {
        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'Finding'
            && is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_NEW) {
            $constructsFinding = true;
        }
        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'threshold'
            && ($tokens[$i + 1] ?? null) === ':') {
            $publishesThreshold = true;
        }
        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'SUPPORTS_THRESHOLD_OVERRIDE') {
            for ($j = $i + 1; $j < $i + 4; ++$j) {
                $t = $tokens[$j] ?? null;
                if (is_array($t) && in_array(strtolower($t[1]), ['true', 'false'], true)) {
                    $supportsOverride = strtolower($t[1]);

                    break;
                }
            }
        }
    }
    if (!$constructsFinding) {
        continue;
    }
    $shapeRows[] = sprintf(
        "%s\t%s\t%s",
        $relative,
        $publishesThreshold ? 'publishes-boundary' : 'no-boundary',
        $supportsOverride ?? 'inherited',
    );
}

sort($shapeRows);
sort($stateRows);
sort($collaboratorRows);
sort($channelRows);

file_put_contents(PLAN_DIR . 'enumeration-execution-state.tsv', "file\tline\tclass\tproperty\n" . implode("\n", $stateRows) . ($stateRows === [] ? '' : "\n"));
file_put_contents(PLAN_DIR . 'enumeration-rule-collaborators.tsv', "kind\tclass\tfile\tcollaborator\n" . implode("\n", $collaboratorRows) . "\n");
file_put_contents(PLAN_DIR . 'enumeration-boundary-publication.tsv', "file\tboundary\tsupports_override\n" . implode("\n", $shapeRows) . "\n");
file_put_contents(PLAN_DIR . 'enumeration-override-read-channels.tsv', "channel\tfile\tline\tkind\n" . implode("\n", $channelRows) . "\n");

printf(
    "rule classes: %d, validators: %d\nproperty writes outside constructor: %d\ncollaborator rows: %d\nchannel rows: %d\nfinding-constructing rule files: %d, of them without a published boundary: %d\n",
    count($rules),
    count($validators),
    count($stateRows),
    count($collaboratorRows),
    count($channelRows),
    count($shapeRows),
    count(array_filter($shapeRows, static fn(string $r): bool => str_contains($r, "\tno-boundary\t"))),
);
foreach ($channelPatterns as $channel => $method) {
    $calls = array_filter($channelRows, static fn(string $row): bool => str_starts_with($row, $channel . "\t") && str_ends_with($row, "\tcall"));
    printf("  %-18s calls=%d files=%d\n", $channel, count($calls), count(array_unique(array_map(static fn(string $r): string => explode("\t", $r)[1], $calls))));
}
