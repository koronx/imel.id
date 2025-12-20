# API Consistency Update

## Overview
API mobile telah disesuaikan untuk menggunakan struktur database yang sama dengan webmail, yaitu menggunakan kolom `user_id` dan `folder` untuk query emails.

## Changes Made

### 1. API Endpoints Updated
File: `webmail/api.php`

#### get_inbox
- **Before**: `WHERE to_email = ?`
- **After**: `WHERE user_id = ? AND folder = 'inbox'`

#### get_sent
- **Before**: `WHERE from_email = ?`
- **After**: `WHERE user_id = ? AND folder = 'sent'`

#### get_email
- **Before**: `WHERE id = ? AND (from_email = ? OR to_email = ?)`
- **After**: `WHERE id = ? AND user_id = ?`

#### send_email
- **Before**: Insert single row with from_email and to_email only
- **After**: Insert 2 rows:
  1. Sender's sent folder: `INSERT INTO emails (user_id, from_email, to_email, subject, body, folder) VALUES (?, ?, ?, ?, ?, 'sent')`
  2. Recipient's inbox folder (if recipient exists): `INSERT INTO emails (user_id, from_email, to_email, subject, body, folder) VALUES (?, ?, ?, ?, ?, 'inbox')`

#### mark_read
- **Before**: `UPDATE emails SET is_read = true WHERE id = ? AND to_email = ?`
- **After**: `UPDATE emails SET is_read = true WHERE id = ? AND user_id = ?`

#### delete_email
- **Before**: `DELETE FROM emails WHERE id = ? AND (from_email = ? OR to_email = ?)`
- **After**: `DELETE FROM emails WHERE id = ? AND user_id = ?`

### 2. Webmail Updated
File: `webmail/src/pages/inbox.php`

- **Before**: Different query per folder (to_email for inbox, from_email for sent)
- **After**: Consistent query: `WHERE user_id = ? AND folder = ?`

### 3. Database Migration
File: `db/populate_user_id_folder.sql`

Created migration script to populate `user_id` and `folder` for existing emails:

```sql
-- For inbox emails
UPDATE emails e
SET user_id = u.id, folder = 'inbox'
FROM users u
WHERE e.to_email = u.email 
  AND e.user_id IS NULL;

-- For sent emails (create duplicates)
INSERT INTO emails (user_id, from_email, to_email, subject, body, folder, received_at, is_read)
SELECT u.id, e.from_email, e.to_email, e.subject, e.body, 'sent', e.received_at, true
FROM emails e
JOIN users u ON e.from_email = u.email
WHERE e.folder = 'inbox';
```

## Benefits

1. **Database Consistency**: Both API and webmail use the same query pattern
2. **Better Performance**: Using indexed user_id instead of email strings
3. **Proper Data Model**: Each user has their own copy of sent/received emails
4. **Folder Support**: Ready for future features like trash, drafts, spam folders
5. **Easier Debugging**: Clear ownership of emails via user_id

## Database Structure

```
emails table:
- id (primary key)
- user_id (foreign key to users.id) - Owner of this email copy
- folder (enum: 'inbox', 'sent', 'trash', 'draft', etc.)
- from_email (sender address)
- to_email (recipient address)
- subject
- body
- is_read (boolean)
- received_at (timestamp)
```

## How Email Flow Works

1. User sends email via API
2. System creates 2 entries:
   - One in sender's 'sent' folder (user_id = sender's id)
   - One in recipient's 'inbox' folder (user_id = recipient's id, is_read = false)
3. Each user queries their own emails: `WHERE user_id = ? AND folder = ?`
4. This allows independent operations (delete, mark read) without affecting other user

## Testing

Run test script:
```bash
./test_api_send.sh
```

This will:
1. Login as test@imel.id
2. Send email to koronx@imel.id
3. Verify sent folder has the email
4. Login as koronx@imel.id
5. Verify inbox has the email
6. Check database for correct structure

## API Usage Example

```bash
# Login
curl -X POST 'http://localhost/api.php?action=login' \
  -H "Content-Type: application/json" \
  -d '{"email":"test@imel.id","password":"password123"}'

# Get inbox (uses user_id and folder)
curl 'http://localhost/api.php?action=get_inbox' \
  -H "Authorization: Bearer YOUR_TOKEN"

# Get sent (uses user_id and folder)
curl 'http://localhost/api.php?action=get_sent' \
  -H "Authorization: Bearer YOUR_TOKEN"

# Send email (creates 2 entries automatically)
curl -X POST 'http://localhost/api.php?action=send_email' \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "to": "recipient@imel.id",
    "subject": "Test Subject",
    "body": "Email body"
  }'
```

## Migration Notes

- Existing emails have been migrated to have user_id and folder
- Inbox emails: user_id set based on to_email
- Sent emails: duplicated from inbox emails with user_id based on from_email
- All migration done via `populate_user_id_folder.sql`
