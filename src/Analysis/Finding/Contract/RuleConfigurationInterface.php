<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;

/** Per-run rule options and exclusion state owned by Finding. */
interface RuleConfigurationInterface
{
    public function resolvedOptions(): ResolvedRuleOptions;

    public function enablement(): ?RuleEnablement;

    public function channelUniverse(): ChannelUniverseInterface;

    public function replace(FindingConfiguration $configuration): void;

    public function captureExcludedFindings(): void;

    public function capturesExcludedFindings(): bool;

    /** @param list<NamespacePattern> $patterns */
    public function configureNamespaceExclusions(string $ruleName, array $patterns): void;

    /** @param array<string, list<NamespacePattern>> $patterns */
    public function configureNamespaceChannelExclusions(string $ruleName, array $patterns): void;

    /** @param list<PathPattern> $patterns */
    public function configurePathExclusions(string $ruleName, array $patterns): void;

    /** @return list<NamespacePattern> */
    public function namespaceExclusions(string $ruleName): array;

    /** @return array<string, list<NamespacePattern>> */
    public function namespaceChannelExclusions(string $ruleName): array;

    /** @return list<PathPattern> */
    public function pathExclusions(string $ruleName): array;

    public function isNamespaceExcluded(string $ruleName, string $namespace): bool;

    public function isNamespaceChannelExcluded(string $ruleName, FindingChannel $channel, string $namespace): bool;

    public function isPathExcluded(string $ruleName, RelativePath $path): bool;

    public function resetRuntimeState(): void;
}
