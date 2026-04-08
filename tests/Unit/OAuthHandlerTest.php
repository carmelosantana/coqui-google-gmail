<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitGoogleGmail\Auth\OAuthHandler;

test('PKCE code verifier has correct length', function () {
    $handler = new OAuthHandler(workspacePath: sys_get_temp_dir());

    // Use reflection to test private methods
    $method = new ReflectionMethod($handler, 'generateCodeVerifier');
    $verifier = $method->invoke($handler);

    // URL-safe base64 of 32 bytes = 43 chars
    expect($verifier)->toBeString();
    expect(strlen($verifier))->toBe(43);
    // Must be URL-safe base64 (no +, /, =)
    expect($verifier)->not->toContain('+');
    expect($verifier)->not->toContain('/');
    expect($verifier)->not->toContain('=');
});

test('PKCE code challenge is derived from verifier', function () {
    $handler = new OAuthHandler(workspacePath: sys_get_temp_dir());

    $verifierMethod = new ReflectionMethod($handler, 'generateCodeVerifier');
    $challengeMethod = new ReflectionMethod($handler, 'generateCodeChallenge');

    $verifier = $verifierMethod->invoke($handler);
    $challenge = $challengeMethod->invoke($handler, $verifier);

    expect($challenge)->toBeString();
    expect(strlen($challenge))->toBe(43);
    // Must be URL-safe base64
    expect($challenge)->not->toContain('+');
    expect($challenge)->not->toContain('/');
    expect($challenge)->not->toContain('=');
    // Challenge must differ from verifier (SHA-256 hash)
    expect($challenge)->not->toBe($verifier);
});

test('two verifiers are different', function () {
    $handler = new OAuthHandler(workspacePath: sys_get_temp_dir());

    $method = new ReflectionMethod($handler, 'generateCodeVerifier');
    $v1 = $method->invoke($handler);
    $v2 = $method->invoke($handler);

    expect($v1)->not->toBe($v2);
});

test('token storage and loading round-trips correctly', function () {
    $tmpDir = sys_get_temp_dir() . '/gmail-test-' . uniqid();
    mkdir($tmpDir, 0o700, true);

    try {
        $handler = new OAuthHandler(workspacePath: $tmpDir);

        $tokens = [
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
        ];

        // Store tokens
        $storeMethod = new ReflectionMethod($handler, 'storeTokens');
        $storeMethod->invoke($handler, 'default', $tokens);

        // Verify file exists
        expect(file_exists($tmpDir . '/.gmail-tokens/default.json'))->toBeTrue();

        // Load tokens
        $loadMethod = new ReflectionMethod($handler, 'loadTokens');
        $loaded = $loadMethod->invoke($handler, 'default');

        expect($loaded)->toBeArray();
        expect($loaded['access_token'])->toBe('test-access-token');
        expect($loaded['refresh_token'])->toBe('test-refresh-token');
        expect($loaded['expires_at'])->toBe($tokens['expires_at']);
    } finally {
        // Cleanup
        @unlink($tmpDir . '/.gmail-tokens/default.json');
        @rmdir($tmpDir . '/.gmail-tokens');
        @rmdir($tmpDir);
    }
});

test('hasTokens returns false when no tokens stored', function () {
    $tmpDir = sys_get_temp_dir() . '/gmail-test-' . uniqid();
    mkdir($tmpDir, 0o700, true);

    try {
        $handler = new OAuthHandler(workspacePath: $tmpDir);
        expect($handler->hasTokens())->toBeFalse();
    } finally {
        @rmdir($tmpDir);
    }
});

test('hasTokens returns true after storing tokens', function () {
    $tmpDir = sys_get_temp_dir() . '/gmail-test-' . uniqid();
    mkdir($tmpDir, 0o700, true);

    try {
        $handler = new OAuthHandler(workspacePath: $tmpDir);

        $storeMethod = new ReflectionMethod($handler, 'storeTokens');
        $storeMethod->invoke($handler, 'default', [
            'access_token' => 'test-token',
        ]);

        expect($handler->hasTokens())->toBeTrue();
    } finally {
        @unlink($tmpDir . '/.gmail-tokens/default.json');
        @rmdir($tmpDir . '/.gmail-tokens');
        @rmdir($tmpDir);
    }
});

