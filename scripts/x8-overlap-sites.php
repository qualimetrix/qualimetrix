<?php

declare(strict_types=1);

/**
 * X8 packages P4a and P4b: take the population of overlap-spelling literal
 * sites with the guard's own optics.
 *
 * The guard this measures is
 * `tests/Analysis/Finding/Integration/RuleIdentifierLiteralGuardTest.php`. It
 * used to subtract every `MetricName` value from its ownership map, so every
 * literal whose spelling is both a channel code and a metric key was invisible
 * to it. P4a enumerated those sites; P4b removed the subtraction, cured every
 * site that read a metric and allowed the three files whose sites cannot be
 * cured. What this script prints is therefore no longer a pending population:
 * it is the standing census of overlap-spelling literal sites, and every site
 * it still lists must be inside an allowed file.
 *
 * It does not restate the guard's reader in its own words: the guard's private
 * static `stringLiterals()`, `capabilityRootFromRelativePath()`,
 * `productionPhpFiles()` and `ownerByLiteral()` are invoked by reflection on
 * the test class itself. The metric-key list is read here directly, because
 * the guard no longer holds one — it is a plain reflection over `MetricName`'s
 * string constants, and the guard has nothing left for it to diverge from. The
 * only other logic written here is the line number, which `stringLiterals()`
 * discards; the local token walk that carries the line is asserted to produce
 * the guard's own value sequence position by position, so a divergence between
 * the two readers is a hard failure rather than a silent difference.
 *
 * Usage: php scripts/x8-overlap-sites.php <tree-root> [--tsv|--owned-channel-codes]
 *
 * Without `--tsv` the full measurement is printed as JSON, the substring optic
 * this package replaces included, so the two can be compared as sets. With
 * `--tsv`, only `file`, `line`, `literal` — the three columns of
 * `enumeration-overlap-sites.tsv` that come from the reader rather than from
 * judgement. With `--owned-channel-codes`, how many of the registered channel
 * codes the guard's ownership map still carries — the coverage the removed
 * subtraction used to cost.
 * *
 * <tree-root> is the tree to measure. It is loaded ahead of the ambient
 * autoloader so that a tree other than the one this script lives in can be
 * measured while neighbouring work edits the working tree; the script refuses
 * to run if any measured class still resolves to a file outside it.
 */

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

const GUARD_CLASS = 'Qualimetrix\\Tests\\Analysis\\Finding\\Integration\\RuleIdentifierLiteralGuardTest';

/** @var list<string> $arguments */
$arguments = array_slice($argv ?? [], 1);

$treeRoot = realpath($arguments[0] ?? '');

if ($treeRoot === false || !is_dir($treeRoot . '/src')) {
    fwrite(STDERR, "Usage: php scripts/x8-overlap-sites.php <tree-root>\n");

    exit(2);
}

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($vendorAutoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing; run composer install first.\n");

    exit(2);
}

require $vendorAutoload;

// Prepended, so the measured tree wins over the ambient one for every
// project class. Third-party packages keep resolving through Composer.
spl_autoload_register(static function (string $class) use ($treeRoot): void {
    $map = [
        'Qualimetrix\\Tests\\' => $treeRoot . '/tests/',
        'Qualimetrix\\' => $treeRoot . '/src/',
    ];

    foreach ($map as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
}, true, true);

chdir($treeRoot);

// A clone whose classes silently resolve back into the original tree is the
// exact way this measurement would report the wrong tree while looking green.
foreach ([MetricName::class, ContainerFactory::class, GUARD_CLASS] as $class) {
    $file = (new ReflectionClass($class))->getFileName();

    if ($file === false || !str_starts_with($file, $treeRoot . '/')) {
        fwrite(STDERR, sprintf("%s resolved to %s, outside the measured tree.\n", $class, var_export($file, true)));

        exit(2);
    }
}

/**
 * @param list<mixed> $arguments
 */
function guard(string $method, array $arguments = []): mixed
{
    return (new ReflectionMethod(GUARD_CLASS, $method))->invoke(null, ...$arguments);
}

$container = (new ContainerFactory())->create();

/** @var array<string, string> $ownerByLiteral rule name or channel code => owning capability root */
$ownerByLiteral = guard('ownerByLiteral', [$container]);

/**
 * Every published metric key.
 *
 * @return list<string>
 */
function metricKeys(): array
{
    $constants = (new ReflectionClass(MetricName::class))->getConstants();

    return array_values(array_filter($constants, static fn(mixed $value): bool => is_string($value)));
}

$metricKeys = metricKeys();

// The overlap: a spelling that is at once a registered channel code (or rule
// name) and a published metric key. These are precisely the literals the guard
// used to drop.
$overlap = [];

foreach ($metricKeys as $key) {
    if (isset($ownerByLiteral[$key])) {
        $overlap[$key] = $ownerByLiteral[$key];
    }
}

ksort($overlap);

/** @var list<string> $files */
$files = guard('productionPhpFiles', [$treeRoot]);

/**
 * Literal token values with their lines. The value sequence is asserted below
 * to equal the guard's own `stringLiterals()` output for the same file.
 *
 * @return list<array{value: string, line: int}>
 */
function literalsWithLines(string $absolutePath): array
{
    $source = file_get_contents($absolutePath);

    if ($source === false) {
        throw new RuntimeException(sprintf('Could not read %s.', $absolutePath));
    }

    $decode = new ReflectionMethod(GUARD_CLASS, 'decodeStringLiteralToken');
    $literals = [];

    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $literals[] = ['value' => $decode->invoke(null, $token[1]), 'line' => $token[2]];
    }

    return $literals;
}

