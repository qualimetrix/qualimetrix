<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Tests\Analysis\Evidence\Coupling\Support\RfcMeasurement;

final class RfcResponseFormsTest extends TestCase
{
    private const string LIBRARY = <<<'PHP'
namespace App\Lib;
abstract class Base { public function inherited(): int { return 1; } public static function sInh(): int { return 1; } }


namespace App\Lib;
class Repo { public function save(): void {} }


namespace App\Lib;
class Svc { public function go(): Svc { return $this; } public function run(): int { return 1; } public static function st(): int { return 1; } public ?Svc $next = null; }


namespace App\Lib;
trait Tr { public function fromTrait(): int { return 1; } }


namespace App\Other;
class Repo { public function save(): void {} }

PHP;

    #[Test]
    #[DataProvider('forms')]
    public function itResolvesEveryResponseForm(string $code, string $class, int $own, int $external): void
    {
        $bags = RfcMeasurement::byName(RfcMeasurement::measure(['Form.php' => $code . self::LIBRARY]));
        self::assertSame($own, $bags[$class]->get('coupling.rfc-own'));
        self::assertSame($external, $bags[$class]->get('coupling.rfc-external'));
        self::assertSame($own + $external, $bags[$class]->get('coupling.rfc'));
    }

    /** @return iterable<string, array{string, string, int, int}> */
    public static function forms(): iterable
    {
        yield 'F01' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F01 { public function m(): void { $this->n(); } public function n(): void {} }

PHP, 'App\\F01', 2, 0];
        yield 'F02' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F02 extends Base { public function m(): void { $this->inherited(); } }

PHP, 'App\\F02', 1, 1];
        yield 'F03' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F03 { use Tr; public function m(): void { $this->fromTrait(); } }

PHP, 'App\\F03', 2, 0];
        yield 'F04' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F04 extends Base { public function m(): void { parent::inherited(); } }

PHP, 'App\\F04', 1, 1];
        yield 'F05' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F05 extends Base { public function m(): void { self::sInh(); static::sInh(); } }

PHP, 'App\\F05', 1, 1];
        yield 'F06' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F06 { public function m(Svc $s): void { $s->run(); $s->run(); } }

PHP, 'App\\F06', 1, 1];
        yield 'F07' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F07 { public function m(Repo $r): void { $r->save(); } public function n(OtherRepo $r): void { $r->save(); } }

PHP, 'App\\F07', 2, 2];
        yield 'F08' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F08 { public function m(Svc $a, Svc $b): void { $a->run(); $b->run(); } }

PHP, 'App\\F08', 1, 1];
        yield 'F09' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F09 { public function __construct(private Svc $svc) {} public function m(): void { $this->svc->run(); $x = $this->svc; $x->run(); } }

PHP, 'App\\F09', 2, 1];
        yield 'F10' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F10 { public function m(): void { Svc::st(); \App\Lib\Svc::st(); Alias::st(); } }

PHP, 'App\\F10', 1, 1];
        yield 'F11' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F11 { public function m(): void { new Svc(); new Svc(); new \App\Lib\Svc(); } }

PHP, 'App\\F11', 1, 1];
        yield 'F12' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F12 { public function m(): void { (new Svc())->run(); } }

PHP, 'App\\F12', 1, 2];
        yield 'F13' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F13 { public function m(Svc $s): void { $s->go()->run(); $s->go()->run(); } }

PHP, 'App\\F13', 1, 2];
        yield 'F14' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F14 { /** @param list<Svc> $xs */ public function m(array $xs): void { $xs[0]->run(); $xs[0]->run(); } }

PHP, 'App\\F14', 1, 1];
        yield 'F15' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F15 { public function m(?Svc $s): void { $s?->run(); $s->run(); } }

PHP, 'App\\F15', 1, 1];
        yield 'F16' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F16 { public function m(Svc $s): void { $f = $s->run(...); $g = strlen(...); } }

PHP, 'App\\F16', 1, 0];
        yield 'F17' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F17 { public function m(): void { strlen("a"); \strlen("b"); } }

PHP, 'App\\F17', 1, 1];
        yield 'F18' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F18 { public function m(Svc $s, string $n): void { $s->$n(); } }

PHP, 'App\\F18', 1, 0];
        yield 'F19' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F19 { public function m(Svc $s): void { $f = function () use ($s) { $s->run(); }; } }

PHP, 'App\\F19', 1, 1];
        yield 'F20' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F20 { public const X = 1; public function m(): void {} }

PHP, 'App\\F20', 1, 0];
        yield 'F21' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
abstract class F21 { abstract public function a(): void; abstract public function b(): void; }

PHP, 'App\\F21', 2, 0];
        yield 'F22' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
interface F22 { public function a(): void; public function b(): void; }

PHP, 'App\\F22', 2, 0];
        yield 'F23' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
trait F23 { abstract public function a(): void; public function b(): void {} }

PHP, 'App\\F23', 2, 0];
        yield 'F24' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
enum F24 { case A; public function m(): int { return Svc::st(); } }

PHP, 'App\\F24', 1, 1];
        yield 'F25' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F25 { public function __construct(private Svc $svc) {} public int $v { get => $this->svc->run(); } }

PHP, 'App\\F25', 2, 1];
        yield 'F26' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F26 { public function __construct(private Svc $svc) {} public function m(): void { $this->svc->next->run(); $this->svc->next->run(); } }

PHP, 'App\\F26', 2, 1];
        yield 'F27' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F27 { /** @param class-string<Svc> $c */ public function m(string $c): void { $c::st(); } }

PHP, 'App\\F27', 1, 1];
        yield 'F28' => [<<<'PHP'
<?php
namespace App;
use App\Lib\Svc;
use App\Lib\Svc as Alias;
use App\Lib\Repo;
use App\Other\Repo as OtherRepo;
use App\Lib\Base;
use App\Lib\Tr;
final class F28 { public function m(): object { return new class { public function x(Svc $s): void { $s->run(); } }; } }

PHP, 'App\\F28', 1, 0];
    }
}
