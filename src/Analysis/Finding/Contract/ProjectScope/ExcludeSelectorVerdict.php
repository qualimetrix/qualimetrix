<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\ProjectScope;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorKind;

/** One authored selector's conclusion from a captured project walk. */
final readonly class ExcludeSelectorVerdict
{
    /**
     * @param non-empty-list<ConfigurationOrigin> $sources
     * @param list<string> $removedEntries
     */
    private function __construct(
        public string $display,
        public array $sources,
        public ExcludeSelectorOutcome $outcome,
        public array $removedEntries = [],
        public ?string $phpEvidence = null,
        public ?string $coveredBy = null,
        public ?string $blockedAt = null,
    ) {}

    /**
     * @param non-empty-list<ConfigurationOrigin> $sources
     * @param list<string> $boundEntries Entries observed to bind before pruning
     * @param list<string> $removedRunEntries Bound entries removed from this run
     * @param list<array{directory: string, selector: string, sources: non-empty-list<ConfigurationOrigin>}> $hiddenDirectories
     */
    public static function fromMeasuredFacts(
        PathPattern $pattern,
        array $sources,
        array $boundEntries,
        array $removedRunEntries,
        ?string $phpEvidence,
        ?string $blockedAt,
        array $hiddenDirectories,
        bool $knownUniverse,
    ): self {
        $display = $pattern->definition->display();
        if ($sources === [] || self::distinct($sources) !== $sources) {
            throw new InvalidArgumentException('Selector sources must be nonempty and distinct');
        }
        if (array_diff($removedRunEntries, $boundEntries) !== []) {
            throw new LogicException('Removed run entries must be observed bindings');
        }
        if ($phpEvidence !== null && ($removedRunEntries === [] || !\in_array($phpEvidence, ['php-file', 'unlistable'], true))) {
            throw new LogicException('PHP search evidence requires a removed run entry');
        }
        if ($boundEntries !== []) {
            return new self($display, $sources, ExcludeSelectorOutcome::Removed, array_values(array_unique($boundEntries)), $phpEvidence);
        }
        if ($blockedAt !== null) {
            return new self($display, $sources, ExcludeSelectorOutcome::Unjudgeable, blockedAt: $blockedAt);
        }
        if (!$knownUniverse) {
            return new self($display, $sources, ExcludeSelectorOutcome::NotJudged);
        }

        $same = $other = null;
        $querySources = array_map(serialize(...), $sources);
        foreach ($hiddenDirectories as $hidden) {
            if ($hidden['selector'] === $display || !self::couldHide($pattern, $hidden['directory'])) {
                continue;
            }
            if ($hidden['sources'] === [] || self::distinct($hidden['sources']) !== $hidden['sources']) {
                throw new InvalidArgumentException('Hider sources must be nonempty and distinct');
            }
            $hiderSources = array_map(serialize(...), $hidden['sources']);
            if (array_diff($hiderSources, $querySources) !== []) {
                $other ??= $hidden['selector'];
            } elseif (array_intersect($hiderSources, $querySources) !== []) {
                $same ??= $hidden['selector'];
            }
        }

        if ($other !== null) {
            return new self($display, $sources, ExcludeSelectorOutcome::CoveredByOtherSource, coveredBy: $other);
        }
        if ($same !== null) {
            return new self($display, $sources, ExcludeSelectorOutcome::CoveredBySameSource, coveredBy: $same);
        }

        return new self($display, $sources, ExcludeSelectorOutcome::Unmatched);
    }

    /** A closed selector question withholds only an unsettled conclusion. */
    public function withoutSelectorJudgement(): self
    {
        if ($this->outcome === ExcludeSelectorOutcome::Removed || $this->outcome === ExcludeSelectorOutcome::Unjudgeable) {
            return $this;
        }

        return new self($this->display, $this->sources, ExcludeSelectorOutcome::NotJudged);
    }

    /** @param non-empty-list<ConfigurationOrigin> $sources
     * @return non-empty-list<ConfigurationOrigin>
     */
    private static function distinct(array $sources): array
    {
        $seen = [];
        $distinct = [];
        foreach ($sources as $source) {
            $key = serialize($source);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $distinct[] = $source;
            }
        }

        return $distinct;
    }

    private static function couldHide(PathPattern $pattern, string $directory): bool
    {
        return $pattern->definition->kind === SelectorKind::Regex
            || str_starts_with($pattern->definition->value, $directory . '/');
    }
}
