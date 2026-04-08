<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitGoogleGmail\Auth;

/**
 * Handles OAuth 2.0 browser-based authentication for Google Gmail.
 *
 * Flow:
 * 1. Reads client_id and client_secret from environment (lazy, hot-reload safe)
 * 2. Starts a temporary local HTTP server as the redirect URI
 * 3. Opens the Google authorization URL in the user's browser
 * 4. Waits for the callback with the authorization code
 * 5. Exchanges the code for tokens (with PKCE + client_secret)
 * 6. Stores tokens in .workspace/.gmail-tokens/default.json
 * 7. Returns the access token for API calls
 *
 * Also handles token refresh when tokens expire.
 *
 * @see https://developers.google.com/identity/protocols/oauth2/native-app
 */
final class OAuthHandler
{
    private const string TOKENS_DIR = '.gmail-tokens';
    private const string DEFAULT_ACCOUNT = 'default';
    private const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const string REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const int CALLBACK_TIMEOUT = 120;
    private const int CALLBACK_PORT_MIN = 49152;
    private const int CALLBACK_PORT_MAX = 65535;

    /**
     * Gmail API scopes requested during authorization.
     *
     * - gmail.modify: read, send, delete, manage labels, and modify messages
     * - gmail.compose: create and send drafts
     *
     * @see https://developers.google.com/gmail/api/auth/scopes
     */
    private const array SCOPES = [
        'https://www.googleapis.com/auth/gmail.modify',
        'https://www.googleapis.com/auth/gmail.compose',
    ];

    public function __construct(
        private readonly string $workspacePath,
    ) {}

    /**
     * Perform the full OAuth 2.0 authorization flow.
     *
     * Opens the user's browser to the Google consent screen, waits for the
     * callback, exchanges the code for tokens, and stores them.
     *
     * @return array{access_token: string, refresh_token?: string, expires_at?: int, scope?: string}
     *
     * @throws OAuthException If the flow fails at any step
     */
    public function authorize(string $account = self::DEFAULT_ACCOUNT): array
    {
        $clientId = $this->resolveClientId();
        $clientSecret = $this->resolveClientSecret();

        if ($clientId === '' || $clientSecret === '') {
            throw OAuthException::configError(
                'GMAIL_CLIENT_ID and GMAIL_CLIENT_SECRET must be set — '
                . 'use credentials(action: "set", key: "GMAIL_CLIENT_ID", value: "...") to configure.',
            );
        }

        // Generate PKCE challenge
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        // Find an available port and start a local callback server
        $port = $this->findAvailablePort();
        $redirectUri = sprintf('http://127.0.0.1:%d/callback', $port);

        // Build authorization URL
        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => bin2hex(random_bytes(16)),
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];

        $fullAuthUrl = self::AUTH_URL . '?' . http_build_query($params);

        // Open browser
        $this->openBrowser($fullAuthUrl);

        // Wait for callback
        $callbackData = $this->waitForCallback($port, $params['state']);

        if (isset($callbackData['error'])) {
            throw OAuthException::authorizationFailed(
                $callbackData['error'],
                $callbackData['error_description'] ?? '',
            );
        }

        $authCode = $callbackData['code'] ?? '';

        if ($authCode === '') {
            throw OAuthException::authorizationFailed('no_code', 'No authorization code received');
        }

        // Exchange code for tokens (Google requires client_secret for Desktop apps)
        $tokens = $this->exchangeCode($authCode, $redirectUri, $clientId, $clientSecret, $codeVerifier);

        // Store tokens
        $this->storeTokens($account, $tokens);

