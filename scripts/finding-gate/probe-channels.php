<?php

declare(strict_types=1);

/**
 * Reports the candidate tree's own declared channel set, with the levels each
 * channel declares it reports at, and the tree's own level vocabulary.
 *
 * Levels rather than names because coverage is counted per (channel, level)
 * pair: a pair the product can produce that fires in no case and is claimed in
 * no case is invisible to an accounting whose declared side is a set of names.
 * The vocabulary comes along for the ride so that the gate's own tag => level
 * map can be held against `SymbolLevel` instead of asserting in a docblock that
 * it matches it.
 *
 * A separate process because the answer must come from the tree under test: its
 * container, its compiler passes, its configuration pipeline. Reading it in the
 * gate's process would answer for whichever tree happened to be autoloaded.
 *
 * Usage: probe-channels.php <tree-root> <case-directory> <config-relative-path>
 */

use QmxFindingGate\CommandLine;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

require __DIR__ . '/CommandLine.php';

[$self, $treeRoot, $caseDirectory, $configuration] = CommandLine::arguments() + [null, null, null, null];

if (!is_string($treeRoot) || !is_string($caseDirectory) || !is_string($configuration)) {
    fwrite(\STDERR, "Usage: probe-channels.php <tree-root> <case-directory> <config-relative-path>\n");
    exit(2);
}

require $treeRoot . '/vendor/autoload.php';

$container = (new ContainerFactory())->create();

$registry = $container->get(ChannelDeclarationRegistryInterface::class);
assert($registry instanceof ChannelDeclarationRegistryInterface);

$pipeline = $container->get(ConfigurationPipelineInterface::class);
assert($pipeline instanceof ConfigurationPipelineInterface);

$computed = $container->get(ComputedMetricConfiguratorInterface::class);
assert($computed instanceof ComputedMetricConfiguratorInterface);

$document = $pipeline->resolve(
    new ConfigurationResolutionRequest(AbsolutePath::fromString($caseDirectory), $configuration, [], []),
);

$values = static fn(array $levels): array => array_values(array_map(
    static fn(SymbolLevel $level): string => $level->value,
    $levels,
));

$staticChannels = [];

foreach ($registry->staticDeclarations() as $channel => $declaration) {
    $staticChannels[$channel] = $values($declaration->levels);
}

$computedChannels = [];

foreach ($computed->resolve($document)->all() as $definition) {
    $computedChannels[ComputedMetricRule::NAME . '#' . $definition->name] = $values($definition->reportingLevels());
}

echo json_encode([
    'static' => $staticChannels,
    'computed' => $computedChannels,
    'levels' => $values(SymbolLevel::cases()),
], \JSON_THROW_ON_ERROR), "\n";
