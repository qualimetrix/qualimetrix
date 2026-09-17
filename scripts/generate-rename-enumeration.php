#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Regenerates the active rename-control inventories under `finding-gate/`.
 *
 * The identity set (which `old`/`kind` rows exist) is MEASURED from the
 * production container and from {@see MetricName} on every run. The `new`
 * column is a decision, not a measurement, so it is preserved across runs by
 * merging onto the existing file keyed by `old`+`kind` rather than being
 * recomputed. A row whose `new` was already filled in disappearing from the
 * measurement is either an executed rename or a lost identity, and the two are
 * told apart by measurement — see retireExecutedRows().
 *
 * The eight per-surface occurrence columns are declared once in SURFACES so
 * that adding a surface is a single-line change, and the footer's surface
 * list is generated from the same array so the two can never drift apart.
 */

/**
 * @return array<string, array{roots: list<string>, files: list<string>, excludeDirs: list<string>, excludeFiles: list<string>}>
 */
function surfaces(): array
{
    $presetFiles = [
        'src/Analysis/Configuration/Preset/ci.yaml',
        'src/Analysis/Configuration/Preset/legacy.yaml',
        'src/Analysis/Configuration/Preset/strict.yaml',
    ];

    return [
        'src' => [
            'roots' => ['src'],
            'files' => [],
            'excludeDirs' => ['node_modules', 'dist'],
            'excludeFiles' => [...$presetFiles, 'src/.gitkeep', 'src/Reporting/Template/package-lock.json'],
        ],
        // One surface, two roots: a control that pins a channel spelling counts
        // the same wherever it is filed, and the repository-controls root is
        // where those controls are moving. A separate column would have split
        // the count in the same step that moves the files, so a sweep reading
        // one column would have read a drop that means nothing.
        'tests' => [
            'roots' => ['tests', 'governance', 'tools', 'scripts/promise-effect/tests', 'scripts/directive-audit/tests', 'scripts/directive-audit-controls/tests', 'scripts/finding-gate/tests', 'scripts/suppression-snapshot/tests', 'scripts/rename-enumeration/tests', 'scripts/health-calibration/tests', 'scripts/benchmark/tests', 'scripts/modular-architecture/tests'],
            'files' => [],
            'excludeDirs' => ['__pycache__'],
            'excludeFiles' => [],
        ],
        'presets' => [
            'roots' => [],
            'files' => $presetFiles,
            'excludeDirs' => [],
            'excludeFiles' => [],
        ],
        'config' => [
            'roots' => [],
            'files' => ['qmx.yaml', 'qmx.yaml.example'],
            'excludeDirs' => [],
            'excludeFiles' => [],
        ],
        'baseline' => [
            'roots' => [],
            'files' => ['qmx-baseline.json'],
            'excludeDirs' => [],
            'excludeFiles' => [],
        ],
        'website_docs' => [
            'roots' => ['website/docs'],
            'files' => [],
            'excludeDirs' => [],
            'excludeFiles' => [],
        ],
        'benchmarks' => [
            'roots' => ['benchmarks'],
            'files' => [],
            'excludeDirs' => ['vendor'],
            'excludeFiles' => [],
        ],
        'finding_gate' => [
            'roots' => ['finding-gate'],
            'files' => [],
            'excludeDirs' => [],
            'excludeFiles' => [],
        ],
    ];
}

/**
 * @return array<string, string> channel key => the string a consumer actually
 *                               writes (selectors, `@qmx-ignore` targets, docs,
 *                               fixtures). The two are the same string: a
 *                               channel is named by one name, and the
 *                               producing rule is a field beside it rather than
 *                               a half of the key.
 */
function channelIdentities(ContainerFactory $factory): array
{
    $container = $factory->create();
    $registry = $container->get(ChannelDeclarationRegistryInterface::class);
    assert($registry instanceof ChannelDeclarationRegistryInterface);

    $identities = [];

    foreach (array_keys($registry->staticDeclarations()) as $key) {
        if (str_contains($key, '#')) {
            throw new RuntimeException(sprintf(
                'Channel key "%s" still carries the retired "#" separator; a channel is one name.',
                $key,
            ));
        }

        $identities[$key] = $key;
    }

    return $identities;
}

/**
 * @return list<string> producer (rule) names
 */
function producerIdentities(ContainerFactory $factory): array
{
    $container = $factory->create();
    $execution = $container->get(RuleExecutionInterface::class);
    assert($execution instanceof RuleExecutionInterface);

    $names = array_map(static fn($rule): string => $rule->name, $execution->allRules());
    $unique = array_values(array_unique($names));

    if (count($unique) !== count($names)) {
        throw new RuntimeException('RuleExecutionInterface::allRules() returned a duplicate rule name.');
    }

    return $unique;
}

/**
 * @return list<string> MetricName constant values
 */
function metricKeyIdentities(): array
{
    $reflection = new ReflectionClass(MetricName::class);
    $values = array_values($reflection->getConstants());

    $strings = array_map(static function (mixed $value) use ($reflection): string {
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('%s declares a non-string constant.', $reflection->getName()));
        }

        return $value;
    }, $values);

    $unique = array_values(array_unique($strings));

    if (count($unique) !== count($strings)) {
        throw new RuntimeException(sprintf('%s declares two constants with the same value.', $reflection->getName()));
    }

    return $unique;
}

/**
 * @param array<string, array{roots: list<string>, files: list<string>, excludeDirs: list<string>, excludeFiles: list<string>}> $surfaces
 *
 * @return array<string, string> surface key => concatenated readable content of every file in scope
 */
function readSurfaceContents(array $surfaces, string $root): array
{
    $contents = [];

    foreach ($surfaces as $surfaceKey => $definition) {
        $chunks = [];

        foreach ($definition['files'] as $relativePath) {
            $chunks[] = readFileOrFail($root . '/' . $relativePath);
        }

        if ($definition['roots'] !== []) {
            $finder = new Finder();
            $finder->files()->in(array_map(static fn(string $relative): string => $root . '/' . $relative, $definition['roots']));

            foreach ($definition['excludeDirs'] as $excludeDir) {
                $finder->exclude($excludeDir);
            }

            foreach ($finder as $file) {
                if (isExcludedFile($file, $root, $definition['excludeFiles'])) {
                    continue;
                }

                $chunks[] = readFileOrFail($file->getPathname());
            }
        }

        $contents[$surfaceKey] = implode("\n\n", $chunks);
    }

    return $contents;
}

/**
 * @param list<string> $excludeFiles project-root-relative paths
 */
function isExcludedFile(SplFileInfo $file, string $root, array $excludeFiles): bool
{
    $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');

    return in_array($relative, $excludeFiles, true);
}

function readFileOrFail(string $path): string
{
    if (!is_readable($path)) {
        throw new RuntimeException(sprintf('Could not read "%s".', $path));
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException(sprintf('Could not read "%s".', $path));
    }

    // Binary content (e.g. a stray compiled asset) can't hold a name literally;
    // treating it as opaque is safe because occurrences are counted textually.
    return mb_check_encoding($contents, 'UTF-8') ? $contents : '';
}

/**
 * Counts whole-identifier occurrences of $needle in $haystack: the match must
 * not be immediately preceded or followed by a word character, `.` or `-`.
 * Without this boundary, a short name like "design.type-coverage" would also
 * count every occurrence of "design.type-coverage.property" — the exact
 * prefix-collision class that exact rename maps must avoid.
 */
function countWholeIdentifierOccurrences(string $needle, string $haystack): int
{
    $pattern = '/(?<![\w.-])' . preg_quote($needle, '/') . '(?![\w.-])/u';
    $matchCount = preg_match_all($pattern, $haystack);

    if ($matchCount === false) {
        throw new RuntimeException(sprintf('Regex failure while counting occurrences of "%s".', $needle));
    }

    return $matchCount;
}

/**
 * @param list<array{old: string, kind: string, search: string}> $rows
 * @param array<string, string> $surfaceContents surface key => content
 * @param list<string> $surfaceOrder
 *
 * @return list<array{old: string, kind: string, search: string, counts: array<string, int>}>
 */
function measure(array $rows, array $surfaceContents, array $surfaceOrder): array
{
    $measured = [];

    foreach ($rows as $row) {
        $counts = [];

        foreach ($surfaceOrder as $surfaceKey) {
            $counts[$surfaceKey] = countWholeIdentifierOccurrences($row['search'], $surfaceContents[$surfaceKey]);
        }

        $measured[] = [...$row, 'counts' => $counts];
    }

    return $measured;
}

/**
 * Channel literals a private `OCCURRENCE_KIND` constant deliberately freezes
 * at today's spelling: the discriminator
 * `OccurrenceKey::semantic()` hashes with, kept equal to the channel's
 * current code on purpose and NOT meant to follow a future rename of that
 * channel — moving it moves `occurrence` for every already-accepted baseline
 * entry on the channel. Derived by scanning src/ for the declaration's exact
 * shape, not by a hand-kept class list, so a future family adopting the same
 * declaration shape is picked up on the next run with no edit here — and a
 * family that regresses to `self::NAME` silently drops out instead of being
 * remembered as still frozen.
 *
 * @return array<string, int> channel literal => number of declarations found
 */
function frozenKindLiterals(string $srcContent): array
{
    if (preg_match_all("/private const string OCCURRENCE_KIND = '((?:[^'\\\\]|\\\\.)*)';/", $srcContent, $matches) === false) {
        throw new RuntimeException('Regex failure while scanning src/ for OCCURRENCE_KIND declarations.');
    }

    $counts = [];

    foreach ($matches[1] as $literal) {
        $literal = stripcslashes($literal);
        $counts[$literal] = ($counts[$literal] ?? 0) + 1;
    }

    return $counts;
}

