<?php
namespace App\NAMESPACE_RELATIVE;

final class V
{
    private const C = 1;
    private static int $p = 0;
    public function run(): array
    {
        $a = namespace\V::m();
        $b = namespace\V::C; $c = namespace\V::$p;
        return [$a];
    }
    private static function m(): int { return 1; }
    private function im(): int { return 1; }
}
