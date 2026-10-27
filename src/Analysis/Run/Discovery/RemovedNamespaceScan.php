<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use PhpToken;
use Qualimetrix\Core\Path\AbsolutePath;
use RuntimeException;
use SplFileObject;

/**
 * Reads the namespaces one removed file declares, without parsing it.
 *
 * A file of at most TOKENIZED_BYTES is read from its token stream: only
 * `namespace` statements count, so comments, strings and `namespace\foo()`
 * cannot be mistaken for one. A larger file is read as text, because its
 * token stream costs up to 160 bytes of heap per source byte.
 *
 * Code outside any statement, or in a braced `namespace { }` block, declares
 * the empty namespace.
 */
final class RemovedNamespaceScan
{
    public const int TOKENIZED_BYTES = 65536;

    private const string IDENTIFIER = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/';

    /**
     * @param int $byteAllowance bytes this read may consume; it is reduced by what was read
     *
     * @return list<string>|null namespaces as written, '' for global code; null when the file cannot be read
     *                           within the allowance or a `namespace` statement is not recognised
     */
    public static function declaredIn(AbsolutePath|string $file, int &$byteAllowance = \PHP_INT_MAX): ?array
    {
        $path = $file instanceof AbsolutePath ? $file->value() : $file;
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        try {
            $text = new RemovedNamespaceText(new SplFileObject($path, 'rb'), $byteAllowance);
        } catch (RuntimeException) {
            return null;
        }

        try {
            $head = $text->read(self::TOKENIZED_BYTES + 1);
            if ($head === null) {
                return null;
            }

            return \strlen($head) <= self::TOKENIZED_BYTES ? self::fromTokens($head) : $text->namespacesAfter($head);
        } finally {
            $byteAllowance = $text->bytesLeft();
        }
    }

    /** @return list<string>|null */
    private static function fromTokens(string $source): ?array
    {
        // Tokenizing is the cost of the scan; a file that never spells the keyword cannot declare a namespace.
        if (stripos($source, 'namespace') === false) {
            return [''];
        }
        $tokens = PhpToken::tokenize($source);
        $declared = [];
        foreach ($tokens as $at => $token) {
            if ($token->id !== \T_NAMESPACE) {
                continue;
            }
            $name = self::declaredBy($tokens, $at);
            if ($name === null) {
                return null;
            }
            if ($name !== false) {
                $declared[$name] = true;
            }
        }

        return $declared === [] ? [''] : array_map(strval(...), array_keys($declared));
    }

    /**
     * @param array<int, PhpToken> $tokens
     *
     * @return string|false|null the declared name; false when `namespace` is a member,
     *                           argument or constant name; null for a statement this cannot read
     */
    private static function declaredBy(array $tokens, int $at): string|false|null
    {
        $name = self::nameIn($tokens[self::significant($tokens, $at, 1)] ?? null);
        if ($name !== null) {
            return $name;
        }

        return self::startsStatement($tokens[self::significant($tokens, $at, -1)] ?? null) ? null : false;
    }

    /** A semi-reserved keyword is a valid single-segment name, so any identifier spelling counts, whatever token the keyword became. */
    private static function nameIn(?PhpToken $token): ?string
    {
        return match (true) {
            $token === null => null,
            $token->is('{') => '',
            $token->id === \T_NAME_QUALIFIED, preg_match(self::IDENTIFIER, $token->text) === 1 => $token->text,
            default => null,
        };
    }

    private static function startsStatement(?PhpToken $previous): bool
    {
        return $previous === null || $previous->is([\T_OPEN_TAG, \T_CLOSE_TAG, \T_INLINE_HTML, ';', '{', '}']);
    }

    /** @param array<int, PhpToken> $tokens */
    private static function significant(array $tokens, int $from, int $step): int
    {
        for ($at = $from + $step; isset($tokens[$at]); $at += $step) {
            if (!$tokens[$at]->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                return $at;
            }
        }

        return -1;
    }
}
