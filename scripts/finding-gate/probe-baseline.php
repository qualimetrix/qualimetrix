<?php

declare(strict_types=1);

use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineGenerator;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Time\SystemClock;
use Qualimetrix\Infrastructure\Console\CheckCommandDefinition;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;

$probeArguments = $_SERVER['argv'] ?? [];
[$self, $treeRoot, $directory, $encodedArguments, $input] = $probeArguments + [null, null, null, null, null];
if (!is_string($treeRoot) || !is_string($directory) || !is_string($encodedArguments) || !is_string($input)) {
    throw new RuntimeException('The baseline probe requires a tree, invocation and complete groups.');
}
$arguments = json_decode($encodedArguments, true, 512, \JSON_THROW_ON_ERROR);
$groups = json_decode((string) file_get_contents($input), true, 512, \JSON_THROW_ON_ERROR);
if (!is_array($arguments) || !array_is_list($arguments) || ($arguments[0] ?? null) !== 'check' || !is_array($groups)) {
    throw new RuntimeException('The baseline probe received an invalid invocation or group population.');
}
foreach ($arguments as $argument) {
    if (!is_string($argument)) {
        throw new RuntimeException('The baseline probe invocation contains a non-string argument.');
    }
}

require $treeRoot . '/vendor/autoload.php';
$container = (new ContainerFactory())->create();
$pipeline = $container->get(ConfigurationPipelineInterface::class);
$computed = $container->get(ComputedMetricConfiguratorInterface::class);
$rules = $container->get(RuleRegistryInterface::class);
$snapshot = $container->get(ChannelUniverseInterface::class);
assert($pipeline instanceof ConfigurationPipelineInterface);
assert($computed instanceof ComputedMetricConfiguratorInterface);
assert($rules instanceof RuleRegistryInterface);
assert($snapshot instanceof RuleChannelSnapshotFactoryInterface);
$command = new Command('check');
$command->setApplication(new Application());
CheckCommandDefinition::addOptions($command, $rules);
$command->mergeApplicationDefinition(false);
$arguments = new ArgvInput(['probe', ...array_slice($arguments, 1)], $command->getDefinition());
$constructor = new ReflectionMethod(ConfigurationInputAdapter::class, '__construct');
$dependencies = [$pipeline, new ErrorStream()];
if ($constructor->getNumberOfRequiredParameters() === 3
    && ($constructor->getParameters()[2]->getType() instanceof ReflectionNamedType)
    && $constructor->getParameters()[2]->getType()->getName() === RuleExecutionInterface::class) {
    $execution = $container->get(RuleExecutionInterface::class);
    assert($execution instanceof RuleExecutionInterface);
    $dependencies[] = $execution;
} elseif ($constructor->getNumberOfRequiredParameters() !== 2) {
    throw new RuntimeException('The probe does not support this configuration adapter constructor.');
}
$adapter = (new ReflectionClass(ConfigurationInputAdapter::class))->newInstanceArgs($dependencies);
$document = $pipeline->resolve($adapter->adapt($arguments, $directory));
$generator = new BaselineGenerator($snapshot->snapshot($computed->resolve($document)), new SystemClock());

$findings = [];
$identities = [];
foreach ($groups as $identity => $values) {
    if (!is_string($identity) || !is_array($values) || $values === []) {
        throw new RuntimeException('The baseline probe requires nonempty exact groups.');
    }
    $fields = json_decode($identity, true, 512, \JSON_THROW_ON_ERROR);
    $channel = $fields['channel'] ?? null;
    if (!is_string($channel) || $channel === '') {
        throw new RuntimeException('The baseline probe group has no channel.');
    }
    $subject = MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('baseline-probe-' . count($identities) . '.php')));
    $identities[$subject->toCanonical()] = $identity;
    foreach ($values as $value) {
        if ($value !== null && !is_int($value) && !is_float($value)) {
            throw new RuntimeException('The baseline probe group has a nonnumeric magnitude.');
        }
        $findings[] = new Finding(Location::none(), $subject, $subject->toSymbolPath(), $channel, $channel, 'probe', Severity::Error, $value);
    }
}
$capture = $generator->generate($findings, []);
$decisions = array_fill_keys(array_keys($groups), null);
foreach ($capture->baseline->entries as $entry) {
    $decisions[$identities[$entry->identity->subjectKey] ?? throw new RuntimeException('Unknown captured baseline group.')] = true;
}
foreach ($capture->uncaptured as $group) {
    $decisions[$identities[$group->identity->subjectKey] ?? throw new RuntimeException('Unknown uncaptured baseline group.')] = false;
}
if (in_array(null, $decisions, true)) {
    throw new RuntimeException('The baseline generator did not partition every group.');
}
echo json_encode($decisions, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), "\n";
