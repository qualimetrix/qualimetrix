<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\PackageVersion;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `bin/qmx`'s interpreter floor against `composer.json`'s `php` constraint.
 *
 * The entry point guards the version before it loads anything, because `src/`
 * uses syntax an older interpreter cannot parse: without the guard the first
 * thing a reader on PHP 8.3 sees is a parse error naming a file they never
 * opened. The guard cannot read the constraint it is enforcing — `composer.json`
 * is in the dist package but deliberately not in the phar, and the guard has to
 * hold there too — so the number is a literal, and a literal that has to agree
 * with another spelling needs something holding the two together.
 *
 * Both sides are read here rather than one being derived from the other on
 * purpose. Deriving would make this control a restatement of one source; what
 * it is for is the case where somebody raises the composer constraint and
 * leaves the binary happily starting up on the version composer now refuses.
 *
 * **What this cannot see.** It reads the *lower bound* of a caret constraint,
 * which is the only shape this project has used. A constraint spelled as a
 * range or a union would need this parser extended, and the assertion below
 * fails rather than guessing, so the gap is loud.
 */
final class EntryPointRefusesTheVersionsComposerRefusesTest extends TestCase
{
    #[Test]
    public function itGuardsTheVersionComposerDeclares(): void
    {
        $declared = self::declaredFloor();
        $guarded = self::guardedFloor();

        self::assertSame(
            $declared,
            $guarded,
            \sprintf(
                'composer.json requires PHP >= %s while bin/qmx starts on anything from %s up. '
                . 'The binary would run where composer refuses to install it, and fail on a parse '
                . 'error instead of the guard.',
                self::spell($declared),
                self::spell($guarded),
            ),
        );
    }

    /** The version id `composer.json`'s `php` constraint refuses below. */
    private static function declaredFloor(): int
    {
        /** @var array{require: array{php?: string}} $manifest */
        $manifest = json_decode(
            (string) file_get_contents(self::projectRoot() . '/composer.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        $constraint = $manifest['require']['php'] ?? '';

        self::assertSame(
            1,
            preg_match('/^\^(\d+)\.(\d+)$/', $constraint, $matches),
            \sprintf(
                'composer.json spells its php constraint as "%s", which this control only knows how to '
                . 'read in the form ^MAJOR.MINOR. Teach it the new shape rather than removing it.',
                $constraint,
            ),
        );

        return ((int) $matches[1]) * 10000 + ((int) $matches[2]) * 100;
    }

    /** The version id `bin/qmx` refuses below. */
    private static function guardedFloor(): int
    {
        $source = (string) file_get_contents(self::projectRoot() . '/bin/qmx');

        self::assertSame(
            1,
            preg_match('/PHP_VERSION_ID\s*<\s*(\d+)/', $source, $matches),
            'bin/qmx no longer guards PHP_VERSION_ID, so an interpreter below the declared floor '
            . 'reaches the autoloader and fails on a parse error instead.',
        );

        return (int) $matches[1];
    }

    private static function spell(int $versionId): string
    {
        return \sprintf('%d.%d', intdiv($versionId, 10000), intdiv($versionId % 10000, 100));
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
