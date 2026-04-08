# Coqui Toolkit: Google Gmail

Gmail integration toolkit for [Coqui Bot](https://github.com/AgentCoqui/coqui) — read, send, label, and draft emails via the Gmail REST API with OAuth2 authentication.

## Features

- **OAuth2 browser-based login** — secure authentication with PKCE, no passwords stored
- **Full message management** — list, search (Gmail query syntax), read, send, reply, forward, trash, delete
- **Label management** — list labels with unread counts, create/update/delete custom labels, modify message labels
- **Draft management** — create, update, send, and delete email drafts
- **Credential guard** — Coqui's credential system prompts for OAuth2 client ID/secret automatically
- **Tool gating** — destructive operations (send, delete, trash, etc.) require user confirmation

## Requirements

- PHP 8.4+
- A Google Cloud project with the Gmail API enabled
- OAuth2 Desktop app credentials (Client ID + Client Secret)

## Installation

```bash
composer require coquibot/coqui-toolkit-google-gmail
```

Coqui discovers the toolkit automatically on next boot.

## Google Cloud Setup

Before using the toolkit, you need to create OAuth2 credentials in Google Cloud Console:

### 1. Create a Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (or use an existing one)
3. Note the project name for later

### 2. Enable the Gmail API

1. Navigate to **APIs & Services > Library**
2. Search for "Gmail API"
3. Click **Enable**

### 3. Configure the OAuth Consent Screen

1. Go to **APIs & Services > OAuth consent screen**
2. Choose **External** user type (or **Internal** for Google Workspace)
3. Fill in the required fields:
   - App name: e.g. "Coqui Gmail"
   - User support email: your email
   - Developer contact: your email
4. Add scopes:
   - `https://www.googleapis.com/auth/gmail.modify`
   - `https://www.googleapis.com/auth/gmail.compose`
5. Add your Google account as a test user (required while in "Testing" status)
6. Save

### 4. Create OAuth2 Desktop Credentials

1. Go to **APIs & Services > Credentials**
2. Click **Create Credentials > OAuth client ID**
3. Application type: **Desktop app**
4. Name: e.g. "Coqui Gmail Desktop"
5. Click **Create**
6. Copy the **Client ID** and **Client Secret**

### 5. Configure Credentials in Coqui

Tell Coqui your OAuth2 credentials:

```
You: Set my Gmail credentials

Coqui: credentials(action: "set", key: "GMAIL_CLIENT_ID", value: "your-client-id.apps.googleusercontent.com")
Coqui: credentials(action: "set", key: "GMAIL_CLIENT_SECRET", value: "your-client-secret")
```

Or set them as environment variables:

```bash
export GMAIL_CLIENT_ID="your-client-id.apps.googleusercontent.com"
export GMAIL_CLIENT_SECRET="your-client-secret"
```

### 6. Authenticate

```
You: Connect my Gmail account

Coqui: gmail_auth(action: "login")
→ Opens browser for Google sign-in
→ Stores tokens securely in .workspace/.gmail-tokens/
```

## Tools

### gmail_auth

Manage OAuth2 authentication lifecycle.

| Action | Description | Gated |
|--------|-------------|-------|
| `status` | Check if connected to Gmail | No |
| `login` | Open browser for Google OAuth2 sign-in | No |
| `revoke` | Disconnect Gmail and delete tokens | Yes |

### gmail_message

Full email message management.

| Action | Description | Gated |
|--------|-------------|-------|
| `list` | List inbox messages (with optional label filter) | No |
| `search` | Search messages using Gmail query syntax | No |
| `get` | Read full message content | No |
| `send` | Send a new email | Yes |
| `reply` | Reply to a message (preserves threading) | Yes |
| `forward` | Forward a message to another recipient | Yes |
| `trash` | Move message to trash | Yes |
| `untrash` | Restore message from trash | Yes |
| `delete` | Permanently delete a message | Yes |
| `modify` | Add/remove labels (mark read, star, archive, etc.) | Yes |

### gmail_label

Gmail label management.

| Action | Description | Gated |
|--------|-------------|-------|
| `list` | List all labels with message/thread counts | No |
| `get` | Get label details and counts | No |
| `create` | Create a custom label | Yes |
| `update` | Rename or change label visibility | Yes |
| `delete` | Delete a custom label | Yes |

### gmail_draft

Email draft management.

| Action | Description | Gated |
|--------|-------------|-------|
| `list` | List all drafts | No |
| `get` | Read draft content | No |
| `create` | Create a new draft | No |
| `update` | Update an existing draft | No |
| `send` | Send a draft as an email | Yes |
| `delete` | Delete a draft | Yes |

## Gmail Search Syntax

The `search` action supports Gmail's full query syntax:

| Operator | Example | Description |
|----------|---------|-------------|
| `from:` | `from:user@example.com` | Messages from sender |
| `to:` | `to:user@example.com` | Messages to recipient |
| `subject:` | `subject:meeting` | Subject contains word |
| `is:unread` | `is:unread` | Unread messages |
| `is:starred` | `is:starred` | Starred messages |
| `has:attachment` | `has:attachment` | Has attachments |
| `after:` | `after:2024/01/01` | After date |
| `before:` | `before:2024/12/31` | Before date |
| `label:` | `label:important` | Has label |
| `in:` | `in:inbox` | In location |
| `larger:` | `larger:5M` | Larger than size |
| `filename:` | `filename:pdf` | Attachment filename |

Combine operators: `from:boss@work.com is:unread subject:urgent after:2024/06/01`

## Usage Examples

### Check for unread messages

```
You: Do I have any unread emails?

Coqui: gmail_message(action: "search", query: "is:unread", max_results: 10)
→ Shows unread messages with subject, sender, and date
```

### Read and reply

```
You: Read the latest email from john@example.com and draft a reply

Coqui: gmail_message(action: "search", query: "from:john@example.com", max_results: 1)
Coqui: gmail_message(action: "get", message_id: "...")
Coqui: gmail_message(action: "reply", message_id: "...", body: "Thanks for your email, John...")
```

### Organize inbox

```
You: Archive all read messages in my inbox

Coqui: gmail_message(action: "search", query: "in:inbox -is:unread", max_results: 50)
Coqui: gmail_message(action: "modify", message_id: "...", remove_label_ids: "INBOX")
```

### Create and send a draft

```
You: Draft an email to the team about the meeting

Coqui: gmail_draft(action: "create", to: "team@company.com", subject: "Team Meeting", body: "...")
Coqui: gmail_draft(action: "get", draft_id: "...")  // Review
Coqui: gmail_draft(action: "send", draft_id: "...")  // Send when ready
```

## Architecture

```
src/
├── GmailToolkit.php          # ToolkitInterface — registers all tools, provides guidelines
├── GmailClient.php           # HTTP client wrapper for Gmail REST API
├── Auth/
│   ├── OAuthHandler.php      # OAuth2 browser flow with PKCE + token storage
│   └── OAuthException.php    # Auth-specific exceptions
├── Exception/
│   ├── GmailApiException.php # API error exceptions
│   └── GmailAuthException.php # Authentication exceptions
└── Tool/
    ├── AuthTool.php           # gmail_auth — OAuth lifecycle
    ├── MessageTool.php        # gmail_message — message CRUD + search
    ├── LabelTool.php          # gmail_label — label management
    └── DraftTool.php          # gmail_draft — draft management
```

## Token Storage

OAuth2 tokens are stored in `.workspace/.gmail-tokens/default.json` with `0600` file permissions. Tokens are:

- **Automatically refreshed** when they expire (using the refresh token)
- **Never exposed** to the LLM — only the `OAuthHandler` and `GmailClient` access them
- **Revocable** via `gmail_auth(action: "revoke")` which also calls Google's revocation endpoint

## Security

- OAuth2 with PKCE protects against authorization code interception
- Client credentials are managed via Coqui's `.env`-based credential system
- Tokens stored with restrictive file permissions (`0600`)
- All destructive operations (send, delete, modify) are gated and require user confirmation
- The toolkit never exposes email content to logs or audit trails beyond what the agent processes

## Development

```bash
# Install dependencies
composer install

# Run tests
./vendor/bin/pest

# Static analysis
./vendor/bin/phpstan analyse
```

## License

MIT
