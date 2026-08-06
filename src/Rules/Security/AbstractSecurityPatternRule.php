<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Security;

use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Rules\AbstractRule;

/**
 * Base class for security pattern rules.
 *
 * Provides common functionality for analyzing security pattern metrics
 * from SecurityPatternCollector.
 */
abstract class AbstractSecurityPatternRule extends AbstractRule
{
    /**
     * Overridden by every concrete subclass with its own slug
     * (`security.command-injection`, `security.sql-injection`,
     * `security.xss`). Declared here — empty, never used directly — only so
     * {@see channelDeclarations()} can read it via `static::NAME` through
     * late static binding, the same idiom {@see AbstractCodeSmellRule}
     * already uses for the same reason.
     */
    public const string NAME = '';

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Security;
    }

    /**
     * Returns the security pattern type this rule checks.
     */
    abstract protected function getPatternType(): string;

    /**
     * Returns severity for this pattern.
     */
    abstract protected function getSeverity(): Severity;

    /**
     * Returns the violation message template.
     */
    abstract protected function getMessageTemplate(): string;

    /**
     * Returns the actionable recommendation for this security pattern.
     *
     * While message describes what is wrong, recommendation tells the user what to do.
     * Subclasses should override to provide a specific recommendation.
     */
    protected function getRecommendation(): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        $type = $this->getPatternType();

        return [
            "security.{$type}",
        ];
    }

    /**
     * @return class-string<SecurityPatternOptions>
     */
    public static function getOptionsClass(): string
    {
        return SecurityPatternOptions::class;
    }

    /**
     * All three concrete subclasses emit their channel through the loop in
     * {@see analyze()} below with a fixed `1.0` occurrence marker
     * (`metricValue: 1.0`) and a fixed `getSeverity()` constant — never a
     * measured magnitude — so `occurrence` is the correct shape uniformly.
     * `static::NAME` resolves per concrete subclass via late static
     * binding; {@see \Qualimetrix\Core\Rule\ChannelDeclarationReader} reads
     * this method by reflection on the concrete rule class, so the binding
     * target is correct without any special-casing on the reader's side.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(static::NAME, static::NAME))->toKey() => ChannelDeclaration::occurrence(),
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof SecurityPatternOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];
        $type = $this->getPatternType();

        foreach ($context->metrics->all(SymbolType::File) as $fileInfo) {
            $metrics = $context->metrics->get($fileInfo->symbolPath);
            $entries = $metrics->entries("security.{$type}");

            if ($entries === []) {
                continue;
            }

            foreach ($entries as $entry) {
                $line = (int) $entry['line'];
                $superglobal = (string) ($entry['superglobal'] ?? '');

                $message = $superglobal !== ''
                    ? \sprintf('%s ($%s)', $this->getMessageTemplate(), $superglobal)
                    : $this->getMessageTemplate();

                $violations[] = new Violation(
                    location: new Location($fileInfo->file, $line, precise: true),
                    symbolPath: $fileInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: $this->getName(),
                    message: $message,
                    severity: $this->getSeverity(),
                    metricValue: 1.0,
                    recommendation: $this->getRecommendation(),
                );
            }
        }

        return $violations;
    }
}