/**
 * Channel literals a `itKeysOccurrenceToTheFrozenChannelSpelling*` pin test
 * asserts against — the test-side counterpart of {@see frozenKindLiterals()}.
 * Derived the same way: every actual DECLARATION of the method (`function
 * itKeysOccurrenceToTheFrozenChannelSpelling...(): void`, never a docblock or
 * comment merely naming it) is followed by a bounded window of text, searched
 * for the one `OccurrenceKey::semantic('<literal>'` call the method makes
 * near its start — not a hand-kept file list, so a new pin adopting the same
 * naming convention is picked up automatically.
 *
 * The window is bounded rather than parsed to the enclosing method's closing
 * brace: brace-matching PHP needs a real parser, and a generous flat window
 * reaches every pin method's own `semantic()` call, which the naming
 * convention places within the method's first few lines. A marker with no
 * call inside its window fails loudly instead of silently counting nothing,
 * because a silent miss here is indistinguishable from "no pin protects this
 * literal any more".
 *
 * @return array<string, int> channel literal => number of pin methods asserting it
 */
function frozenPinLiterals(string $testsContent): array
{
    $window = 4000;

    if (preg_match_all('/function itKeysOccurrenceToTheFrozenChannelSpelling\w*\(\)/', $testsContent, $matches, PREG_OFFSET_CAPTURE) === false) {
        throw new RuntimeException('Regex failure while scanning tests/ for frozen-occurrence pin methods.');
    }

    $counts = [];

    foreach ($matches[0] as [$declaration, $pos]) {
        $slice = substr($testsContent, $pos, $window);

        if (preg_match("/OccurrenceKey::semantic\\(\\s*\\n?\\s*'((?:[^'\\\\]|\\\\.)*)'/", $slice, $match) !== 1) {
            throw new RuntimeException(sprintf(
                'Found pin method declaration "%s" at byte offset %d of the tests surface, but no'
                . ' `OccurrenceKey::semantic(\'literal\')` call within %d characters of it. Widen the window or'
                . ' investigate the method — a silent miss here would misreport a protected literal as unprotected.',
                $declaration,
                $pos,
                $window,
            ));
        }

        $literal = stripcslashes($match[1]);
        $counts[$literal] = ($counts[$literal] ?? 0) + 1;
    }

    return $counts;
}

/**
 * Channels whose occurrence discriminator is a `SMELL_TYPE`/`PATTERN_TYPE`
 * constant holding the channel's LEAF rather than its code — the second,
 * differently shaped freeze `leaf_const`/`leaf_pin` mark (see the footer).
 * `AbstractCodeSmellRule` and `AbstractSecurityPatternRule` pass that
 * constant to `OccurrenceKey::semantic()` through their finding VO, so its
 * value is the discriminator for every family deriving from them, exactly as
 * `OCCURRENCE_KIND` is for the six.
 *
 * Read FILE BY FILE rather than off the concatenated `src` surface on
 * purpose: the constant holds `'eval'`, never `'code-smell.eval'`, so nothing
 * in its text says which channel it belongs to. The binding is the same-file
 * coincidence of `public const string NAME = '<channel>'` with the leaf
 * constant, and concatenating the surface destroys exactly that. The binding
 * is therefore DERIVED, with no hand-kept list of classes, files or pairs —
 * a thirteenth family declaring the same shape is picked up on the next run,
 * and a family that stops declaring it drops out instead of being remembered.
 *
 * Every way the derivation could silently under-report throws instead: a leaf
 * constant in a file with no channel `NAME` to bind it to, two files claiming
 * one channel, or two channels sharing one leaf (which would make the
 * inversion {@see leafPinLiterals()} needs ambiguous).
 *
 * `byChannel` therefore carries a PRESENCE FLAG, always 1, and the emitted
 * `leaf_const` column is the only one of the freeze quartet that is not a
 * count. Counting is what the refusals above rule out: multiplicity here is
 * an error state, not a magnitude, and reporting it as `2` would state as a
 * measurement what the binding cannot express.
 *
 * `byChannel` maps a channel code to the flag 1; `channelByLeaf` inverts leaf
 * literal => channel code.
 *
 * @return array{byChannel: array<string, int>, channelByLeaf: array<string, string>}
 */
function leafConstantChannels(string $root): array
{
    $byChannel = [];
    $channelByLeaf = [];

    $finder = new Finder();
    $finder->files()->in($root . '/src')->name('*.php');

    foreach ($finder as $file) {
        $source = readFileOrFail($file->getPathname());

        if (preg_match("/const string (?:SMELL_TYPE|PATTERN_TYPE) = '((?:[^'\\\\]|\\\\.)*)';/", $source, $leafMatch) !== 1) {
            continue;
        }

        $leaf = stripcslashes($leafMatch[1]);

        // The two abstract bases declare the constant empty so every concrete
        // subclass must state its own; an empty leaf names no family.
        if ($leaf === '') {
            continue;
        }

        $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');

        if (preg_match("/const string NAME = '((?:[^'\\\\]|\\\\.)*)';/", $source, $nameMatch) !== 1 || $nameMatch[1] === '') {
            throw new RuntimeException(sprintf(
                '%s declares a leaf occurrence constant ("%s") but no non-empty `const string NAME` in the same'
                . ' file to bind it to a channel. The binding is derived from that co-location; without it the'
                . ' protected literal would be silently reported as unprotected.',
                $relative,
                $leaf,
            ));
        }

        $channel = stripcslashes($nameMatch[1]);

        if (isset($byChannel[$channel])) {
            throw new RuntimeException(sprintf(
                'Channel "%s" carries a leaf occurrence constant in more than one file (last: %s). One channel'
                . ' must bind to one declaring file for the count to mean anything.',
                $channel,
                $relative,
            ));
        }

        if (isset($channelByLeaf[$leaf])) {
            throw new RuntimeException(sprintf(
                'Leaf "%s" is declared by both "%s" and "%s". The pin scan inverts leaf => channel; two channels'
                . ' sharing a leaf makes that inversion ambiguous and would attribute a pin to the wrong row.',
                $leaf,
                $channelByLeaf[$leaf],
                $channel,
            ));
        }

        $byChannel[$channel] = 1;
        $channelByLeaf[$leaf] = $channel;
    }

    return ['byChannel' => $byChannel, 'channelByLeaf' => $channelByLeaf];
}

/**
 * Channel literals an `itKeysOccurrenceToItsOwnSmellType`/`...PatternType`
 * pin test asserts against — the test-side counterpart of
 * {@see leafConstantChannels()}, and the leaf-shaped analogue of
 * {@see frozenPinLiterals()}.
 *
 * Derived the same bounded-window way as the frozen pins: every actual
 * DECLARATION of such a method is followed by a window of text searched for
 * the one `OccurrenceKey::semantic('<leaf>'` call it makes. The leaf is then
 * translated to its channel through the inversion of the src-side binding, so
 * the column lands on the row a sweep would actually be renaming.
 *
 * A marker whose window holds no call, and a pin naming a leaf no src file
 * declares, both fail loudly: a silent miss here is indistinguishable from
 * "no pin protects this literal any more".
 *
 * @param array<string, string> $channelByLeaf leaf literal => channel code
 *
 * @return array<string, int> channel literal => number of pin methods asserting its leaf
 */
function leafPinLiterals(string $testsContent, array $channelByLeaf): array
{
    $window = 4000;

    if (preg_match_all('/function itKeysOccurrenceToItsOwn(?:Smell|Pattern)Type\w*\(\)/', $testsContent, $matches, PREG_OFFSET_CAPTURE) === false) {
        throw new RuntimeException('Regex failure while scanning tests/ for leaf-occurrence pin methods.');
    }

    $counts = [];

    foreach ($matches[0] as [$declaration, $pos]) {
        $slice = substr($testsContent, $pos, $window);

        if (preg_match("/OccurrenceKey::semantic\\(\\s*\\n?\\s*'((?:[^'\\\\]|\\\\.)*)'/", $slice, $match) !== 1) {
            throw new RuntimeException(sprintf(
                'Found pin method declaration "%s" at byte offset %d of the tests surface, but no'
                . ' `OccurrenceKey::semantic(\'literal\')` call within %d characters of it. Widen the window or'
                . ' investigate the method — a silent miss here would misreport a protected literal as unprotected.',
                $declaration,
                $pos,
                $window,
            ));
        }

        $leaf = stripcslashes($match[1]);
        $channel = $channelByLeaf[$leaf] ?? null;

        if ($channel === null) {
            throw new RuntimeException(sprintf(
                'Pin method "%s" asserts the leaf "%s", which no src/ file declares as a SMELL_TYPE/PATTERN_TYPE'
                . ' constant. Either the constant was renamed and the pin was not (the pin is now stale and'
                . ' protects nothing), or the pin belongs to a family that no longer keys occurrence this way.',
                $declaration,
                $leaf,
            ));
        }

        $counts[$channel] = ($counts[$channel] ?? 0) + 1;
    }

    return $counts;
}

/**
 * Reads the previously generated file (if any) and returns the decided columns
 * — `new` and the `step` that decision belongs to — for every row that had one
 * filled in, keyed by "old\tkind".
 *
 * @return array<string, array{new: string, step: string}>
 */
