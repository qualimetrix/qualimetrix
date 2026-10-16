<?php
namespace App;
use SensitiveParameter as SP;
final class P
{
    public function fq(#[\SensitiveParameter] string $password): void {}
    public function unq(#[SensitiveParameter] string $password): void {}
    public function ali(#[SP] string $password): void {}
    public function low(#[\sensitiveparameter] string $password): void {}
    public function none(string $password): void {}
}
