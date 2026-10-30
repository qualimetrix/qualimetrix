<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\ProjectScope;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;

/** The distinct sources that wrote one configured value, as a finding names them. */
final readonly class ConfiguredValueWriters
{
    /** @param non-empty-list<ConfigurationOrigin> $origins distinct by value */
    public function __construct(public array $origins)
    {
        if ($origins === [] || self::distinct($origins) !== $origins) {
            throw new InvalidArgumentException('Value writers must be nonempty and distinct');
        }
    }

    /**
     * @param non-empty-list<Provenance> $provenance
     */
    public static function fromProvenance(array $provenance): self
    {
        return new self(self::distinct(array_map(static fn(Provenance $writer): ConfigurationOrigin => $writer->origin, $provenance)));
    }

    /** The writers of either value, this one's first. */
    public function with(self $other): self
    {
        return new self(self::distinct([...$this->origins, ...$other->origins]));
    }

    /** `Written by A.`, `Written by A and B.` or `Written by A, B and C.` */
    public function sentence(): string
    {
        $names = array_map(static fn(ConfigurationOrigin $origin): string => $origin->describe(), $this->origins);
        $last = array_pop($names);

        return 'Written by ' . ($names === [] ? $last : implode(', ', $names) . ' and ' . $last) . '.';
    }

    /**
     * @param list<ConfigurationOrigin> $origins
     *
     * @return ($origins is non-empty-list<ConfigurationOrigin> ? non-empty-list<ConfigurationOrigin> : list<ConfigurationOrigin>)
     */
    private static function distinct(array $origins): array
    {
        $distinct = [];
        foreach ($origins as $origin) {
            $distinct[serialize($origin)] ??= $origin;
        }

        return array_values($distinct);
    }
}
