<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;

/**
 * Reads the documentation page a rule class declares for itself.
 *
 * Reflection-based, on a class-string without instantiation — the same
 * idiom {@see RuleNameReader} already establishes, and for the same reason:
 * rules may take constructor dependencies beyond their Options object, so
 * instantiating one outside the DI container is never safe. Ownership
 * validation (declared on the class itself, not inherited) is
 * {@see RuleOwnConstantReader}'s job, shared with
 * {@see RuleRemediationMinutesReader}.
 *
 * The value is a path relative to `website/docs/` (e.g. `rules/complexity.md`),
 * chosen so a single string deterministically yields both the file the guard
 * reads (`website/docs/{value}`) and the URL segment a consumer builds a
 * `helpUri` from (`{site root}/{value}` with the `.md` extension replaced by
 * a trailing slash, matching the site's clean-URL routing).
 *
 * Deliberately **not** derived from {@see RuleCategory} or from the rule
 * name's dot-separated prefix: two registered rules break any such
 * derivation outright —
 *
 * - `cohesion.lcom` documents at `rules/cohesion.md`;
 * - the computed-metric family documents at `reference/health-scores.md`, entirely
 *   outside `rules/` — no category value and no prefix rewrite can produce
 *   that path.
 *
 * Both are named here so a future reader who notices "every other rule's
 * page is just `rules/{prefix}.md`" does not fold this reader back into a
 * derivation and delete the one case it exists to handle.
 */
final class RuleDocsPageReader
{
    private const string CONSTANT = 'DOCS_PAGE';

    /**
     * @throws LogicException when the class cannot be loaded, declares no
     *                        string `DOCS_PAGE` constant, or the constant it
     *                        exposes is only inherited (from
     *                        {@see \Qualimetrix\Analysis\Evidence\CodeSmell\AbstractCodeSmellRule}
     *                        or {@see \Qualimetrix\Analysis\Evidence\Security\AbstractSecurityPatternRule})
     *                        rather than declared on the class itself
     */
    public static function read(string $ruleClass): string
    {
        $constant = RuleOwnConstantReader::read(
            $ruleClass,
            self::CONSTANT,
            \sprintf(
                'a string %s constant naming its documentation page (e.g. "rules/complexity.md"), relative to website/docs/',
                self::CONSTANT,
            ),
        );

        $value = $constant->getValue();

        if (!\is_string($value) || $value === '') {
            throw new LogicException(\sprintf(
                'Rule class %s must declare a non-empty string %s constant.',
                $ruleClass,
                self::CONSTANT,
            ));
        }

        return $value;
    }
}