        return $tokens;
    }

    /**
     * Get a valid access token, refreshing if expired.
     *
     * @return string|null Access token or null if no tokens stored / re-auth needed
     */
    public function getAccessToken(string $account = self::DEFAULT_ACCOUNT): ?string
    {
        $tokens = $this->loadTokens($account);

        if ($tokens === null) {
            return null;
        }

        // Check if token is expired (with 60s buffer)
        $expiresAt = $tokens['expires_at'] ?? 0;

        if ($expiresAt > 0 && $expiresAt < time() + 60) {
            $refreshToken = $tokens['refresh_token'] ?? null;

            if ($refreshToken === null) {
                return null;
            }

            $clientId = $this->resolveClientId();
            $clientSecret = $this->resolveClientSecret();

            if ($clientId === '' || $clientSecret === '') {
                return null;
            }

            try {
                $newTokens = $this->refreshToken($refreshToken, $clientId, $clientSecret);

                // Preserve the refresh token if the new response doesn't include one
                if (!isset($newTokens['refresh_token'])) {
                    $newTokens['refresh_token'] = $tokens['refresh_token'];
                }

                $this->storeTokens($account, $newTokens);

                return $newTokens['access_token'];
            } catch (\Throwable) {
                return null;
            }
        }

        return $tokens['access_token'];
    }

    /**
     * Get the stored token metadata (for status display).
     *
     * @return array{authenticated: bool, expires_at?: int, scope?: string, has_refresh_token?: bool}
     */
    public function getStatus(string $account = self::DEFAULT_ACCOUNT): array
    {
        $tokens = $this->loadTokens($account);

        if ($tokens === null) {
            return ['authenticated' => false];
        }

        return [
            'authenticated' => true,
            'expires_at' => $tokens['expires_at'] ?? null,
            'scope' => $tokens['scope'] ?? implode(' ', self::SCOPES),
            'has_refresh_token' => isset($tokens['refresh_token']),
        ];
    }

    /**
     * Check if stored tokens exist for an account.
     */
    public function hasTokens(string $account = self::DEFAULT_ACCOUNT): bool
    {
        return $this->loadTokens($account) !== null;
    }

    /**
     * Revoke the access token via Google's revoke endpoint, then delete stored tokens.
     */
    public function revoke(string $account = self::DEFAULT_ACCOUNT): bool
    {
        $tokens = $this->loadTokens($account);

        if ($tokens === null) {
            return false;
        }

        // Revoke the token with Google
        $token = $tokens['refresh_token'] ?? $tokens['access_token'];

        if ($token !== '') {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query(['token' => $token]),
                    'timeout' => 15,
                ],
            ]);

            // Best-effort revocation — don't throw if it fails
            @file_get_contents(self::REVOKE_URL, false, $context);
        }

        $this->clearTokens($account);

        return true;
    }

    /**
     * Delete stored tokens for an account.
     */
    public function clearTokens(string $account = self::DEFAULT_ACCOUNT): void
    {
        $path = $this->tokensPath($account);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Generate a PKCE code verifier (43-128 characters, URL-safe base64).
     */
    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Generate a PKCE code challenge from a verifier (S256 method).
     */
    private function generateCodeChallenge(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * Find an available port for the callback server.
     */
    private function findAvailablePort(): int
    {
        for ($i = 0; $i < 100; $i++) {
            $port = random_int(self::CALLBACK_PORT_MIN, self::CALLBACK_PORT_MAX);
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr, STREAM_SERVER_BIND);

            if ($socket !== false) {
                fclose($socket);

                return $port;
            }
        }

        throw OAuthException::configError('Could not find an available port for OAuth callback');
    }

    /**
     * Open a URL in the user's default browser.
     */
    private function openBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Linux' => 'xdg-open',
            'Darwin' => 'open',
            'Windows' => 'start',
            default => null,
        };

        if ($command === null) {
            return;
        }

        $escapedUrl = escapeshellarg($url);
        exec("{$command} {$escapedUrl} > /dev/null 2>&1 &");
    }

    /**
     * Start a temporary HTTP server and wait for the OAuth callback.
     *
     * @return array<string, string> Query parameters from the callback
     */
    private function waitForCallback(int $port, string $expectedState): array
    {
        $server = @stream_socket_server(
            "tcp://127.0.0.1:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );

        if ($server === false) {
            throw OAuthException::configError("Could not start callback server on port {$port}: {$errstr}");
        }

        stream_set_timeout($server, self::CALLBACK_TIMEOUT);

        $result = [];

        try {
            $client = @stream_socket_accept($server, self::CALLBACK_TIMEOUT);

            if ($client === false) {
                throw OAuthException::authorizationFailed('timeout', 'No callback received within timeout');
            }

            $request = '';

            while (($line = fgets($client)) !== false) {
                $request .= $line;

                if (trim($line) === '') {
                    break;
                }
            }

            // Parse the GET request to extract query parameters
            if (preg_match('/GET\s+\/callback\?([^\s]+)/', $request, $matches)) {
                parse_str($matches[1], $queryParams);
                /** @var array<string, string> $queryParams */
                $result = $queryParams;
            }

            // Validate state
            $receivedState = $result['state'] ?? '';

            if ($receivedState !== $expectedState) {
                throw OAuthException::authorizationFailed('state_mismatch', 'OAuth state parameter does not match');
            }

            // Send a success response to the browser
            $body = <<<'HTML'
                <!DOCTYPE html>
                <html>
                <body style="font-family: system-ui, sans-serif; text-align: center; padding: 60px;">
                <h1>✅ Gmail Authorization Complete</h1>
                <p>You can close this tab and return to Coqui.</p>
                <script>setTimeout(() => window.close(), 2000);</script>
                </body>
                </html>
                HTML;

            $response = "HTTP/1.1 200 OK\r\n"
                . "Content-Type: text/html\r\n"
                . 'Content-Length: ' . strlen($body) . "\r\n"
                . "Connection: close\r\n\r\n"
                . $body;

            fwrite($client, $response);
            fclose($client);
        } finally {
            fclose($server);
        }

        return $result;
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * Google Desktop apps require client_secret in the token exchange,
     * unlike OAuth 2.1 public clients.
     *
     * @return array{access_token: string, refresh_token?: string, expires_at?: int, scope?: string}
     */
    private function exchangeCode(
        string $code,
        string $redirectUri,
        string $clientId,
        string $clientSecret,
        string $codeVerifier,
    ): array {
        $postData = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code_verifier' => $codeVerifier,
        ];

        return $this->tokenRequest($postData);
    }

    /**
     * Refresh an access token using a refresh token.
     *
     * @return array{access_token: string, refresh_token?: string, expires_at?: int, scope?: string}
     */
    private function refreshToken(
        string $refreshToken,
        string $clientId,
        string $clientSecret,
    ): array {
        $postData = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];

        return $this->tokenRequest($postData);
    }

    /**
     * Make a token endpoint request.
     *
     * @param array<string, string> $postData
     *
     * @return array{access_token: string, refresh_token?: string, expires_at?: int, scope?: string}
     */
    private function tokenRequest(array $postData): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => http_build_query($postData),
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents(self::TOKEN_URL, false, $context);

        if ($response === false) {
            throw OAuthException::tokenExchangeFailed('Token endpoint request failed');
        }

        $data = json_decode($response, true);

        if (!is_array($data) || !isset($data['access_token'])) {
            $error = $data['error'] ?? 'unknown';
            $description = $data['error_description'] ?? 'Invalid token response';
            throw OAuthException::tokenExchangeFailed("{$error}: {$description}");
        }

        $result = [
            'access_token' => (string) $data['access_token'],
        ];

        if (isset($data['refresh_token'])) {
            $result['refresh_token'] = (string) $data['refresh_token'];
        }

        if (isset($data['expires_in'])) {
            $result['expires_at'] = time() + (int) $data['expires_in'];
        }

        if (isset($data['scope'])) {
            $result['scope'] = (string) $data['scope'];
        }

        return $result;
    }

    /**
     * Store tokens to disk.
     *
     * @param array{access_token: string, refresh_token?: string, expires_at?: int, scope?: string} $tokens
     */
    private function storeTokens(string $account, array $tokens): void
    {
        $dir = $this->tokensDir();

        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        $path = $this->tokensPath($account);
        $json = json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        file_put_contents($path, $json . "\n");
        chmod($path, 0o600);
    }

    /**
     * Load tokens from disk.
     *
     * @return array{access_token: string, refresh_token?: string, expires_at?: int, scope?: string}|null
     */
    private function loadTokens(string $account): ?array
    {
        $path = $this->tokensPath($account);

        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        if (!is_array($data) || !isset($data['access_token'])) {
            return null;
        }

        /** @var array{access_token: string, refresh_token?: string, expires_at?: int, scope?: string} $data */
        return $data;
    }

    /**
     * Lazy resolution of GMAIL_CLIENT_ID for hot-reload support.
     */
    private function resolveClientId(): string
    {
        $env = getenv('GMAIL_CLIENT_ID');

        return $env !== false ? $env : '';
    }

    /**
     * Lazy resolution of GMAIL_CLIENT_SECRET for hot-reload support.
     */
    private function resolveClientSecret(): string
    {
        $env = getenv('GMAIL_CLIENT_SECRET');

        return $env !== false ? $env : '';
    }

    private function tokensDir(): string
    {
        return rtrim($this->workspacePath, '/') . '/' . self::TOKENS_DIR;
    }

    private function tokensPath(string $account): string
    {
        $sanitized = preg_replace('/[^a-z0-9_-]/', '_', strtolower($account)) ?? $account;

        return $this->tokensDir() . '/' . $sanitized . '.json';
    }
}
