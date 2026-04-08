<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitGoogleGmail\GmailToolkit;

test('toolkit provides expected tools', function () {
    $toolkit = new GmailToolkit(
        client: createMockClient(),
    );

    $tools = $toolkit->tools();
    $names = array_map(fn($tool) => $tool->name(), $tools);

    expect($names)->toBe(['gmail_auth', 'gmail_message', 'gmail_label', 'gmail_draft']);
});

test('toolkit provides four tools', function () {
    $toolkit = new GmailToolkit(
        client: createMockClient(),
    );

    expect($toolkit->tools())->toHaveCount(4);
});

test('guidelines contain XML tags', function () {
    $toolkit = new GmailToolkit(
        client: createMockClient(),
    );

    expect($toolkit->guidelines())
        ->toContain('<GMAIL-TOOLKIT-GUIDELINES>')
        ->toContain('</GMAIL-TOOLKIT-GUIDELINES>');
});

test('guidelines contain tool names', function () {
    $toolkit = new GmailToolkit(
        client: createMockClient(),
    );

    expect($toolkit->guidelines())
        ->toContain('gmail_auth')
        ->toContain('gmail_message')
        ->toContain('gmail_label')
        ->toContain('gmail_draft');
});

test('guidelines contain search syntax reference', function () {
    $toolkit = new GmailToolkit(
        client: createMockClient(),
    );

    expect($toolkit->guidelines())
        ->toContain('from:')
        ->toContain('is:unread')
        ->toContain('has:attachment');
});

test('tools produce valid function schemas', function () {
    $toolkit = new GmailToolkit(
        client: createMockClient(),
    );

    foreach ($toolkit->tools() as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema)->toBeArray()
            ->toHaveKey('type')
            ->toHaveKey('function');

        expect($schema['type'])->toBe('function');
        expect($schema['function'])->toHaveKey('name')
            ->toHaveKey('description')
            ->toHaveKey('parameters');

        expect($schema['function']['name'])->toBeString()->not->toBeEmpty();
        expect($schema['function']['description'])->toBeString()->not->toBeEmpty();
    }
});

/**
 * Create a mock GmailClient for testing (no real HTTP or OAuth).
 */
function createMockClient(): \CarmeloSantana\CoquiToolkitGoogleGmail\GmailClient
{
    $oauthHandler = new \CarmeloSantana\CoquiToolkitGoogleGmail\Auth\OAuthHandler(
        workspacePath: sys_get_temp_dir(),
    );

    return new \CarmeloSantana\CoquiToolkitGoogleGmail\GmailClient(
        oauthHandler: $oauthHandler,
    );
}
