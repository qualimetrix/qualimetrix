<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Detects hardcoded credentials in PHP code.
 *
 * Checks for string literal values assigned to variables, properties, constants,
 * array keys, and parameters with credential-related names.
 */
final class HardcodedCredentialsRule extends AbstractRule
{
    public const string NAME = 'security.hardcoded-credentials';
    public const string DOCS_PAGE = 'rules/security.md';

    public const int REMEDIATION_MINUTES = 30;

    public const ChannelShape SHAPE = ChannelShape::Occurrence;
    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects hardcoded credentials in code';
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
        return [MetricName::SECURITY_HARDCODED_CREDENTIALS];
    }

    /**
     * @return class-string<HardcodedCredentialsOptions>
     */
    public static function getOptionsClass(): string
    {
        return HardcodedCredentialsOptions::class;
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof HardcodedCredentialsOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::File) as $fileInfo) {
            $metrics = $context->metrics->get($fileInfo->symbolPath);
            $entries = $metrics->entries(MetricName::SECURITY_HARDCODED_CREDENTIALS);

            if ($entries === []) {
                continue;
            }

            array_push($violations, ...$this->violationsForEntries($fileInfo, $entries, $context));
        }

        return $violations;
    }

    /**
     * @param list<array<string, bool|float|int|string>> $entries
     *
     * @return list<Violation>
     */
    private function violationsForEntries(SymbolInfo $fileInfo, array $entries, AnalysisContext $context): array
    {
        \assert($this->options instanceof HardcodedCredentialsOptions);
        $file = $fileInfo->file ?? throw new LogicException('File symbol must carry a relative path');
        $violations = [];

        foreach ($entries as $entry) {
            $line = (int) $entry['line'];
            $pattern = (string) $entry['pattern'];
            $subject = MetricSubjectCodec::decodeEntry($entry, $file);
            $severity = $this->getEffectiveSeverity($context, $this->options, $subject, 1);
            if ($severity === null) {
                continue;
            }
            $violations[] = new Violation(
                location: new Location($file, $line, precise: true),
                subject: $subject,
                symbolPath: $fileInfo->symbolPath,
                ruleName: $this->getName(),
                violationCode: self::NAME,
                message: $this->messageForPattern($pattern),
                severity: $severity,
                metricValue: 1.0,
                recommendation: 'Move secrets to environment variables or a secrets manager.',
                occurrenceKey: OccurrenceKey::semantic(self::NAME, ['pattern' => $pattern]),
            );
        }

        return $violations;
    }

    private function messageForPattern(string $pattern): string
    {
        $message = match ($pattern) {
            'variable' => 'Hardcoded credential in variable assignment',
            'array_key' => 'Hardcoded credential in array key',
            'class_const' => 'Hardcoded credential in class constant',
            'define' => 'Hardcoded credential in define() call',
            'property' => 'Hardcoded credential in property default',
            'parameter' => 'Hardcoded credential in parameter default',
            'enum_case' => 'Hardcoded credential in enum case',
            default => 'Hardcoded credential found',
        };

        return $message . ' — use environment variables or a secrets manager';
    }

    /**
     * `security.hardcoded-credentials` reports a fixed `1.0` occurrence
     * marker per entry (see the emission above). Severity receives the same
     * fixed per-occurrence value `1` via
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule::getEffectiveSeverity()}, and
     * {@see HardcodedCredentialsOptions::getSeverity()} only checks that the
     * value is greater than zero. There is no live threshold that varies the
     * outcome, so no direction to declare.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::occurrence(SymbolLevel::Class_),
        ];
    }
}
