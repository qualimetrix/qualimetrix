<?php

declare(strict_types=1);

/**
 * The cheap half of the promise-effect oracle: declarations, grid span,
 * population coverage and input freshness — without taking a single probe.
 *
 * The expensive stand costs about 200 s of product runs and stays outside
 * `composer check` for that reason. What belongs in the aggregate is the
 * question a change to a declaration makes urgent: does the grid still span
 * what the ledger promises, does it still carry every member of every
 * population the code knows about, and was it measured from these inputs? All
 * three are answerable in seconds, and none of them re-measures the product.
 *
 * The boundary is deliberate and it is not a hedge: a green run here says the
 * grid matches its declarations, never that it matches the product's current
 * behaviour. Only `composer promise-effect` says that.
 *
 * Usage:
 *   php scripts/promise-effect-grid.php            report
 *   php scripts/promise-effect-grid.php --check    same, exit 1 on drift
 *   php scripts/promise-effect-grid.php --root=DIR judge a copied tree
 *
 * Exit codes: 0 clean, 1 drift or an uncovered population member,
 * 2 a declaration that cannot be read.
 */

namespace Qualimetrix\PromiseEffect;

use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/promise-effect/Ledger.php';
require __DIR__ . '/promise-effect/Declarations.php';
require __DIR__ . '/promise-effect/InProcess.php';
require __DIR__ . '/promise-effect/ProcessProbe.php';
require __DIR__ . '/promise-effect/Classifier.php';
require __DIR__ . '/promise-effect/Stand.php';
require __DIR__ . '/promise-effect/Population.php';
require __DIR__ . '/promise-effect/FifthSet.php';
require __DIR__ . '/promise-effect/Stamp.php';

/** @return array<string, string> producer name => options class, as the product wires them */
function runtimeRules(): array
{
    $execution = (new ContainerFactory())->create()->get(RuleExecutionInterface::class);

    if (!$execution instanceof RuleExecutionInterface) {
        throw new LedgerError('the container did not yield the rule execution the product wires');
    }

    $rules = [];

    foreach ($execution->allRules() as $metadata) {
        $rules[$metadata->name] = $metadata->optionsClass;
    }

    return $rules;
}

/** @return array<string, int> grid key => how many rows carry it */
function gridKeys(string $path): array
{
    $lines = file($path, \FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        throw new LedgerError('cannot read ' . $path . ': the grid has never been written');
    }

    $keys = [];

    foreach (\array_slice($lines, 1) as $line) {
        if ($line === '') {
            continue;
        }

        $cells = explode("\t", $line);
        $key = $cells[1] ?? '';
        $keys[$key] = ($keys[$key] ?? 0) + 1;
    }

    return $keys;
}

$root = \dirname(__DIR__);
/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
$check = false;

foreach (\array_slice($argv, 1) as $argument) {
    if ($argument === '--check') {
        $check = true;
    }

    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, \strlen('--root='));
    }
}

try {
    $rules = runtimeRules();
    $ledger = Ledger::load($root);
    $declarations = Declarations::load($root);
    $stand = new Stand(
        $root,
        $ledger,
        $declarations,
        new InProcess(sys_get_temp_dir() . '/qmx-promise-effect-grid'),
        new ProcessProbe($root, sys_get_temp_dir() . '/qmx-promise-effect-grid'),
    );
    $expected = $stand->expectedKeys();
    $actual = gridKeys($root . '/' . Stand::SNAPSHOT_DIR . '/verdicts.tsv');
} catch (LedgerError $error) {
    fwrite(\STDERR, 'promise-effect-grid: ' . $error->getMessage() . "\n");

    exit(2);
}

$problems = [];

foreach ($expected as $key => $count) {
    $carried = $actual[$key] ?? 0;

    if ($carried !== $count) {
        $problems[] = \sprintf('the ledger owes %d cell(s) for %s, the grid carries %d', $count, $key, $carried);
    }
}