test('clearTokens removes token file', function () {
    $tmpDir = sys_get_temp_dir() . '/gmail-test-' . uniqid();
    mkdir($tmpDir, 0o700, true);

    try {
        $handler = new OAuthHandler(workspacePath: $tmpDir);

        $storeMethod = new ReflectionMethod($handler, 'storeTokens');
        $storeMethod->invoke($handler, 'default', [
            'access_token' => 'test-token',
        ]);

        expect($handler->hasTokens())->toBeTrue();

        $handler->clearTokens();

        expect($handler->hasTokens())->toBeFalse();
        // Verify file is actually gone
        expect(file_exists($tmpDir . '/.gmail-tokens/default.json'))->toBeFalse();
    } finally {
        // Only clean up what still exists (clearTokens already removed the token file)
        if (is_dir($tmpDir . '/.gmail-tokens')) {
            @rmdir($tmpDir . '/.gmail-tokens');
        }
        @rmdir($tmpDir);
    }
});

test('getStatus returns unauthenticated when no tokens', function () {
    $tmpDir = sys_get_temp_dir() . '/gmail-test-' . uniqid();
    mkdir($tmpDir, 0o700, true);

    try {
        $handler = new OAuthHandler(workspacePath: $tmpDir);
        $status = $handler->getStatus();

        expect($status)->toBeArray();
        expect($status['authenticated'])->toBeFalse();
    } finally {
        @rmdir($tmpDir);
    }
});

test('getStatus returns authenticated with token details', function () {
    $tmpDir = sys_get_temp_dir() . '/gmail-test-' . uniqid();
    mkdir($tmpDir, 0o700, true);

    try {
        $handler = new OAuthHandler(workspacePath: $tmpDir);

        $storeMethod = new ReflectionMethod($handler, 'storeTokens');
        $storeMethod->invoke($handler, 'default', [
            'access_token' => 'test-token',
            'refresh_token' => 'test-refresh',
            'expires_at' => time() + 3600,
            'scope' => 'https://www.googleapis.com/auth/gmail.modify',
        ]);

        $status = $handler->getStatus();

        expect($status['authenticated'])->toBeTrue();
        expect($status['has_refresh_token'])->toBeTrue();
        expect($status['scope'])->toContain('gmail.modify');
    } finally {
        @unlink($tmpDir . '/.gmail-tokens/default.json');
        @rmdir($tmpDir . '/.gmail-tokens');
        @rmdir($tmpDir);
    }
});

test('getAccessToken returns null when no tokens', function () {
    $tmpDir = sys_get_temp_dir() . '/gmail-test-' . uniqid();
    mkdir($tmpDir, 0o700, true);

    try {
        $handler = new OAuthHandler(workspacePath: $tmpDir);
        expect($handler->getAccessToken())->toBeNull();
    } finally {
        @rmdir($tmpDir);
    }
});

test('getAccessToken returns token when not expired', function () {
    $tmpDir = sys_get_temp_dir() . '/gmail-test-' . uniqid();
    mkdir($tmpDir, 0o700, true);

    try {
        $handler = new OAuthHandler(workspacePath: $tmpDir);

        $storeMethod = new ReflectionMethod($handler, 'storeTokens');
        $storeMethod->invoke($handler, 'default', [
            'access_token' => 'valid-token',
            'expires_at' => time() + 3600,
        ]);

        expect($handler->getAccessToken())->toBe('valid-token');
    } finally {
        @unlink($tmpDir . '/.gmail-tokens/default.json');
        @rmdir($tmpDir . '/.gmail-tokens');
        @rmdir($tmpDir);
    }
});
