<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Base class for security pattern rules.
 *
 * Concrete rules are expected to define their metadata via typed class
 * constants (NAME, DOCS_PAGE, REMEDIATION_MINUTES, DESCRIPTION, PATTERN_TYPE,
 * MESSAGE_TEMPLATE, RECOMMENDATION), read here via late static binding —
 * mirrors {@see \Qualimetrix\Analysis\Evidence\CodeSmell\AbstractCodeSmellRule},
 * which keeps concrete code-smell rules free of the same boilerplate hook
 * methods for the same reason: three near-identical subclasses that only
 * override a handful of methods returning a literal each were themselves
 * a duplication finding once a fourth per-rule constant
 * (`REMEDIATION_MINUTES`) tipped them over the detector's threshold.
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

    /**
     * Empty here for the same reason as {@see NAME}: it exists only so that
     * concrete subclasses are forced to declare their own value. Unlike
     * `NAME`, nothing in this class reads `static::DOCS_PAGE` — its sole
     * purpose is to make an *omitted* declaration on a subclass distinguishable
     * from a *declared* one by {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleDocsPageReader},
     * which checks `getDeclaringClass()` and rejects this inherited empty
     * string rather than silently accepting it.
     */
    public const string DOCS_PAGE = '';

    /**
     * Empty (zero) here for the same reason as {@see DOCS_PAGE}: it forces
     * every concrete subclass to declare its own value, and
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleRemediationMinutesReader}
     * rejects this inherited placeholder via `getDeclaringClass()` rather
     * than silently accepting it.
     */
    public const int REMEDIATION_MINUTES = 0;

    protected const string DESCRIPTION = '';
    protected const string PATTERN_TYPE = '';
    protected const string MESSAGE_TEMPLATE = '';
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
        return RuleCategory::Security;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [
            'security.' . static::PATTERN_TYPE,
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
     * {@see analyze()} below with a fixed `1.0` occurrence marker projected
     * by {@see SecurityPatternFinding} and a fixed {@see Severity::Error} —
     * never a measured magnitude — so `occurrence` is the correct shape
     * uniformly. `static::NAME` resolves per concrete subclass via late
     * static binding; {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader} reads
     * this method by reflection on the concrete rule class, so the binding
     * target is correct without any special-casing on the reader's side.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(static::NAME, static::NAME))->toKey() => ChannelDeclaration::occurrence(SymbolLevel::Callable),
        ];
    }

    /**
     * Shared by every subclass — a fixed marker, never a measured magnitude.
     * {@see HardcodedCredentialsRule} and {@see SensitiveParameterRule} are
     * the same family but extend {@see AbstractRule} directly, so they
     * declare their own `occurrence` value instead of inheriting this one.
     */
    public const ChannelShape SHAPE = ChannelShape::Occurrence;

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof SecurityPatternOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];
        $type = static::PATTERN_TYPE;

        foreach ($context->metrics->all(SymbolType::File) as $fileInfo) {
            $metrics = $context->metrics->get($fileInfo->symbolPath);
            $entries = $metrics->entries("security.{$type}");

            if ($entries === []) {
                continue;
            }

            foreach ($entries as $entry) {
                $file = $fileInfo->file ?? throw new LogicException('File symbol must carry a relative path');
                $violations[] = SecurityPatternFinding::fromEntry($entry, $file)->toViolation(
                    $fileInfo->symbolPath,
                    static::NAME,
                    $type,
                    Severity::Error,
                    static::MESSAGE_TEMPLATE,
                    static::RECOMMENDATION,
                );
            }
        }

        return $violations;
    }
}
