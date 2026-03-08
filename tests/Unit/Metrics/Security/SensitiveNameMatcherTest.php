<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Security;

use AiMessDetector\Metrics\Security\SensitiveNameMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SensitiveNameMatcher::class)]
final class SensitiveNameMatcherTest extends TestCase
{
    private SensitiveNameMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SensitiveNameMatcher();
    }

    #[DataProvider('provideSensitiveNames')]
    public function testSensitiveNames(string $name): void
    {
        self::assertTrue($this->matcher->isSensitive($name), "Expected '{$name}' to be sensitive");
    }

    #[DataProvider('provideNonSensitiveNames')]
    public function testNonSensitiveNames(string $name): void
    {
        self::assertFalse($this->matcher->isSensitive($name), "Expected '{$name}' to NOT be sensitive");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSensitiveNames(): iterable
    {
        // password variants — exact and case variations
        yield 'password' => ['password'];
        yield 'Password' => ['Password'];
        yield 'PASSWORD' => ['PASSWORD'];

        // password with non-blacklisted prefixes
        yield 'dbPassword' => ['dbPassword'];
        yield 'db_password' => ['db_password'];
        yield 'DB_PASSWORD' => ['DB_PASSWORD'];
        yield 'userPassword' => ['userPassword'];
        yield 'mysqlPassword' => ['mysqlPassword'];

        // other password variants
        yield 'smtpPasswd' => ['smtpPasswd'];
        yield 'userPwd' => ['userPwd'];
        yield 'SMTP_PASSWD' => ['SMTP_PASSWD'];

        // secret
        yield 'appSecret' => ['appSecret'];
        yield 'clientSecret' => ['clientSecret'];
        yield 'APP_SECRET' => ['APP_SECRET'];
        yield 'secret' => ['secret'];

        // credential(s)
        yield 'credential' => ['credential'];
        yield 'userCredentials' => ['userCredentials'];
        yield 'CREDENTIALS' => ['CREDENTIALS'];

        // compound key with qualifying prefixes
        yield 'apiKey' => ['apiKey'];
        yield 'api_key' => ['api_key'];
        yield 'API_KEY' => ['API_KEY'];
        yield 'secretKey' => ['secretKey'];
        yield 'privateKey' => ['privateKey'];
        yield 'encryptionKey' => ['encryptionKey'];
        yield 'signingKey' => ['signingKey'];
        yield 'authKey' => ['authKey'];
        yield 'accessKey' => ['accessKey'];
        yield 'ACCESS_KEY' => ['ACCESS_KEY'];

        // compound token with qualifying prefixes
        yield 'authToken' => ['authToken'];
        yield 'accessToken' => ['accessToken'];
        yield 'bearerToken' => ['bearerToken'];
        yield 'apiToken' => ['apiToken'];
        yield 'refreshToken' => ['refreshToken'];
        yield 'AUTH_TOKEN' => ['AUTH_TOKEN'];
        yield 'ACCESS_TOKEN' => ['ACCESS_TOKEN'];
        yield 'REFRESH_TOKEN' => ['REFRESH_TOKEN'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonSensitiveNames(): iterable
    {
        // password with suffix blacklist
        yield 'passwordHash' => ['passwordHash'];
        yield 'passwordField' => ['passwordField'];
        yield 'passwordValidator' => ['passwordValidator'];
        yield 'passwordLength' => ['passwordLength'];
        yield 'PASSWORD_HASH' => ['PASSWORD_HASH'];
        yield 'PASSWORD_FIELD' => ['PASSWORD_FIELD'];
        yield 'passwordPolicy' => ['passwordPolicy'];
        yield 'passwordManager' => ['passwordManager'];
        yield 'passwordEncoder' => ['passwordEncoder'];
        yield 'passwordStrength' => ['passwordStrength'];
        yield 'passwordReset' => ['passwordReset'];

        // password with prefix blacklist
        yield 'OPTION_PASSWORD' => ['OPTION_PASSWORD'];
        yield 'CONFIG_SECRET' => ['CONFIG_SECRET'];
        yield 'RESET_PASSWORD' => ['RESET_PASSWORD'];
        yield 'ERROR_PASSWORD' => ['ERROR_PASSWORD'];
        yield 'defaultPassword' => ['defaultPassword'];
        yield 'hashedPassword' => ['hashedPassword'];

        // boolean-indicator prefixes
        yield 'isPasswordRequired' => ['isPasswordRequired'];
        yield 'hasPassword' => ['hasPassword'];
        yield 'canResetPassword' => ['canResetPassword'];
        yield 'shouldChangePassword' => ['shouldChangePassword'];
        yield 'needsPassword' => ['needsPassword'];

        // token with suffix blacklist
        yield 'tokenStorage' => ['tokenStorage'];
        yield 'tokenHandler' => ['tokenHandler'];
        yield 'tokenExpiry' => ['tokenExpiry'];
        yield 'tokenFactory' => ['tokenFactory'];
        yield 'tokenLifetime' => ['tokenLifetime'];

        // token without qualifying prefix
        yield 'INVALID_TOKEN' => ['INVALID_TOKEN'];
        yield 'sessionToken' => ['sessionToken'];
        yield 'csrfToken' => ['csrfToken'];

        // bare compound-only words — not sensitive alone
        yield 'key' => ['key'];
        yield 'token' => ['token'];
        yield 'KEY' => ['KEY'];
        yield 'TOKEN' => ['TOKEN'];

        // key without qualifying prefix
        yield 'cacheKey' => ['cacheKey'];
        yield 'primaryKey' => ['primaryKey'];
        yield 'sortKey' => ['sortKey'];
        yield 'foreignKey' => ['foreignKey'];
        yield 'arrayKey' => ['arrayKey'];
        yield 'CACHE_KEY' => ['CACHE_KEY'];

        // empty and trivial
        yield 'empty string' => [''];
        yield 'username' => ['username'];
        yield 'email' => ['email'];
        yield 'count' => ['count'];
    }

    public function testExtraSensitiveNamesAreDetected(): void
    {
        $matcher = new SensitiveNameMatcher(['connection_string']);

        self::assertTrue($matcher->isSensitive('connectionString'));
        self::assertTrue($matcher->isSensitive('connection_string'));
        self::assertTrue($matcher->isSensitive('dbConnectionString'));
        self::assertTrue($matcher->isSensitive('CONNECTION_STRING'));
    }

    public function testExtraSensitiveNamesRespectSuffixBlacklist(): void
    {
        $matcher = new SensitiveNameMatcher(['connection_string']);

        // "path" is in suffix blacklist
        self::assertFalse($matcher->isSensitive('connectionStringPath'));
        // "config" is in prefix blacklist
        self::assertFalse($matcher->isSensitive('configConnectionString'));
    }

    public function testExtraSensitiveNamesDoNotAffectDefaultBehavior(): void
    {
        $matcher = new SensitiveNameMatcher(['connection_string']);

        // Default sensitive words still work
        self::assertTrue($matcher->isSensitive('dbPassword'));
        self::assertTrue($matcher->isSensitive('apiKey'));
        self::assertTrue($matcher->isSensitive('authToken'));

        // Default non-sensitive still work
        self::assertFalse($matcher->isSensitive('passwordHash'));
        self::assertFalse($matcher->isSensitive('cacheKey'));
    }
}