function readExistingNewColumn(string $path): array
{
    return readPreviousFile($path)['decided'];
}

/**
 * Every identity the previous generation measured, per kind.
 *
 * This is the "before" retirement needs and nothing else has: a rename is
 * executed when its targets **appeared**, and an identity that was already
 * there cannot have appeared. See retireExecutedRows() for what that buys.
 *
 * @return array<string, array<string, true>>
 */
function readPreviousIdentities(string $path): array
{
    return readPreviousFile($path)['identities'];
}

/**
 * @return array{decided: array<string, array{new: string, step: string}>, identities: array<string, array<string, true>>}
 */
function readPreviousFile(string $path): array
{
    if (!is_file($path)) {
        return ['decided' => [], 'identities' => []];
    }

    $contents = readFileOrFail($path);
    $existing = [];
    $identities = [];
    // The column may be absent: this file predates the `step` decision, and a
    // positional read would take the first occurrence count for a step name.
    $stepColumn = null;

    foreach (explode("\n", $contents) as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $columns = explode("\t", $line);

        if ($columns[0] === 'old') {
            $index = array_search('step', $columns, true);
            $stepColumn = $index === false ? null : $index;

            continue;
        }

        if (count($columns) < 3) {
            throw new RuntimeException(sprintf(
                'Line "%s" of %s has %d column(s), fewer than the three a decided row needs. Dropping it would'
                . ' silently revert a recorded decision to "?" — the file says "do not hand-edit" precisely'
                . ' because a lost tab is invisible otherwise.',
                $line,
                basename($path),
                count($columns),
            ));
        }

        [$old, $kind, $new] = $columns;
        $step = $stepColumn === null ? '' : ($columns[$stepColumn] ?? '');
        $identities[$kind][$old] = true;

        if ($new !== '' && $new !== '?') {
            $existing[$old . "\t" . $kind] = ['new' => $new, 'step' => $step];
        }
    }

    return ['decided' => $existing, 'identities' => $identities];
}

/**
 * Rename batches persisted by the tracked inventories.
 *
 * This closed set is the authority for the routing column. Adding a batch is
 * an explicit control change; an arbitrary label in a generated TSV must not
 * silently create a new map route.
 *
 * @return list<string>
 */
function knownRenameSteps(): array
{
    return [
        "\u{0425}12\u{041F}4",
        "\u{0428}4b",
        "\u{0428}5c",
        "\u{0428}5d",
        "\u{0428}5e3",
    ];
}

function assertKnownRenameStep(string $step): void
{
    if (!in_array($step, knownRenameSteps(), true)) {
        throw new RuntimeException(sprintf(
            'Unknown rename batch "%s". Register it in knownRenameSteps() before routing maps through it.',
            $step,
        ));
    }
}

/**
 * A decision has to say which registered batch makes it, or decisions can leak
 * into a map requested for another batch. The pair is checked in both
 * directions: a decided `new` without a `step`, and a `step` on a row nobody
 * has decided.
 *
 * Reads only the decided columns, so it holds a retired row to the same rule as
 * a measured one — a decision without a step is unusable wherever it lives.
 *
 * @param list<array{old: string, kind: string, new: string, step: string}> $rows
 */
function assertEveryDecisionNamesItsStep(array $rows): void
{
    $undated = [];
    $premature = [];
    $unknown = [];

    foreach ($rows as $row) {
        $decided = $row['new'] !== '?' && $row['new'] !== '';

        if ($decided && $row['step'] === '') {
            $undated[] = $row['old'] . ' (' . $row['kind'] . ')';
        }

        if (!$decided && $row['step'] !== '') {
            $premature[] = $row['old'] . ' (' . $row['kind'] . ')';
        }

        if ($row['step'] !== '' && !in_array($row['step'], knownRenameSteps(), true)) {
            $unknown[] = $row['old'] . ' (' . $row['kind'] . '): ' . $row['step'];
        }
    }

    if ($undated !== []) {
        throw new RuntimeException(
            'These rows carry a rename decision but no step, so nothing says which map they belong in: '
            . implode(', ', $undated),
        );
    }

    if ($premature !== []) {
        throw new RuntimeException(
            'These rows name a step but no decision, and a step with nothing decided emits an empty map: '
            . implode(', ', $premature),
        );
    }

    if ($unknown !== []) {
        throw new RuntimeException(
            'These rows name an unknown rename batch: ' . implode(', ', $unknown)
            . '. Register the batch in knownRenameSteps() before routing maps through it.',
        );
    }
}

/**
 * The map rows a step's decisions amount to, as text ready to be appended to
 * the named map file.
 *
 * The mapping from kind to map file follows the gate's input contracts: a
 * channel key is a `channels.tsv` row, a metric key a `metric-keys.tsv` row,
 * and a producer name is an input — that is where a selector and an option key
 * write it. A decision with several targets is a split, which no map row can
 * state, so it is emitted as a comment naming what it is.
 *
 * **What cannot be emitted, and why it is not a gap to close.** `inputs.tsv`
 * carries whole tokens — `rule:option-key`, a flag with its two dashes — and
 * this enumeration models *identities*: channels, producer names, metric keys.
 * An option key is not an identity: it is a name inside one producer's options
 * class, invented by that class, and nothing here measures it. So a step whose
 * inputs move gets one emitted comment and hand-authored rows, and the check on
 * those rows is the gate: an undeclared input makes the reference refuse its
 * arguments (`reference-input-untranslated`), and a declared row that translates
 * nothing is `map-stale`. Emitting them from this file would mean teaching it a
 * second model of the world — every rule's option keys and CLI aliases — whose
 * only consumer is a map the gate already checks from both sides. Identity
 * rows are emitted here; option and alias rows remain hand-authored inputs.
 *
 * Reads only the decided columns, so a retired row — which no longer carries a
 * measurement — is emitted the same way a measured one is. That is what keeps
 * `--emit-maps=<step>` working after the step has landed.
 *
 * @param list<array{old: string, kind: string, new: string, step: string}> $rows
 */
function emitMapsForStep(array $rows, string $step): string
{
    assertKnownRenameStep($step);

    $files = ['channel' => 'channels.tsv', 'metric-key' => 'metric-keys.tsv', 'producer' => 'inputs.tsv'];
    $sections = [];

    foreach ($files as $kind => $file) {
        $lines = [];
        $splits = [];

        foreach ($rows as $row) {
            if ($row['step'] !== $step || $row['kind'] !== $kind) {
                continue;
            }

            if (str_contains($row['new'], '|')) {
                $splits[] = sprintf('# %s splits into %s', $row['old'], str_replace('|', ', ', $row['new']));

                continue;
            }

            $lines[] = implode("\t", [$row['old'], $row['new'], sprintf('%s renames this %s', $step, $kind)]);
        }

        sort($lines);
        $sections[] = sprintf('# maps/%s — step %s, %d emitted row(s)', $file, $step, count($lines));

        if ($file === 'inputs.tsv') {
            $sections[] = '# Option keys, CLI aliases and selector spellings are NOT emitted: this file models'
                . ' identities, and an option key is a name inside one producer\'s options class. Author those rows'
                . ' by hand; the gate holds them from both sides (reference-input-untranslated, map-stale).';
        }
        $sections = [...$sections, ...$splits, ...$lines];
        $sections[] = '';
    }

    return implode("\n", $sections) . "\n";
}

/**
 * Merges a previously decided `new` value onto a freshly measured row.
 *
 * @param list<array{old: string, kind: string, search: string, counts: array<string, int>}> $measuredRows
 * @param array<string, array{new: string, step: string}> $existingNew
 *
 * @return list<array{old: string, kind: string, search: string, counts: array<string, int>, new: string, step: string}>
 */
function mergeExistingNewColumn(array $measuredRows, array $existingNew): array
{
    $merged = [];

    foreach ($measuredRows as $row) {
        $mergeKey = $row['old'] . "\t" . $row['kind'];
        $decided = $existingNew[$mergeKey] ?? ['new' => '?', 'step' => ''];
        $merged[] = [...$row, 'new' => $decided['new'], 'step' => $decided['step']];
    }

    return $merged;
}

/**
 * Retires the rows a step has executed, and refuses the rows an identity was
 * merely lost from.
 *
 * A decided row whose `old` identity is no longer measured means one of two
 * things, and only one of them is fine. Either the step that decided the
 * rename has landed — in which case every name the decision promised is now a
 * measured identity of the same kind — or the identity was lost, renamed by
 * something nobody decided, or dropped along with a rule. The first retires
 * into the tracked executed file; the second still fails, loudly, the way it
 * always did.
 *
 * **Why retirement cannot be a flag.** A `--retire=<batch>` switch, or a `retired`
 * column somebody fills in, would let the operator assert the thing the check
 * exists to test. The whole value of this guard is that it distinguishes "the
 * step landed" from "an identity vanished", and those two states are
 * indistinguishable in the operator's intent — they are only distinguishable
 * in the measurement: did the new names *appear*. A flag would turn the one
 * question nothing else asks into a question nobody asks.
 *
 * **Appeared, not merely present.** The first version asked only whether the
 * targets are measured now, and review showed what that lets through: a
 * collapse. When two identities become one, the target can already be measured
 * before the second rename lands, and that second identity could then vanish
 * for any reason at all and retire as "executed" without ever being merged.
 * The same hole retires a row whose `old` was deleted rather than renamed, as
 * long as its target happened to exist already. So the previous generation's
 * identity set is read back (it is on disk, in the file being regenerated) and a target that
 * was *already there* forbids retirement. A collapse's second half therefore
 * fails, loudly, on its own account.
 *
 * A target spelled with a wildcard is not a measurable identity, so a decision
 * that names one can never retire automatically; the failure says so instead of
 * guessing.
 *
 * **What this cannot see, and who owns it.** Nothing here can tell that a row
 * was *removed* from the executed file, or that its `new` was edited after the
 * fact: the file is the only record of a measurement that no longer exists, so
 * re-deriving it is impossible by construction. That history is owned by git,
 * not by this checker, and the two invariants a checker *can* hold — an
 * executed row's `old` is not measured again, and every target it promised is
 * measured — are enforced on every run in {@see assertExecutedRowsStillHold()}.
 * `--emit-maps` additionally refuses to emit a step whose rows have retired,
 * because that step's maps are already in git and re-emitting them from history
 * would launder a tampered row into a fresh declaration.
 *
 * @param array<string, array{new: string, step: string}> $existingNew decided rows from the measured file
 * @param list<array{old: string, kind: string, search: string, counts: array<string, int>}> $measuredRows
 * @param list<array{old: string, kind: string, new: string, step: string}> $alreadyExecuted carried over verbatim
 * @param array<string, array<string, true>> $previousIdentities what the previous generation measured, per kind
 *
 * @return list<array{old: string, kind: string, new: string, step: string}>
 */
