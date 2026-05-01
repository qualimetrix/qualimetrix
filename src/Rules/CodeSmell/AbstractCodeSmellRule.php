<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Base class for code smell rules.
 *
 * Concrete rules are expected to define the metadata via typed class
 * constants (NAME, DESCRIPTION, SMELL_TYPE, SEVERITY, MESSAGE_TEMPLATE,
 * MESSAGE_TEMPLATE_WITH_EXTRA, RECOMMENDATION). The base class reads them
 * via late static binding so subclasses stay free of boilerplate methods.
 *
 * For rules that whitelist individual occurrences (e.g. allowed boolean
 * prefixes, allowed @-suppressed functions) the options class must
 * implement {@see EntryFilteringOptionsInterface}.
 */
abstract class AbstractCodeSmellRule extends AbstractRule
{
    public const string NAME = '';
    protected const string DESCRIPTION = '';
    protected const string SMELL_TYPE = '';
    protected const Severity SEVERITY = Severity::Warning;
    protected const string MESSAGE_TEMPLATE = '';
    protected const ?string MESSAGE_TEMPLATE_WITH_EXTRA = null;
    protected const ?string RECOMMENDATION = null;

    public function getName(): string
    {
        return static::NAME;
    }

    public function getDescription(): string
    {
        return static::DESCRIPTION;
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::CodeSmell;
    }

    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [
            'codeSmell.' . static::SMELL_TYPE,
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        $violations = [];
        $type = static::SMELL_TYPE;

        foreach ($context->metrics->all(SymbolType::File) as $fileInfo) {
            $metrics = $context->metrics->get($fileInfo->symbolPath);
            $entries = $metrics->entries("codeSmell.{$type}");

            foreach ($entries as $entry) {
                if (!$this->shouldIncludeEntry($entry)) {
                    continue;
                }

                $line = (int) $entry['line'];

                $violations[] = new Violation(
                    location: new Location($fileInfo->file, $line, precise: true),
                    symbolPath: $fileInfo->symbolPath,
                    ruleName: static::NAME,
                    violationCode: static::NAME,
                    message: $this->buildMessage($entry),
                    severity: static::SEVERITY,
                    metricValue: 1.0,
                    recommendation: static::RECOMMENDATION,
                );
            }
        }

        return $violations;
    }

    /**
     * Filters entries before violation creation.
     *
     * Default behaviour: when the options class implements
     * {@see EntryFilteringOptionsInterface}, the entry's `extra` value is
     * routed through it. Otherwise every entry is kept.
     *
     * @param array<string, mixed> $entry
     */
    protected function shouldIncludeEntry(array $entry): bool
    {
        $options = $this->options;

        if (!$options instanceof EntryFilteringOptionsInterface) {
            return true;
        }

        $extra = $entry['extra'] ?? null;

        return !\is_string($extra) || !$options->isExtraAllowed($extra);
    }

    /**
     * Builds the violation message for a single entry.
     *
     * Default behaviour: when MESSAGE_TEMPLATE_WITH_EXTRA is set and the
     * entry carries a non-empty `extra` value, sprintf-format it (with a
     * leading `$` stripped if present, so $-prefixed param names render
     * cleanly). Otherwise return the plain MESSAGE_TEMPLATE.
     *
     * @param array<string, mixed> $entry
     */
    protected function buildMessage(array $entry): string
    {
        $template = static::MESSAGE_TEMPLATE_WITH_EXTRA;
        if ($template === null) {
            return static::MESSAGE_TEMPLATE;
        }

        $extra = $entry['extra'] ?? null;
        if (!\is_string($extra) || $extra === '') {
            return static::MESSAGE_TEMPLATE;
        }

        return \sprintf($template, ltrim($extra, '$'));
    }
}
