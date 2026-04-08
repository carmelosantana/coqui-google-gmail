<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitGoogleGmail\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\CoquiToolkitGoogleGmail\GmailClient;

/**
 * Gmail message management tool.
 *
 * Covers listing, searching, reading, sending, replying, forwarding,
 * trashing, and modifying messages via the Gmail REST API.
 */
final readonly class MessageTool
{
    private const array ACTIONS = [
        'list', 'search', 'get', 'send', 'reply', 'forward',
        'trash', 'untrash', 'delete', 'modify',
    ];

    private const int MAX_BODY_LENGTH = 10_000;
    private const int DEFAULT_MAX_RESULTS = 20;

    public function __construct(
        private GmailClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'gmail_message',
            description: 'Manage Gmail messages: list inbox, search with Gmail query syntax, read full messages, send new emails, reply/forward, trash/delete, or modify labels (mark read/unread, star, archive).',
            parameters: [
                new EnumParameter('action', 'The message operation to perform', self::ACTIONS),
                new StringParameter('message_id', 'Message ID (for get, reply, forward, trash, untrash, delete, modify)', required: false),
                new StringParameter('to', 'Recipient email address(es), comma-separated (for send, forward)', required: false),
                new StringParameter('subject', 'Email subject (for send)', required: false),
                new StringParameter('body', 'Email body text (for send, reply, forward)', required: false),
                new StringParameter('cc', 'CC recipients, comma-separated', required: false),
                new StringParameter('bcc', 'BCC recipients, comma-separated', required: false),
                new StringParameter('query', 'Gmail search query (for search) — e.g. "from:user@example.com is:unread", "subject:invoice after:2024/01/01"', required: false),
                new NumberParameter('max_results', 'Max messages to return (1-100)', required: false, integer: true, minimum: 1, maximum: 100),
                new StringParameter('page_token', 'Pagination token from a previous list/search result', required: false),
                new StringParameter('label_ids', 'Comma-separated label IDs to filter by (for list)', required: false),
                new StringParameter('add_label_ids', 'Comma-separated label IDs to add (for modify)', required: false),
                new StringParameter('remove_label_ids', 'Comma-separated label IDs to remove (for modify)', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        try {
            return match ($action) {
                'list' => $this->listMessages($args),
                'search' => $this->searchMessages($args),
                'get' => $this->getMessage($args),
                'send' => $this->sendMessage($args),
                'reply' => $this->replyToMessage($args),
                'forward' => $this->forwardMessage($args),
                'trash' => $this->trashMessage($args),
                'untrash' => $this->untrashMessage($args),
                'delete' => $this->deleteMessage($args),
                'modify' => $this->modifyMessage($args),
                default => ToolResult::error("Unknown action: {$action}"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('Gmail error: ' . $e->getMessage());
        }
    }

    /**
     * List messages in the inbox (optionally filtered by label).
     */
    private function listMessages(array $args): ToolResult
    {
        $query = [];
        $query['maxResults'] = (int) ($args['max_results'] ?? self::DEFAULT_MAX_RESULTS);

        $labelIds = trim((string) ($args['label_ids'] ?? ''));
        if ($labelIds !== '') {
            $query['labelIds'] = array_map('trim', explode(',', $labelIds));
        } else {
            $query['labelIds'] = ['INBOX'];
        }

        $pageToken = trim((string) ($args['page_token'] ?? ''));
        if ($pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }

        $response = $this->client->get('messages', $query);

        return $this->formatMessageList($response);
    }

    /**
     * Search messages using Gmail query syntax.
     */
    private function searchMessages(array $args): ToolResult
    {
        $searchQuery = trim((string) ($args['query'] ?? ''));
        if ($searchQuery === '') {
            return ToolResult::error('query is required for search.');
        }

        $query = [
            'q' => $searchQuery,
            'maxResults' => (int) ($args['max_results'] ?? self::DEFAULT_MAX_RESULTS),
        ];

        $pageToken = trim((string) ($args['page_token'] ?? ''));
        if ($pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }

        $response = $this->client->get('messages', $query);

        return $this->formatMessageList($response);
    }

    /**
     * Get a single message with full content.
     */
    private function getMessage(array $args): ToolResult
    {
        $messageId = trim((string) ($args['message_id'] ?? ''));
        if ($messageId === '') {
            return ToolResult::error('message_id is required for get.');
        }

        $response = $this->client->get("messages/{$messageId}", ['format' => 'full']);

        return ToolResult::success(json_encode(
            $this->parseMessage($response),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * Send a new email.
     */
    private function sendMessage(array $args): ToolResult
    {
        $to = trim((string) ($args['to'] ?? ''));
        $subject = trim((string) ($args['subject'] ?? ''));
        $body = (string) ($args['body'] ?? '');

        if ($to === '') {
            return ToolResult::error('to is required for send.');
        }

        if ($subject === '') {
            return ToolResult::error('subject is required for send.');
        }

        $rawMessage = $this->buildRawMessage(
            to: $to,
            subject: $subject,
            body: $body,
            cc: trim((string) ($args['cc'] ?? '')),
            bcc: trim((string) ($args['bcc'] ?? '')),
        );

        $response = $this->client->post('messages/send', [
            'raw' => $rawMessage,
        ]);

        return ToolResult::success(json_encode([
            'success' => true,
            'message_id' => $response['id'] ?? null,
            'thread_id' => $response['threadId'] ?? null,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Reply to an existing message (preserves threading).
     */
    private function replyToMessage(array $args): ToolResult
    {
        $messageId = trim((string) ($args['message_id'] ?? ''));
        $body = (string) ($args['body'] ?? '');

        if ($messageId === '') {
            return ToolResult::error('message_id is required for reply.');
        }

        if ($body === '') {
            return ToolResult::error('body is required for reply.');
        }

        // Fetch the original message to get threading headers
        $original = $this->client->get("messages/{$messageId}", ['format' => 'metadata', 'metadataHeaders' => 'Subject,From,To,Message-ID,References,In-Reply-To']);
        $headers = $this->extractHeaders($original);

        $originalFrom = $headers['From'] ?? '';
        $originalSubject = $headers['Subject'] ?? '';
        $originalMessageId = $headers['Message-ID'] ?? '';
        $references = $headers['References'] ?? '';
        $threadId = $original['threadId'] ?? '';

        // Build References header: existing references + original Message-ID
        $newReferences = trim($references . ' ' . $originalMessageId);

        $subject = $originalSubject;
        if (!str_starts_with(strtolower($subject), 're:')) {
            $subject = 'Re: ' . $subject;
        }

        $rawMessage = $this->buildRawMessage(
            to: $originalFrom,
            subject: $subject,
            body: $body,
            inReplyTo: $originalMessageId,
            references: $newReferences,
        );

        $response = $this->client->post('messages/send', [
            'raw' => $rawMessage,
            'threadId' => $threadId,
        ]);

        return ToolResult::success(json_encode([
            'success' => true,
            'message_id' => $response['id'] ?? null,
            'thread_id' => $response['threadId'] ?? null,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Forward a message to another recipient.
     */
    private function forwardMessage(array $args): ToolResult
    {
        $messageId = trim((string) ($args['message_id'] ?? ''));
        $to = trim((string) ($args['to'] ?? ''));

        if ($messageId === '') {
            return ToolResult::error('message_id is required for forward.');
        }

        if ($to === '') {
            return ToolResult::error('to is required for forward.');
        }

        // Fetch the original message
        $original = $this->client->get("messages/{$messageId}", ['format' => 'full']);
        $parsed = $this->parseMessage($original);

        $originalSubject = $parsed['subject'] ?? '';
        $subject = $originalSubject;
        if (!str_starts_with(strtolower($subject), 'fwd:')) {
            $subject = 'Fwd: ' . $subject;
        }

        $forwardPrefix = trim((string) ($args['body'] ?? ''));
        $originalBody = $parsed['body'] ?? '';

        $forwardBody = '';
        if ($forwardPrefix !== '') {
            $forwardBody .= $forwardPrefix . "\n\n";
        }
        $forwardBody .= "---------- Forwarded message ----------\n";
        $forwardBody .= 'From: ' . ($parsed['from'] ?? '') . "\n";
        $forwardBody .= 'Date: ' . ($parsed['date'] ?? '') . "\n";
        $forwardBody .= 'Subject: ' . $originalSubject . "\n";
        $forwardBody .= 'To: ' . ($parsed['to'] ?? '') . "\n\n";
        $forwardBody .= $originalBody;

        $rawMessage = $this->buildRawMessage(
            to: $to,
            subject: $subject,
            body: $forwardBody,
            cc: trim((string) ($args['cc'] ?? '')),
            bcc: trim((string) ($args['bcc'] ?? '')),
        );

        $response = $this->client->post('messages/send', [
            'raw' => $rawMessage,
        ]);

        return ToolResult::success(json_encode([
            'success' => true,
            'message_id' => $response['id'] ?? null,
            'thread_id' => $response['threadId'] ?? null,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Move a message to trash.
     */
    private function trashMessage(array $args): ToolResult
    {
        $messageId = trim((string) ($args['message_id'] ?? ''));
        if ($messageId === '') {
            return ToolResult::error('message_id is required for trash.');
        }

        $this->client->post("messages/{$messageId}/trash");

        return ToolResult::success(json_encode([
            'success' => true,
            'message' => "Message {$messageId} moved to trash.",
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Restore a message from trash.
     */
    private function untrashMessage(array $args): ToolResult
    {
        $messageId = trim((string) ($args['message_id'] ?? ''));
        if ($messageId === '') {
            return ToolResult::error('message_id is required for untrash.');
        }

        $this->client->post("messages/{$messageId}/untrash");

        return ToolResult::success(json_encode([
            'success' => true,
            'message' => "Message {$messageId} restored from trash.",
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Permanently delete a message (cannot be undone).
     */
    private function deleteMessage(array $args): ToolResult
    {
        $messageId = trim((string) ($args['message_id'] ?? ''));
        if ($messageId === '') {
            return ToolResult::error('message_id is required for delete.');
        }

        $this->client->delete("messages/{$messageId}");

        return ToolResult::success(json_encode([
            'success' => true,
            'message' => "Message {$messageId} permanently deleted.",
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Modify message labels (mark read/unread, star, archive, etc.).
     */
    private function modifyMessage(array $args): ToolResult
    {
        $messageId = trim((string) ($args['message_id'] ?? ''));
        if ($messageId === '') {
            return ToolResult::error('message_id is required for modify.');
        }

        $body = [];

        $addLabels = trim((string) ($args['add_label_ids'] ?? ''));
        if ($addLabels !== '') {
            $body['addLabelIds'] = array_map('trim', explode(',', $addLabels));
        }

        $removeLabels = trim((string) ($args['remove_label_ids'] ?? ''));
        if ($removeLabels !== '') {
            $body['removeLabelIds'] = array_map('trim', explode(',', $removeLabels));
        }

        if ($body === []) {
            return ToolResult::error('At least one of add_label_ids or remove_label_ids is required for modify.');
        }

        $this->client->post("messages/{$messageId}/modify", $body);

        return ToolResult::success(json_encode([
            'success' => true,
            'message' => "Message {$messageId} labels modified.",
            'added' => $body['addLabelIds'] ?? [],
            'removed' => $body['removeLabelIds'] ?? [],
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Format a list/search response by fetching snippets for each message.
     */
    private function formatMessageList(array $response): ToolResult
    {
        $messages = $response['messages'] ?? [];
        $nextPageToken = $response['nextPageToken'] ?? null;
        $resultSizeEstimate = $response['resultSizeEstimate'] ?? 0;

        if ($messages === []) {
            return ToolResult::success(json_encode([
                'messages' => [],
                'total_estimate' => $resultSizeEstimate,
            ], JSON_PRETTY_PRINT));
        }

        // Fetch metadata for each message to get useful snippets
        $enriched = [];
        foreach ($messages as $msg) {
            $id = $msg['id'] ?? '';
            if ($id === '') {
                continue;
            }

            try {
                $detail = $this->client->get("messages/{$id}", [
                    'format' => 'metadata',
                    'metadataHeaders' => 'Subject,From,Date',
                ]);

                $headers = $this->extractHeaders($detail);
                $enriched[] = [
                    'id' => $id,
                    'thread_id' => $msg['threadId'] ?? $detail['threadId'] ?? '',
                    'subject' => $headers['Subject'] ?? '',
                    'from' => $headers['From'] ?? '',
                    'date' => $headers['Date'] ?? '',
                    'snippet' => $detail['snippet'] ?? '',
                    'label_ids' => $detail['labelIds'] ?? [],
                ];
            } catch (\Throwable) {
                $enriched[] = [
                    'id' => $id,
                    'thread_id' => $msg['threadId'] ?? '',
                ];
            }
        }

        $result = [
            'messages' => $enriched,
            'total_estimate' => $resultSizeEstimate,
        ];

        if ($nextPageToken !== null) {
            $result['next_page_token'] = $nextPageToken;
        }

        return ToolResult::success(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Parse a full message response into a structured format.
     *
     * @return array<string, mixed>
     */
    private function parseMessage(array $response): array
    {
        $headers = $this->extractHeaders($response);

        $body = $this->decodeBody($response['payload'] ?? []);

        // Truncate body to prevent context overflow
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            $body = mb_substr($body, 0, self::MAX_BODY_LENGTH) . "\n\n[... truncated — message exceeds " . self::MAX_BODY_LENGTH . ' characters]';
        }

        $attachments = $this->extractAttachments($response['payload'] ?? []);

        return [
            'id' => $response['id'] ?? '',
            'thread_id' => $response['threadId'] ?? '',
            'subject' => $headers['Subject'] ?? '',
            'from' => $headers['From'] ?? '',
            'to' => $headers['To'] ?? '',
            'cc' => $headers['Cc'] ?? '',
            'date' => $headers['Date'] ?? '',
            'label_ids' => $response['labelIds'] ?? [],
            'snippet' => $response['snippet'] ?? '',
            'body' => $body,
            'attachments' => $attachments,
        ];
    }

    /**
     * Extract headers from a Gmail API message response.
     *
     * @return array<string, string>
     */
    private function extractHeaders(array $response): array
    {
        $result = [];
        $headers = $response['payload']['headers'] ?? [];

        foreach ($headers as $header) {
            $name = $header['name'] ?? '';
            $value = $header['value'] ?? '';
            if ($name !== '') {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Decode the message body from a Gmail MIME payload.
     *
     * Prefers text/plain, falls back to text/html with strip_tags().
     */
    private function decodeBody(array $payload): string
    {
        // Simple single-part message
        $mimeType = $payload['mimeType'] ?? '';
        $bodyData = $payload['body']['data'] ?? '';

        if ($mimeType === 'text/plain' && $bodyData !== '') {
            return $this->base64UrlDecode($bodyData);
        }

        // Multi-part message — search through parts
        $parts = $payload['parts'] ?? [];
        $plainText = '';
        $htmlText = '';

        foreach ($parts as $part) {
            $partMime = $part['mimeType'] ?? '';
            $partData = $part['body']['data'] ?? '';

            if ($partMime === 'text/plain' && $partData !== '') {
                $plainText = $this->base64UrlDecode($partData);
            } elseif ($partMime === 'text/html' && $partData !== '') {
                $htmlText = $this->base64UrlDecode($partData);
            }

            // Check nested parts (multipart/alternative inside multipart/mixed)
            if (isset($part['parts'])) {
                foreach ($part['parts'] as $subPart) {
                    $subMime = $subPart['mimeType'] ?? '';
                    $subData = $subPart['body']['data'] ?? '';

                    if ($subMime === 'text/plain' && $subData !== '') {
                        $plainText = $this->base64UrlDecode($subData);
                    } elseif ($subMime === 'text/html' && $subData !== '' && $htmlText === '') {
                        $htmlText = $this->base64UrlDecode($subData);
                    }
                }
            }
        }

        if ($plainText !== '') {
            return $plainText;
        }

        if ($htmlText !== '') {
            // Convert HTML to readable text
            $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlText));

            return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Single-part HTML
        if ($mimeType === 'text/html' && $bodyData !== '') {
            $html = $this->base64UrlDecode($bodyData);
            $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $html));

            return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    /**
     * Extract attachment metadata from a message payload.
     *
     * @return list<array{filename: string, mime_type: string, size: int, attachment_id: string}>
     */
    private function extractAttachments(array $payload): array
    {
        $attachments = [];

        $parts = $payload['parts'] ?? [];

        foreach ($parts as $part) {
            $filename = $part['filename'] ?? '';
            $attachmentId = $part['body']['attachmentId'] ?? '';

            if ($filename !== '' && $attachmentId !== '') {
                $attachments[] = [
                    'filename' => $filename,
                    'mime_type' => $part['mimeType'] ?? '',
                    'size' => (int) ($part['body']['size'] ?? 0),
                    'attachment_id' => $attachmentId,
                ];
            }

            // Check nested parts
            if (isset($part['parts'])) {
                foreach ($part['parts'] as $subPart) {
                    $subFilename = $subPart['filename'] ?? '';
                    $subAttachmentId = $subPart['body']['attachmentId'] ?? '';

                    if ($subFilename !== '' && $subAttachmentId !== '') {
                        $attachments[] = [
                            'filename' => $subFilename,
                            'mime_type' => $subPart['mimeType'] ?? '',
                            'size' => (int) ($subPart['body']['size'] ?? 0),
                            'attachment_id' => $subAttachmentId,
                        ];
                    }
                }
            }
        }

        return $attachments;
    }

    /**
     * Build an RFC 2822 email message and encode it as base64url.
     */
    private function buildRawMessage(
        string $to,
        string $subject,
        string $body,
        string $cc = '',
        string $bcc = '',
        string $inReplyTo = '',
        string $references = '',
    ): string {
        $headers = [];
        $headers[] = 'To: ' . $to;
        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $headers[] = 'MIME-Version: 1.0';

        if ($cc !== '') {
            $headers[] = 'Cc: ' . $cc;
        }

        if ($bcc !== '') {
            $headers[] = 'Bcc: ' . $bcc;
        }

        if ($inReplyTo !== '') {
            $headers[] = 'In-Reply-To: ' . $inReplyTo;
        }

        if ($references !== '') {
            $headers[] = 'References: ' . $references;
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n" . base64_encode($body);

        return $this->base64UrlEncode($message);
    }

    /**
     * Decode base64url-encoded data (Gmail API format).
     */
    private function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded !== false ? $decoded : '';
    }

    /**
     * Encode data as base64url (Gmail API format).
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
