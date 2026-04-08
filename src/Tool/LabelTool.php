<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitGoogleGmail\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\CoquiToolkitGoogleGmail\GmailClient;

/**
 * Gmail label management tool.
 *
 * Supports listing, reading, creating, updating, and deleting labels.
 * Labels are used to organize messages in Gmail (similar to folders).
 */
final readonly class LabelTool
{
    private const array ACTIONS = ['list', 'get', 'create', 'update', 'delete'];

    public function __construct(
        private GmailClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'gmail_label',
            description: 'Manage Gmail labels: list all labels with unread counts, get label details, create/update/delete custom labels. System labels (INBOX, SENT, TRASH, etc.) cannot be deleted.',
            parameters: [
                new EnumParameter('action', 'The label operation to perform', self::ACTIONS),
                new StringParameter('label_id', 'Label ID (for get, update, delete)', required: false),
                new StringParameter('name', 'Label name (for create, update)', required: false),
                new EnumParameter(
                    'label_list_visibility',
                    'Whether the label is shown in the label list (for create, update)',
                    ['labelShow', 'labelShowIfUnread', 'labelHide'],
                    required: false,
                ),
                new EnumParameter(
                    'message_list_visibility',
                    'Whether messages with this label are shown in the message list (for create, update)',
                    ['show', 'hide'],
                    required: false,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        try {
            return match ($action) {
                'list' => $this->listLabels(),
                'get' => $this->getLabel($args),
                'create' => $this->createLabel($args),
                'update' => $this->updateLabel($args),
                'delete' => $this->deleteLabel($args),
                default => ToolResult::error("Unknown action: {$action}"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('Gmail label error: ' . $e->getMessage());
        }
    }

    private function listLabels(): ToolResult
    {
        $response = $this->client->get('labels');
        $labels = $response['labels'] ?? [];

        $formatted = [];
        foreach ($labels as $label) {
            $formatted[] = [
                'id' => $label['id'] ?? '',
                'name' => $label['name'] ?? '',
                'type' => $label['type'] ?? '',
                'messages_total' => $label['messagesTotal'] ?? 0,
                'messages_unread' => $label['messagesUnread'] ?? 0,
                'threads_total' => $label['threadsTotal'] ?? 0,
                'threads_unread' => $label['threadsUnread'] ?? 0,
            ];
        }

        return ToolResult::success(json_encode([
            'labels' => $formatted,
            'count' => count($formatted),
        ], JSON_PRETTY_PRINT));
    }

    private function getLabel(array $args): ToolResult
    {
        $labelId = trim((string) ($args['label_id'] ?? ''));
        if ($labelId === '') {
            return ToolResult::error('label_id is required for get.');
        }

        $response = $this->client->get("labels/{$labelId}");

        return ToolResult::success(json_encode([
            'id' => $response['id'] ?? '',
            'name' => $response['name'] ?? '',
            'type' => $response['type'] ?? '',
            'messages_total' => $response['messagesTotal'] ?? 0,
            'messages_unread' => $response['messagesUnread'] ?? 0,
            'threads_total' => $response['threadsTotal'] ?? 0,
            'threads_unread' => $response['threadsUnread'] ?? 0,
            'label_list_visibility' => $response['labelListVisibility'] ?? '',
            'message_list_visibility' => $response['messageListVisibility'] ?? '',
            'color' => $response['color'] ?? null,
        ], JSON_PRETTY_PRINT));
    }

    private function createLabel(array $args): ToolResult
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('name is required for create.');
        }

        $body = ['name' => $name];

        $labelListVisibility = trim((string) ($args['label_list_visibility'] ?? ''));
        if ($labelListVisibility !== '') {
            $body['labelListVisibility'] = $labelListVisibility;
        }

        $messageListVisibility = trim((string) ($args['message_list_visibility'] ?? ''));
        if ($messageListVisibility !== '') {
            $body['messageListVisibility'] = $messageListVisibility;
        }

        $response = $this->client->post('labels', $body);

        return ToolResult::success(json_encode([
            'success' => true,
            'label_id' => $response['id'] ?? '',
            'name' => $response['name'] ?? '',
        ], JSON_PRETTY_PRINT));
    }

    private function updateLabel(array $args): ToolResult
    {
        $labelId = trim((string) ($args['label_id'] ?? ''));
        if ($labelId === '') {
            return ToolResult::error('label_id is required for update.');
        }

        $body = [];

        $name = trim((string) ($args['name'] ?? ''));
        if ($name !== '') {
            $body['name'] = $name;
        }

        $labelListVisibility = trim((string) ($args['label_list_visibility'] ?? ''));
        if ($labelListVisibility !== '') {
            $body['labelListVisibility'] = $labelListVisibility;
        }

        $messageListVisibility = trim((string) ($args['message_list_visibility'] ?? ''));
        if ($messageListVisibility !== '') {
            $body['messageListVisibility'] = $messageListVisibility;
        }

        if ($body === []) {
            return ToolResult::error('At least one of name, label_list_visibility, or message_list_visibility is required for update.');
        }

        $response = $this->client->patch("labels/{$labelId}", $body);

        return ToolResult::success(json_encode([
            'success' => true,
            'label_id' => $response['id'] ?? '',
            'name' => $response['name'] ?? '',
        ], JSON_PRETTY_PRINT));
    }

    private function deleteLabel(array $args): ToolResult
    {
        $labelId = trim((string) ($args['label_id'] ?? ''));
        if ($labelId === '') {
            return ToolResult::error('label_id is required for delete.');
        }

        $this->client->delete("labels/{$labelId}");

        return ToolResult::success(json_encode([
            'success' => true,
            'message' => "Label {$labelId} deleted.",
        ], JSON_PRETTY_PRINT));
    }
}
