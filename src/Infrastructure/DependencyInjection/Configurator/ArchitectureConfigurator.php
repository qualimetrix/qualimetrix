<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Configures services that live in the Architecture vertical slice
 * (ADR 0010 / ADR 0012).
 *
 * Architecture is the project's pilot vertical slice. All feature code lives
 * under {@code src/Architecture/{Domain,Configuration,Processing,Rules}/}.
 * Adapters (e.g. {@code LayerAssignmentCommand}) stay in
 * {@code src/Infrastructure/} and depend on the slice's public contracts.
 *
 * This configurator is responsible for:
 * - Auto-registering Architecture rules (mirrors {@see RuleConfigurator}
 *   so they pick up the {@code qmx.rule} autoconfiguration tag).
 * - Auto-registering Architecture processing helpers (autowired services
 *   under {@code src/Architecture/Processing/}).
 * - Auto-registering Architecture configuration validators (autowired
 *   services under {@code src/Architecture/Configuration/Validation/}).
 *
 * Domain types ({@code src/Architecture/Domain/}) are pure VOs / enums /
 * exceptions and are intentionally NOT scanned: they are constructed by
 * factories and helpers, not retrieved from the container.
 *
 * Cross-feature wiring (the shared {@code ArchitectureConfigurationHolder}
 * runtime holder and {@code LayerExpansionStage}) continues to live in
 * {@see AnalysisConfigurator} until Phase 4 replaces them with the
 * {@code ArchitectureProcessor}; see ADR 0008.
 */
final class ArchitectureConfigurator implements ContainerConfiguratorInterface
{
    public function __construct(
        private readonly string $srcDir,
    ) {}

    public function configure(ContainerBuilder $container): void
    {
        $this->registerRules($container);
        $this->registerProcessing($container);
        $this->registerConfigurationValidation($container);

        // TODO Phase 4 (ADR 0008):
        // $container->setAlias(ArchitectureProcessorInterface::class, ArchitectureProcessor::class);
        // ArchitectureProcessor / ArchitectureProcessorInterface do not exist yet;
        // the alias is needed by RuleOptionsCompilerPass::resolveServiceId() once
        // LayerViolationRule injects the interface (per ADR 0010 Part 1 / ADR 0008).
    }

    /**
     * Registers Architecture rules from src/Architecture/Rules/.
     *
     * Mirrors {@see RuleConfigurator}'s settings:
     * - Autoconfigured (picks up {@code qmx.rule} tag via
     *   registerForAutoconfiguration(RuleInterface::class) in ContainerFactory).
     * - Autowiring disabled (rules accept RuleOptionsInterface; injection
     *   is performed by RuleOptionsCompilerPass).
     * - Lazy (rule instantiation is gated by configuration).
     */
    private function registerRules(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));

        $prototype = (new Definition())
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);

        $loader->registerClasses(
            $prototype,
            'Qualimetrix\\Architecture\\Rules\\',
            $this->srcDir . '/Architecture/Rules/*Rule.php',
        );
    }

    /**
     * Registers Architecture processing services (autowired).
     *
     * Today this picks up {@code LayerExpansionStage}-adjacent helpers.
     * Phase 4 (ADR 0008) will introduce {@code ArchitectureProcessor},
     * {@code TupleExtractor}, and {@code LayerInstantiator} into the same
     * directory; they will be registered automatically by this scan.
     *
     * Exclusions follow the project convention (interfaces, abstracts,
     * VOs, exceptions, result types).
     */
    private function registerProcessing(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));

        $prototype = (new Definition())
            ->setAutoconfigured(true)
            ->setAutowired(true);

        $loader->registerClasses(
            $prototype,
            'Qualimetrix\\Architecture\\Processing\\',
            $this->srcDir . '/Architecture/Processing/*.php',
            $this->srcDir . '/Architecture/Processing/{Abstract*.php,*Interface.php,*Exception.php,*Result.php}',
        );
    }

    /**
     * Registers Architecture configuration validators (autowired).
     *
     * These are stateless helpers consumed by
     * {@code ArchitectureConfigurationFactory} during the configuration
     * pipeline. Autowiring is sufficient — none of them carry runtime state.
     */
    private function registerConfigurationValidation(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));

        $prototype = (new Definition())
            ->setAutoconfigured(true)
            ->setAutowired(true);

        $loader->registerClasses(
            $prototype,
            'Qualimetrix\\Architecture\\Configuration\\Validation\\',
            $this->srcDir . '/Architecture/Configuration/Validation/*.php',
            $this->srcDir . '/Architecture/Configuration/Validation/{Abstract*.php,*Interface.php,*Exception.php}',
        );
    }
}
