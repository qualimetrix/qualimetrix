<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\DebugCodeRule;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use ReflectionClass;

#[CoversClass(DebugCodeRule::class)]
final class DebugCodeDocumentationConsistencyTest extends TestCase
{
    #[Test]
    public function documentedSeverityMatchesEffectiveSeverity(): void
    {
        $doc = file_get_contents(\dirname(__DIR__, 5) . '/website/docs/rules/code-smell.md');
        self::assertIsString($doc);

        self::assertSame(
            1,
            preg_match('/code-smell\.debug-code`\s*\*\*Severity:\*\*\s*(\w+)/', $doc, $matches),
        );

        $documented = $matches[1];
        $effective = (new ReflectionClass(DebugCodeRule::class))->getConstant('SEVERITY');
        self::assertInstanceOf(Severity::class, $effective);

        self::assertSame($documented, $effective->displayName());
    }
}
