<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\ExitPolicy;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

/**
 * `Severity::Info` is report-only: no `fail_on` setting turns an Info
 * finding into a failing build.
 */
#[CoversClass(ExitCodeResolver::class)]
final class ExitCodeResolverReportOnlyTest extends TestCase
{
    #[Test]
    public function itExitsCleanOnAnInfoOnlyRunUnderEveryFailOnSetting(): void
    {
        $resolver = self::resolver();
        $violations = [self::finding(Severity::Info)];

        foreach ([null, new ExitPolicy(), new ExitPolicy(Severity::Warning), new ExitPolicy(Severity::Error), new ExitPolicy(false)] as $policy) {
            self::assertSame(0, $resolver->resolve($violations, null, $policy));
        }
    }

    /**
     * Without this, the case above would pass for a resolver that returned 0
     * unconditionally.
     */
    #[Test]
    public function itStillFailsOnAWarningUnderTheWarningThreshold(): void
    {
        self::assertSame(
            1,
            self::resolver()->resolve([self::finding(Severity::Warning)], null, new ExitPolicy(Severity::Warning)),
        );
    }

    /**
     * An Info finding alongside a gating one neither raises nor lowers the
     * verdict the gating finding already reached.
     */
    #[Test]
    public function itIgnoresInfoWhenAGatingFindingIsPresent(): void
    {
        $violations = [self::finding(Severity::Info), self::finding(Severity::Error)];

        self::assertSame(2, self::resolver()->resolve($violations, null, new ExitPolicy(Severity::Warning)));
    }

    private static function resolver(): ExitCodeResolver
    {
        return new ExitCodeResolver(StubChannelDeclarationRegistry::withDefaults());
    }

    private static function finding(Severity $severity): Violation
    {
        return new Violation(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: 'code-smell.goto',
            violationCode: 'code-smell.goto',
            message: 'finding',
            severity: $severity,
        );
    }
}
