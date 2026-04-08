<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitGoogleGmail\Tool\LabelTool;
use CarmeloSantana\CoquiToolkitGoogleGmail\Tool\DraftTool;
use CarmeloSantana\CoquiToolkitGoogleGmail\Auth\OAuthHandler;
use CarmeloSantana\CoquiToolkitGoogleGmail\GmailClient;

test('label tool has correct name', function () {
    $tool = (new LabelTool(createToolTestClient()))->build();
    expect($tool->name())->toBe('gmail_label');
});

test('label tool has action parameter with all actions', function () {
    $tool = (new LabelTool(createToolTestClient()))->build();
    $schema = $tool->toFunctionSchema();

    $actionParam = $schema['function']['parameters']['properties']['action'] ?? [];
    expect($actionParam['enum'])->toContain('list')
        ->toContain('get')
        ->toContain('create')
        ->toContain('update')
        ->toContain('delete');
});

test('label get action requires label_id', function () {
    $tool = (new LabelTool(createToolTestClient()))->build();
    $result = $tool->execute(['action' => 'get']);

    expect($result->content)->toContain('label_id is required');
});

test('label create action requires name', function () {
    $tool = (new LabelTool(createToolTestClient()))->build();
    $result = $tool->execute(['action' => 'create']);

    expect($result->content)->toContain('name is required');
});

test('label update action requires label_id', function () {
    $tool = (new LabelTool(createToolTestClient()))->build();
    $result = $tool->execute(['action' => 'update', 'name' => 'New Name']);

    expect($result->content)->toContain('label_id is required');
});

test('label delete action requires label_id', function () {
    $tool = (new LabelTool(createToolTestClient()))->build();
    $result = $tool->execute(['action' => 'delete']);

    expect($result->content)->toContain('label_id is required');
});

test('draft tool has correct name', function () {
    $tool = (new DraftTool(createToolTestClient()))->build();
    expect($tool->name())->toBe('gmail_draft');
});

test('draft tool has action parameter with all actions', function () {
    $tool = (new DraftTool(createToolTestClient()))->build();
    $schema = $tool->toFunctionSchema();

    $actionParam = $schema['function']['parameters']['properties']['action'] ?? [];
    expect($actionParam['enum'])->toContain('list')
        ->toContain('get')
        ->toContain('create')
        ->toContain('update')
        ->toContain('send')
        ->toContain('delete');
});

test('draft get action requires draft_id', function () {
    $tool = (new DraftTool(createToolTestClient()))->build();
    $result = $tool->execute(['action' => 'get']);

    expect($result->content)->toContain('draft_id is required');
});

test('draft update action requires draft_id', function () {
    $tool = (new DraftTool(createToolTestClient()))->build();
    $result = $tool->execute(['action' => 'update']);

    expect($result->content)->toContain('draft_id is required');
});

test('draft send action requires draft_id', function () {
    $tool = (new DraftTool(createToolTestClient()))->build();
    $result = $tool->execute(['action' => 'send']);

    expect($result->content)->toContain('draft_id is required');
});

test('draft delete action requires draft_id', function () {
    $tool = (new DraftTool(createToolTestClient()))->build();
    $result = $tool->execute(['action' => 'delete']);

    expect($result->content)->toContain('draft_id is required');
});

test('unknown action returns error for label tool', function () {
    $tool = (new LabelTool(createToolTestClient()))->build();
    $result = $tool->execute(['action' => 'nonexistent']);

    expect($result->content)->toContain('Unknown action');
});

test('unknown action returns error for draft tool', function () {
    $tool = (new DraftTool(createToolTestClient()))->build();
    $result = $tool->execute(['action' => 'nonexistent']);

    expect($result->content)->toContain('Unknown action');
});

function createToolTestClient(): GmailClient
{
    return new GmailClient(
        oauthHandler: new OAuthHandler(workspacePath: sys_get_temp_dir()),
    );
}
