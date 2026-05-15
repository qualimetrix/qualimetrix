<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Architecture\Processing\ArchitectureProcessor;
use Qualimetrix\Architecture\Processing\ArchitectureProcessorInterface;
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
 * Cross-feature wiring lives in {@see AnalysisConfigurator}: it wires
 * {@code ArchitectureProcessorInterface} into {@code AnalysisPipeline}
 * so the rules-pipeline lifecycle (ADR 0008) is fed the per-run graph and
 * class set.
 *
 * The {@see ArchitectureProcessorInterface} alias registered here is the
 * load-bearing handle for both rule injection (resolved by
 * {@code RuleOptionsCompilerPass::resolveExtraDependencies()}) and pipeline
 * wiring (referenced directly by {@code AnalysisConfigurator}).
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

        // Per ADR 0008: alias is required so RuleOptionsCompilerPass can
        // resolve the interface as an extra dependency when injecting the
        // processor into LayerViolationRule. AnalysisPipeline (and any other
        // direct consumer) benefits from the same alias.
        $container->setAlias(ArchitectureProcessorInterface::class, ArchitectureProcessor::class)
            ->setPublic(true);
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
     * Picks up {@code ArchitectureProcessor}, {@code LayerExpansionStage},
     * {@code TupleExtractor} and {@code LayerInstantiator} — every concrete
     * helper under {@code src/Architecture/Processing/}.
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
