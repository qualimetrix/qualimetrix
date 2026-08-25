<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Detects parameters with sensitive names missing #[\SensitiveParameter].
 *
 * Parameters named password, secret, apiKey, etc. should use the
 * #[\SensitiveParameter] attribute to prevent credential leakage in stack traces.
 */
final class SensitiveParameterRule extends AbstractRule
{
    public const string NAME = 'security.sensitive-parameter';
    public const string DOCS_PAGE = 'rules/security.md';

    public const int REMEDIATION_MINUTES = 10;

    public const ChannelShape SHAPE = ChannelShape::Occurrence;
    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects sensitive parameters missing #[\\SensitiveParameter] attribute';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Security;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::SECURITY_SENSITIVE_PARAMETER];
    }

    /**
     * @return class-string<SensitiveParameterOptions>
     */
    public static function getOptionsClass(): string
    {
        return SensitiveParameterOptions::class;
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof SensitiveParameterOptions || !$this->options->isEnabled()) {
            return [];
        }

        $findings = [];

        foreach ($context->metrics->all(SymbolType::File) as $fileInfo) {
            $metrics = $context->metrics->get($fileInfo->symbolPath);
            $entries = $metrics->entries(MetricName::SECURITY_SENSITIVE_PARAMETER);

            if ($entries === []) {
                continue;
            }

            foreach ($entries as $entry) {
                $finding = $this->findingForEntry($fileInfo, $entry, $context);
                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, bool|float|int|string> $entry
     */
    private function findingForEntry(SymbolInfo $fileInfo, array $entry, AnalysisContext $context): ?Finding
    {
        \assert($this->options instanceof SensitiveParameterOptions);
        $file = $fileInfo->file ?? throw new LogicException('File symbol must carry a relative path');
        $line = (int) $entry['line'];
        $subject = MetricSubjectCodec::decodeEntry($entry, $file);
        $severity = $this->getEffectiveSeverity($context, $this->options, $subject, 1);
        if ($severity === null) {
            return null;
        }

        return new Finding(
            location: new Location($file, $line, precise: true),
            subject: $subject,
            symbolPath: $fileInfo->symbolPath,
            ruleName: $this->getName(),
            code: self::NAME,
            message: 'Sensitive parameter missing #[\\SensitiveParameter] attribute — add it to prevent credential leakage in stack traces',
            severity: $severity,
            metricValue: 1.0,
            recommendation: 'Add #[\\SensitiveParameter] attribute to prevent credential leakage in stack traces.',
            occurrenceKey: OccurrenceKey::semantic(self::NAME, ['paramName' => (string) $entry['paramName']]),
        );
    }

    /**
     * `security.sensitive-parameter` reports a fixed `1.0` occurrence
     * marker per entry — same pattern as `security.hardcoded-credentials`.
     * Severity receives the same fixed per-occurrence value `1`, and
     * {@see SensitiveParameterOptions::getSeverity()} only checks that the
     * value is greater than zero. There is no live threshold that varies the
     * outcome, so no direction to declare.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::occurrence(SymbolLevel::Callable),
        ];
    }
}
