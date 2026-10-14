<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;

final readonly class CacheSource
{
    private function __construct(public bool $explicit, public string $spelling, private ?Provenance $writer) {}

    public static function explicit(Provenance $writer): self
    {
        $path = $writer->displayPath();

        return new self(true, $writer->origin->describe() . ($path === '' ? '' : ', ' . $path), $writer);
    }

    public static function default(): self
    {
        return new self(false, 'the user cache directory (XDG_CACHE_HOME, HOME or LOCALAPPDATA)', null);
    }

    public function refuse(string $reason): never
    {
        if ($this->writer === null) {
            throw new LogicException('Only an explicit cache source can refuse its written value.');
        }

        throw Provenance::refusalOf([$this->writer], $reason);
    }
}
