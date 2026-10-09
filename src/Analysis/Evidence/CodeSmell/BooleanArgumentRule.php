<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\FlagExcludes;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Detects boolean arguments in method/function signatures.
 *
 * Boolean arguments often indicate that a method does too many things
 * and should be split into separate methods. They harm readability
 * since the caller must know what `true` or `false` means.
 *
 * Supports `allowed_prefixes` option to whitelist self-documenting
 * boolean parameters (e.g., $isActive, $hasPermission).
 *
 * Bad:  function save(bool $overwrite) {}
 * Good: function save() {} and function saveOverwriting() {}
 */
final class BooleanArgumentRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.boolean-argument';
    public const string DOCS_PAGE = 'rules/code-smell.md';
    public const int REMEDIATION_MINUTES = 10;
    protected const string DESCRIPTION = 'Detects boolean arguments in method/function signatures';
    protected const string SMELL_TYPE = 'boolean_argument';
    protected const Severity SEVERITY = Severity::Warning;
    protected const string MESSAGE_TEMPLATE = 'Boolean argument detected - consider splitting methods or using enums';
    protected const ?string MESSAGE_TEMPLATE_WITH_EXTRA = 'Boolean argument $%s detected - consider splitting methods or using enums';
    protected const ?string RECOMMENDATION = 'Replace boolean parameter with two explicit methods or use an enum.';
    protected const bool FILE_OCCURRENCES = true;

    /**
     * @return class-string<BooleanArgumentOptions>
     */
    public static function getOptionsClass(): string
    {
        return BooleanArgumentOptions::class;
    }

    public static function channelDeclarations(): array
    {
        $declaration = parent::channelDeclarations()[self::NAME];
        $gates = $declaration->populationGates;
        foreach ([SymbolLevel::Callable, SymbolLevel::File] as $level) {
            $gates[] = new PopulationGate('flag-promoted-properties', new FindingChannel(self::NAME), $level, 'occurrence', new FlagExcludes('flag-promoted-properties', null, true, false), 'Promoted constructor properties are excluded by configuration.');
        }
        return [self::NAME => $declaration->withGates(...$gates)];
    }

    /** @param array<string, mixed> $entry
     * @return iterable<GateInput>
     */
    protected function populationInputs(array $entry): iterable
    {
        yield from parent::populationInputs($entry);
        $options = $this->options;
        $flagPromoted = !$options instanceof BooleanArgumentOptions || $options->flagPromotedProperties;
        yield GateInput::flag('flag-promoted-properties', $flagPromoted ? null : ($entry['promoted'] ?? false) === true, $flagPromoted);
    }
}