function retireExecutedRows(array $existingNew, array $measuredRows, array $alreadyExecuted, array $previousIdentities): array
{
    $measuredKeys = [];
    $identitiesByKind = [];

    foreach ($measuredRows as $row) {
        $measuredKeys[$row['old'] . "\t" . $row['kind']] = true;
        $identitiesByKind[$row['kind']][$row['old']] = true;
    }

    assertExecutedRowsStillHold($alreadyExecuted, $measuredKeys, $identitiesByKind, $existingNew);

    $retired = $alreadyExecuted;
    $lost = [];

    foreach ($existingNew as $mergeKey => $decision) {
        if (isset($measuredKeys[$mergeKey])) {
            continue;
        }

        [$old, $kind] = array_pad(explode("\t", $mergeKey, 2), 2, '');
        $unmeasured = [];
        $preexisting = [];

        foreach (explode('|', $decision['new']) as $target) {
            // A channel decision names a whole `rule#code` key and is measured
            // as one; a producer or metric-key decision names a bare identity.
            if (str_contains($target, '*') || !isset($identitiesByKind[$kind][$target])) {
                $unmeasured[] = $target;

                continue;
            }

            if (isset($previousIdentities[$kind][$target])) {
                $preexisting[] = $target;
            }
        }

        if ($unmeasured !== []) {
            $lost[] = sprintf(
                '%s (%s), decided by %s to become %s — not measured: %s',
                $old,
                $kind,
                $decision['step'] === '' ? 'nobody' : $decision['step'],
                $decision['new'],
                implode(', ', $unmeasured),
            );

            continue;
        }

        if ($preexisting !== []) {
            $lost[] = sprintf(
                '%s (%s), decided by %s to become %s — target(s) that existed before this measurement and'
                . ' therefore did not appear from this rename: %s',
                $old,
                $kind,
                $decision['step'] === '' ? 'nobody' : $decision['step'],
                $decision['new'],
                implode(', ', $preexisting),
            );

            continue;
        }

        $retired[] = ['old' => $old, 'kind' => $kind, 'new' => $decision['new'], 'step' => $decision['step']];
    }

    if ($lost !== []) {
        throw new RuntimeException(
            'The following rows carried a decided "new" value in the previous file, no longer appear in the'
            . ' measurement, and cannot be retired as executed: a rename is executed only when every name it'
            . ' promised APPEARED in this measurement. A disappeared identity must be investigated, not silently'
            . ' dropped: ' . implode('; ', $lost),
        );
    }

    usort($retired, static fn(array $a, array $b): int => [$a['step'], $a['kind'], $a['old']] <=> [$b['step'], $b['kind'], $b['old']]);

    return $retired;
}

/**
 * Follows a name through every later rename of it, and answers where it lands.
 *
 * Three exits, and the caller needs to tell them apart, which is why this
 * returns a verdict rather than a name. A chain that ends at a single name is
 * the ordinary case. A chain that reaches a SPLIT ends at several names, and
 * every one of them has to be measured — returning the pre-split name instead
 * would demand that a name the split consumed still exist. And a chain that
 * loops is a contradiction in the declarations, which has to be reported as one
 * instead of resolved to whichever member the walk stopped on.
 *
 * @param array<string, string> $later "kind\told" => new
 *
 * @return array{names: list<string>, cycle: bool}
 */
function resolveThroughLaterRenames(string $name, string $kind, array $later): array
{
    $seen = [];

    while (isset($later[$kind . "\t" . $name])) {
        if (isset($seen[$name])) {
            return ['names' => [$name], 'cycle' => true];
        }

        $seen[$name] = true;
        $next = $later[$kind . "\t" . $name];

        if ($next === '?' || $next === '' || str_contains($next, '*')) {
            break;
        }

        $halves = array_map(
            static fn(string $target): string => $kind === 'channel' && str_contains($target, '#')
                ? substr($target, (int) strpos($target, '#') + 1)
                : $target,
            explode('|', $next),
        );

        if (count($halves) > 1) {
            return ['names' => $halves, 'cycle' => false];
        }

        $name = $halves[0];
    }

    return ['names' => [$name], 'cycle' => false];
}

/**
 * The two invariants an executed row must still satisfy on every later run.
 *
 * History cannot be re-derived — that is stated in retireExecutedRows() and
 * owned by git — but it can be held to what it claims. Both directions are
 * checked, because they fail differently: an `old` that is measured again means
 * the rename was reverted or the row is wrong, and a promised target that is no
 * longer measured means the row is describing an outcome that has since been
 * undone. Neither is a state to carry silently.
 *
 * A target spelled with a wildcard is exempt from the second check: a wildcard
 * was never a measurable identity, so a row that names one could not have
 * retired automatically in the first place.
 *
 * A `channel` target recorded as a `rule#code` pair is held against the channel
 * half. History is left in the vocabulary it was recorded in — rewriting a
 * settled row would restate the measurement instead of preserving it. A
 * channel is identified by that half alone, so this is the same identity
 * spelled the way it was spelled then.
 *
 * A promised target that a LATER step renamed again is followed rather than
 * reported. A producer can be split and its resulting names renamed again;
 * holding the older row against its literal target would
 * force history to be rewritten every time a name moves twice, which is the one
 * thing this file must never do. The chain is followed through the executed
 * rows and the decided ones alike, and it is the END of the chain that has to
 * be measured.
 *
 * @param list<array{old: string, kind: string, new: string, step: string}> $executed
 * @param array<string, true> $measuredKeys
 * @param array<string, array<string, true>> $identitiesByKind
 * @param array<string, array{new: string, step: string}> $decided rows a later step has decided but not yet executed
 */
function assertExecutedRowsStillHold(
    array $executed,
    array $measuredKeys,
    array $identitiesByKind,
    array $decided,
): void {
    $later = [];

    foreach ($executed as $row) {
        $later[$row['kind'] . "\t" . $row['old']] = $row['new'];
    }

    // A decided row is only a hop once it has HAPPENED. While its old name is
    // still measured the rename has not been performed, and following it would
    // resolve a live name to one that does not exist yet — reporting a history
    // that holds as broken because a LATER step has been planned.
    foreach ($decided as $mergeKey => $decision) {
        [$old, $kind] = array_pad(explode("\t", $mergeKey, 2), 2, '');

        if (isset($measuredKeys[$old . "\t" . $kind])) {
            continue;
        }

        $later[$kind . "\t" . $old] = $decision['new'];
    }

    $problems = [];

    foreach ($executed as $row) {
        if (isset($measuredKeys[$row['old'] . "\t" . $row['kind']])) {
            $problems[] = sprintf(
                '%s (%s), recorded as executed by %s, is measured again: either the rename was reverted or the'
                . ' executed file is wrong',
                $row['old'],
                $row['kind'],
                $row['step'],
            );

            continue;
        }

        foreach (explode('|', $row['new']) as $target) {
            if (str_contains($target, '*')) {
                continue;
            }

            $measurable = $row['kind'] === 'channel' && str_contains($target, '#')
                ? substr($target, (int) strpos($target, '#') + 1)
                : $target;

            $chain = resolveThroughLaterRenames($measurable, $row['kind'], $later);

            if ($chain['cycle']) {
                $problems[] = sprintf(
                    '%s (%s), recorded as executed by %s, promised %s, and the declarations rename that name in a'
                    . ' loop through "%s": a chain that returns to itself names no outcome',
                    $row['old'],
                    $row['kind'],
                    $row['step'],
                    $row['new'],
                    $chain['names'][0],
                );

                continue;
            }

            foreach ($chain['names'] as $resolved) {
                if (isset($identitiesByKind[$row['kind']][$resolved])) {
                    continue;
                }

                $problems[] = sprintf(
                    '%s (%s), recorded as executed by %s, promised %s — but %s is not measured any more',
                    $row['old'],
                    $row['kind'],
                    $row['step'],
                    $row['new'],
                    $resolved === $target ? $target : $target . ' (renamed since to ' . $resolved . ')',
                );
            }
        }
    }

    if ($problems !== []) {
        throw new RuntimeException(
            'The executed-rename history no longer describes this measurement. Both directions are checked'
            . ' because a checker cannot re-derive history, only hold it to its own claims: '
            . implode('; ', $problems),
        );
    }
}

