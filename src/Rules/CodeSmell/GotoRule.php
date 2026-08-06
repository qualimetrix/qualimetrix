<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\ViolationChannel;

/**
 * Detects usage of goto statement.
 *
 * The goto statement should be avoided as it makes code flow hard to follow
 * and can lead to spaghetti code.
 */
final class GotoRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.goto';
    protected const string DESCRIPTION = 'Detects usage of goto statement';
    protected const string SMELL_TYPE = 'goto';
    protected const Severity SEVERITY = Severity::Error;
    protected const string MESSAGE_TEMPLATE = 'goto statement detected - avoid using goto';
    protected const ?string RECOMMENDATION = 'Replace goto with structured control flow (loops, early returns).';

    /**
     * `code-smell.goto` reports a fixed `1.0` marker as `metricValue` — see
     * the shared emission in {@see AbstractCodeSmellRule::analyze()} — not a
     * measured magnitude. Only the number of occurrences in a group matters
     * (§5.1 of the baseline ceiling plan), which is exactly the `occurrence`
     * shape.
     *
     * The other eight rules sharing that same base-class emission
     * (`code-smell.boolean-argument`, `.count-in-loop`, `.debug-code`,
     * `.empty-catch`, `.error-suppression`, `.eval`, `.exit`,
     * `.superglobals`) are structurally identical and would take the same
     * declaration, but are deliberately left undeclared here — not
     * individually verified at their own channel by this package.
     *
     * Keyed by the full channel key (`ruleName#violationCode`), not a bare
     * `violationCode` — both halves happen to equal `self::NAME` here, but
     * the reader accepts only the full form (see
     * {@see \Qualimetrix\Core\Rule\ChannelDeclarationReader}'s docblock for
     * why a shorthand pairing with the declaring rule's own name is not
     * offered).
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::occurrence(),
        ];
    }
}
