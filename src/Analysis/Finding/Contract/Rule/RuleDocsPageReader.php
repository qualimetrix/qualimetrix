<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;
use ReflectionClass;

/**
 * Reads the documentation page a rule class declares for itself.
 *
 * Reflection-based, on a class-string without instantiation — the same
 * idiom {@see RuleNameReader} already establishes for `NAME`, and for the
 * same reason: rules may take constructor dependencies beyond their Options
 * object, so instantiating one outside the DI container is never safe.
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
 * - `computed.health` documents at `reference/health-scores.md`, entirely
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
        if (!class_exists($ruleClass)) {
            throw new LogicException(\sprintf(
                'Rule class %s does not exist or cannot be autoloaded.',
                $ruleClass,
            ));
        }

        $reflection = new ReflectionClass($ruleClass);

        if (!$reflection->hasConstant(self::CONSTANT)) {
            throw new LogicException(\sprintf(
                'Rule class %s must declare a string %s constant naming its documentation page'
                . ' (e.g. "rules/complexity.md"), relative to website/docs/.',
                $ruleClass,
                self::CONSTANT,
            ));
        }

        $constant = $reflection->getReflectionConstant(self::CONSTANT);

        if ($constant === false || $constant->getDeclaringClass()->getName() !== $ruleClass) {
            throw new LogicException(\sprintf(
                'Rule class %s must declare its own %s constant rather than inherit one from %s. An inherited'
                . ' placeholder (e.g. AbstractCodeSmellRule\'s empty string) is not a declaration.',
                $ruleClass,
                self::CONSTANT,
                $constant !== false ? $constant->getDeclaringClass()->getName() : '(unknown)',
            ));
        }

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
