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
 * Gmail draft management tool.
 *
 * Supports listing, reading, creating, updating, sending, and deleting drafts.
 */
final readonly class DraftTool
{
    private const array ACTIONS = ['list', 'get', 'create', 'update', 'send', 'delete'];
    private const int DEFAULT_MAX_RESULTS = 20;
    private const int MAX_BODY_LENGTH = 10_000;

    public function __construct(
        private GmailClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'gmail_draft',
            description: 'Manage Gmail drafts: list all drafts, read draft content, create new drafts, update existing drafts, send a draft as an email, or delete drafts.',
            parameters: [
                new EnumParameter('action', 'The draft operation to perform', self::ACTIONS),
                new StringParameter('draft_id', 'Draft ID (for get, update, send, delete)', required: false),
                new StringParameter('to', 'Recipient email address(es), comma-separated (for create, update)', required: false),
                new StringParameter('subject', 'Email subject (for create, update)', required: false),
                new StringParameter('body', 'Email body text (for create, update)', required: false),
                new StringParameter('cc', 'CC recipients, comma-separated (for create, update)', required: false),
                new StringParameter('bcc', 'BCC recipients, comma-separated (for create, update)', required: false),
                new NumberParameter('max_results', 'Max drafts to return (1-100)', required: false, integer: true, minimum: 1, maximum: 100),
                new StringParameter('page_token', 'Pagination token from a previous list result', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        try {
            return match ($action) {
                'list' => $this->listDrafts($args),
                'get' => $this->getDraft($args),
                'create' => $this->createDraft($args),
                'update' => $this->updateDraft($args),
                'send' => $this->sendDraft($args),
                'delete' => $this->deleteDraft($args),
                default => ToolResult::error("Unknown action: {$action}"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('Gmail draft error: ' . $e->getMessage());
        }
    }

    private function listDrafts(array $args): ToolResult
    {
        $query = [
            'maxResults' => (int) ($args['max_results'] ?? self::DEFAULT_MAX_RESULTS),
        ];

        $pageToken = trim((string) ($args['page_token'] ?? ''));
        if ($pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }

        $response = $this->client->get('drafts', $query);
        $drafts = $response['drafts'] ?? [];
        $nextPageToken = $response['nextPageToken'] ?? null;

        $formatted = [];
        foreach ($drafts as $draft) {
            $draftId = $draft['id'] ?? '';
            $message = $draft['message'] ?? [];

            $formatted[] = [
                'draft_id' => $draftId,
                'message_id' => $message['id'] ?? '',
                'thread_id' => $message['threadId'] ?? '',
            ];
        }

        $result = [
            'drafts' => $formatted,
            'count' => count($formatted),
        ];

        if ($nextPageToken !== null) {
            $result['next_page_token'] = $nextPageToken;
        }

        return ToolResult::success(json_encode($result, JSON_PRETTY_PRINT));
    }

    private function getDraft(array $args): ToolResult
    {
        $draftId = trim((string) ($args['draft_id'] ?? ''));
        if ($draftId === '') {
            return ToolResult::error('draft_id is required for get.');
        }

        $response = $this->client->get("drafts/{$draftId}", ['format' => 'full']);

        $message = $response['message'] ?? [];
        $headers = $this->extractHeaders($message);
        $body = $this->decodeBody($message['payload'] ?? []);

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            $body = mb_substr($body, 0, self::MAX_BODY_LENGTH) . "\n\n[... truncated]";
        }

        return ToolResult::success(json_encode([
            'draft_id' => $response['id'] ?? '',
            'message_id' => $message['id'] ?? '',
            'thread_id' => $message['threadId'] ?? '',
            'subject' => $headers['Subject'] ?? '',
            'to' => $headers['To'] ?? '',
            'cc' => $headers['Cc'] ?? '',
            'bcc' => $headers['Bcc'] ?? '',
            'body' => $body,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function createDraft(array $args): ToolResult
    {
        $to = trim((string) ($args['to'] ?? ''));
        $subject = trim((string) ($args['subject'] ?? ''));
        $body = (string) ($args['body'] ?? '');

        $rawMessage = $this->buildRawMessage(
            to: $to,
            subject: $subject,
            body: $body,
            cc: trim((string) ($args['cc'] ?? '')),
            bcc: trim((string) ($args['bcc'] ?? '')),
        );

        $response = $this->client->post('drafts', [
            'message' => [
                'raw' => $rawMessage,
            ],
        ]);

        return ToolResult::success(json_encode([
            'success' => true,
            'draft_id' => $response['id'] ?? '',
            'message_id' => $response['message']['id'] ?? '',
        ], JSON_PRETTY_PRINT));
    }

    private function updateDraft(array $args): ToolResult
    {
        $draftId = trim((string) ($args['draft_id'] ?? ''));
        if ($draftId === '') {
            return ToolResult::error('draft_id is required for update.');
        }

        $to = trim((string) ($args['to'] ?? ''));
        $subject = trim((string) ($args['subject'] ?? ''));
        $body = (string) ($args['body'] ?? '');

        $rawMessage = $this->buildRawMessage(
            to: $to,
            subject: $subject,
            body: $body,
            cc: trim((string) ($args['cc'] ?? '')),
            bcc: trim((string) ($args['bcc'] ?? '')),
        );

        $response = $this->client->put("drafts/{$draftId}", [
            'message' => [
                'raw' => $rawMessage,
            ],
        ]);

        return ToolResult::success(json_encode([
            'success' => true,
            'draft_id' => $response['id'] ?? '',
            'message_id' => $response['message']['id'] ?? '',
        ], JSON_PRETTY_PRINT));
    }

    private function sendDraft(array $args): ToolResult
    {
        $draftId = trim((string) ($args['draft_id'] ?? ''));
        if ($draftId === '') {
            return ToolResult::error('draft_id is required for send.');
        }

        $response = $this->client->post('drafts/send', [
            'id' => $draftId,
        ]);

        return ToolResult::success(json_encode([
            'success' => true,
            'message_id' => $response['id'] ?? '',
            'thread_id' => $response['threadId'] ?? '',
            'label_ids' => $response['labelIds'] ?? [],
        ], JSON_PRETTY_PRINT));
    }

    private function deleteDraft(array $args): ToolResult
    {
        $draftId = trim((string) ($args['draft_id'] ?? ''));
        if ($draftId === '') {
            return ToolResult::error('draft_id is required for delete.');
        }

        $this->client->delete("drafts/{$draftId}");

        return ToolResult::success(json_encode([
            'success' => true,
            'message' => "Draft {$draftId} deleted.",
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Extract headers from a Gmail API message response.
     *
     * @return array<string, string>
     */
    private function extractHeaders(array $message): array
    {
        $result = [];
        $headers = $message['payload']['headers'] ?? [];

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
     */
    private function decodeBody(array $payload): string
    {
        $mimeType = $payload['mimeType'] ?? '';
        $bodyData = $payload['body']['data'] ?? '';

        if ($mimeType === 'text/plain' && $bodyData !== '') {
            return $this->base64UrlDecode($bodyData);
        }

        $parts = $payload['parts'] ?? [];
        foreach ($parts as $part) {
            $partMime = $part['mimeType'] ?? '';
            $partData = $part['body']['data'] ?? '';

            if ($partMime === 'text/plain' && $partData !== '') {
                return $this->base64UrlDecode($partData);
            }
        }

        // Fallback to HTML
        if ($mimeType === 'text/html' && $bodyData !== '') {
            $html = $this->base64UrlDecode($bodyData);

            return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        foreach ($parts as $part) {
            $partMime = $part['mimeType'] ?? '';
            $partData = $part['body']['data'] ?? '';

            if ($partMime === 'text/html' && $partData !== '') {
                $html = $this->base64UrlDecode($partData);

                return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return '';
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
    ): string {
        $headers = [];

        if ($to !== '') {
            $headers[] = 'To: ' . $to;
        }

        if ($subject !== '') {
            $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        }

        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $headers[] = 'MIME-Version: 1.0';

        if ($cc !== '') {
            $headers[] = 'Cc: ' . $cc;
        }

        if ($bcc !== '') {
            $headers[] = 'Bcc: ' . $bcc;
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n" . base64_encode($body);

        return rtrim(strtr(base64_encode($message), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded !== false ? $decoded : '';
    }
}
