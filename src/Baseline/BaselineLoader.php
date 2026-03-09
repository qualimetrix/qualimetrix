<?php

declare(strict_types=1);

namespace AiMessDetector\Baseline;

use DateTimeImmutable;
use Exception;
use JsonException;
use RuntimeException;

/**
 * Loads baseline from JSON file.
 */
final readonly class BaselineLoader
{
    /**
     * Loads baseline from file.
     *
     * @throws RuntimeException if file doesn't exist, is not readable, or contains invalid data
     */
    public function load(string $path): Baseline
    {
        if (!file_exists($path)) {
            throw new RuntimeException("Baseline file not found: {$path}");
        }

        if (!is_readable($path)) {
            throw new RuntimeException("Baseline file is not readable: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read baseline file: {$path}");
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON in baseline file: {$e->getMessage()}", 0, $e);
        }

        if (!\is_array($data)) {
            throw new RuntimeException("Baseline file must contain a JSON object");
        }

        return $this->parseBaseline($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseBaseline(array $data): Baseline
    {
        $this->validateStructure($data);

        $entries = [];
        foreach ($data['violations'] as $key => $keyViolations) {
            if (!\is_string($key)) {
                throw new RuntimeException('Baseline violation keys must be strings');
            }

            if (!\is_array($keyViolations)) {
                throw new RuntimeException("Violations for key {$key} must be an array");
            }

            $entries[$key] = [];
            foreach ($keyViolations as $violation) {
                if (!\is_array($violation)) {
                    throw new RuntimeException("Each violation must be an array");
                }

                $this->validateViolationEntry($violation);
                $entries[$key][] = BaselineEntry::fromArray($violation);
            }
        }

        try {
            $generated = new DateTimeImmutable($data['generated']);
        } catch (Exception $e) {
            throw new RuntimeException(
                "Invalid date in baseline \"generated\" field: {$data['generated']}",
                0,
                $e,
            );
        }

        return new Baseline(
            version: $data['version'],
            generated: $generated,
            entries: $entries,
        );
    }

    /**
     * Validates that violation entry has all required fields.
     *
     * @param array<mixed, mixed> $violation
     *
     * @phpstan-assert array{rule: string, hash: string} $violation
     */
    private function validateViolationEntry(array $violation): void
    {
        if (!isset($violation['rule']) || !\is_string($violation['rule'])) {
            throw new RuntimeException('Violation entry must have "rule" field (string)');
        }

        if (!isset($violation['hash']) || !\is_string($violation['hash'])) {
            throw new RuntimeException('Violation entry must have "hash" field (string)');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateStructure(array $data): void
    {
        if (!isset($data['version'])) {
            throw new RuntimeException('Baseline file must contain "version" field');
        }

        if (!\is_int($data['version'])) {
            throw new RuntimeException('Baseline "version" must be an integer');
        }

        if ($data['version'] !== 4) {
            throw new RuntimeException(\sprintf(
                'Unsupported baseline version: %d. Expected version 4. '
                . 'The hash algorithm changed in version 4, making older baselines incompatible. '
                . 'Please regenerate your baseline with --generate-baseline.',
                $data['version'],
            ));
        }

        if (!isset($data['generated'])) {
            throw new RuntimeException('Baseline file must contain "generated" field');
        }

        if (!\is_string($data['generated'])) {
            throw new RuntimeException('Baseline "generated" must be a string (ISO 8601 datetime)');
        }

        if (!isset($data['violations'])) {
            throw new RuntimeException('Baseline file must contain "violations" field');
        }

        if (!\is_array($data['violations'])) {
            throw new RuntimeException('Baseline "violations" must be an object');
        }
    }
}
