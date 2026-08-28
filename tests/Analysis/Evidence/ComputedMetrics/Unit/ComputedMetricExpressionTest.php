<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricConfigurationException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricExpression;

/**
 * The reader that replaced a pattern over formula text.
 *
 * Every refused shape gets its own case. A single assertion over a list passes
 * as soon as the FIRST entry throws, which is how the pattern this replaced
 * looked correct while `m .offsetGet("k")` and `m ["k"]` walked past it: both
 * were found by review, not by a green test over a list.
 */
#[CoversClass(ComputedMetricExpression::class)]
final class ComputedMetricExpressionTest extends TestCase
{
    private ComputedMetricExpression $expression;

    protected function setUp(): void
    {
        $this->expression = new ComputedMetricExpression();
    }

    #[Test]
    #[DataProvider('accessesThatAreNotALiteralIndex')]
    public function itRefusesAnAccessThatIsNotALiteralIndex(string $formula, string $why): void
    {
        self::expectException(ComputedMetricConfigurationException::class);
        self::expectExceptionMessage('reaches "m" by something other than a quoted metric key');

        $this->expression->assertEveryAccessIsALiteralIndex($formula, 'health.test');
        self::fail($why);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function accessesThatAreNotALiteralIndex(): iterable
    {
        yield 'a method call on the lookup' => [
            'm.offsetGet("complexity.ccn")',
            'ArrayAccess is public, so the parser accepts a method call and the key becomes invisible',
        ];
        yield 'a method call with a space' => [
            'm .offsetExists("complexity.ccn")',
            'whitespace is nothing to the parser and was everything to the pattern',
        ];
        yield 'an index built by concatenation' => [
            'm["complexity." ~ "ccn"]',
            'a computed index names no key that can be checked',
        ];
        yield 'an index that is another lookup' => [
            'm[m["complexity.ccn"]]',
            'the outer index is a value, not a key',
        ];
        yield 'the lookup handed to a function' => [
            'max(m, 1)',
            'a formula that passes the whole lookup around reaches keys nothing enumerated',
        ];
        yield 'the lookup guarded on its own' => [
            '(m ?? 0)',
            'the variable itself is not an access to a metric',
        ];
    }

    /**
     * A space between `m` and its index is not a defect — the parser does not
     * see it. This is the counterweight to the cases above: the refusal has to
     * be about the SHAPE of the access, not about how it was typed.
     */
    #[Test]
    public function itAcceptsALiteralIndexHoweverItIsSpaced(): void
    {
        $this->expression->assertEveryAccessIsALiteralIndex('m ["complexity.ccn"] + m[ \'size.loc\' ]', 'health.test');

        self::assertSame(
            ['complexity.ccn', 'size.loc'],
            $this->expression->keysOf('m ["complexity.ccn"] + m[ \'size.loc\' ]'),
        );
    }

    /**
     * The reason this reads the tree rather than the text: whether a key is
     * required is a fact about each occurrence, and a name-keyed pattern cannot
     * hold two answers for one name.
     */
    #[Test]
    public function itRequiresAKeyThatIsGuardedInOnePlaceAndBareInAnother(): void
    {
        $formula = 'm["complexity.ccn"] * 2 + (m["complexity.ccn"] ?? 0)';

        self::assertSame(['complexity.ccn'], $this->expression->keysOf($formula));
        self::assertSame(['complexity.ccn'], $this->expression->requiredKeysOf($formula));
    }

    #[Test]
    public function itDoesNotRequireAKeyGuardedEverywhere(): void
    {
        self::assertSame([], $this->expression->requiredKeysOf('(m["complexity.ccn"] ?? 0) * 2'));
    }

    #[Test]
    public function itReadsOnlyComputedMetricReferences(): void
    {
        $formula = '(m["health.complexity"] ?? 75) * 0.5 + (m["computed.density"] ?? 0) + m["size.loc"]';

        self::assertSame(
            ['health.complexity', 'computed.density'],
            $this->expression->computedReferencesOf($formula),
        );
    }
}
