<?php

declare(strict_types=1);

/**
 * The option keys every registered producer answers for, at each depth a user
 * can write one, with the CLI alias that reaches each.
 *
 * Run from anywhere; the bootstrap is resolved from this file's position in the
 * repository rather than from the working directory:
 *
 *     php docs/internal/plans/rules-listing/measurement/dump-declared-options.php \
 *       > docs/internal/plans/rules-listing/measurement/declared-options.tsv
 */

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

$repositoryRoot = dirname(__DIR__, 5);
require $repositoryRoot . '/vendor/autoload.php';

$execution = (new ContainerFactory())->create()->get(RuleExecutionInterface::class);
\assert($execution instanceof RuleExecutionInterface);

/** @var list<array{string, string, string, string, string, string}> $rows */
$rows = [];
/** @var list<array{string, string, string}> $orphans */
$orphans = [];

foreach ($execution->allRules() as $rule) {
    $optionsClass = $rule->optionsClass;

    // An alias target is authored freely — `class.max_warning`, `excludeDataClasses` —
    // so it is folded to (level, key) before it can be joined to a declaration.
    // The authored spelling is carried alongside, because the claim "34 of 80
    // targets are not canonical" has to be checkable from this table rather
    // than from prose about it.
    $aliasesByTarget = [];
    foreach ($rule->aliases as $alias => $target) {
        $parts = explode('.', $target, 2);
        $folded = \count($parts) === 2
            ? ConfigKeySpelling::normalize($parts[0]) . "\0" . ConfigKeySpelling::normalize($parts[1])
            : "\0" . ConfigKeySpelling::normalize($target);
        $aliasesByTarget[$folded][] = [$alias, $target];
    }

    $slots = is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true)
        ? $optionsClass::levelOptionsClasses()
        : [];

    $addressed = [];

    $emit = static function (string $level, string $key, string $kind) use (
        &$rows, &$addressed, $aliasesByTarget, $rule
    ): void {
        $folded = ($level === '-' ? '' : ConfigKeySpelling::normalize($level))
            . "\0" . ConfigKeySpelling::normalize($key);
        $addressed[$folded] = true;

        $found = $aliasesByTarget[$folded] ?? [];
        $rows[] = [
            $rule->name,
            $level,
            $key,
            $kind,
            implode(',', array_column($found, 0)) ?: '-',
            implode(',', array_column($found, 1)) ?: '-',
        ];
    };

    foreach ($optionsClass::acceptedOptionKeys()->acceptedForDisplay() as $key) {
        $emit('-', $key, isset($slots[$key]) ? 'level-slot' : 'option');
    }

    foreach ($slots as $level => $levelOptionsClass) {
        foreach ($levelOptionsClass::acceptedOptionKeys()->acceptedForDisplay() as $key) {
            $emit((string) $level, $key, 'option');
        }
    }

    foreach ($aliasesByTarget as $folded => $found) {
        if (!isset($addressed[$folded])) {
            $orphans[] = [$rule->name, str_replace("\0", '|', (string) $folded), implode(',', array_column($found, 0))];
        }
    }
}

echo "rule\tlevel\toption\tkind\tcli_alias\talias_target_as_authored\n";

foreach ($rows as $row) {
    echo implode("\t", $row) . "\n";
}

file_put_contents(
    __DIR__ . '/orphan-aliases.tsv',
    "rule\tfolded_target\taliases\n"
        . implode('', array_map(static fn(array $r): string => implode("\t", $r) . "\n", $orphans)),
);
