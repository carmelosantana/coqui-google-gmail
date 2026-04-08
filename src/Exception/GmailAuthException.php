<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitGoogleGmail\Exception;

/**
 * Thrown when Gmail authentication fails or tokens are invalid.
 */
final class GmailAuthException extends \RuntimeException
{
    public static function notAuthenticated(): self
    {
        return new self(
            'Not authenticated with Gmail — use gmail_auth(action: "login") to connect your account.',
        );
    }

    public static function tokenExpired(): self
    {
        return new self(
            'Gmail access token expired and could not be refreshed — use gmail_auth(action: "login") to re-authenticate.',
        );
    }

    public static function unauthorized(): self
    {
        return new self(
            'Gmail API returned 401 Unauthorized — your credentials may be invalid or revoked.',
        );
    }

    public static function forbidden(): self
    {
        return new self(
            'Gmail API returned 403 Forbidden — insufficient permissions or the Gmail API may not be enabled.',
        );
    }
}
