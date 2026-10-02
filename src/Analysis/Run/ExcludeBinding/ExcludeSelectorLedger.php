<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

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
            $hidden = [];
            foreach ($this->removedSubtrees as $subtree) {
                foreach ($subtree['selectors'] as $covering) {
                    $hider = $this->selector($covering);
                    if ($hider !== null) {
                        $hidden[] = ['directory' => $subtree['directory'], 'selector' => $covering, 'sources' => $hider->sources];
                    }
                }
            }
            $verdicts[] = ExcludeSelectorVerdict::fromMeasuredFacts(
                $selector->pattern,
                $selector->sources,
                $this->bound[$display] ?? [],
                $this->removed[$display] ?? [],
                $this->phpEvidence[$display] ?? null,
                $this->unjudgeable[$display] ?? null,
                $hidden,
                $knownUniverse,
            );
        }

        return $verdicts;
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

}