/**
 * Reads the tracked executed file. Its rows are history: they are carried over
 * verbatim and never re-measured, because a later step renaming one of their
 * targets is ordinary and would otherwise resurrect a settled failure.
 *
 * @return list<array{old: string, kind: string, new: string, step: string}>
 */
function readExecutedRows(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $rows = [];

    foreach (explode("\n", readFileOrFail($path)) as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $columns = explode("\t", $line);

        if ($columns[0] === 'old') {
            continue;
        }

        if (count($columns) < 4) {
            throw new RuntimeException(sprintf(
                'Line "%s" of the executed-rename history has %d column(s), fewer than the four a retired row'
                . ' needs. Dropping it would lose history this script cannot re-derive.',
                $line,
                count($columns),
            ));
        }

        [$old, $kind, $new, $step] = $columns;
        $rows[] = ['old' => $old, 'kind' => $kind, 'new' => $new, 'step' => $step];
    }

    return $rows;
}

/**
 * @param list<array{old: string, kind: string, new: string, step: string}> $rows
 */
function renderExecutedTsv(array $rows): string
{
    $lines = [];

    foreach ($rows as $row) {
        $lines[] = implode("\t", [$row['old'], $row['kind'], $row['new'], $row['step']]);
    }

    $header = <<<HEADER
# Executed renames that no longer belong to the current-name measurement.
#
# A row lands here by measurement, never by a flag: its `old` identity is gone
# from the production container (or from MetricName), and every name its `new`
# column promised is a measured identity of the same kind. A row whose identity
# is gone while its promised names are NOT measured is not retired — it fails
# `composer enumeration:renames`, because that is a lost identity rather than an
# executed step. See retireExecutedRows() in
# scripts/generate-rename-enumeration.php for why the difference has to come
# from the measurement and not from whoever runs the script.
#
# Rows here are history and are never re-measured: a later step renaming one of
# the names promised below is ordinary, and re-checking would resurrect a
# settled question. Do not hand-edit; both this file and
# enumeration-renames.tsv are regenerated by `composer enumeration:renames`.
#
# `new` carries several `|`-separated names when the rename was a split.
old	kind	new	step
HEADER;

    return $header . "\n" . ($lines === [] ? '' : implode("\n", $lines) . "\n");
}

/**
 * `$leafConst` is a PRESENCE FLAG rather than a count, unlike the other three:
 * {@see leafConstantChannels()} binds one declaring file per channel and throws
 * on a second, so `leaf_const` is the one column of the freeze quartet whose
 * value never exceeds 1.
 *
 * @param list<array{old: string, kind: string, search: string, counts: array<string, int>, new: string, step: string}> $rows
 * @param list<string> $surfaceOrder
 * @param array<string, int> $frozenKind channel literal => frozen OCCURRENCE_KIND declaration count
 * @param array<string, int> $frozenPin channel literal => frozen pin-test count
 * @param array<string, int> $leafConst channel literal => 1 where a leaf constant is declared
 * @param array<string, int> $leafPin channel literal => leaf pin-test count
 */
function renderTsv(array $rows, array $surfaceOrder, array $frozenKind, array $frozenPin, array $leafConst, array $leafPin): string
{
    usort($rows, static fn(array $a, array $b): int => [$a['kind'], $a['old']] <=> [$b['kind'], $b['old']]);

    $header = ['old', 'kind', 'new', 'step', ...$surfaceOrder, 'frozen_kind', 'frozen_pin', 'leaf_const', 'leaf_pin'];
    $lines = [implode("\t", $header)];
    $frozenRows = 0;
    $leafRows = 0;

    foreach ($rows as $row) {
        $line = [$row['old'], $row['kind'], $row['new'], $row['step']];

        foreach ($surfaceOrder as $surfaceKey) {
            $line[] = (string) $row['counts'][$surfaceKey];
        }

        // Only a `channel` row can carry a frozen occurrence discriminator or
        // a pin asserting one — a producer name or metric key never reaches
        // OccurrenceKey::semantic() as its first argument.
        $kindCount = $row['kind'] === 'channel' ? ($frozenKind[$row['old']] ?? 0) : 0;
        $pinCount = $row['kind'] === 'channel' ? ($frozenPin[$row['old']] ?? 0) : 0;
        $leafConstCount = $row['kind'] === 'channel' ? ($leafConst[$row['old']] ?? 0) : 0;
        $leafPinCount = $row['kind'] === 'channel' ? ($leafPin[$row['old']] ?? 0) : 0;
        $line[] = (string) $kindCount;
        $line[] = (string) $pinCount;
        $line[] = (string) $leafConstCount;
        $line[] = (string) $leafPinCount;

        if ($kindCount > 0 || $pinCount > 0) {
            $frozenRows++;
        }

        if ($leafConstCount > 0 || $leafPinCount > 0) {
            $leafRows++;
        }

        $lines[] = implode("\t", $line);
    }

    $channelCount = count(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'channel'));
    $producerCount = count(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'producer'));
    $metricKeyCount = count(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'metric-key'));

    return implode("\n", $lines) . "\n" . footer($surfaceOrder, $channelCount, $producerCount, $metricKeyCount, $frozenRows, $leafRows);
}

/**
 * The row counts below are computed from $rows on every call, never a frozen
 * literal: a hand-written copy of a number this script also computes is
 * exactly the kind of drift `--check` exists to catch elsewhere, and a
 * footer is not exempt from that just because it renders as a comment. See
 * the DoD of this script's own generating step for how a point-in-time count
 * was cross-checked against the drift-guarded fixture and `bin/qmx rules`.
 *
 * @param list<string> $surfaceOrder
 */
