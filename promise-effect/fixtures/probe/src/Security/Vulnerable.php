<?php

declare(strict_types=1);

namespace Probe\Security;

class Vulnerable
{
    private string $apiKey = 'sk-live-9f2b7c4d1e8a3057';

    private string $password = 'Sup3rSecretProbe!';

    public function query(\PDO $pdo): void
    {
        $pdo->query('SELECT * FROM orders WHERE id = ' . $_GET['id']);
    }

    public function render(): void
    {
        echo $_GET['name'];
        print $_POST['comment'];
    }

    public function shell(): void
    {
        shell_exec('ls ' . $_GET['dir']);
        system('cat ' . $_POST['file']);
    }

    public function login(string $user, string $password, string $token): bool
    {
        return $user !== '' && $password === $this->password && $token === $this->apiKey;
    }
}
