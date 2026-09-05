<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(MetricSubject::class)]
final class MetricSubjectTest extends TestCase
{
    #[Test]
    public function itKeepsTheThreeIdentityVariantsSeparate(): void
    {
        $declaration = DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'handle'), RelativePath::fromString('src/Service.php'), DeclarationOrdinal::fromRank(0));
        $logicalClass = new LogicalClassPath(SymbolPath::forClass('App', 'Service'));
        $aggregate = SymbolPath::forNamespace('App');

        self::assertSame($declaration, MetricSubject::declaration($declaration)->declarationPath());
        self::assertSame($logicalClass, MetricSubject::logicalClass($logicalClass)->logicalClassPath());
        self::assertSame($aggregate, MetricSubject::aggregate($aggregate)->aggregatePath());
    }

    #[Test]
    public function itRejectsACallableAsAnAggregateSubject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MetricSubject::aggregate(SymbolPath::forMethod('App', 'Service', 'handle'));
    }

    /**
     * Every identity family lands in its own region of the canonical string
     * space.
     *
     * {@see MetricSubject::equals()} decides value equality by the canonical
     * form, so two subjects of different families sharing one canonical would
     * make them equal — and the threshold audit, which asks whether a directive
     * covers a finding's subject, would answer yes across the families. The
     * separation is by construction (disjoint prefixes) and was until now held
     * by nothing.
     *
     * The subjects are chosen to collide if the prefixes were dropped: the
     * class, the namespace and the file all spell `App\Service` or a path over
     * it, and the method and the namespaced function both spell
     * `App\Service::handle`.
     *
     * Three of the seven words — `class:`, `callable:`, `func:` — are the
     * families of the logical path a declaration wraps, and a subject reaches
     * them only through `declaration:`. The keys say so rather than pretending
     * every word is a top-level one.
     */
    #[Test]
    public function itGivesEachIdentityFamilyItsOwnCanonicalForm(): void
    {
        $canonical = array_map(
            static fn(MetricSubject $subject): string => $subject->toCanonical(),
            self::oneSubjectPerFamily(),
        );

        foreach ($canonical as $family => $value) {
            self::assertStringStartsWith($family, $value, 'a family stopped announcing itself with its own prefix');
        }

        self::assertSame(
            \count($canonical),
            \count(array_unique($canonical)),
            'two identity families share a canonical form',
        );
    }

    /**
     * Injectivity inside a family, not only across them: the fields that make
     * two subjects of one family different must reach the canonical form.
     */
    #[Test]
    public function itSeparatesSubjectsOfOneFamilyThatDifferInOneField(): void
    {
        $method = SymbolPath::forMethod('App', 'Service', 'handle');
        $here = RelativePath::fromString('src/Service.php');
        $there = RelativePath::fromString('src/Other.php');

        $canonical = array_map(
            static fn(MetricSubject $subject): string => $subject->toCanonical(),
            [
                'first here' => MetricSubject::declaration(DeclarationPath::of($method, $here, DeclarationOrdinal::fromRank(0))),
                'second here' => MetricSubject::declaration(DeclarationPath::of($method, $here, DeclarationOrdinal::fromRank(1))),
                'first there' => MetricSubject::declaration(DeclarationPath::of($method, $there, DeclarationOrdinal::fromRank(0))),
                'another method' => MetricSubject::declaration(DeclarationPath::of(
                    SymbolPath::forMethod('App', 'Service', 'other'),
                    $here,
                    DeclarationOrdinal::fromRank(0),
                )),
            ],
        );

        self::assertSame(\count($canonical), \count(array_unique($canonical)));
    }

    /**
     * The control the two cases above are worth nothing without: equality by
     * canonical form still answers yes for two separately built subjects of
     * the same identity.
     */
    #[Test]
    public function itCallsTwoSeparatelyBuiltSubjectsOfOneIdentityEqual(): void
    {
        $subject = static fn(): MetricSubject => MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forMethod('App', 'Service', 'handle'),
            RelativePath::fromString('src/Service.php'),
            DeclarationOrdinal::fromRank(0),
        ));

        self::assertTrue($subject()->equals($subject()));
        self::assertFalse($subject()->equals(MetricSubject::aggregate(SymbolPath::forNamespace('App'))));
    }

    /**
     * @return array<non-empty-string, MetricSubject> family prefix => a subject of that family
     */
    private static function oneSubjectPerFamily(): array
    {
        $file = RelativePath::fromString('src/Service.php');

        return [
            'class:' => MetricSubject::logicalClass(new LogicalClassPath(SymbolPath::forClass('App', 'Service'))),
            'declaration:class:' => MetricSubject::declaration(DeclarationPath::of(
                SymbolPath::forClass('App', 'Service'),
                $file,
                DeclarationOrdinal::fromRank(0),
            )),
            'declaration:func:' => MetricSubject::declaration(DeclarationPath::of(
                SymbolPath::forGlobalFunction('App\Service', 'handle'),
                $file,
                DeclarationOrdinal::fromRank(0),
            )),
            'declaration:callable:' => MetricSubject::declaration(DeclarationPath::of(
                SymbolPath::forMethod('App', 'Service', 'handle'),
                $file,
                DeclarationOrdinal::fromRank(0),
            )),
            'file:' => MetricSubject::aggregate(SymbolPath::forFile($file)),
            'ns:' => MetricSubject::aggregate(SymbolPath::forNamespace('App\Service')),
            'project:' => MetricSubject::aggregate(SymbolPath::forProject()),
        ];
    }
}