function footer(array $surfaceOrder, int $channelCount, int $producerCount, int $metricKeyCount, int $frozenRows, int $leafRows): string
{
    $surfaceList = implode(', ', $surfaceOrder);

    return <<<FOOTER
#
# ROW COUNTS: {$channelCount} channel, {$producerCount} producer, {$metricKeyCount} metric-key,
# {$frozenRows} channel row(s) carrying a frozen occurrence discriminator (see `frozen_kind`/`frozen_pin` below),
# {$leafRows} channel row(s) carrying a leaf occurrence discriminator (see `leaf_const`/`leaf_pin` below)
#
# HOW THIS WAS PRODUCED: `php scripts/generate-rename-enumeration.php`.
# - `channel` rows: {$channelCount} keys from
#   `ChannelDeclarationRegistryInterface::staticDeclarations()` on the
#   production container (the same oracle
#   `ChannelDeclarationFixtureDriftTest` uses) — never regex over rule files,
#   because a rule can name its own channel through an inherited constant.
# - `producer` rows: {$producerCount} names from
#   `RuleExecutionInterface::allRules()` (`RuleMetadata::name`).
# - `metric-key` rows: {$metricKeyCount} values, `ReflectionClass(MetricName::class)->getConstants()`.
#   `MetricName` is a plain final class with `public const string` members,
#   not a backed enum — reflecting its constants is the equivalent measurement.
# Occurrence columns ({$surfaceList}) count WHOLE-IDENTIFIER matches of the
# search string (the channel's `violationCode` half for `channel` rows, the
# name itself for `producer` and `metric-key` rows) — not files. A match must
# not be immediately preceded or followed by a word character, `.` or `-`, so
# a short name like "design.type-coverage" does not also count every
# occurrence of "design.type-coverage.property".
#
# BOUNDARY OF THE SET: every file under the eight surfaces above, minus
# generated/vendored noise (src/Reporting/Template/{node_modules,dist},
# benchmarks/vendor, __pycache__) and the three preset YAML files, which are
# counted once, under `presets`, not again under `src`.
#
# THE `new` AND `step` COLUMNS ARE DECISIONS, NOT MEASUREMENTS. This script
# never invents either: an unfilled row keeps `?` and an empty `step`. Both
# survive every later regeneration (merged back in by `old`+`kind`). When the row
# they were recorded against stops being measured there are two cases, and they
# are told apart by measurement rather than by assertion: if every name the
# decision promised APPEARED in this measurement, the rename has landed and the
# row retires into enumeration-renames-executed.tsv; otherwise the script fails
# instead of quietly dropping the decision. A target that existed before this
# measurement cannot have appeared, so it forbids retirement — that is what
# stops a collapse from retiring the half that was lost. A decided row must name
# the step that makes the decision, and a step must not name an undecided row:
# `--emit-maps=<step>` renders one step's decisions as map rows, and refuses a
# step that has already landed, whose maps are in git.
#
# `frozen_kind` AND `frozen_pin` NAME OCCURRENCES A RENAME SWEEP MUST SKIP.
# Six channels freeze their `OccurrenceKey` discriminator
# away from the channel code: each carries a private `OCCURRENCE_KIND`
# constant, equal to today's spelling ON PURPOSE, that must NOT follow a
# future rename of the channel — moving it would move `occurrence` for every
# already-accepted baseline entry on that channel. `frozen_kind` counts that
# constant's declaration (`private const string OCCURRENCE_KIND = '<this
# channel's literal>';`) found under src/; `frozen_pin` counts the
# `itKeysOccurrenceToTheFrozenChannelSpelling*` test method(s) that assert the
# constant still equals this literal. Both are DERIVED by scanning current
# source text for those two exact shapes (see frozenKindLiterals() and
# frozenPinLiterals() in this script) — never a hand-kept list of class or
# file names — so a family frozen the same way after this file was last
# regenerated is flagged with no edit here, and a family whose freeze
# regresses (the constant rewritten as `self::NAME`, or the pin deleted)
# silently drops back to `0` instead of staying flagged by memory alone.
#
# A row with `frozen_kind` or `frozen_pin` greater than zero MUST NOT have
# every one of its counted occurrences renamed by a mechanical sweep: the
# `OCCURRENCE_KIND` declaration(s) and the pin-test literal(s) these two
# columns count are exactly the occurrences that keep their PRE-rename
# spelling forever, by design — renaming them along with the channel silently
# ends the freeze and moves `occurrence` for the channel's accepted entries.
# Every other occurrence the row's surface counts include (selectors,
# `@qmx-ignore` targets, other tests, docs, config) renames normally.
# `governance/Occurrence/OccurrenceKindFreezeGuardTest.php`
# re-derives the same frozen set independently on every `composer test` run
# and fails if the count drifts, if a
# frozen constant no longer equals its own pin, or if the constant is no
# longer what `OccurrenceKey::semantic()` is called with — so a sweep that
# renamed a frozen occurrence by mistake is caught there, not only here. A
# frozen constant reading differently from `NAME` after a future channel
# rename is the freeze working as designed, not a drift to reconcile.
#
# `leaf_const` AND `leaf_pin` NAME A SECOND, DIFFERENTLY SHAPED FREEZE.
# Twelve further channels — the nine `code-smell.*` families deriving from
# `AbstractCodeSmellRule` and the three `security.*` families deriving from
# `AbstractSecurityPatternRule` — key `occurrence` off a
# `SMELL_TYPE`/`PATTERN_TYPE` constant instead, which the base passes to
# `OccurrenceKey::semantic()` through its finding VO. That constant holds the
# channel's LEAF in snake_case (`'eval'` for `code-smell.eval`,
# `'sql_injection'` for `security.sql-injection`), so it does not resemble
# the channel code and cannot be matched to it by spelling. `leaf_const` is a
# FLAG, 0 or 1, and not a count like the other three columns: the leaf is
# bound to its channel by ONE declaring file, and a second file claiming the
# same channel is refused with an exception rather than added up, so a value
# above 1 is unreachable by construction. `leaf_pin` counts the
# `itKeysOccurrenceToItsOwnSmellType`/`...PatternType` test method(s) that
# assert a finding this rule produced still keys on that literal. Both are
# DERIVED — the leaf is bound to its channel by the same-file co-location of
# `public const string NAME = '<channel>'` with the leaf constant, never by a
# hand-kept list — so a thirteenth family adopting the shape is flagged with
# no edit here, and a family that stops declaring it drops back to `0`.
#
# THE DANGER THESE TWO COLUMNS MARK IS NOT THE ONE `frozen_kind` MARKS.
# The six frozen literals ARE among their row's counted src/tests
# occurrences, so a mechanical whole-identifier sweep would rename them. A
# leaf is counted NOWHERE in its row: the row's search identifier is
# `code-smell.eval`, and neither `'eval'` nor the bag key `codeSmell.eval`
# matches it. The mechanical sweep will therefore leave these alone by
# construction; what moves them is a CONSISTENCY rename done by hand ("the
# channel is now `code-smell.dynamic-eval`, so the constant should read
# `dynamic_eval`"). A nonzero `leaf_const` says: this channel has a
# snake_case twin outside the sweep's reach that MUST keep its pre-rename
# spelling, because moving it moves `occurrence` for every already-accepted
# baseline entry on the channel, exactly as with the six.
#
# The leaf's occurrences are not listed here either. Locating them for one
# channel needs `grep -rn "SMELL_TYPE = '<leaf>'\|PATTERN_TYPE = '<leaf>'"
# src/`, plus its list entry in `CodeSmellCollector::SMELL_TYPES` /
# `SecurityPatternCollector::PATTERN_TYPES`, its `CodeSmellLocation('<leaf>'`
# / equivalent producer literal in the capability's visitor, and the
# `'codeSmell.<leaf>'` / `'security.<leaf>'` bag keys throughout tests/.
# `governance/Occurrence/OccurrenceLeafFreezeGuardTest.php`
# re-derives this set independently on every `composer test` run and fails if
# the twelve drift, if a leaf stops matching its own pin, or if a family
# loses its pin — a consistent sweep that renamed a leaf everywhere at once
# (src, collector, visitor and every test, which the full suite otherwise
# passes green) is caught there and nowhere else.
#
# WHAT THIS METHOD DOES NOT SEE:
#  - a name assembled at runtime (string concatenation, a value read from
#    `RuleMetadata::name` in code and stored under a different local
#    variable) is invisible to whole-identifier text matching, in exactly the
#    same way `enumeration-consumers.tsv` and `enumeration-rules.tsv` already
#    warn about grep-based extraction;
#  - `computed.*`/`health.*` beyond the six built-in health dimensions is
#    open-ended by construction — a user's own `computed_metrics:` entries in
#    their own qmx.yaml are not, and cannot be, enumerated here (same
#    boundary `enumeration-rules.tsv` names for `computed.health`);
#  - occurrence counts are OVERSTATED by prose that mentions a name in running
#    text without meaning "this literal identifier is written here" (a
#    website doc sentence that happens to contain the words), and
#    UNDERSTATED by string concatenation that assembles the identifier from
#    parts (`'design.' . 'type-coverage'`) or by a fixture line that
#    round-trips the name through a different serialization (e.g. escaped
#    slashes in a JSON string spanning what a plain-text regex reads as two
#    tokens);
#  - a channel's `ruleName` half is not counted on its own: for the channels
#    where `ruleName` differs from `violationCode` (the diagnostics on
#    `architecture.layer-violation` and `annotation.directive`), the
#    `ruleName` half's occurrences show up in the corresponding `producer`
#    row instead, because the two strings are identical text;
#  - `leaf_const`/`leaf_pin` bind a leaf to a channel by same-file
#    co-location with `NAME`. A family that split the two across files, or
#    computed either from parts, would not bind — the script fails loudly
#    rather than reporting zero, but the shape it can read is the one every
#    family uses today, not a general rule about PHP;
#  - `frozen_kind`/`frozen_pin` name WHICH channels carry protected
#    occurrences and HOW MANY, not WHERE (no file:line). Locating them for a
#    sweep still needs `grep -rn "OCCURRENCE_KIND = '<literal>'" src/` and
#    `grep -rln "itKeysOccurrenceToTheFrozenChannelSpelling" tests/` for that
#    one literal; both columns exist so a sweep knows it has to run those
#    greps at all, for exactly the rows where the count is nonzero.
FOOTER;
}

/**
 * Measures the current identity set and renders it to a TSV string, merging
 * onto whatever `new` decisions already sit in $outputPath. Used by both the
 * generate and the `--check` path so the two can never measure differently.
 *
 * @return array{content: string, executedContent: string, rows: list<array{old: string, kind: string, new: string, step: string}>, executedRows: list<array{old: string, kind: string, new: string, step: string}>, channelCount: int, producerCount: int, metricKeyCount: int, executedCount: int}
 */
function renderCurrentState(string $root, string $outputPath, string $executedPath, ContainerFactory $factory): array
{
    $rows = [];

    foreach (channelIdentities($factory) as $old => $search) {
        $rows[$old . "\tchannel"] = ['old' => $old, 'kind' => 'channel', 'search' => $search];
    }

    foreach (producerIdentities($factory) as $name) {
        $rows[$name . "\tproducer"] = ['old' => $name, 'kind' => 'producer', 'search' => $name];
    }

    foreach (metricKeyIdentities() as $value) {
        $rows[$value . "\tmetric-key"] = ['old' => $value, 'kind' => 'metric-key', 'search' => $value];
    }

    $surfaceOrder = array_keys(surfaces());
    $surfaceContents = readSurfaceContents(surfaces(), $root);

    $measuredRows = measure(array_values($rows), $surfaceContents, $surfaceOrder);
    $existingNew = readExistingNewColumn($outputPath);
    $executedRows = retireExecutedRows(
        $existingNew,
        $measuredRows,
        readExecutedRows($executedPath),
        readPreviousIdentities($outputPath),
    );
    $finalRows = mergeExistingNewColumn($measuredRows, $existingNew);
    assertEveryDecisionNamesItsStep([...$finalRows, ...$executedRows]);

    $frozenKind = frozenKindLiterals($surfaceContents['src']);
    $frozenPin = frozenPinLiterals($surfaceContents['tests']);
    $leaf = leafConstantChannels($root);
    $leafPin = leafPinLiterals($surfaceContents['tests'], $leaf['channelByLeaf']);

    return [
        'content' => renderTsv($finalRows, $surfaceOrder, $frozenKind, $frozenPin, $leaf['byChannel'], $leafPin),
        'executedContent' => renderExecutedTsv($executedRows),
        // Only the measured rows answer `--emit-maps`. A landed step's rows are
        // history, and its maps are already in git — see the refusal in main().
        'rows' => $finalRows,
        'executedRows' => $executedRows,
        'channelCount' => count(array_filter($finalRows, static fn(array $row): bool => $row['kind'] === 'channel')),
        'producerCount' => count(array_filter($finalRows, static fn(array $row): bool => $row['kind'] === 'producer')),
        'metricKeyCount' => count(array_filter($finalRows, static fn(array $row): bool => $row['kind'] === 'metric-key')),
        'executedCount' => count($executedRows),
    ];
}

