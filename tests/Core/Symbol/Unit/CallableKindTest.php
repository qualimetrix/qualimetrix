<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\CallableKind;

#[CoversClass(CallableKind::class)]
final class CallableKindTest extends TestCase
{
    /**
     * The separators the values are joined with by the code that reads them.
     *
     * `DerivedCollectorRunner` keys callables by `kind:declaration` and builds
     * derived metric keys as `metric:kind:subject`; `DeclarationControlBindings`
     * builds a source-identity string as `declaration\0kind:syntax`. A value
     * carrying one of these would split a composite key in the wrong place and
     * make two distinct declarations read as one — silently, because every
     * resulting key is still a well-formed string.
     *
     * @var list<string>
     */
    private const array KEY_SEPARATORS = [':', "\0"];

    /**
     * Listing the values here instead would be the same literal the enum
     * carries, and an edit to one is an edit to the other: what is checked is
     * what the values have to be *for*, which the consumers decide.
     */
    #[Test]
    public function itUsesValuesNoCompositeKeyCanSplitInTheWrongPlace(): void
    {
        $unusable = [];

        foreach (CallableKind::cases() as $kind) {
            if ($kind->value === '') {
                $unusable[] = $kind->name . ' has an empty value';
            }

            foreach (self::KEY_SEPARATORS as $separator) {
                if (str_contains($kind->value, $separator)) {
                    $unusable[] = \sprintf(
                        '%s carries %s, which the composite keys built from it use as a separator',
                        $kind->name,
                        var_export($separator, true),
                    );
                }
            }
        }

        self::assertSame([], $unusable, implode("\n", $unusable));
    }

}
