<?php
namespace App\CLOSURE_FROMCALLABLE_SELF;

final class V
{
    private const C = 1;
    private static int $p = 0;
    public function run(): array
    {
        $a = \Closure::fromCallable([self::class, 'm']);
        
        return [$a];
    }
    private static function m(): int { return 1; }
    private function im(): int { return 1; }
}