$sites = [];
$sameOwnerSites = 0;
$substringHits = [];

foreach ($files as $absolutePath) {
    $relative = substr($absolutePath, strlen($treeRoot) + 1);
    /** @var string $fileOwner */
    $fileOwner = guard('capabilityRootFromRelativePath', [$relative]);

    $withLines = literalsWithLines($absolutePath);
    /** @var list<string> $guardLiterals */
    $guardLiterals = guard('stringLiterals', [$absolutePath]);

    if (array_column($withLines, 'value') !== $guardLiterals) {
        fwrite(STDERR, sprintf("Local token walk diverged from the guard's reader in %s.\n", $relative));

        exit(2);
    }

    foreach ($withLines as $literal) {
        if (!isset($overlap[$literal['value']])) {
            continue;
        }

        if ($overlap[$literal['value']] === $fileOwner) {
            ++$sameOwnerSites;

            continue;
        }

        $sites[] = [
            'file' => $relative,
            'line' => $literal['line'],
            'literal' => $literal['value'],
            'owner' => $overlap[$literal['value']],
            'file_owner' => $fileOwner,
        ];
    }

    // The optics package P4a exists to replace: a raw substring count over the
    // file text, single-quoted form, ignoring tokens entirely.
    $source = file_get_contents($absolutePath);

    if ($source === false) {
        throw new RuntimeException(sprintf('Could not read %s.', $absolutePath));
    }

    foreach ($overlap as $code => $owner) {
        if ($owner === $fileOwner) {
            continue;
        }

        $count = substr_count($source, "'" . $code . "'");

        if ($count > 0) {
            $substringHits[$relative][$code] = $count;
        }
    }
}

if (in_array('--tsv', $arguments, true)) {
    foreach ($sites as $site) {
        echo $site['file'], "\t", $site['line'], "\t", $site['literal'], "\n";
    }

    exit(0);
}

$universe = $container->get(ChannelUniverseInterface::class);
assert($universe instanceof ChannelUniverseInterface);

$channelCodes = array_map(static fn(object $channel): string => $channel->code, $universe->channels());
$ownedChannelCodes = array_values(array_filter(
    $channelCodes,
    static fn(string $code): bool => isset($ownerByLiteral[$code]),
));

if (in_array('--owned-channel-codes', $arguments, true)) {
    printf("%d of %d channel codes are under the guard's ownership half.\n", count($ownedChannelCodes), count($channelCodes));

    exit(0);
}

$report = [
    'channel_codes' => count($channelCodes),
    'owned_channel_codes' => count($ownedChannelCodes),
    'overlap_literals' => $overlap,
    'sites' => $sites,
    'same_owner_sites' => $sameOwnerSites,
    'sites_by_file' => array_count_values(array_column($sites, 'file')),
    'substring_hits' => $substringHits,
    'substring_total' => array_sum(array_map(static fn(array $byCode): int => array_sum($byCode), $substringHits)),
    'allowed_files' => (new ReflectionClass(GUARD_CLASS))->getConstant('ALLOWED_FILES'),
];

echo json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), "\n";
