<?php
namespace App\USE_ALIAS;
use App\USE_ALIAS\V as Me;
final class V
{
    private const C = 1;
    private static int $p = 0;
    public function run(): array
    {
        $a = Me::m();
        $b = Me::C; $c = Me::$p;
        return [$a];
    }
    private static function m(): int { return 1; }
    private function im(): int { return 1; }
}
