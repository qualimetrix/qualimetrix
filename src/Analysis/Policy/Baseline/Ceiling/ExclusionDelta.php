<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;

/** Compares complete authored exclusion definitions over named PHP files. */
final readonly class ExclusionDelta
{
    /** @var list<PathPattern> */
    private array $recordedPatterns;

    /** @var list<PathPattern> */
    private array $currentPatterns;

    public function __construct(
        public RecordedExclusions $recorded,
        public RecordedExclusions $current,
    ) {
        $this->recordedPatterns = self::compile($recorded->patterns);
        $this->currentPatterns = self::compile($current->patterns);
    }

    public function equalDefinitions(): bool
    {
        return $this->recorded->equals($this->current);
    }

    public function generatedPolicyChanged(): bool
    {
        return $this->recorded->generatedFilePolicy !== $this->current->generatedFilePolicy;
    }

    public function differsAt(RelativePath $file): bool
    {
        return self::matches($this->recordedPatterns, $file) !== self::matches($this->currentPatterns, $file);
    }

    public function currentlyExcluded(RelativePath $file): bool
    {
        return self::matches($this->currentPatterns, $file);
    }

    /** @param list<PathPattern> $patterns */
    private static function matches(array $patterns, RelativePath $file): bool
    {
        return array_any($patterns, static fn(PathPattern $pattern): bool => $pattern->matches($file));
    }

    /**
     * @param list<string> $selectors
     *
     * @return list<PathPattern>
     */
    private static function compile(array $selectors): array
    {
        return array_map(static function (string $selector): PathPattern {
            [$kind, $value] = explode(':', $selector, 2);

            return new PathPattern(SelectorDefinition::fromKindAndValue($kind, $value));
        }, $selectors);
    }
}
