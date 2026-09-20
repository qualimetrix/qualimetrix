<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SubprocessDrain;

use InvalidArgumentException;
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
 * ## Callers require this file by path
 *
 * The group already asks that of the subprocess module, for a reason that
 * applies here word for word: an isolated scratch project symlinks `vendor/`,
 * whose PSR-4 map holds absolute paths back into the source tree, so an
 * autoloaded class resolves *there* and the copy under test is never read.
 * That rule used to cover only the module, and moving this mechanism out of the
 * controls — which PHPUnit loads by path — would have quietly put it back in
 * reach of that hazard. Measured both ways on such a stand: autoloaded, a
 * mutation of the copy is ignored and the stand is green on a broken scan;
 * required by path, `__DIR__` points into the copy, this file is declared
 * first, and the mutation is what runs.
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
 * They are the folded spelling, because that is what is searched for. A name
 * in any other case could never match, and a name that is empty would match at
 * every offset while advancing by nothing — so both are refused rather than
 * answered with an empty result. Silence there is the expensive kind: the
 * caller asked where a name occurs, and "nowhere" is a truthful answer to a
 * question that was never asked. Measured within the hour this was written: a
 * needle spelled `tokenAt(` in a control here matched nothing, and it took a
 * failing positive case to find out.
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
     * @param list<string> $names already folded, and not empty
     *
     * @throws InvalidArgumentException a name this scan could never match, so
     *                                  that a caller hears about it instead of
     *                                  being answered with silence
     *
     * @return list<self>
     */
    public static function findIn(string $contents, array $names): array
    {
        foreach ($names as $name) {
            if ($name === '') {
                throw new InvalidArgumentException(
                    'An empty name matches at every offset and advances by nothing, so this scan would spin '
                    . 'instead of answering. Hanging rather than failing is the defect this group exists to '
                    . 'refuse; it does not get to arrive through the scan itself.',
                );
            }

            if ($name !== strtolower($name)) {
                throw new InvalidArgumentException(
                    'The name "' . $name . '" is searched for in a lowercased copy of the file, so a name that '
                    . 'is not already folded matches nothing anywhere. Answering that with an empty result is '
                    . 'silence where a caller asked a question: pass the folded spelling.',
                );
            }
        }

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
