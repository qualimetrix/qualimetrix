<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionAttribution;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Util\NamespaceMatcher;
use Qualimetrix\Core\Util\PathMatcher;

/**
 * Applies a producer's `suppress_namespaces`, `suppress_namespace_channels` and
 * `suppress_paths` to one finding, and remembers what that cost.
 *
 * Its own subject is the run's exclusion account: which findings were counted
 * out, under whose name, and — when the run asked for it — the findings
 * themselves, so `--show-suppressed` can print what the report does not. That
 * is four pieces of per-run state and the rules for reading them; keeping them
 * beside rule execution made one class answer both "what did the rules find"
 * and "what did configuration remove", and the two change for different
 * reasons.
 *
 * A ledger lives for one {@see RuleExecution::execute()} call: {@see begin()}
 * opens it, {@see stats()} reads it afterwards. It is deliberately mutable and
 * deliberately not shared.
 */
final class FindingExclusionLedger
{
    /** @var array<string, int> */
    private array $namespaceExclusionCounts = [];

    /** @var array<string, int> */
    private array $pathExclusionCounts = [];

    /** @var list<Finding> */
    private array $excludedFindings = [];

    /** @var list<RuleExclusionAttribution> */
    private array $attributions = [];

    private bool $capturesExcluded = false;

    public function __construct(
        private readonly RuleConfigurationInterface $ruleOptionsRegistry,
    ) {}

    public function begin(): void
    {
        $this->namespaceExclusionCounts = [];
        $this->pathExclusionCounts = [];
        $this->excludedFindings = [];
        $this->attributions = [];
        $this->capturesExcluded = $this->ruleOptionsRegistry->capturesExcludedFindings();
    }

    /**
     * Whether the finding survives its producer's exclusions — and, when it
     * does not, the tally that says so.
     *
     * `$producerRuleName` is the producer of the **finding**, not of the
     * instance that ran: one class may publish under several producer names,
     * and each carries its own exclusions.
     */
    public function keeps(string $producerRuleName, Finding $finding): bool
    {
        $namespaceAttribution = $this->namespaceExclusionAttribution($producerRuleName, $finding);

        if ($namespaceAttribution !== null) {
            $this->namespaceExclusionCounts[$producerRuleName] = ($this->namespaceExclusionCounts[$producerRuleName] ?? 0) + 1;
            $this->record($finding, $namespaceAttribution);

            return false;
        }

        $file = $finding->location->file;

        if ($file !== null && $this->ruleOptionsRegistry->isPathExcluded($producerRuleName, $file)) {
            $this->pathExclusionCounts[$producerRuleName] = ($this->pathExclusionCounts[$producerRuleName] ?? 0) + 1;
            $this->record($finding, new RuleExclusionAttribution(
                $producerRuleName,
                isPathExclusion: true,
                matchedPatterns: $this->matchingPathPatterns($this->configuredPatterns($producerRuleName, 'suppressPaths', 'suppress_paths'), $file),
            ));

            return false;
        }

        return true;
    }

    public function stats(): RuleExclusionStats
    {
        return new RuleExclusionStats(
            namespaceExclusionsByRule: $this->namespaceExclusionCounts,
            pathExclusionsByRule: $this->pathExclusionCounts,
            excludedFindings: $this->excludedFindings,
            attributions: $this->attributions,
        );
    }

    private function record(Finding $finding, RuleExclusionAttribution $attribution): void
    {
        if ($this->capturesExcluded) {
            $this->excludedFindings[] = $finding;
            $this->attributions[] = $attribution;
        }
    }

