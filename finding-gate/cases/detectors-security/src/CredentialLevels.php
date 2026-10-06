<?php

declare(strict_types=1);

namespace Corpus\Security;

const API_KEY = 'corpus-fixture-file-secret';
const PASSWORD = 'Admin.Pass123', TOKEN = 'SG.corpus.FixtureToken';

$password = 'corpus-fixture-file-password';

function credentialDefault(string $password = 'corpus-fixture-callable-secret'): string
{
    return $password;
}
