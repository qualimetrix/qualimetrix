<?php
namespace App\F;
function debug(mixed $x): void { var_dump($x); }
final class Repo
{
    public function dump(string $file): void { file_put_contents($file, print_r($this, true)); var_dump($this); }
    public function debugInfo(): array { $f = fn() => var_dump(1); return []; }
}
