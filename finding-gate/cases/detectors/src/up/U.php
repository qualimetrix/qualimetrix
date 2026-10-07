<?php
namespace App;
final class U
{
    private const C1 = 1;
    private const C2 = 2;
    public function run(array $xs): array
    {
        $a = U::k(1) + \App\U::m(2);
        $b = array_map([U::class, 'j'], $xs);
        $c = array_map([\App\U::class, 'n'], $xs);
        $d = U::C1 + \App\U::C2;
        $e = U::$p1 . \App\U::$p2;
        $f = array_map('self::q', $xs);
        $g = array_map([self::class, 's'], $xs);
        return [$a, $b, $c, $d, $e, $f, $g];
    }
    private static string $p1 = '';
    private static string $p2 = '';
    private static function k(int $x): int { return $x; }
    private static function m(int $x): int { return $x; }
    private static function j(int $x): int { return $x > 0 ? self::j($x - 1) : 0; }
    private static function n(int $x): int { return $x; }
    private static function q(int $x): int { return $x; }
    private static function s(int $x): int { return $x > 0 ? self::s($x - 1) : 0; }
}
