<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Directive\Audit\ExecutionFingerprint;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionClass;
use ReflectionParameter;

/**
 * A field the fingerprint does not read does not exist for the threshold
 * audit: a difference in it reads as "nothing moved", which is the verdict
 * that tells an author to delete an annotation.
 *
 * Two guards, because either alone is satisfiable by a lie. The reflective one
 * proves the two declared lists cover `Finding` — a field added with a default
 * compiles everywhere and would otherwise slip in silently. The behavioural one
 * proves the lists are not decoration: each field, moved on its own, has to
 * move the comparison, and has to move it into the half its list claims.
 */
#[CoversClass(ExecutionFingerprint::class)]
final class ExecutionFingerprintFieldCoverageTest extends TestCase
{
    #[Test]
    public function itNamesEveryFieldAFindingCarries(): void
    {
        $constructor = (new ReflectionClass(Finding::class))->getConstructor();
        self::assertNotNull($constructor);

        $carried = array_map(
            static fn(ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );
        sort($carried);

        $keyed = [...ExecutionFingerprint::IDENTITY_FIELDS, ...ExecutionFingerprint::BOUNDARY_FIELDS];
        sort($keyed);

        self::assertSame(
            $carried,
            $keyed,
            'Every field of a finding is either part of what it is or part of the boundary it names.',
        );
    }

    /**
     * The expectation is read off the declared lists, not written beside each
     * field.
     *
     * That is what makes the two guards cover each other. The reflective one
     * sees a field name disappear from the lists; it cannot see a name **move**
     * between them, because their union does not change. Deriving the
     * expectation here does: a moved name flips what this test demands while
     * the code that reads the field stands still, and the case goes red.
     *
     * @param callable(Finding): Finding $move
     */
    #[Test]
    #[DataProvider('provideMovedFields')]
    public function itSeesEveryFieldItNames(string $field, callable $move): void
    {
        $expected = \in_array($field, ExecutionFingerprint::BOUNDARY_FIELDS, true)
            ? DirectiveEffect::Overrun
            : DirectiveEffect::Effective;

        $before = ExecutionFingerprint::of([self::finding()]);
        $after = ExecutionFingerprint::of([$move(self::finding())]);

        self::assertSame($expected, $before->compareTo($after), \sprintf('moving %s', $field));
        self::assertFalse($before->reproduces($after), \sprintf('moving %s', $field));
    }

    /**
     * @return iterable<string, array{string, callable(Finding): Finding}>
     */
    public static function provideMovedFields(): iterable
    {
        yield 'location' => ['location', static fn(Finding $f): Finding => self::with($f, location: new Location(
            RelativePath::fromString('src/Sample.php'),
            99,
        ))];
        yield 'subject' => ['subject', static fn(Finding $f): Finding => self::with(
            $f,
            subject: self::subject('Other'),
        )];
        yield 'symbolPath' => ['symbolPath', static fn(Finding $f): Finding => self::with(
            $f,
            symbolPath: SymbolPath::forClass('App', 'Other'),
        )];
        yield 'ruleName' => ['ruleName', static fn(Finding $f): Finding => self::with($f, ruleName: 'other.rule')];
        yield 'code' => ['code', static fn(Finding $f): Finding => self::with($f, code: 'other.rule')];
        yield 'severity' => ['severity', static fn(Finding $f): Finding => self::with($f, severity: Severity::Error)];
        yield 'metricValue' => ['metricValue', static fn(Finding $f): Finding => self::with($f, metricValue: 99)];
        yield 'relatedLocations' => ['relatedLocations', static fn(Finding $f): Finding => self::with(
            $f,
            relatedLocations: [new Location(RelativePath::fromString('src/Other.php'), 3)],
        )];

        yield 'dependencyTarget' => ['dependencyTarget', static fn(Finding $f): Finding => self::with(
            $f,
            dependencyTarget: SymbolPath::forClass('App', 'Target'),
        )];
        yield 'dependencyType' => ['dependencyType', static fn(Finding $f): Finding => self::with(
            $f,
            dependencyType: DependencyType::Extends,
        )];
        yield 'acceptedLevel' => ['acceptedLevel', static fn(Finding $f): Finding => self::with(
            $f,
            acceptedLevel: new AcceptedLevel([7.0], 1),
        )];
        yield 'occurrenceKey' => ['occurrenceKey', static fn(Finding $f): Finding => self::with(
            $f,
            occurrenceKey: OccurrenceKey::semantic('sample', ['seed' => 'a']),
        )];

        // Prose, both of it: rules spell the boundary into the advice as
        // readily as into the message, so a moved recommendation is a moved
        // boundary and not a different finding — which is what the boundary
        // list says, and what this case therefore demands.
        yield 'recommendation' => ['recommendation', static fn(Finding $f): Finding => self::with(
            $f,
            recommendation: 'do something else',
        )];
        yield 'threshold' => ['threshold', static fn(Finding $f): Finding => self::with($f, threshold: 42)];
        yield 'message' => ['message', static fn(Finding $f): Finding => self::with($f, message: 'a different tale')];
    }

    private static function finding(): Finding
    {
        $subject = self::subject('Widget');

        return new Finding(
            location: new Location(RelativePath::fromString('src/Sample.php'), 10),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: 'coupling.cbo',
            code: 'coupling.cbo',
            message: 'CBO: 30 (threshold: 25)',
            severity: Severity::Warning,
            metricValue: 30,
            threshold: 25,
        );
    }

    /**
     * One field of a finding replaced, every other kept — the shape the data
     * provider needs and `Finding` does not offer, being `final readonly` in a
     * language without `clone … with`.
     *
     * @param list<Location> $relatedLocations
     */
    private static function with(
        Finding $finding,
        ?Location $location = null,
        ?MetricSubject $subject = null,
        ?SymbolPath $symbolPath = null,
        ?string $ruleName = null,
        ?string $code = null,
        ?string $message = null,
        ?Severity $severity = null,
        int|float|null $metricValue = null,
        ?array $relatedLocations = null,
        ?string $recommendation = null,
        int|float|null $threshold = null,
        ?SymbolPath $dependencyTarget = null,
        ?DependencyType $dependencyType = null,
        ?AcceptedLevel $acceptedLevel = null,
        ?OccurrenceKey $occurrenceKey = null,
    ): Finding {
        return new Finding(
            location: $location ?? $finding->location,
            subject: $subject ?? $finding->subject,
            symbolPath: $symbolPath ?? $finding->symbolPath,
            ruleName: $ruleName ?? $finding->ruleName,
            code: $code ?? $finding->code,
            message: $message ?? $finding->message,
            severity: $severity ?? $finding->severity,
            metricValue: $metricValue ?? $finding->metricValue,
            relatedLocations: $relatedLocations ?? $finding->relatedLocations,
            recommendation: $recommendation ?? $finding->recommendation,
            threshold: $threshold ?? $finding->threshold,
            dependencyTarget: $dependencyTarget ?? $finding->dependencyTarget,
            dependencyType: $dependencyType ?? $finding->dependencyType,
            acceptedLevel: $acceptedLevel ?? $finding->acceptedLevel,
            occurrenceKey: $occurrenceKey ?? $finding->occurrenceKey,
        );
    }

    private static function subject(string $class): MetricSubject
    {
        return MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forClass('App', $class),
            RelativePath::fromString('src/Sample.php'),
            DeclarationOrdinal::fromRank(0),
        ));
    }
}
