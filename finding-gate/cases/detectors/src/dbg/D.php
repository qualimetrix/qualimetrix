<?php
namespace App;
final class D
{
    public function a(mixed $ctx): void
    {
        dump($ctx, return: true);
        var_dump($ctx, return: true);
        dd($ctx, return: true);
        $s = print_r($ctx, return: true);
        $t = print_r($ctx, true);
        $u = var_export($ctx, true);
        var_dump($ctx);
    }
    public function dump(mixed $x): void { var_dump($x); print_r($x); }
    public function debug(mixed $x): void { var_dump($x); }
}
function dump_helper(mixed $x): void { var_dump($x); }
