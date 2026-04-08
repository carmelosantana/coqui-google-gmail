<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitGoogleGmail;

use CarmeloSantana\CoquiToolkitGoogleGmail\Auth\OAuthHandler;
use CarmeloSantana\CoquiToolkitGoogleGmail\Exception\GmailApiException;
use CarmeloSantana\CoquiToolkitGoogleGmail\Exception\GmailAuthException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client wrapper for the Gmail REST API.
 *
 * Handles authentication via OAuthHandler, automatic token refresh on 401,
 * request construction, and response parsing. All Gmail API endpoints are
 * called relative to the base URL for the authenticated user (`users/me`).
 *
 * Uses lazy credential resolution for hot-reload support — after the LLM sets
 * credentials via the credentials tool, the next call picks them up immediately.
 *
 * @see https://developers.google.com/gmail/api/reference/rest
 */
final class GmailClient
{
    private const string BASE_URL = 'https://gmail.googleapis.com/gmail/v1/users/me';

    private HttpClientInterface $httpClient;

    public function __construct(
        private readonly OAuthHandler $oauthHandler,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => 30]);
    }

    /**
     * Factory method for ToolkitDiscovery — reads workspace path from environment.
     */
    public static function fromEnv(): self
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');
        $workspacePath = $workspacePath !== false ? $workspacePath : '.workspace';

        return new self(
            oauthHandler: new OAuthHandler($workspacePath),
        );
    }

    /**
     * Get the OAuthHandler instance (for auth tool operations).
     */
    public function oauthHandler(): OAuthHandler
    {
        return $this->oauthHandler;
    }

    /**
     * Check if the client has valid authentication.
     */
    public function isAuthenticated(): bool
    {
        return $this->oauthHandler->getAccessToken() !== null;
    }

    /**
     * Make a GET request to the Gmail API.
     *
     * @param array<string, mixed> $query Query parameters
     * @return array<string, mixed> Decoded JSON response
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, query: $query);
    }

    /**
     * Make a POST request to the Gmail API.
     *
     * @param array<string, mixed>|string|null $body Request body (array is JSON-encoded)
     * @param array<string, mixed> $query Query parameters
     * @return array<string, mixed> Decoded JSON response
     */
    public function post(string $endpoint, array|string|null $body = null, array $query = []): array
    {
        return $this->request('POST', $endpoint, query: $query, body: $body);
    }

    /**
     * Make a PUT request to the Gmail API.
     *
     * @param array<string, mixed>|string|null $body Request body
     * @return array<string, mixed> Decoded JSON response
     */
    public function put(string $endpoint, array|string|null $body = null): array
    {
        return $this->request('PUT', $endpoint, body: $body);
    }

    /**
     * Make a PATCH request to the Gmail API.
     *
     * @param array<string, mixed>|string|null $body Request body
     * @return array<string, mixed> Decoded JSON response
     */
    public function patch(string $endpoint, array|string|null $body = null): array
    {
        return $this->request('PATCH', $endpoint, body: $body);
    }

    /**
     * Make a DELETE request to the Gmail API.
     *
     * @return array<string, mixed> Decoded JSON response (may be empty)
     */
    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Execute an authenticated request against the Gmail API.
     *
     * Automatically resolves the access token, builds the full URL,
     * and handles 401 responses by attempting a token refresh and retry.
     *
     * @param array<string, mixed> $query Query parameters
     * @param array<string, mixed>|string|null $body Request body
     * @return array<string, mixed> Decoded JSON response
     */
    private function request(
        string $method,
        string $endpoint,
        array $query = [],
        array|string|null $body = null,
    ): array {
        $accessToken = $this->oauthHandler->getAccessToken();

        if ($accessToken === null) {
            throw GmailAuthException::notAuthenticated();
        }

        $url = self::BASE_URL . '/' . ltrim($endpoint, '/');

        $response = $this->doRequest($method, $url, $accessToken, $query, $body);

        // On 401, attempt token refresh and retry once
        if ($response['_status'] === 401) {
            $newToken = $this->oauthHandler->getAccessToken();

            if ($newToken === null || $newToken === $accessToken) {
                throw GmailAuthException::tokenExpired();
            }

            $response = $this->doRequest($method, $url, $newToken, $query, $body);

            if ($response['_status'] === 401) {
                throw GmailAuthException::tokenExpired();
            }
        }

        $statusCode = $response['_status'];
        unset($response['_status']);

        if ($statusCode === 403) {
            throw GmailAuthException::forbidden();
        }

        if ($statusCode === 429) {
            throw GmailApiException::rateLimited();
        }

        if ($statusCode >= 400) {
            $errorMessage = $response['error']['message']
                ?? $response['error_description']
                ?? 'Unknown error';
            throw GmailApiException::fromResponse($statusCode, $errorMessage, $endpoint);
        }

        return $response;
    }

    /**
     * Perform a single HTTP request.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed>|string|null $body
     * @return array<string, mixed> Response data with extra '_status' key
     */
    private function doRequest(
        string $method,
        string $url,
        string $accessToken,
        array $query = [],
        array|string|null $body = null,
    ): array {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
        ];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($body !== null) {
            if (is_array($body)) {
                $options['headers']['Content-Type'] = 'application/json';
                $options['body'] = json_encode($body, JSON_THROW_ON_ERROR);
            } else {
                $options['headers']['Content-Type'] = 'message/rfc822';
                $options['body'] = $body;
            }
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();

            // DELETE and some POST operations return 204 No Content
            $content = $response->getContent(false);

            if ($content === '') {
                return ['_status' => $statusCode];
            }

            $data = json_decode($content, true);

            if (!is_array($data)) {
                return ['_status' => $statusCode];
            }

            $data['_status'] = $statusCode;

            return $data;
        } catch (HttpExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $content = $e->getResponse()->getContent(false);

            $data = json_decode($content, true);

            if (is_array($data)) {
                $data['_status'] = $statusCode;

                return $data;
            }

            return ['_status' => $statusCode, 'error' => ['message' => $e->getMessage()]];
        } catch (\Throwable $e) {
            throw GmailApiException::connectionFailed($url, $e->getMessage());
        }
    }
}
