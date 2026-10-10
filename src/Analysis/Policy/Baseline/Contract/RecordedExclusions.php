<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Contract;

use InvalidArgumentException;
use Qualimetrix\Analysis\Run\Contract\Configuration\AuthoredExclude;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;

/** The complete discovery exclusion definition recorded when a baseline was captured. */
final readonly class RecordedExclusions
{
    /** @var list<string> canonical explicit kind:value selectors, sorted and unique */
    public array $patterns;

    /** @param list<string> $patterns */
    public function __construct(array $patterns, public GeneratedFilePolicy $generatedFilePolicy)
    {
        if (\count($patterns) > SelectorDefinition::MAX_SELECTOR_COUNT) {
            throw new InvalidArgumentException(\sprintf('At most %d exclusion selectors may be recorded', SelectorDefinition::MAX_SELECTOR_COUNT));
        }

        foreach ($patterns as $pattern) {
            $parts = explode(':', $pattern, 2);
            if (\count($parts) !== 2) {
                throw new InvalidArgumentException('A recorded exclusion must use exact:value, subtree:value, or regex:value');
            }

            new PathPattern(SelectorDefinition::fromKindAndValue($parts[0], $parts[1]));
        }

        $canonical = array_values(array_unique($patterns));
        sort($canonical, \SORT_STRING);
        $this->patterns = $canonical;
    }

    public static function fromRunConfiguration(RunConfiguration $configuration): self
    {
        return new self(
            array_map(static fn(AuthoredExclude $exclude): string => $exclude->display(), $configuration->authoredPathExcludes),
            $configuration->generatedFilePolicy,
        );
    }

    public function equals(self $other): bool
    {
        return $this->patterns === $other->patterns && $this->generatedFilePolicy === $other->generatedFilePolicy;
    }

    /** @return array{patterns: list<string>, generated: 'included'|'excluded'} */
    public function toArray(): array
    {
        return [
            'patterns' => $this->patterns,
            'generated' => $this->generatedFilePolicy === GeneratedFilePolicy::Include ? 'included' : 'excluded',
        ];
    }
}
