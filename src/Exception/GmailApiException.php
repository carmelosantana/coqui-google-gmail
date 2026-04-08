<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitGoogleGmail\Exception;

/**
 * Thrown when a Gmail REST API call returns an error response.
 */
final class GmailApiException extends \RuntimeException
{
    public static function fromResponse(int $statusCode, string $message, string $endpoint): self
    {
        return new self(
            sprintf('Gmail API error %d on %s: %s', $statusCode, $endpoint, $message),
            $statusCode,
        );
    }

    public static function rateLimited(): self
    {
        return new self('Gmail API rate limit exceeded — wait a moment and try again.', 429);
    }

    public static function invalidResponse(string $reason): self
    {
        return new self('Invalid Gmail API response: ' . $reason);
    }

    public static function connectionFailed(string $endpoint, string $reason): self
    {
        return new self(sprintf('Failed to connect to Gmail API at %s: %s', $endpoint, $reason));
    }
}