    /**
     * Decides the namespace half of {@see keeps()} and, when it excludes the
     * finding, names every configured pattern responsible — not only the one
     * {@see RuleConfigurationInterface::isNamespaceExcluded()} happened to hit
     * first — so a consumer computing "which pattern fired nothing" never has
     * to re-derive this decision under a name of its own choosing.
     */
    private function namespaceExclusionAttribution(string $producerRuleName, Finding $finding): ?RuleExclusionAttribution
    {
        // Occurrence-style rules attach a file symbol path (namespace null) to
        // their findings; the declaring namespace lives on the subject, so
        // fall back to it the same way NamespaceExclusionFilter does.
        $namespace = $finding->symbolPath->namespace
            ?? $finding->subject->toSymbolPath()->namespace
            ?? null;

        if ($namespace === null || $namespace === '') {
            return null;
        }

        if ($this->ruleOptionsRegistry->isNamespaceExcluded($producerRuleName, $namespace)) {
            $patterns = $this->matchingNamespacePatterns(
                $this->configuredPatterns($producerRuleName, 'suppressNamespaces', 'suppress_namespaces'),
                $namespace,
            );

            return new RuleExclusionAttribution($producerRuleName, isPathExclusion: false, matchedPatterns: $patterns);
        }

        if (
            $finding->symbolPath->getType()->value === 'namespace'
            && $this->ruleOptionsRegistry->isNamespaceChannelExcluded($producerRuleName, $finding->channel(), $namespace)
        ) {
            $hits = $this->matchingChannelPatterns($producerRuleName, $finding->channel(), $namespace);

            return new RuleExclusionAttribution($producerRuleName, isPathExclusion: false, matchedChannelPatterns: $hits);
        }

        return null;
    }

    /**
     * Reads one raw option off a producer's own `rules:` section, accepting
     * both the key an author writes in `qmx.yaml` and the camelCase form
     * {@see RuleConfigurationInterface::all()} returns once the configuration
     * pipeline's section-normalization policy has run.
     *
     * @return list<string>
     */
    private function configuredPatterns(string $producerRuleName, string $camelKey, string $snakeKey): array
    {
        $options = $this->ruleOptionsRegistry->all()[$producerRuleName] ?? null;

        if (!\is_array($options)) {
            return [];
        }

        return $this->stringList($options[$camelKey] ?? $options[$snakeKey] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawChannelOptions(string $producerRuleName): array
    {
        $options = $this->ruleOptionsRegistry->all()[$producerRuleName] ?? null;

        if (!\is_array($options)) {
            return [];
        }

        $channels = $options['suppressNamespaceChannels'] ?? $options['suppress_namespace_channels'] ?? [];

        return \is_array($channels) ? $channels : [];
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private function matchingNamespacePatterns(array $patterns, string $namespace): array
    {
        $hits = [];
        foreach ($patterns as $pattern) {
            if ((new NamespaceMatcher([$pattern]))->matches($namespace) !== null) {
                $hits[] = $pattern;
            }
        }

        return $hits;
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private function matchingPathPatterns(array $patterns, RelativePath $file): array
    {
        $hits = [];
        foreach ($patterns as $pattern) {
            if ((new PathMatcher([$pattern]))->matches($file) !== null) {
                $hits[] = $pattern;
            }
        }

        return $hits;
    }

    /**
     * Every `suppress_namespace_channels` selector/pattern pair that applies to
     * `$channel` at the namespace level and matches `$namespace` — every one,
     * not only the first, for the same reason {@see matchingNamespacePatterns()}
     * enumerates rather than short-circuits.
     *
     * @return list<array{selector: string, pattern: string}>
     */
    private function matchingChannelPatterns(string $producerRuleName, FindingChannel $channel, string $namespace): array
    {
        $hits = [];

        foreach ($this->rawChannelOptions($producerRuleName) as $selector => $patterns) {
            if (!\is_string($selector) || ChannelLevelSelector::tryParse($selector)?->matches($channel->code, SymbolLevel::Namespace_) !== true) {
                continue;
            }

            foreach ($this->matchingNamespacePatterns($this->stringList($patterns), $namespace) as $pattern) {
                $hits[] = ['selector' => $selector, 'pattern' => $pattern];
            }
        }

        return $hits;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (\is_string($value)) {
            return [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (\is_string($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }
}
