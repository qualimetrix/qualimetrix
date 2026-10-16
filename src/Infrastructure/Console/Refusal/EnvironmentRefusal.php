<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Refusal;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusalInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use RuntimeException;
use Throwable;

final class EnvironmentRefusal extends RuntimeException implements RefusalInterface
{
    private function __construct(private readonly string $summary, ?Throwable $previous = null)
    {
        parent::__construct($summary, 0, $previous);
    }

    public static function aboutFile(string $path, string $operation, string $reason): self
    {
        return new self(\sprintf('Cannot %s file "%s": %s', $operation, $path, $reason));
    }

    public static function aboutContention(string $path, string $detail): self
    {
        return new self(\sprintf('File target "%s" changed during use: %s', $path, $detail));
    }

    public static function aboutCapability(string $function, string $feature, string $advice): self
    {
        return new self(\sprintf('%s requires %s. %s', $feature, $function, $advice));
    }

    public static function fromFailure(EnvironmentFailureInterface $failure): self
    {
        return new self($failure->getMessage(), $failure);
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function position(): ?RefusedPosition
    {
        return null;
    }

    public function sources(): ?array
    {
        return null;
    }
}
