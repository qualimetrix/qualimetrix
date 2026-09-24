<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security\Credential;

/**
 * Decides whether a string literal stored under a sensitive name looks like a
 * secret rather than a placeholder, a key or a message.
 */
final readonly class CredentialValue
{
    public function __construct(private int $minValueLength) {}

    public function isCredential(string $value): bool
    {
        return $value !== '' && \strlen($value) >= $this->minValueLength && \strlen($value) !== substr_count($value, $value[0]) && !$this->dotIdentifier($value) && !$this->humanMessage($value);
    }
    /**
     * A translation, configuration or channel key such as `auth.password.reset`
     * or `auth.password-reset`: every dot-separated segment is either a code
     * identifier or lowercase letter-only words joined by hyphens. A hyphenated
     * segment with a capital or a digit (`Summer-2024`, `abc123-def456`) is the
     * shape of a password or a key, not of a name. A JWT has the identifier
     * shape, but its header always encodes `{"` and so starts with `eyJ`.
     */
    private function dotIdentifier(string $value): bool
    {
        $segment = '(?:[a-zA-Z_]\w*|[a-z]+(?:-[a-z]+)+)';

        return (bool) preg_match("/^{$segment}(?:\\.{$segment})+$/", $value) && !str_starts_with($value, 'eyJ');
    }
    /**
     * Words of a message are separated by whitespace. Hyphens, dots, slashes
     * and plus signs separate the groups of a key (UUID, AWS-style, base64),
     * so they must not count as word breaks.
     */
    private function humanMessage(string $value): bool
    {
        return \strlen($value) > 20 && preg_match_all('/(?<!\S)\S*\w{3,}\S*/', $value) >= 3;
    }
}
