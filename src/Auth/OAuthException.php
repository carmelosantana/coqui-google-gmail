<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitGoogleGmail\Auth;

/**
 * OAuth-related errors for Gmail authentication.
 */
final class OAuthException extends \RuntimeException
{
    public static function configError(string $message): self
    {
        return new self('Gmail OAuth config error: ' . $message);
    }

    public static function authorizationFailed(string $error, string $description = ''): self
    {
        $message = 'Gmail OAuth authorization failed: ' . $error;

        if ($description !== '') {
            $message .= ' — ' . $description;
        }

        return new self($message);
    }

    public static function tokenExchangeFailed(string $reason): self
    {
        return new self('Gmail OAuth token exchange failed: ' . $reason);
    }

    public static function tokenRefreshFailed(string $reason): self
    {
        return new self('Gmail OAuth token refresh failed: ' . $reason);
    }
}
