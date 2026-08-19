<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\ExitPolicy;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

/**
 * A configuration error does not go through `fail_on` at all.
 *
 * The rejected design said "its severity must be at least Warning", which
 * reads like a fix and is not one: the default `fail_on` is `error`, so a
 * Warning never reaches the threshold and the build stays green. These cases
 * pin the model that replaced it — the comparison is skipped, not tuned —
 * across every setting of `fail_on`, including `none`, which is the one a
 * user reaches for precisely to stop being told about findings.
 */
#[CoversClass(ExitCodeResolver::class)]
final class ExitCodeResolverConfigurationErrorTest extends TestCase
{
    private const string CHANNEL = 'architecture.coverage';

    #[Test]
    public function itFailsTheRunOnAConfigurationErrorWhateverFailOnSays(): void
    {
        $resolver = self::resolver();
        $violations = [self::finding(Severity::Error)];

        foreach ([null, new ExitPolicy(Severity::Warning), new ExitPolicy(Severity::Error), new ExitPolicy(false)] as $policy) {
            self::assertNotSame(
                0,
                $resolver->resolve($violations, null, $policy),
                'A configuration error must fail the run regardless of the fail_on setting, including "none".',
            );
        }
    }

    /**
     * The same finding on an ordinary channel is exactly what `fail_on:
     * none` is for — without this, the case above would pass for a resolver
     * that simply ignored `fail_on` altogether.
     */
    #[Test]
    public function itStillHonoursFailOnForAnOrdinaryDebtFinding(): void
    {
        $violations = [self::finding(Severity::Error, 'code-smell.goto')];

        self::assertSame(0, self::resolver()->resolve($violations, null, new ExitPolicy(false)));
        self::assertNotSame(0, self::resolver()->resolve($violations, null, null));
    }

    /**
     * The severity floor, enforced where it can actually be observed rather
     * than asserted in prose: a producer that reported a configuration error
     * as `Info` would be printing a weight the finding does not have, since
     * the run fails on it either way.
     */
    #[Test]
    public function itRefusesAConfigurationErrorReportedBelowWarning(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('severity "info"');

        self::resolver()->resolve([self::finding(Severity::Info)], null, null);
    }

    private static function resolver(): ExitCodeResolver
    {
        $registry = StubChannelDeclarationRegistry::withDefaults();
        $registry->declare(
            self::CHANNEL . '#' . self::CHANNEL,
            ChannelDeclaration::configurationError(),
        );

        return new ExitCodeResolver($registry);
    }

    private static function finding(Severity $severity, string $channel = self::CHANNEL): Violation
    {
        return new Violation(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: $channel,
            violationCode: $channel,
            message: 'finding',
            severity: $severity,
        );
    }
}
