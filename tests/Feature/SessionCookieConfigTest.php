<?php

namespace Tests\Feature;

use Tests\TestCase;

class SessionCookieConfigTest extends TestCase
{
    /** The session config as it comes out for the given environment variables. */
    private function sessionConfig(array $environment): array
    {
        $before = [];

        foreach (['APP_URL', 'SESSION_SECURE_COOKIE'] as $name) {
            $before[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }

        foreach ($environment as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            return require config_path('session.php');
        } finally {
            foreach ($before as $name => [$server, $env, $process]) {
                unset($_SERVER[$name], $_ENV[$name]);
                $server === null || $_SERVER[$name] = $server;
                $env === null || $_ENV[$name] = $env;
                putenv($process === false ? $name : "{$name}={$process}");
            }
        }
    }

    public function test_the_session_cookie_is_https_only_when_the_app_url_is_https(): void
    {
        $this->assertTrue($this->sessionConfig(['APP_URL' => 'https://powercollect.example'])['secure']);
        $this->assertFalse($this->sessionConfig(['APP_URL' => 'http://localhost:8000'])['secure']);
        $this->assertFalse($this->sessionConfig([])['secure']);
    }

    public function test_an_explicit_setting_wins_either_way(): void
    {
        $this->assertFalse($this->sessionConfig(['APP_URL' => 'https://powercollect.example', 'SESSION_SECURE_COOKIE' => 'false'])['secure']);
        $this->assertTrue($this->sessionConfig(['APP_URL' => 'http://localhost:8000', 'SESSION_SECURE_COOKIE' => 'true'])['secure']);
    }
}
