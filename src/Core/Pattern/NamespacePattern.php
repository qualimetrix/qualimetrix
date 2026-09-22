<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Pattern;

use InvalidArgumentException;

/**
 * A selector definition bound once to the PHP namespace separator.
 */
final readonly class NamespacePattern
{
    private const string SEPARATOR = '\\';

    private string $rendered;

    /**
     * @throws InvalidArgumentException when the definition cannot address namespaces
     */
    public function __construct(public SelectorDefinition $definition)
    {
        self::assertNamespaceShape($definition);
        $this->rendered = self::render($definition);
        self::assertCompiles($this->rendered, $definition);
    }

    public function rendered(): string
    {
        return $this->rendered;
    }

    /**
     * @throws SelectorMatchFailure when PCRE cannot complete the match
     */
    public function matches(string $namespace): bool
    {
        $matches = [];
        $result = preg_match($this->rendered, $namespace, $matches, \PREG_OFFSET_CAPTURE);

        if ($result === false) {
            throw new SelectorMatchFailure($this->definition, preg_last_error_msg());
        }

        if ($result !== 1 || !isset($matches[0]) || !\is_array($matches[0])) {
            return false;
        }

        [$matched, $offset] = $matches[0];

        return $offset === 0 && \strlen($matched) === \strlen($namespace);
    }

    private static function assertNamespaceShape(SelectorDefinition $definition): void
    {
        if ($definition->kind === SelectorKind::Regex) {
            return;
        }

        if (str_contains($definition->value, '/')) {
            throw new InvalidArgumentException(\sprintf(
                'Namespace selector "%s" must use "\\" separators',
                $definition->display(),
            ));
        }

        if (str_starts_with($definition->value, self::SEPARATOR)
            || str_ends_with($definition->value, self::SEPARATOR)
            || str_contains($definition->value, self::SEPARATOR . self::SEPARATOR)) {
            throw new InvalidArgumentException(\sprintf(
                'Namespace selector "%s" must not have leading, trailing, or empty namespace segments',
                $definition->display(),
            ));
        }
    }

    private static function render(SelectorDefinition $definition): string
    {
        $body = match ($definition->kind) {
            SelectorKind::Exact => preg_quote($definition->value, '~'),
            SelectorKind::Subtree => preg_quote($definition->value, '~') . '(?:\\\\.+)?',
            SelectorKind::Regex => $definition->value,
        };

        return '~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=1000)\\A(?:' . $body . ')\\z~';
    }

    private static function assertCompiles(string $rendered, SelectorDefinition $definition): void
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = preg_match($rendered, '');
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new InvalidArgumentException(\sprintf(
                'Selector "%s" is not valid PCRE: %s',
                $definition->display(),
                $warning ?? preg_last_error_msg(),
            ));
        }
    }
}
