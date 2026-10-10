<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\SuppressionBinding;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Exclusion\ConfiguredSuppression;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

final readonly class UnboundSuppressionFinding
{
    /**
     * What one finding on these channels is about: the value, and — for a
     * per-rule entry — the rule and option it sits under.
     *
     * Without it every finding of a channel shared one baseline identity, and
     * an accepted entry bounded their *number* rather than naming them.
     * Accepting `src/Gone` then accepted `src/AlsoGone` in its place, silently,
     * which is the acceptance these channels exist to end.
     */
    private const string OCCURRENCE_KIND = 'unbound-suppression-value';

    /**
     * @param array{channel: string, rule: ?string, option: string, pattern: PathPattern|NamespacePattern} $value
     */
    public static function forValue(array $value): Finding
    {
        $pattern = $value['pattern'];

        if ($value['rule'] !== null) {
            return self::ledgerFinding($value['rule'], $value['option'], $pattern->definition);
        }

        return $pattern instanceof PathPattern ? self::pathFinding($pattern) : self::namespaceFinding($pattern);
    }

    private static function pathFinding(PathPattern $pattern): Finding
    {
        return self::finding(
            UnboundSuppressionOptions::UNMATCHED_PATH,
            ['option' => ConfiguredSuppression::PATHS, 'pattern' => $pattern->definition->display()],
            \sprintf(
                'The suppress_paths pattern "%s" matched no file analysed by this run, so it suppressed nothing'
                . ' and could not have. If the code it was written for still exists under another spelling, its'
                . ' findings are being reported.',
                $pattern->definition->display(),
            ),
            \sprintf(
                'Check "%s" against the tree. %s Drop the entry if the code it names is gone, or correct its'
                . ' spelling.',
                $pattern->definition->display(),
                self::selectorMeaning($pattern->definition, '/', 'path'),
            ),
        );
    }

    private static function namespaceFinding(NamespacePattern $pattern): Finding
    {
        return self::finding(
            UnboundSuppressionOptions::UNMATCHED_NAMESPACE,
            ['option' => ConfiguredSuppression::NAMESPACES, 'pattern' => $pattern->definition->display()],
            \sprintf(
                'The suppress_namespaces pattern "%s" matched no namespace declared in this run, so it suppressed'
                . ' nothing and could not have. If the code it was written for still exists under another'
                . ' spelling, its findings are being reported.',
                $pattern->definition->display(),
            ),
            \sprintf(
                'Check "%s" against the code. %s Drop the entry if the namespace is gone, or correct its spelling.',
                $pattern->definition->display(),
                self::selectorMeaning($pattern->definition, '\\', 'namespace'),
            ),
        );
    }

    private static function selectorMeaning(
        SelectorDefinition $definition,
        string $separator,
        string $subject,
    ): string {
        return match ($definition->kind->value) {
            'exact' => \sprintf('The "exact" selector matches only the authored %s.', $subject),
            'subtree' => \sprintf(
                'The "subtree" selector also includes descendants across "%s" boundaries.',
                $separator,
            ),
            'regex' => 'The "regex" selector is a full-subject PCRE fragment.',
        };
    }

    private static function ledgerFinding(string $ruleName, string $option, SelectorDefinition $pattern): Finding
    {
        return self::finding(
            UnboundSuppressionOptions::UNMATCHED_RULE_LEDGER,
            ['rule' => $ruleName, 'option' => $option, 'pattern' => $pattern->display()],
            \sprintf(
                'The %s pattern "%s" configured under rule "%s" matched nothing this run analysed, so it suppressed'
                . ' nothing and could not have.',
                $option,
                $pattern->display(),
                $ruleName,
            ),
            \sprintf(
                'Check "%s" under rules.%s.%s against the tree, and drop it if what it names is gone.',
                $pattern->display(),
                $ruleName,
                $option,
            ),
        );
    }

    /**
     * @param array<string, string> $occurrence what this finding is about, for its identity
     */
    private static function finding(string $channel, array $occurrence, string $message, string $recommendation): Finding
    {
        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: $channel,
            code: $channel,
            message: $message,
            severity: Severity::Warning,
            recommendation: $recommendation,
            occurrenceKey: OccurrenceKey::semantic(self::OCCURRENCE_KIND, $occurrence),
        );
    }
}
