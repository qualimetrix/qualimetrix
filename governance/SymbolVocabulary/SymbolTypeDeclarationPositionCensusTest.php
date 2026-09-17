<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SymbolVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Reporting\Formatter\MetricsJsonFormatter;
use ReflectionClass;

/**
 * Split off from `MetricsJsonFormatterTest` (which keeps the behavioural
 * export cases): a declaration kind absent from the publication order is
 * dropped from the export, so `DECLARATION_KINDS` must stay exactly
 * {@see SymbolType::cases()}.
 */
#[CoversClass(MetricsJsonFormatter::class)]
final class SymbolTypeDeclarationPositionCensusTest extends TestCase
{
    #[Test]
    public function itGivesEveryDeclarationKindAPublicationPosition(): void
    {
        $kinds = (new ReflectionClass(MetricsJsonFormatter::class))->getConstant('DECLARATION_KINDS');

        self::assertIsArray($kinds);
        self::assertEqualsCanonicalizing(SymbolType::cases(), $kinds);
    }
}
