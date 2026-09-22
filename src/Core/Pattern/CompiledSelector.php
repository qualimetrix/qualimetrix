<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Pattern;

use InvalidArgumentException;

/** Separator-bound executable form shared by path and namespace selectors. */
final readonly class CompiledSelector
{
    public string $rendered;

    public function __construct(private SelectorDefinition $definition, string $separator)
    {
        $quotedSeparator = preg_quote($separator, '~');
        $body = match ($definition->kind) {
            SelectorKind::Exact => preg_quote($definition->value, '~'),
            SelectorKind::Subtree => preg_quote($definition->value, '~') . '(?:' . $quotedSeparator . '.+)?',
            SelectorKind::Regex => $definition->value,
        };
        $this->rendered = '~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=1000)\A(?:' . $body . ')\z~';
        $this->assertCompiles();
    }

    public static function assertShape(
        SelectorDefinition $definition,
        string $separator,
        string $forbiddenSeparator,
        string $subject,
    ): void {
        if ($definition->kind === SelectorKind::Regex) {
            return;
        }

        if (str_contains($definition->value, $forbiddenSeparator)) {
            throw new InvalidArgumentException(\sprintf(
                '%s selector "%s" must use "%s" separators',
                ucfirst($subject),
                $definition->display(),
                $separator,
            ));
        }

        if (str_starts_with($definition->value, $separator)
            || str_ends_with($definition->value, $separator)
            || str_contains($definition->value, $separator . $separator)) {
            throw new InvalidArgumentException(\sprintf(
                '%s selector "%s" must not have leading, trailing, or empty %s segments',
                ucfirst($subject),
                $definition->display(),
                $subject,
            ));
        }
    }

    /** @throws SelectorMatchFailure when PCRE cannot complete the match */
    public function matches(string $subject): bool
    {
        $matches = [];
        $result = preg_match($this->rendered, $subject, $matches, \PREG_OFFSET_CAPTURE);

        if ($result === false) {
            throw new SelectorMatchFailure($this->definition, preg_last_error_msg());
        }

        if ($result !== 1 || !isset($matches[0]) || !\is_array($matches[0])) {
            return false;
        }

        [$matched, $offset] = $matches[0];

        return $offset === 0 && \strlen($matched) === \strlen($subject);
    }

    private function assertCompiles(): void
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = preg_match($this->rendered, '');
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new InvalidArgumentException(\sprintf(
                'Selector "%s" is not valid PCRE: %s',
                $this->definition->display(),
                $warning ?? preg_last_error_msg(),
            ));
        }
    }
}
