<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\RuleOptionKeys;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * No file in `src/` but `RuleOptionSurface` asks an options class which keys it
 * accepts.
 *
 * **The defect this closes, which shipped.** Two sides of the product answer
 * "what options does this rule take?": the refusal that lists the allowed set,
 * and `bin/qmx rules` that advertises it. They answered from different places —
 * the refusal from the declarations, the listing from CLI aliases — so they
 * disagreed by construction, and 54 of 132 real options were named nowhere a
 * reader looks. ADR 0063 gave the question one owner; this keeps it one.
 *
 * **Why a census and not an allow-list.** The population is already exactly one
 * file: both remaining calls are inside `RuleOptionSurface` itself. A guard
 * with nothing to enumerate cannot go stale the way an allow-list does — the
 * sole-reader guard next door
 * ({@see \Qualimetrix\Governance\SuppressionOptionKeys\SuppressionOptionKeyReaderCensusTest})
 * records what its earlier, list-shaped form let hide. A second reader here is
 * not forbidden on taste: it is how the two answers drifted apart the first
 * time, and it would drift silently, because nothing downstream compares them
 * until someone reads the listing.
 *
 * **What this deliberately does not say.** Not that the declaration is right —
 * {@see DeclaredOptionKeysCoverReadKeysTest} owns that, by walking `fromArray()`
 * and comparing. Not that a consumer of `RuleMetadata::$aliases` prints its
 * target canonically: the aliases have two legitimate readers doing different
 * acts, so that question has no list-free shape and is left to review rather
 * than guarded by a list this file would have to carry.
 *
 * **Named blind spot.** The recogniser is textual, so a call reached through
 * reflection or a variable method name is invisible to it. Sweeping `src/` for
 * `->{$`, `call_user_func` and `ReflectionMethod` against the options classes
 * yields nothing today, and the guard below would not see it if it did.
 */
final class RuleOptionDeclarationReaderCensusTest extends TestCase
{
    /** The only file in `src/` allowed to ask an options class for its key set. */
    private const string READER = 'src/Analysis/Finding/Contract/Rule/RuleOptionSurface.php';

    /**
     * A call to the declaration, static or instance, on anything but the
     * declaring class itself.
     *
     * The declaration site — `public static function acceptedOptionKeys()` in
     * every options class — is not a call and is excluded by requiring the
     * arrow or the double colon before the name.
     */
    private const string ASKS_THE_DECLARATION = '/(?:->|::)acceptedOptionKeys\s*\(/';

    #[Test]
    public function itIsTheOnlyPlaceInSourceThatAsksAnOptionsClassForItsKeys(): void
    {
        $root = \dirname(__DIR__, 2);
        $readers = [];

        /** @var SplFileInfo $file */
        foreach (new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')),
            '/\.php$/',
        ) as $file) {
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, $file->getPathname());

            if (preg_match(self::ASKS_THE_DECLARATION, self::withoutComments($source)) === 1) {
                $readers[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        sort($readers);

        self::assertSame([self::READER], $readers, \sprintf(
            'An options class is asked for its key set outside %s. That is how the refusal and the `rules`'
            . ' listing came to answer "what options does this rule take?" differently, and it drifts'
            . ' silently: ask RuleOptionSurface instead.',
            self::READER,
        ));
    }

    /**
     * The token stream without comments.
     *
     * Taken from the tokens rather than by a regular expression over the text,
     * because every docblock that *explains* this seam names the method — the
     * ones in `RuleOptionKeySet`, in the capability READMEs' sibling classes —
     * and a guard that counted those would name a dozen files that ask nothing.
     */
    private static function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
