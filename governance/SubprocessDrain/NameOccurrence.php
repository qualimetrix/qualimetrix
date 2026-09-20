<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SubprocessDrain;

use PhpToken;

/**
 * One place in a PHP file where a name is written, as the controls in this
 * group find it.
 *
 * It is one class rather than a copy per control for the reason
 * {@see PhpFilePopulation} already states about the population: two controls
 * that each computed this would agree today and drift apart on the next change
 * to either. That is not hypothetical here. Both controls were edited twice in
 * a row while their copies of this were byte-identical, and the second edit —
 * reading the spelling out of the original instead of answering a boolean —
 * had to be discovered twice, because fixing one copy said nothing about the
 * other.
 *
 * ## Case is folded, and the fold has to preserve byte length
 *
 * PHP resolves function, class and method names without regard to case, so a
 * case-sensitive search is one spelling away from blind. The file is lowercased
 * once and the names are searched in that copy.
 *
 * Every other field is then read back out of the *original* at the offset found
 * in the folded copy — the line, the token, and the bytes the file actually
 * carries. That is why the fold must be `strtolower` and not `mb_strtolower`:
 * since PHP 8.2 `strtolower` maps `A`-`Z` and nothing else, so it cannot change
 * a length on any input, while a multi-byte fold can and then every offset is
 * read early. That is a language guarantee rather than a property of this tree.
 *
 * `spelled` exists so a wrong fold is observable rather than arithmetic: it
 * comes back shifted the moment an offset is off, whatever the surrounding
 * text. A control asserting only a boolean cannot see that — a shifted offset
 * still lands on some token, and whether the answer flips depends on how far
 * the next token boundary happens to be.
 *
 * ## Only a comment is excused
 *
 * A name inside a comment token cannot execute, so it is not an occurrence.
 * Nothing else is excused, and a string literal least of all: the tree holds a
 * `proc_open` inside a nowdoc that a spawned `php -r` runs, so a scan that
 * exempted literals would exempt precisely the live one.
 *
 * The token is reported rather than interpreted. What a caller makes of a name
 * sitting in a `T_STRING` or in an encapsed string is that caller's subject,
 * not this one's.
 *
 * ## The names are given in lower case
 *
 * They are the folded spelling, because that is what is searched for. A caller
 * passing `Proc_Open` would match nothing, which is why the callers spell their
 * names in halves and in lower case, and why {@see self::findIn()} is the only
 * thing in this group that lowercases anything.
 */
final class NameOccurrence
{
    private function __construct(
        /** The name as the caller spelled it: lower case, and what `spelled` folds to. */
        public string $name,
        /** The bytes the file carries at this offset, which need not be `$name`. */
        public string $spelled,
        public int $line,
        /** The token this occurrence sits in, or null past the last token. */
        public ?PhpToken $token,
    ) {}

    /**
     * Every occurrence of any of `$names`, outside comments, in source order
     * per name.
     *
     * @param list<string> $names lower-case; anything else matches nothing
     *
     * @return list<self>
     */
    public static function findIn(string $contents, array $names): array
    {
        $folded = strtolower($contents);
        $found = [];
        $tokens = null;

        foreach ($names as $name) {
            if (!str_contains($folded, $name)) {
                continue;
            }

            $tokens ??= PhpToken::tokenize($contents);
            $offset = 0;

            while (($at = strpos($folded, $name, $offset)) !== false) {
                $offset = $at + \strlen($name);
                $token = self::tokenAt($tokens, $at);

                if ($token !== null && $token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                    continue;
                }

                $found[] = new self(
                    $name,
                    substr($contents, $at, \strlen($name)),
                    substr_count($contents, "\n", 0, $at) + 1,
                    $token,
                );
            }
        }

        return $found;
    }

    /**
     * The token the byte at `$offset` belongs to. A token's own `line` is where
     * it starts, which for a nowdoc is several lines above the text inside it,
     * so the line is counted from the offset instead and the token is consulted
     * only for what kind of thing the occurrence sits in.
     *
     * @param array<PhpToken> $tokens
     */
    private static function tokenAt(array $tokens, int $offset): ?PhpToken
    {
        foreach ($tokens as $token) {
            if ($token->pos > $offset) {
                return null;
            }

            if ($offset < $token->pos + \strlen($token->text)) {
                return $token;
            }
        }

        return null;
    }
}
