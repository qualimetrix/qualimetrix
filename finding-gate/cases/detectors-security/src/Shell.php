<?php

namespace Corpus\Security;

class Shell
{
    public function listDir(): void
    {
        system('ls ' . $_GET['dir']);
    }
}
