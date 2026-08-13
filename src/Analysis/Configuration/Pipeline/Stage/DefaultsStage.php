<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline\Stage;

use Qualimetrix\Analysis\Configuration\ConfigSchema;

use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationContext;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationStageInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Throwable;

/**
 * Default configuration values (priority: 0).
 *
 * Applied first, can be overridden by all other stages.
 */
final class DefaultsStage implements ConfigurationStageInterface
{
    private const int PRIORITY = 0;

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function name(): string
    {
        return 'defaults';
    }

    public function apply(ConfigurationContext $context): ConfigurationLayer
    {
        return new ConfigurationLayer('defaults', [
            ConfigSchema::PATHS => ['.'],
            ConfigSchema::EXCLUDES => ['vendor', 'node_modules', '.git'],
            ConfigSchema::CACHE_DIR => TransitionalRuntimeConfiguration::DEFAULT_CACHE_DIR,
            ConfigSchema::CACHE_ENABLED => true,
            ConfigSchema::FORMAT => TransitionalRuntimeConfiguration::DEFAULT_FORMAT,
            ConfigSchema::NAMESPACE_STRATEGY => TransitionalRuntimeConfiguration::DEFAULT_NAMESPACE_STRATEGY,
            ConfigSchema::PROJECT_ROOT => $this->resolveProjectRoot($context->workingDirectory),
        ]);
    }

    /**
     * Returns the canonical (symlink-resolved) project root, falling back to
     * the raw input when canonicalization fails (path doesn't exist).
     * {@see TransitionalRuntimeConfiguration::fromArray()} re-resolves the resulting string
     * to {@see AbsolutePath} via {@see \Qualimetrix\Core\Path\PathFactory::fromCliArgument()}.
     */
    private function resolveProjectRoot(string $workingDirectory): string
    {
        try {
            return AbsolutePath::fromString($workingDirectory)->canonicalize()->value();
        } catch (Throwable) {
            return $workingDirectory;
        }
    }
}
