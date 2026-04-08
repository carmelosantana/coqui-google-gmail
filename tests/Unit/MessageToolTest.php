<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitGoogleGmail\Tool\MessageTool;
use CarmeloSantana\CoquiToolkitGoogleGmail\Auth\OAuthHandler;
use CarmeloSantana\CoquiToolkitGoogleGmail\GmailClient;

test('message tool has correct name', function () {
    $tool = (new MessageTool(createTestClient()))->build();

    expect($tool->name())->toBe('gmail_message');
});

test('message tool has action parameter with all actions', function () {
    $tool = (new MessageTool(createTestClient()))->build();
    $schema = $tool->toFunctionSchema();

    $actionParam = $schema['function']['parameters']['properties']['action'] ?? [];
    expect($actionParam['enum'])->toContain('list')
        ->toContain('search')
        ->toContain('get')
        ->toContain('send')
        ->toContain('reply')
        ->toContain('forward')
        ->toContain('trash')
        ->toContain('untrash')
        ->toContain('delete')
        ->toContain('modify');
});

test('send action requires to parameter', function () {
    $tool = (new MessageTool(createTestClient()))->build();
    $result = $tool->execute(['action' => 'send', 'subject' => 'Test']);

    expect($result->content)->toContain('to is required');
});

test('send action requires subject parameter', function () {
    $tool = (new MessageTool(createTestClient()))->build();
    $result = $tool->execute(['action' => 'send', 'to' => 'user@example.com']);

    expect($result->content)->toContain('subject is required');
});

test('get action requires message_id parameter', function () {
    $tool = (new MessageTool(createTestClient()))->build();
    $result = $tool->execute(['action' => 'get']);

    expect($result->content)->toContain('message_id is required');
});

test('reply action requires message_id parameter', function () {
    $tool = (new MessageTool(createTestClient()))->build();
    $result = $tool->execute(['action' => 'reply', 'body' => 'Hello']);

    expect($result->content)->toContain('message_id is required');
});

test('reply action requires body parameter', function () {
    $tool = (new MessageTool(createTestClient()))->build();
    $result = $tool->execute(['action' => 'reply', 'message_id' => 'abc123']);

    expect($result->content)->toContain('body is required');
});

test('forward action requires message_id parameter', function () {
    $tool = (new MessageTool(createTestClient()))->build();
    $result = $tool->execute(['action' => 'forward', 'to' => 'user@example.com']);

    expect($result->content)->toContain('message_id is required');
});

test('forward action requires to parameter', function () {
    $tool = (new MessageTool(createTestClient()))->build();
    $result = $tool->execute(['action' => 'forward', 'message_id' => 'abc123']);

    expect($result->content)->toContain('to is required');
});

test('trash action requires message_id parameter', function () {
    $tool = (new MessageTool(createTestClient()))->build();
    $result = $tool->execute(['action' => 'trash']);

    expect($result->content)->toContain('message_id is required');
});

test('delete action requires message_id parameter', function () {
    $tool = (new MessageTool(createTestClient()))->build();
    $result = $tool->execute(['action' => 'delete']);

    expect($result->content)->toContain('message_id is required');
});

test('modify action requires label parameters', function () {
    $tool = (new MessageTool(createTestClient()))->build();
    $result = $tool->execute(['action' => 'modify', 'message_id' => 'abc123']);

    expect($result->content)->toContain('add_label_ids or remove_label_ids is required');
});

test('search action requires query parameter', function () {
    $tool = (new MessageTool(createTestClient()))->build();
    $result = $tool->execute(['action' => 'search']);

    expect($result->content)->toContain('query is required');
});

test('unknown action returns error', function () {
    $tool = (new MessageTool(createTestClient()))->build();
    $result = $tool->execute(['action' => 'invalid_action']);

    expect($result->content)->toContain('Unknown action');
});

