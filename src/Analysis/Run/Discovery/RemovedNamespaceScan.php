<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use PhpToken;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * Reads the namespaces one removed file declares, without parsing it.
 *
 * Only `namespace` statements are recognised, from the token stream, so
 * comments, strings and `namespace\foo()` cannot be mistaken for one. Code
 * outside any statement, or in a braced `namespace { }` block, declares the
 * empty namespace.
 */
final class RemovedNamespaceScan
{
    /**
     * @return list<string>|null namespaces as written, '' for global code; null when the file cannot be read
     */
    public static function declaredIn(AbsolutePath|string $file): ?array
    {
        $path = $file instanceof AbsolutePath ? $file->value() : $file;
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        // @qmx-ignore-next-line code-smell.error-suppression -- A failed read stays null and makes the removed namespaces unknown; interpreter notices must not precede the report document.
        $source = @file_get_contents($path);
        if ($source === false) {
            return null;
        }

        // Tokenizing is the cost of the scan; a file that never spells the keyword cannot declare a namespace.
        if (stripos($source, 'namespace') === false) {
            return [''];
        }

        return self::declaredNamespaces(PhpToken::tokenize($source));
    }

    /**
     * @param array<int, PhpToken> $tokens
     *
     * @return list<string>
     */
    private static function declaredNamespaces(array $tokens): array
    {
        $declared = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== \T_NAMESPACE) {
                continue;
            }
            $next = self::nextSignificant($tokens, $i + 1);
            if ($next === null) {
                continue;
            }
            if ($tokens[$next]->is('{')) {
                $declared[''] = true;
            } elseif ($tokens[$next]->is([\T_STRING, \T_NAME_QUALIFIED])) {
                $declared[$tokens[$next]->text] = true;
            }
        }

        return $declared === [] ? [''] : array_map(strval(...), array_keys($declared));
    }

    /** @param array<int, PhpToken> $tokens */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from, $count = \count($tokens); $i < $count; $i++) {
            if (!$tokens[$i]->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                return $i;
            }
        }

        return null;
    }
}
