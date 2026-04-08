<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitGoogleGmail\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\CoquiToolkitGoogleGmail\GmailClient;

/**
 * Gmail authentication management tool.
 *
 * Handles the OAuth2 lifecycle: status checks, browser-based login,
 * and token revocation.
 */
final readonly class AuthTool
{
    private const array ACTIONS = ['status', 'login', 'revoke'];

    public function __construct(
        private GmailClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'gmail_auth',
            description: 'Manage Gmail authentication: check connection status, log in via browser-based OAuth2, or revoke access. Call with action "login" to connect a Gmail account.',
            parameters: [
                new EnumParameter(
                    'action',
                    'The auth operation: "status" to check connection, "login" to authenticate via browser, "revoke" to disconnect',
                    self::ACTIONS,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'status' => $this->status(),
            'login' => $this->login(),
            'revoke' => $this->revoke(),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function status(): ToolResult
    {
        $status = $this->client->oauthHandler()->getStatus();

        if (!$status['authenticated']) {
            return ToolResult::success(json_encode([
                'authenticated' => false,
                'message' => 'Not connected to Gmail. Use gmail_auth(action: "login") to authenticate.',
            ], JSON_PRETTY_PRINT));
        }

        $expiresAt = $status['expires_at'] ?? null;
        $expiresIn = $expiresAt !== null ? max(0, $expiresAt - time()) : null;

        return ToolResult::success(json_encode([
            'authenticated' => true,
            'has_refresh_token' => $status['has_refresh_token'] ?? false,
            'token_expires_in_seconds' => $expiresIn,
            'scopes' => $status['scope'] ?? '',
        ], JSON_PRETTY_PRINT));
    }

    private function login(): ToolResult
    {
        try {
            $tokens = $this->client->oauthHandler()->authorize();

            return ToolResult::success(json_encode([
                'success' => true,
                'message' => 'Successfully authenticated with Gmail.',
                'has_refresh_token' => isset($tokens['refresh_token']),
                'scopes' => $tokens['scope'] ?? '',
            ], JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            return ToolResult::error('Gmail login failed: ' . $e->getMessage());
        }
    }

    private function revoke(): ToolResult
    {
        try {
            $revoked = $this->client->oauthHandler()->revoke();

            if (!$revoked) {
                return ToolResult::error('No Gmail account is currently connected.');
            }

            return ToolResult::success(json_encode([
                'success' => true,
                'message' => 'Gmail access has been revoked and tokens deleted.',
            ], JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            return ToolResult::error('Gmail revoke failed: ' . $e->getMessage());
        }
    }
}