/**
 * Describes exactly which lines of the committed file disagree with a fresh
 * regeneration, so a `--check` failure names the divergence instead of just
 * asserting one exists. The `new` column can never be part of that
 * divergence: $fresh was rendered by merging $onDisk's own decided `new`
 * values (see mergeExistingNewColumn()), so a human decision recorded on
 * disk is reproduced verbatim, not recomputed.
 */
function describeMismatch(string $onDisk, string $fresh, string $path, string $command = 'composer enumeration:renames'): string
{
    $onDiskLines = explode("\n", $onDisk);
    $freshLines = explode("\n", $fresh);
    $lineCount = max(count($onDiskLines), count($freshLines));

    $diffs = [];

    for ($line = 0; $line < $lineCount; $line++) {
        $committed = $onDiskLines[$line] ?? '<no such line on disk>';
        $regenerated = $freshLines[$line] ?? '<line removed by regeneration>';

        if ($committed !== $regenerated) {
            $diffs[] = sprintf(
                "  line %d:\n    committed:    %s\n    regenerated:  %s",
                $line + 1,
                $committed,
                $regenerated,
            );
        }
    }

    $shown = array_slice($diffs, 0, 10);
    $summary = sprintf(
        "%s is stale: %d of %d line(s) differ from a fresh measurement. Run `%s` to refresh it.\n",
        $path,
        count($diffs),
        $lineCount,
        $command,
    );

    if (count($diffs) > count($shown)) {
        $shown[] = sprintf('  ... and %d more differing line(s).', count($diffs) - count($shown));
    }

    return $summary . implode("\n", $shown) . "\n";
}

/**
 * The runtime half of the channel universe, which `staticDeclarations()` cannot
 * see and this file's other mode therefore never enumerated.
 *
 * `ChannelUniverse` resolves every computed-metric channel from the
 * *resolved* definition catalog, so the set exists only once a configuration
 * document has been read. It splits in two, and the two halves are knowable in
 * different ways:
 *
 *  - the built-in health dimensions are a CLOSED set, and it is derived here
 *    from {@see ComputedMetricDefaults::getDefaults()} — the same array the
 *    resolver starts from. Adding a seventh dimension there therefore adds a
 *    seventh row here, with no edit to this script;
 *  - a user-defined computed metric is OPEN by construction: its name comes out
 *    of somebody's own `qmx.yaml`. It cannot be enumerated, only OBSERVED, so
 *    this file narrows it explicitly to the corpus the finding gate runs
 *    (`finding-gate/cases/*​/qmx.yaml`) and says so in the `origin` column.
 *
 * The `producer` column is measured, not decided: built-in health dimensions
 * {@see ComputedMetricChannelFamily::producerFor()}'s answer — the same arbiter
 * the universe and the emission use. The rename it records is history and lives
 * in enumeration-renames-executed.tsv; what this file states is the standing
 * property that a built-in dimension is its own producer while every
 * user-defined metric shares the open one.
 *
 * Both shapes are held fail-closed — a built-in name that is not `health.*`, or
 * an observed name that is not `computed.*`, is a name the vocabulary says
 * nothing about, and this refuses to invent an answer for it.
 *
 * @return list<array{channel: string, origin: string, source: string, producer: string, step: string}>
 */
function runtimeChannelRows(string $root): array
{
    $defaultsFile = relativeToRoot((string) (new ReflectionClass(ComputedMetricDefaults::class))->getFileName(), $root);

    $builtin = [];

    foreach (ComputedMetricDefaults::getDefaults() as $definition) {
        if (!str_starts_with($definition->name, 'health.')) {
            throw new RuntimeException(sprintf(
                'Built-in computed metric "%s" is not a `health.*` name, so its channel is not enumerable.'
                . ' Either rename the built-in or widen the explicit rule here.',
                $definition->name,
            ));
        }

        $builtin[$definition->name] = true;
    }

    if ($builtin === []) {
        throw new RuntimeException(sprintf(
            '%s returned no definitions. An empty built-in half is a broken measurement, not a valid state.',
            ComputedMetricDefaults::class,
        ));
    }

    $rows = [];

    foreach (array_keys($builtin) as $name) {
        $rows[$name] = [
            'channel' => $name,
            'origin' => 'builtin',
            'source' => $defaultsFile,
            'producer' => ComputedMetricChannelFamily::producerFor($name),
            'step' => "\u{0428}5d",
        ];
    }

    foreach (observedComputedMetricNames($root) as $name => $sources) {
        if (isset($builtin[$name])) {
            continue;
        }

        if (!str_starts_with($name, 'computed.')) {
            throw new RuntimeException(sprintf(
                'The corpus declares computed metric "%s", which is neither a built-in `health.*` dimension nor a'
                . ' `computed.*` user metric. Only those two shapes have a defined channel target, so this cannot be'
                . ' translated without a new decision. Declared in: %s',
                $name,
                implode(', ', $sources),
            ));
        }

        $rows[$name] = [
            'channel' => $name,
            'origin' => 'corpus-observed',
            'source' => implode(',', $sources),
            'producer' => ComputedMetricChannelFamily::producerFor($name),
            'step' => "\u{0428}5d",
        ];
    }

    $rows = array_values($rows);
    usort($rows, static fn(array $a, array $b): int => [$a['origin'], $a['channel']] <=> [$b['origin'], $b['channel']]);

    return $rows;
}

/**
 * Every `computed_metrics:` key the gate corpus writes, with the case files that
 * write it. Keys are returned verbatim: telling a built-in threshold override
 * apart from a user-defined metric is the caller's job, and it does it by
 * subtracting the measured built-in set rather than by matching a prefix.
 *
 * @return array<string, list<string>> metric name => project-relative case config paths
 */
function observedComputedMetricNames(string $root): array
{
    $pattern = $root . '/finding-gate/cases/*/qmx.yaml';
    $files = glob($pattern);

    if ($files === false || $files === []) {
        throw new RuntimeException(sprintf(
            'No corpus configuration matched "%s". The observed half of this enumeration would silently come out'
            . ' empty, which reads as "the corpus defines no computed metric" — a claim nothing measured.',
            $pattern,
        ));
    }

    sort($files);
    $names = [];

    foreach ($files as $file) {
        $document = Yaml::parseFile($file);

        if (!is_array($document)) {
            throw new RuntimeException(sprintf('%s does not parse to a YAML mapping.', $file));
        }

        $section = $document['computed_metrics'] ?? [];

        if (!is_array($section)) {
            throw new RuntimeException(sprintf('%s has a non-mapping `computed_metrics` section.', $file));
        }

        foreach (array_keys($section) as $name) {
            $names[(string) $name][] = relativeToRoot($file, $root);
        }
    }

    ksort($names);

    return $names;
}

function relativeToRoot(string $path, string $root): string
{
    $real = realpath($path);
    $absolute = $real === false ? $path : $real;

    return str_starts_with($absolute, $root . '/') ? substr($absolute, strlen($root) + 1) : $absolute;
}

/**
 * @param list<array{channel: string, origin: string, source: string, producer: string, step: string}> $rows
 */
function renderRuntimeChannelsTsv(array $rows): string
{
    $builtinCount = count(array_filter($rows, static fn(array $row): bool => $row['origin'] === 'builtin'));
    $observedCount = count($rows) - $builtinCount;

    $header = <<<HEADER
# RUNTIME channels: the computed-metric half of the channel universe, which
# `ChannelDeclarationRegistryInterface::staticDeclarations()` does not contain
# and enumeration-renames.tsv therefore does not list. The split that created
# these producers is recorded once, as a retired `|`-separated row in
# enumeration-renames-executed.tsv; it is a record of a SPLIT and not a map row
# — `RenameMaps` knows neither `|` nor a wildcard.
#
# Do not hand-edit. Regenerate with:
#   php scripts/generate-rename-enumeration.php --runtime-channels
#
# ROW COUNTS: {$builtinCount} builtin, {$observedCount} corpus-observed
#
# HOW THIS WAS PRODUCED
# - `builtin` rows are DERIVED from
#   `ComputedMetricDefaults::getDefaults()` — the array the config resolver
#   itself starts from — not written out here. A seventh health dimension added
#   there appears here on the next run, with no edit to the generator.
# - `corpus-observed` rows are the keys of `computed_metrics:` in
#   finding-gate/cases/*/qmx.yaml minus the built-in set. A user-defined
#   computed metric is open-ended by construction (it is a name in somebody's
#   own qmx.yaml), so it is not enumerable — only observable, and only over the
#   corpus this run read. That narrowing is the `origin` column's whole point.
# - `producer` is MEASURED, through `ComputedMetricChannelFamily::producerFor()`
#   — the same arbiter the channel universe and the finding emission ask. Since
#   A built-in dimension is its own producer and every user-defined metric
#   shares the open producer `computed`; this column is where that stops being
#   prose. A built-in name that is not `health.*`, or an observed name that is
#   not `computed.*`, fails the generator instead of getting an invented
#   answer: the vocabulary names those two shapes and no others.
#
# WHAT THIS DOES NOT SEE
# - a computed metric defined in a qmx.yaml outside the gate corpus (a user's
#   own project). That set has no upper bound and no measurement can close it;
# - whether a channel listed here actually FIRES. A case may disable a built-in
#   dimension (`enabled: false`) or threshold it out of range; what fires is
#   measured by the gate's coverage check, against each case's `channels` claim.
channel	origin	source	producer	step
HEADER;

    $lines = [];

    foreach ($rows as $row) {
        $lines[] = implode("\t", [$row['channel'], $row['origin'], $row['source'], $row['producer'], $row['step']]);
    }

    return $header . "\n" . ($lines === [] ? '' : implode("\n", $lines) . "\n");
}