test('buildRawMessage produces base64url output', function () {
    $tool = new MessageTool(createTestClient());

    $method = new ReflectionMethod($tool, 'buildRawMessage');
    $raw = $method->invoke(
        $tool,
        'user@example.com',
        'Test Subject',
        'Hello World',
    );

    // base64url should not contain +, /, or =
    expect($raw)->not->toContain('+');
    expect($raw)->not->toContain('/');
    expect($raw)->not->toContain('=');

    // Decode and verify structure
    $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
    expect($decoded)->toContain('To: user@example.com');
    expect($decoded)->toContain('MIME-Version: 1.0');
    expect($decoded)->toContain('Content-Type: text/plain');
});

test('base64UrlDecode handles empty string', function () {
    $tool = new MessageTool(createTestClient());

    $method = new ReflectionMethod($tool, 'base64UrlDecode');
    $result = $method->invoke($tool, '');

    expect($result)->toBe('');
});

test('base64UrlDecode round-trips with base64UrlEncode', function () {
    $tool = new MessageTool(createTestClient());

    $encode = new ReflectionMethod($tool, 'base64UrlEncode');
    $decode = new ReflectionMethod($tool, 'base64UrlDecode');

    $original = 'Hello, this is a test message with special chars: àéîõü!';
    $encoded = $encode->invoke($tool, $original);
    $decoded = $decode->invoke($tool, $encoded);

    expect($decoded)->toBe($original);
});

test('decodeBody extracts plain text from simple message', function () {
    $tool = new MessageTool(createTestClient());

    $method = new ReflectionMethod($tool, 'decodeBody');
    $payload = [
        'mimeType' => 'text/plain',
        'body' => [
            'data' => rtrim(strtr(base64_encode('Hello plain text'), '+/', '-_'), '='),
        ],
    ];

    $result = $method->invoke($tool, $payload);
    expect($result)->toBe('Hello plain text');
});

test('decodeBody prefers plain text over html in multipart', function () {
    $tool = new MessageTool(createTestClient());

    $method = new ReflectionMethod($tool, 'decodeBody');
    $payload = [
        'mimeType' => 'multipart/alternative',
        'parts' => [
            [
                'mimeType' => 'text/plain',
                'body' => [
                    'data' => rtrim(strtr(base64_encode('Plain text content'), '+/', '-_'), '='),
                ],
            ],
            [
                'mimeType' => 'text/html',
                'body' => [
                    'data' => rtrim(strtr(base64_encode('<p>HTML content</p>'), '+/', '-_'), '='),
                ],
            ],
        ],
    ];

    $result = $method->invoke($tool, $payload);
    expect($result)->toBe('Plain text content');
});

test('parseMessage extracts headers and body', function () {
    $tool = new MessageTool(createTestClient());

    $method = new ReflectionMethod($tool, 'parseMessage');
    $response = [
        'id' => 'msg123',
        'threadId' => 'thread456',
        'labelIds' => ['INBOX', 'UNREAD'],
        'snippet' => 'Hello this is a test...',
        'payload' => [
            'mimeType' => 'text/plain',
            'headers' => [
                ['name' => 'Subject', 'value' => 'Test Email'],
                ['name' => 'From', 'value' => 'sender@example.com'],
                ['name' => 'To', 'value' => 'recipient@example.com'],
                ['name' => 'Date', 'value' => 'Mon, 1 Jan 2024 12:00:00 +0000'],
            ],
            'body' => [
                'data' => rtrim(strtr(base64_encode('Hello World'), '+/', '-_'), '='),
            ],
        ],
    ];

    $parsed = $method->invoke($tool, $response);

    expect($parsed['id'])->toBe('msg123');
    expect($parsed['thread_id'])->toBe('thread456');
    expect($parsed['subject'])->toBe('Test Email');
    expect($parsed['from'])->toBe('sender@example.com');
    expect($parsed['to'])->toBe('recipient@example.com');
    expect($parsed['body'])->toBe('Hello World');
    expect($parsed['label_ids'])->toContain('INBOX');
    expect($parsed['label_ids'])->toContain('UNREAD');
});

/**
 * Create a test GmailClient that is not authenticated.
 */
function createTestClient(): GmailClient
{
    return new GmailClient(
        oauthHandler: new OAuthHandler(workspacePath: sys_get_temp_dir()),
    );
}
