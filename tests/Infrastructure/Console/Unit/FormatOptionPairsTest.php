<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Console\FormatOptionPairs;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;

#[CoversClass(FormatOptionPairs::class)]
final class FormatOptionPairsTest extends TestCase
{
    /** @return iterable<string, array{list<string>}> */
    public static function provideTwoSpellingsOfOneValue(): iterable
    {
        yield 'violations, then limit' => [['violations=2', 'limit=3']];
        yield 'limit, then violations' => [['limit=3', 'violations=2']];
        yield 'both meaning everything' => [['violations=all', 'limit=all']];
    }

    /**
     * `violations` and `limit` both bound the JSON finding list. Written
     * together, one was silently ignored; which one was a rule of the reader
     * the caller could not see.
     *
     * @param list<string> $written
     */
    #[Test]
    #[DataProvider('provideTwoSpellingsOfOneValue')]
    public function itRefusesTwoSpellingsOfOneValue(array $written): void
    {
        try {
            $this->pairs()->resolve($written);
            self::fail('Two spellings of one value must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('"violations" and "limit" set one value', $refusal->getMessage());
            self::assertSame('--format-opt', $refusal->origin()->locator());
        }
    }

    /** `--all` writes `violations=all`, so a written `limit` is the same conflict. */
    #[Test]
    public function itRefusesTheAllFlagBesideTheOtherSpelling(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('--all cannot be combined with --format-opt=limit=N');

        $this->pairs()->resolveUnderAllFlag(['limit=5']);
    }

    #[Test]
    public function itAcceptsEachSpellingOnItsOwn(): void
    {
        self::assertSame(['limit' => '5'], $this->pairs()->resolve(['limit=5']));
        self::assertSame(['violations' => '0'], $this->pairs()->resolve(['violations=0']));
        self::assertSame(['violations' => 'all'], $this->pairs()->resolveUnderAllFlag(['violations=all']));
        self::assertSame(['top' => '3', 'violations' => 'all'], $this->pairs()->resolveUnderAllFlag(['top=3']));
    }

    private function pairs(): FormatOptionPairs
    {
        $registry = self::createStub(FormatterRegistryInterface::class);
        $registry->method('declaredFormatOptionKeys')->willReturn(['limit', 'top', 'violations']);

        return new FormatOptionPairs($registry);
    }
}
