<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Run\Contract\Configuration\AuthoredExclude;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\SelectorKind;

/** Per-walk selector evidence; the caller owns every filesystem observation. */
final class ExcludeSelectorLedger
{
    /** @var array<string, list<string>> */
    private array $bound = [];

    /** @var array<string, list<string>> */
    private array $removed = [];

    /** @var array<string, ?string> */
    private array $phpEvidence = [];

    /** @var list<array{directory: string, selectors: list<string>}> */
    private array $removedSubtrees = [];

    /** @var array<string, string> */
    private array $unjudgeable = [];

    /** @param list<AuthoredExclude> $selectors */
    public function __construct(private readonly array $selectors) {}

    /** @return list<string> */
    public function matching(RelativePath $relative): array
    {
        $matched = [];
        foreach ($this->selectors as $selector) {
            if ($selector->pattern->matches($relative)) {
                $matched[] = $selector->display();
            }
        }

        return $matched;
    }

    /** @param list<string> $selectors */
    public function bind(RelativePath $relative, array $selectors): void
    {
        foreach ($selectors as $selector) {
            $this->bound[$selector][] = $relative->value();
        }
    }

    /** @param list<string> $selectors */
    public function needsPhpEvidence(array $selectors): bool
    {
        foreach ($selectors as $selector) {
            if (($this->phpEvidence[$selector] ?? null) !== 'php-file') {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $selectors */
    public function removedFromRun(RelativePath $relative, array $selectors, ?string $phpEvidence): void
    {
        foreach ($selectors as $selector) {
            $this->removed[$selector][] = $relative->value();
            if (($this->phpEvidence[$selector] ?? null) !== 'php-file') {
                $this->phpEvidence[$selector] = $phpEvidence ?? ($this->phpEvidence[$selector] ?? null);
            }
        }
    }

    /** @param list<string> $selectors */
    public function hiddenDirectory(RelativePath $relative, array $selectors): void
    {
        $this->removedSubtrees[] = ['directory' => $relative->value(), 'selectors' => $selectors];
    }

    public function unlistable(RelativePath $relative): void
    {
        foreach ($this->selectors as $selector) {
            if ($this->couldHide($selector, $relative->value())) {
                $this->unjudgeable[$selector->display()] ??= $relative->value();
            }
        }
    }

    public function unlistableRoot(): void
    {
        foreach ($this->selectors as $selector) {
            $this->unjudgeable[$selector->display()] ??= '.';
        }
    }

    public function unsettled(): bool
    {
        foreach ($this->selectors as $selector) {
            if (($this->bound[$selector->display()] ?? []) === []) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ExcludeSelectorVerdict> */
    public function verdicts(bool $knownUniverse): array
    {
        $verdicts = [];
        foreach ($this->selectors as $selector) {
            $display = $selector->display();
            $bound = $this->bound[$display] ?? [];
            if ($bound !== []) {
                $verdicts[] = new ExcludeSelectorVerdict(
                    $display,
                    $selector->sources,
                    ExcludeSelectorOutcome::Removed,
                    $this->removed[$display] ?? $bound,
                    $this->phpEvidence[$display] ?? null,
                );
                continue;
            }
            if (!$knownUniverse) {
                $verdicts[] = new ExcludeSelectorVerdict($display, $selector->sources, ExcludeSelectorOutcome::NotJudged);
                continue;
            }
            if (isset($this->unjudgeable[$display])) {
                $verdicts[] = new ExcludeSelectorVerdict(
                    $display,
                    $selector->sources,
                    ExcludeSelectorOutcome::Unjudgeable,
                    blockedAt: $this->unjudgeable[$display],
                );
                continue;
            }
            $verdicts[] = $this->coveredOrUnmatched($selector);
        }

        return $verdicts;
    }

    private function coveredOrUnmatched(AuthoredExclude $selector): ExcludeSelectorVerdict
    {
        $same = null;
        $other = null;
        foreach ($this->removedSubtrees as $subtree) {
            if (!$this->couldHide($selector, $subtree['directory'])) {
                continue;
            }
            foreach ($subtree['selectors'] as $covering) {
                if ($covering === $selector->display()) {
                    continue;
                }
                $coveringSelector = $this->selector($covering);
                if ($coveringSelector === null) {
                    continue;
                }
                if ($this->hasOtherSource($selector, $coveringSelector)) {
                    $other ??= $covering;
                } else {
                    $same ??= $covering;
                }
            }
        }
        $outcome = $other !== null ? ExcludeSelectorOutcome::CoveredByOtherSource
            : ($same !== null ? ExcludeSelectorOutcome::CoveredBySameSource : ExcludeSelectorOutcome::Unmatched);

        return new ExcludeSelectorVerdict(
            $selector->display(),
            $selector->sources,
            $outcome,
            coveredBy: $other ?? $same,
        );
    }

    private function couldHide(AuthoredExclude $selector, string $directory): bool
    {
        return $selector->pattern->definition->kind === SelectorKind::Regex
            || str_starts_with($selector->pattern->definition->value, $directory . '/');
    }

    private function selector(string $display): ?AuthoredExclude
    {
        foreach ($this->selectors as $selector) {
            if ($selector->display() === $display) {
                return $selector;
            }
        }

        return null;
    }

    private function hasOtherSource(AuthoredExclude $query, AuthoredExclude $hider): bool
    {
        $querySources = array_map(serialize(...), $query->sources);
        foreach ($hider->sources as $source) {
            if (!\in_array(serialize($source), $querySources, true)) {
                return true;
            }
        }

        return false;
    }
}
