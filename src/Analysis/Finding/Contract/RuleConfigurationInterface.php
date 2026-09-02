<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Core\Path\RelativePath;

/** Per-run rule options and exclusion state owned by Finding. */
interface RuleConfigurationInterface
{
    public function replace(FindingConfiguration $configuration): void;

    /** @param array<string, mixed> $options */
    public function configureCli(string $ruleName, array $options): void;

    /** @return array<string, mixed> */
    public function configFileOptions(): array;

    /** @return array<string, array<string, mixed>> */
    public function cliOptions(): array;

    /** @return array<string, mixed> */
    public function all(): array;

    public function configureSelection(RuleSelection $selection): void;

    public function selection(): RuleSelection;

    public function captureExcludedFindings(): void;

    public function capturesExcludedFindings(): bool;

    /** @param list<string> $patterns */
    public function configureNamespaceExclusions(string $ruleName, array $patterns): void;

    /** @param array<string, list<string>> $patterns */
    public function configureNamespaceChannelExclusions(string $ruleName, array $patterns): void;

    /** @param list<string> $patterns */
    public function configurePathExclusions(string $ruleName, array $patterns): void;

    public function isNamespaceExcluded(string $ruleName, string $namespace): bool;

    public function isNamespaceChannelExcluded(string $ruleName, FindingChannel $channel, string $namespace): bool;

    public function isPathExcluded(string $ruleName, RelativePath $path): bool;

    public function resetRuntimeState(): void;
}