foreach ($actual as $key => $count) {
    if (!isset($expected[$key])) {
        // The growth guard of 02 §2, taken at the cheap point: a grid row no
        // ledger row owes is exactly the UNPROMISED verdict, and letting it
        // travel unnoticed is what that verdict exists to prevent.
        $problems[] = 'UNPROMISED: the grid carries ' . $key . ' and the ledger owes no such cell';
    }
}

$population = new Population($root, $rules);
$uncovered = $population->uncovered(array_keys($actual));
// 02 §5 asks for the kind-to-coordinate mapping to be fixed before the stand
// starts, "otherwise the guard's population moves with the interpretation".
// The column it names is not in `key-pairs.tsv` and that artifact is frozen,
// so the mapping is declared in `promise-effect/pair-kind-scope.tsv` and held
// to the ledger here, row by row.
$scopeProblems = $population->pairScopeProblems($ledger->pairs, $declarations->pairScopes);
$drift = (new Stamp($root))->drift();
$fifth = (new FifthSet($root, $population->optionsClassNames()))->compute();

printf("grid            %d cell(s) over %d distinct key(s)\n", array_sum($actual), \count($actual));
printf("ledger owes     %d cell(s) over %d distinct key(s)\n", array_sum($expected), \count($expected));
printf("populations     %d member(s), %d not carried by the grid\n", \count($population->all()), \count($uncovered));
printf("input stamp     %s\n", $drift === [] ? 'fresh' : \count($drift) . ' input(s) moved since the grid was measured');
printf(
    "pair scopes     %d key-pairs row(s) against %d ledger pair row(s), %s\n",
    \count($population->all()) === 0 ? 0 : \count(array_filter($population->all(), static fn(Member $m): bool => $m->population === 'same-source-pair')),
    \count($ledger->pairs),
    $scopeProblems === [] ? 'the declared kind-to-coordinate mapping holds' : \count($scopeProblems) . ' disagreement(s)',
);

printf(
    "\nfifth set (consumer \\ declaration) — NOT EVIDENCE\n"
    . "  %-34s %d\n  %-34s %d\n  %-34s %d\n  %-34s %d\n  %-34s %d\n  %-34s %d\n",
    'form-deciding sites',
    $fifth->sites,
    'of them named-key (resolved)',
    $fifth->namedSites,
    'of them any-key (UNRESOLVED)',
    $fifth->anyKeySites,
    'inside fromArray()',
    $fifth->factorySites,
    'key literals actually compared',
    $fifth->comparedSites,
    'read and not declared',
    \count($fifth->undeclared),
);

printf("  %-34s %d\n", 'spellings not resolvable to a key', \count($fifth->unresolvedSpelling));

printf(
    "  the %d unresolved site(s) are the reason this set is printed and not counted: 03 §P1 resolves them.\n",
    $fifth->anyKeySites,
);

// Inside the aggregate only the counts are printed: the set is not evidence,
// and a hundred lines of it scrolling past a green `composer check` trains the
// reader to skip the part that is.
if (!$check) {
    foreach ($fifth->undeclared as $line) {
        printf("  - %s\n", $line);
    }

    foreach ($fifth->unresolvable as $line) {
        printf("  ? site class not matched to an options class: %s\n", $line);
    }

    foreach ($fifth->unresolvedSpelling as $line) {
        printf("  ? %s\n", $line);
    }
}

foreach ($uncovered as $line) {
    fwrite(\STDERR, 'UNCOVERED: ' . $line . "\n");
}

foreach ($problems as $line) {
    fwrite(\STDERR, 'GRID: ' . $line . "\n");
}

foreach ($drift as $line) {
    fwrite(\STDERR, 'STALE: ' . $line . "\n");
}

foreach ($scopeProblems as $line) {
    fwrite(\STDERR, 'PAIR SCOPE: ' . $line . "\n");
}

if (!$check) {
    exit(0);
}

exit($problems === [] && $uncovered === [] && $drift === [] && $scopeProblems === [] ? 0 : 1);
