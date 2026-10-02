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
     * @param list<ConfigurationOrigin> $coveredBySources Sources of the chosen hider absent from this selector
     *
     * @qmx-threshold code-smell.constructor-overinjection warning=9 error=12 -- One immutable selector
     *                conclusion carries measured observations, not injected collaborators.
     * @qmx-threshold code-smell.long-parameter-list warning=9 error=12 -- These fields describe one
     *                selector conclusion and have no independent lifecycle.
     */
    private function __construct(
        public string $display,
        public array $sources,
        public ExcludeSelectorOutcome $outcome,
        public array $removedEntries = [],
        public ?string $phpEvidence = null,
        public ?string $coveredBy = null,
        public ?string $blockedAt = null,
        public array $coveredBySources = [],
    ) {}

    /**
     * @param non-empty-list<ConfigurationOrigin> $sources
     * @param list<string> $boundEntries Entries observed to bind before pruning
     * @param list<string> $removedRunEntries Bound entries removed from this run
     * @param list<array{directory: string, selector: string, sources: non-empty-list<ConfigurationOrigin>}> $hiddenDirectories
     *
     * @qmx-threshold code-smell.long-parameter-list warning=9 error=12 -- Independent measured facts
     *                must be compared before deriving one selector conclusion.
     *
     * @qmx-ignore code-smell.boolean-argument -- knownUniverse is a measured denominator fact,
     *             not a switch enabling another operation.
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
        self::assertSources($sources, 'Selector');
        self::assertRemovedEvidence($boundEntries, $removedRunEntries, $phpEvidence);
        if ($boundEntries !== []) {
            return new self($display, $sources, ExcludeSelectorOutcome::Removed, array_values(array_unique($boundEntries)), $phpEvidence);
        }
        if ($blockedAt !== null) {
            return new self($display, $sources, ExcludeSelectorOutcome::Unjudgeable, blockedAt: $blockedAt);
        }
        if (!$knownUniverse) {
            return new self($display, $sources, ExcludeSelectorOutcome::NotJudged);
        }

        return self::coveringVerdict($pattern, $sources, $hiddenDirectories);
    }

    /** @param non-empty-list<ConfigurationOrigin> $sources */
    private static function assertSources(array $sources, string $subject): void
    {
        if ($sources === [] || self::distinct($sources) !== $sources) {
            throw new InvalidArgumentException($subject . ' sources must be nonempty and distinct');
        }
    }

    /**
     * @param list<string> $boundEntries
     * @param list<string> $removedRunEntries
     */
    private static function assertRemovedEvidence(array $boundEntries, array $removedRunEntries, ?string $phpEvidence): void
    {
        if (array_diff($removedRunEntries, $boundEntries) !== []) {
            throw new LogicException('Removed run entries must be observed bindings');
        }
        if ($phpEvidence !== null && ($removedRunEntries === [] || !\in_array($phpEvidence, ['php-file', 'unlistable'], true))) {
            throw new LogicException('PHP search evidence requires a removed run entry');
        }
    }

    /**
     * @param non-empty-list<ConfigurationOrigin> $sources
     * @param list<array{directory: string, selector: string, sources: non-empty-list<ConfigurationOrigin>}> $hiddenDirectories
     */
    private static function coveringVerdict(PathPattern $pattern, array $sources, array $hiddenDirectories): self
    {
        $display = $pattern->definition->display();
        $hiders = self::eligibleHiders($pattern, $hiddenDirectories);
        $same = null;
        $querySources = array_map(serialize(...), $sources);
        foreach ($hiders as $hidden) {
            $hiderSources = array_map(serialize(...), $hidden['sources']);
            if (array_diff($hiderSources, $querySources) !== []) {
                return new self($display, $sources, ExcludeSelectorOutcome::CoveredByOtherSource, coveredBy: $hidden['selector'], coveredBySources: self::foreignSources($hidden['sources'], $querySources));
            }
            if (array_intersect($hiderSources, $querySources) !== []) {
                $same ??= $hidden['selector'];
            }
        }
        if ($same !== null) {
            return new self($display, $sources, ExcludeSelectorOutcome::CoveredBySameSource, coveredBy: $same);
        }

        return new self($display, $sources, ExcludeSelectorOutcome::Unmatched);
    }

    /**
     * @param list<array{directory: string, selector: string, sources: non-empty-list<ConfigurationOrigin>}> $hiddenDirectories
     *
     * @return list<array{directory: string, selector: string, sources: non-empty-list<ConfigurationOrigin>}>
     */
    private static function eligibleHiders(PathPattern $pattern, array $hiddenDirectories): array
    {
        $hiders = [];
        foreach ($hiddenDirectories as $hidden) {
            if ($hidden['selector'] === $pattern->definition->display() || !self::couldHide($pattern, $hidden['directory'])) {
                continue;
            }
            self::assertSources($hidden['sources'], 'Hider');
            $hiders[] = $hidden;
        }

        return $hiders;
    }

    /**
     * @param non-empty-list<ConfigurationOrigin> $sources
     * @param list<string> $querySources
     *
     * @return list<ConfigurationOrigin>
     */
    private static function foreignSources(array $sources, array $querySources): array
    {
        return array_values(array_filter(
            $sources,
            static fn(ConfigurationOrigin $source): bool => !\in_array(serialize($source), $querySources, true),
        ));
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
