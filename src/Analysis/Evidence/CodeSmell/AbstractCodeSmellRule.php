<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;

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
 *
 * @qmx-threshold coupling.cbo 22 -- Declaring the levels a channel reports at costs every rule one edge onto SymbolLevel; this base sat exactly on the inclusive warning threshold of 20 before it. Raw CBO 21 now, after ADR 0031 (rule-vocabulary Ш4c) added one more constant-typed dependency (ChannelShape) that every subclass answering shape() through this base needs; 22 gets one-edge headroom again.
 */
abstract class AbstractCodeSmellRule extends AbstractRule
{
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
     * Shared by every subclass that inherits {@see channelDeclarations()}
     * below unchanged — a fixed `1.0` occurrence marker is never a magnitude.
     * The code-smell rules that report a real measured magnitude
     * (`ConstructorOverinjectionRule`, `LongParameterListRule`,
     * `UnreachableCodeRule`, `UnusedPrivateRule`) extend `AbstractRule`
     * directly instead, precisely so this default cannot apply to them by
     * accident.
     */
    public const ChannelShape SHAPE = ChannelShape::Occurrence;

    /**
     * Every subclass that does not override {@see analyze()} emits its
     * channel through the loop below with a fixed `1.0` occurrence marker
     * projected by {@see CodeSmellFinding} — never a
     * measured magnitude — so `occurrence` is the correct shape for all of
     * them uniformly. `static::NAME` resolves per concrete subclass via
     * late static binding, exactly as {@see getName()} above already relies
     * on; {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader} reads this
     * method via reflection on the concrete rule class, so the binding
     * target is correct without any special-casing on the reader's side.
     *
     * A subclass whose shape genuinely differs from this base emission
     * overrides this method instead of inheriting it — none currently do
     * (every subclass of this base is occurrence-shaped); if one starts
     * overriding `analyze()` to emit a real magnitude, it must also override
     * this method, or the drift guard will catch the mismatch against
     * `tests/Analysis/Finding/Fixtures/Channels/declared.txt`.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            static::NAME => ChannelDeclaration::occurrence(SymbolLevel::Callable),
        ];
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
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        $findings = [];
        $type = static::SMELL_TYPE;

        foreach ($context->metrics->all(SymbolLevel::File) as $fileInfo) {
            $metrics = $context->metrics->get($fileInfo->symbolPath);
            $entries = $metrics->entries("codeSmell.{$type}");

            foreach ($entries as $entry) {
                if (!$this->shouldIncludeEntry($entry)) {
                    continue;
                }

                $file = $fileInfo->file ?? throw new LogicException('File symbol must carry a relative path');
                $findings[] = CodeSmellFinding::fromEntry($entry, $file)->toFinding(
                    $fileInfo->symbolPath,
                    static::NAME,
                    static::SMELL_TYPE,
                    static::SEVERITY,
                    $this->buildMessage($entry),
                    static::RECOMMENDATION,
                );
            }
        }

        return $findings;
    }

    /**
     * Filters entries before finding creation.
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
     * Builds the finding message for a single entry.
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