/**
 * The number of declared channel rows in the STATIC half's own artifact
 * (`enumeration-channels.tsv`): every non-blank, non-comment line minus the
 * one column-header line. Read from that file rather than re-measured from
 * the container, because the static half already has its own measurement
 * process (see that file's header) and a second one here would be a second
 * authority for the same count.
 */
function staticChannelCount(string $path): int
{
    if (!is_file($path)) {
        throw new RuntimeException(sprintf('Static channel enumeration not found: %s', $path));
    }

    $lines = array_values(array_filter(
        explode("\n", (string) file_get_contents($path)),
        static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#'),
    ));

    if ($lines === []) {
        throw new RuntimeException(sprintf('%s has no header row; an empty static half is a broken measurement.', $path));
    }

    return count($lines) - 1;
}

/**
 * Prints the two halves of the channel universe and their sum, without
 * merging their source files: the static half resolves once when the
 * container compiles, the runtime half resolves later, at metric-lookup
 * time from configuration, and merging the files would lose that
 * distinction. Each half is read through its own artifact/measurement — see
 * `staticChannelCount()` and `runtimeChannelRows()`.
 *
 * @param list<array{channel: string, origin: string, source: string, producer: string, step: string}> $runtimeRows
 */
function renderChannelUniverseReport(int $staticCount, string $staticSource, array $runtimeRows, string $runtimeSource): string
{
    $builtinCount = count(array_filter($runtimeRows, static fn(array $row): bool => $row['origin'] === 'builtin'));
    $observedCount = count($runtimeRows) - $builtinCount;
    $runtimeCount = $builtinCount + $observedCount;
    $total = $staticCount + $runtimeCount;

    return <<<REPORT
Channel universe, two halves, two resolution times — never merged into one file or one count:

  STATIC   {$staticCount} channels   resolved once, at container-compile time
           source: {$staticSource}

  RUNTIME  {$runtimeCount} channels   ({$builtinCount} builtin + {$observedCount} corpus-observed), resolved later, at metric-lookup time from configuration
           source: {$runtimeSource}

  TOTAL    {$total} channels   = STATIC + RUNTIME

The corpus-observed count is a lower bound: a computed metric declared in a
qmx.yaml outside the gate corpus is not enumerable, only observable, and only
over the corpus this run read.

REPORT;
}

function main(): int
{
    $arguments = array_slice($_SERVER['argv'] ?? [], 1);
    $check = in_array('--check', $arguments, true);
    $runtimeChannels = in_array('--runtime-channels', $arguments, true);
    $channelUniverse = in_array('--channel-universe', $arguments, true);
    $emitStep = null;

    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--emit-maps=')) {
            $emitStep = substr($argument, strlen('--emit-maps='));
        }
    }

    $unknown = array_values(array_filter(
        $arguments,
        static fn(string $argument): bool => $argument !== '--check'
            && $argument !== '--runtime-channels'
            && $argument !== '--channel-universe'
            && !str_starts_with($argument, '--emit-maps='),
    ));

    if ($unknown !== []) {
        fwrite(STDERR, 'Unknown argument: ' . implode(', ', $unknown) . "\n");

        return 2;
    }

    $root = dirname(__DIR__);
    $outputPath = $root . '/finding-gate/enumeration-renames.tsv';
    $executedPath = $root . '/finding-gate/enumeration-renames-executed.tsv';

    // Handled before anything touches the container, same as --runtime-channels
    // below: neither half this prints needs the compiled container, and this
    // mode never writes a file — it only reads the static half's own artifact
    // and re-derives the runtime half, then prints both plus their sum.
    if ($channelUniverse) {
        $staticPath = $root . '/finding-gate/enumeration-static-channels.tsv';
        $staticCount = staticChannelCount($staticPath);
        $runtimeSourcePath = $root . '/finding-gate/enumeration-runtime-channels.tsv';

        fwrite(STDOUT, renderChannelUniverseReport(
            $staticCount,
            relativeToRoot($staticPath, $root),
            runtimeChannelRows($root),
            relativeToRoot($runtimeSourcePath, $root) . ' (re-derived here; run --runtime-channels --check to prove the file agrees)',
        ));

        return 0;
    }

    // Handled before anything touches the container: this half is resolved from
    // the definition catalog, not from the static declarations, so it needs no
    // container — and running the measured half here would rewrite
    // enumeration-renames.tsv as a side effect of asking about runtime channels.
    if ($runtimeChannels) {
        $runtimePath = $root . '/finding-gate/enumeration-runtime-channels.tsv';
        $content = renderRuntimeChannelsTsv(runtimeChannelRows($root));

        if ($check) {
            $onDisk = is_file($runtimePath) ? file_get_contents($runtimePath) : false;

            if ($onDisk === false || $onDisk !== $content) {
                fwrite(STDERR, describeMismatch(
                    $onDisk === false ? '' : $onDisk,
                    $content,
                    $runtimePath,
                    'php scripts/generate-rename-enumeration.php --runtime-channels',
                ));

                return 1;
            }

            fwrite(STDOUT, sprintf("Checked %s: up to date.\n", $runtimePath));

            return 0;
        }

        writeAtomically($runtimePath, $content);
        fwrite(STDOUT, sprintf("Wrote %s.\n", $runtimePath));

        return 0;
    }

    $factory = new ContainerFactory();

    $state = renderCurrentState($root, $outputPath, $executedPath, $factory);

    if ($emitStep !== null) {
        if ($emitStep === '') {
            fwrite(STDERR, "--emit-maps=<step> needs a step, e.g. --emit-maps=\u{0428}4b.\n");

            return 2;
        }

        try {
            assertKnownRenameStep($emitStep);
        } catch (RuntimeException $error) {
            fwrite(STDERR, $error->getMessage() . "\n");

            return 2;
        }

        // A landed step is refused rather than re-emitted. Its rows are in the
        // executed file, which is history this script cannot re-derive, and its
        // maps are already tracked in git: emitting from history would launder a
        // tampered row into a fresh-looking declaration, and comparing the
        // output against the tracked maps by eye is exactly the check nobody
        // performs. Read the maps from git instead.
        $retired = array_values(array_filter(
            $state['executedRows'],
            static fn(array $row): bool => $row['step'] === $emitStep,
        ));

        if ($retired !== []) {
            fwrite(STDERR, sprintf(
                "Step %s has already landed: %d of its rows are retired into %s, so its maps are in git rather than"
                . " re-derivable here. Read finding-gate/maps/*.tsv from the commit that landed it.\n",
                $emitStep,
                count($retired),
                'enumeration-renames-executed.tsv',
            ));

            return 2;
        }

        $emitted = emitMapsForStep($state['rows'], $emitStep);

        if (!str_contains($emitted, "\t")) {
            fwrite(STDERR, sprintf(
                "Step %s has no decided row in the measured enumeration, so there is no map to emit. Decide the"
                . " renames first (the `new` and `step` columns of %s).\n",
                $emitStep,
                'enumeration-renames.tsv',
            ));

            return 2;
        }

        fwrite(STDOUT, $emitted);

        return 0;
    }

    if ($check) {
        $onDisk = is_file($outputPath) ? file_get_contents($outputPath) : false;

        if ($onDisk === false) {
            fwrite(STDERR, sprintf("%s does not exist. Run `composer enumeration:renames` to generate it.\n", $outputPath));

            return 1;
        }

        if ($onDisk !== $state['content']) {
            fwrite(STDERR, describeMismatch($onDisk, $state['content'], $outputPath));

            return 1;
        }

        $executedOnDisk = is_file($executedPath) ? file_get_contents($executedPath) : '';

        if ($executedOnDisk === false || $executedOnDisk !== $state['executedContent']) {
            fwrite(STDERR, describeMismatch(
                $executedOnDisk === false ? '' : $executedOnDisk,
                $state['executedContent'],
                $executedPath,
            ));

            return 1;
        }

        fwrite(STDOUT, sprintf(
            "Checked %s: up to date (%d channel, %d producer, %d metric-key rows, %d executed).\n",
            $outputPath,
            $state['channelCount'],
            $state['producerCount'],
            $state['metricKeyCount'],
            $state['executedCount'],
        ));

        return 0;
    }

    writeAtomically($outputPath, $state['content']);
    writeAtomically($executedPath, $state['executedContent']);

    fwrite(STDOUT, sprintf(
        "Wrote %s: %d channel, %d producer, %d metric-key rows; %d executed rename(s) retired to %s.\n",
        $outputPath,
        $state['channelCount'],
        $state['producerCount'],
        $state['metricKeyCount'],
        $state['executedCount'],
        basename($executedPath),
    ));

    return 0;
}

function writeAtomically(string $path, string $contents): void
{
    $tmpPath = $path . '.tmp.' . getmypid();
    file_put_contents($tmpPath, $contents);
    rename($tmpPath, $path);
}

// Include-safe: running the file executes it, requiring it only defines the
// functions. The retirement predicate is the one piece of this script a test
// has to reach directly — its branches are all fail-closed, and a fail-closed
// branch nobody has seen fire is a branch nobody has tested. See
// scripts/rename-enumeration/tests/RenameEnumerationRetirementTest.php.
if (realpath((string) ($_SERVER['argv'][0] ?? '')) === realpath(__FILE__)) {
    exit(main());
}
