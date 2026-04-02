<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Violation\Severity;

/**
 * Detects usage of error suppression operator (@).
 *
 * The @ operator hides errors which can make debugging difficult.
 * Handle errors explicitly instead.
 *
 * Supports `allowed_functions` option to whitelist specific functions
 * where @ usage is acceptable (e.g., fopen, unlink).
 */
final class ErrorSuppressionRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.error-suppression';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects usage of error suppression operator (@)';
    }

    protected function getSmellType(): string
    {
        return 'error_suppression';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Warning;
    }

    protected function getMessageTemplate(): string
    {
        return 'Error suppression operator (@) detected - handle errors explicitly';
    }

    protected function getRecommendation(): string
    {
        return 'Handle the error explicitly with try/catch or conditional checks.';
    }

    /**
     * @param array<string, mixed> $entry
     */
    protected function shouldIncludeEntry(array $entry): bool
    {
        if (!$this->options instanceof ErrorSuppressionOptions) {
            return true;
        }

        $funcName = $entry['extra'] ?? null;

        return !\is_string($funcName) || !$this->options->isFunctionAllowed($funcName);
    }

    /**
     * @param array<string, mixed> $entry
     */
    protected function buildMessage(array $entry): string
    {
        $funcName = $entry['extra'] ?? null;

        if (\is_string($funcName) && $funcName !== '') {
            return \sprintf('Error suppression (@) on %s() - handle errors explicitly', $funcName);
        }

        return $this->getMessageTemplate();
    }

    /**
     * @return class-string<ErrorSuppressionOptions>
     */
    public static function getOptionsClass(): string
    {
        return ErrorSuppressionOptions::class;
    }
}
