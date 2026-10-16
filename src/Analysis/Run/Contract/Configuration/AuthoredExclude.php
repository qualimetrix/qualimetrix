<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Core\Pattern\PathPattern;

/** One effective selector with every source that wrote the same spelling. */
final readonly class AuthoredExclude
{
    /** @param non-empty-list<ConfigurationOrigin> $sources */
    public function __construct(public PathPattern $pattern, public array $sources)
    {
        if ($sources === []) {
            throw new InvalidArgumentException('An authored exclude requires a source');
        }
    }

    public function display(): string
    {
        return $this->pattern->definition->display();
    }
}
