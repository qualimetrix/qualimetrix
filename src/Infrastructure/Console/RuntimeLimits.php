<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

final readonly class RuntimeLimits
{
    public const string MEMORY_LIMIT_KEY = 'memory_limit';

    public function __construct(
        public ?string $memoryLimit = null,
        private ?ResolvedValueInterface $memoryLimitValue = null,
    ) {
        // Zero has the shape of a size and PHP refuses it at `ini_set()`, where
        // the refusal no longer knows which value it is about. A leading zero
        // is read as octal by PHP (`010M` is 8 MB), so it is not a size either.
        if ($memoryLimit !== null && preg_match('/^(?:-1|[1-9][0-9]*[KMG]?)$/i', $memoryLimit) !== 1) {
            throw $this->refusal(self::invalidMemoryLimitSummary($memoryLimit));
        }
    }

    public static function fromResolvedValue(?ResolvedValueInterface $value): self
    {
        if ($value === null) {
            return new self();
        }

        $plain = $value->plain();
        if (!\is_string($plain) && !\is_int($plain)) {
            throw Provenance::refusalOf($value->contributors(), self::invalidMemoryLimitSummary(get_debug_type($plain)));
        }

        return new self((string) $plain, $value);
    }

    public function refusal(string $summary): ConfigurationRefusal
    {
        if ($this->memoryLimitValue !== null) {
            return Provenance::refusalOf($this->memoryLimitValue->contributors(), $summary);
        }

        return ConfigurationRefusal::aboutResolvedInput($summary, self::MEMORY_LIMIT_KEY);
    }

    private static function invalidMemoryLimitSummary(string $memoryLimit): string
    {
        return \sprintf(
            'Invalid memory_limit "%s". Expected a positive size in bytes without leading zeros, optionally with a K, M, or G suffix, or -1 for no limit.',
            $memoryLimit,
        );
    }
}
