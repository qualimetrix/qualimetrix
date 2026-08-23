<?php

declare(strict_types=1);

/**
 * Reports the candidate tree's own declared channel set.
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

$computedChannels = [];

foreach ($computed->resolve($document)->all() as $definition) {
    $computedChannels[] = ComputedMetricRule::NAME . '#' . $definition->name;
}

echo json_encode([
    'static' => array_keys($registry->staticDeclarations()),
    'computed' => $computedChannels,
], \JSON_THROW_ON_ERROR), "\n";
