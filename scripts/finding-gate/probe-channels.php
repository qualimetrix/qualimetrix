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
 * Usage: probe-channels.php <tree-root> <static|case> <working-directory> <check-argv-json>
 */

use QmxFindingGate\CommandLine;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Console\CheckCommandDefinition;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;

require __DIR__ . '/classes.php';

[$self, $treeRoot, $mode, $caseDirectory, $writtenArguments] = CommandLine::arguments() + [null, null, null, null, null];

if (!is_string($treeRoot) || !in_array($mode, ['static', 'case'], true)
    || !is_string($caseDirectory) || !is_string($writtenArguments)) {
    fwrite(\STDERR, "Usage: probe-channels.php <tree-root> <static|case> <working-directory> <check-argv-json>\n");
    exit(2);
}
$arguments = json_decode($writtenArguments, true, 512, \JSON_THROW_ON_ERROR);
if (!is_array($arguments) || !array_is_list($arguments) || ($arguments[0] ?? null) !== 'check'
    || array_filter($arguments, static fn(mixed $argument): bool => !is_string($argument) || $argument === '') !== []) {
    throw new RuntimeException('The channel probe requires the complete check command argument list.');
}

require $treeRoot . '/vendor/autoload.php';

$container = (new ContainerFactory())->create();

$registry = $container->get(ChannelDeclarationRegistryInterface::class);
assert($registry instanceof ChannelDeclarationRegistryInterface);

$document = null;
$computed = null;
if ($mode === 'case') {
    $pipeline = $container->get(ConfigurationPipelineInterface::class);
    assert($pipeline instanceof ConfigurationPipelineInterface);
    $computed = $container->get(ComputedMetricConfiguratorInterface::class);
    assert($computed instanceof ComputedMetricConfiguratorInterface);
    $rules = $container->get(RuleRegistryInterface::class);
    assert($rules instanceof RuleRegistryInterface);
    $command = new Command('check');
    $command->setApplication(new Application());
    CheckCommandDefinition::addOptions($command, $rules);
    $command->mergeApplicationDefinition(false);
    $input = new ArgvInput(['probe', ...array_slice($arguments, 1)], $command->getDefinition());
    $document = $pipeline->resolve((new ConfigurationInputAdapter($pipeline, new ErrorStream()))->adapt($input, $caseDirectory));
}

$values = static fn(array $levels): array => array_values(array_map(
    static fn(SymbolLevel $level): string => $level->value,
    $levels,
));

$staticChannels = [];

foreach ($registry->staticDeclarations() as $channel => $declaration) {
    $staticChannels[$channel] = $values($declaration->levels);
}

$computedChannels = [];

foreach ($computed === null ? [] : $computed->resolve($document)->all() as $definition) {
    $computedChannels[$definition->name] = $values($definition->reportingLevels());
}

echo json_encode([
    'mode' => $mode,
    'static' => $staticChannels,
    'computed' => $computedChannels,
    'levels' => $values(SymbolLevel::cases()),
], \JSON_THROW_ON_ERROR), "\n";
