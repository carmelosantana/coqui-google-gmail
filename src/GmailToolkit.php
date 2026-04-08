<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitGoogleGmail;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\CoquiToolkitGoogleGmail\Tool\AuthTool;
use CarmeloSantana\CoquiToolkitGoogleGmail\Tool\DraftTool;
use CarmeloSantana\CoquiToolkitGoogleGmail\Tool\LabelTool;
use CarmeloSantana\CoquiToolkitGoogleGmail\Tool\MessageTool;

/**
 * Google Gmail toolkit for Coqui.
 *
 * Provides 4 domain-level tools covering Gmail operations:
 * authentication, messages, labels, and drafts — all via the Gmail REST API
 * with OAuth2 browser-based authentication.
 *
 * Each tool exposes an `action` enum parameter that dispatches to specific
 * Gmail API endpoints.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 * Credentials (GMAIL_CLIENT_ID, GMAIL_CLIENT_SECRET) are managed through
 * Coqui's credential system.
 *
 * @see https://developers.google.com/gmail/api/reference/rest
 */
final class GmailToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly GmailClient $client,
    ) {}

    /**
     * Factory method for ToolkitDiscovery — reads workspace path from environment.
     */
    public static function fromEnv(): self
    {
        return new self(client: GmailClient::fromEnv());
    }

    public function tools(): array
    {
        return [
            (new AuthTool($this->client))->build(),
            (new MessageTool($this->client))->build(),
            (new LabelTool($this->client))->build(),
            (new DraftTool($this->client))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <GMAIL-TOOLKIT-GUIDELINES>
            ## Gmail Email Management

            You have access to Gmail through 4 tools covering authentication,
            messages, labels, and drafts via the Gmail REST API.

            ### Getting Started
            1. **Authenticate first**: Call `gmail_auth(action: "status")` to check connection
            2. If not connected, call `gmail_auth(action: "login")` — this opens a browser for Google sign-in
            3. Once authenticated, all other tools work automatically

            ### Tool Overview
            - `gmail_auth` — OAuth2 lifecycle: check status, login via browser, revoke access
            - `gmail_message` — Full message lifecycle: list, search, read, send, reply, forward, trash, delete, modify labels
            - `gmail_label` — Label management: list (with unread counts), create, update, delete custom labels
            - `gmail_draft` — Draft lifecycle: list, read, create, update, send as email, delete

            ### Gmail Search Syntax (for gmail_message search action)
            Gmail supports powerful search operators via the `query` parameter:
            - `from:user@example.com` — messages from a specific sender
            - `to:user@example.com` — messages to a specific recipient
            - `subject:meeting` — messages with "meeting" in the subject
            - `is:unread` — unread messages
            - `is:starred` — starred messages
            - `has:attachment` — messages with attachments
            - `after:2024/01/01` — messages after a date
            - `before:2024/12/31` — messages before a date
            - `label:important` — messages with a specific label
            - `in:inbox` / `in:sent` / `in:trash` — messages in a specific location
            - Combine operators: `from:boss@work.com is:unread after:2024/06/01`

            ### Common Label IDs
            - `INBOX` — inbox messages
            - `SENT` — sent messages
            - `DRAFT` — draft messages
            - `TRASH` — trashed messages
            - `SPAM` — spam messages
            - `UNREAD` — unread flag (add to mark unread, remove to mark read)
            - `STARRED` — starred flag
            - `IMPORTANT` — important flag
            - `CATEGORY_PERSONAL`, `CATEGORY_SOCIAL`, `CATEGORY_PROMOTIONS`, `CATEGORY_UPDATES`, `CATEGORY_FORUMS` — Gmail categories

            ### Common Workflows

            **Check unread mail:**
            1. `gmail_message(action: "search", query: "is:unread", max_results: 10)`

            **Read and reply to a message:**
            1. `gmail_message(action: "get", message_id: "...")`
            2. `gmail_message(action: "reply", message_id: "...", body: "...")`

            **Compose and send:**
            1. `gmail_message(action: "send", to: "user@example.com", subject: "Hello", body: "...")`

            **Draft workflow:**
            1. `gmail_draft(action: "create", to: "user@example.com", subject: "...", body: "...")`
            2. Review: `gmail_draft(action: "get", draft_id: "...")`
            3. Send: `gmail_draft(action: "send", draft_id: "...")`

            **Organize with labels:**
            1. Mark as read: `gmail_message(action: "modify", message_id: "...", remove_label_ids: "UNREAD")`
            2. Archive: `gmail_message(action: "modify", message_id: "...", remove_label_ids: "INBOX")`
            3. Star: `gmail_message(action: "modify", message_id: "...", add_label_ids: "STARRED")`

            ### Gating Summary
            These actions require user confirmation:
            - **gmail_message**: send, reply, forward, trash, untrash, delete, modify
            - **gmail_draft**: send, delete
            - **gmail_label**: create, delete, update
            - **gmail_auth**: revoke

            ### Best Practices
            - Use `search` with Gmail query syntax instead of `list` for targeted lookups
            - Prefer `trash` over `delete` — trash is reversible, delete is permanent
            - Always quote the original message content when replying or forwarding
            - Check unread count via `gmail_label(action: "get", label_id: "INBOX")` before listing all messages
            - Use pagination (`page_token`) for large result sets instead of setting very high `max_results`
            </GMAIL-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
