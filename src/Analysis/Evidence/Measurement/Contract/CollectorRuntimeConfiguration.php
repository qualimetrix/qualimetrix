<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use InvalidArgumentException;

final readonly class CollectorRuntimeConfiguration
{
    /** @param array<mixed> $lcomExcludedMethods */
    public function __construct(array $lcomExcludedMethods = [])
    {
        if (!array_is_list($lcomExcludedMethods)) {
            throw new InvalidArgumentException('lcom_excluded_methods must be a list of strings.');
        }

        $unique = [];
        foreach ($lcomExcludedMethods as $method) {
            if (!\is_string($method)) {
                throw new InvalidArgumentException('lcom_excluded_methods must be a list of strings.');
            }
            if (!\in_array($method, $unique, true)) {
                $unique[] = $method;
            }
        }

        $this->lcomExcludedMethods = $unique;
    }

    /** @var list<string> */
    public array $lcomExcludedMethods;

    public static function empty(): self
    {
        return new self();
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $unknown = array_diff(array_keys($payload), ['lcom_excluded_methods']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown collector runtime configuration key: ' . reset($unknown));
        }

        $methods = $payload['lcom_excluded_methods'] ?? [];
        if (!\is_array($methods)) {
            throw new InvalidArgumentException('lcom_excluded_methods must be a list of strings.');
        }

        return new self($methods);
    }

    /** @return array{lcom_excluded_methods: list<string>} */
    public function toPayload(): array
    {
        return ['lcom_excluded_methods' => $this->lcomExcludedMethods];
    }
}
