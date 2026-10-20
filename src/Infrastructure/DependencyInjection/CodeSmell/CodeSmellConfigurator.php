<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CodeSmell;

use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Qualimetrix\Infrastructure\DependencyInjection\Registration\EvidenceRegistration;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Registers the exact collector and rule roots owned by CodeSmell. */
final class CodeSmellConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\CodeSmell\\';

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = EvidenceRegistration::loader($container, $this->srcDir);
        foreach (['' => '*', 'ParameterCount\\' => 'ParameterCount/*', 'RepeatedExpression\\' => 'RepeatedExpression/*', 'UnreachableCode\\' => 'UnreachableCode/*', 'UnusedPrivate\\' => 'UnusedPrivate/*'] as $namespace => $root) {
            $loader->registerClasses(
                EvidenceRegistration::collectors(),
                self::NAMESPACE . $namespace,
                $this->srcDir . '/Analysis/Evidence/CodeSmell/' . $root . 'Collector.php',
            );
        }
        foreach (['' => '*', 'BooleanArgument\\' => 'BooleanArgument/*', 'ControlFlow\\' => 'ControlFlow/*', 'RepeatedExpression\\' => 'RepeatedExpression/*', 'ParameterCount\\' => 'ParameterCount/*', 'UnreachableCode\\' => 'UnreachableCode/*', 'UnusedPrivate\\' => 'UnusedPrivate/*'] as $namespace => $root) {
            $loader->registerClasses(
                EvidenceRegistration::rules(),
                self::NAMESPACE . $namespace,
                $this->srcDir . '/Analysis/Evidence/CodeSmell/' . $root . 'Rule.php',
                $this->srcDir . '/Analysis/Evidence/CodeSmell/' . str_replace('*', 'Abstract*', $root) . 'Rule.php',
            );
        }
    }
}
