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

    /**
     * Whether the rule's own options section turns it off.
     *
     * This is the second, independent way a rule stops producing findings,
     * and it is invisible to {@see selection()}: `disabled_rules` and
     * `--disable-rule` keep the rule from being executed at all, while
     * `rules: { X: false }` and `rules: { X: { enabled: false } }` let it run
     * and make it return nothing. Anything reasoning about "did this rule
     * report in this run" has to ask both questions, or it will treat a rule
     * switched off one way as if it were live.
     */
    public function isRuleDisabledByOptions(string $ruleName): bool;

    public function captureExcludedViolations(): void;

    public function capturesExcludedViolations(): bool;

    /** @param list<string> $patterns */
    public function configureNamespaceExclusions(string $ruleName, array $patterns): void;

    /** @param array<string, list<string>> $patterns */
    public function configureNamespaceChannelExclusions(string $ruleName, array $patterns): void;

    /** @param list<string> $patterns */
    public function configurePathExclusions(string $ruleName, array $patterns): void;

    public function isNamespaceExcluded(string $ruleName, string $namespace): bool;

    public function isNamespaceChannelExcluded(string $ruleName, ViolationChannel $channel, string $namespace): bool;

    public function isPathExcluded(string $ruleName, RelativePath $path): bool;

    public function resetRuntimeState(): void;
}
