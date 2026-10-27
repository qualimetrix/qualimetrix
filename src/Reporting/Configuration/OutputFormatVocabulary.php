<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Configuration;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\LiteralHint;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SchemaWordSet;
use Qualimetrix\Reporting\Contract\Formatting\FormatterRegistryInterface;

/**
 * The output format names the registered formatters answer to. The
 * configuration engine refuses any other name against {@see self::words()},
 * so a resolved format is always one of them.
 */
final readonly class OutputFormatVocabulary
{
    public const string KEY = 'format';

    public function __construct(private FormatterRegistryInterface $formatters) {}

    public function words(): SchemaWordSet
    {
        return SchemaWordSet::of(...$this->formatters->getAvailableNames());
    }

    /** @return list<LiteralHint> */
    public function retiredNames(): array
    {
        return [new LiteralHint('text-verbose', 'Output format "text-verbose" was retired; use "--format=text --detail=all".')];
    }

    public function accepted(ResolvedValueInterface $format): string
    {
        $value = $format->plain();
        if (!\is_string($value) || !$this->formatters->has($value)) {
            throw new LogicException('The configuration engine admits only a registered output format as format.');
        }

        return $value;
    }
}
