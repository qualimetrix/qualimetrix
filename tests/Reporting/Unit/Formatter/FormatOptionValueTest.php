<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Formatter;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\Formatter\FormatOptionValue;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;

#[CoversClass(FormatOptionValue::class)]
final class FormatOptionValueTest extends TestCase
{
    /**
     * A declared key without a grammar would reach the door's LogicException
     * on the first run that used it; this names it before any run does.
     */
    #[Test]
    public function itWritesAGrammarForEveryKeyAFormatterDeclares(): void
    {
        /** @var FormatterRegistryInterface $registry */
        $registry = (new ContainerFactory())->create()->get(FormatterRegistryInterface::class);
        $declared = $registry->declaredFormatOptionKeys();
        $grammar = FormatOptionValue::keys();
        sort($declared);
        sort($grammar);

        self::assertSame($declared, $grammar);
    }

    #[Test]
    public function itParsesTheLegitimateFormsOfEveryGrammar(): void
    {
        self::assertSame(0, FormatOptionValue::count('contributors', '0'));
        self::assertSame(12, FormatOptionValue::count('contributors', '12'));
        self::assertSame(1, FormatOptionValue::positive('top', '1'));
        self::assertNull(FormatOptionValue::limit('violations', 'all'));
        self::assertSame(0, FormatOptionValue::limit('violations', '0'));
        self::assertSame('density', FormatOptionValue::rankBy('density'));
        self::assertSame('count', FormatOptionValue::rankBy('count'));
        self::assertNull(FormatOptionValue::problem('project-name', 'acme/app'));
    }

    #[Test]
    public function itRefusesToReadAValueTheDoorShouldHaveRefused(): void
    {
        $this->expectException(LogicException::class);

        FormatOptionValue::rankBy('dnesity');
    }

    #[Test]
    public function itRefusesAKeyWithoutAGrammar(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"nope" is declared by a formatter but has no value grammar');

        FormatOptionValue::problem('nope', '1');
    }
}
