<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration\Exception;

use RuntimeException;
use Throwable;

final class ConfigLoadException extends RuntimeException
{
    public function __construct(
        public readonly string $configPath,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fileNotFound(string $path): self
    {
        return new self($path, \sprintf('Configuration file not found: %s', $path));
    }

    public static function parseError(string $path, string $error, ?Throwable $previous = null): self
    {
        return new self($path, \sprintf('Failed to parse configuration file %s: %s', $path, $error), $previous);
    }

    public static function invalidFormat(string $path, string $expectedFormat): self
    {
        return new self($path, \sprintf('Configuration file %s is not valid %s format', $path, $expectedFormat));
    }

    public static function invalidStructure(string $path, string $reason): self
    {
        return new self($path, \sprintf('Invalid configuration in %s: %s', $path, $reason));
    }
}
