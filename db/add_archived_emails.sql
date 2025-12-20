-- Create archived emails table
CREATE TABLE IF NOT EXISTS archived_emails (
    id SERIAL PRIMARY KEY,
    original_email_id INTEGER,
    message_id VARCHAR(255),
    user_id INTEGER,
    user_email VARCHAR(255),
    from_email VARCHAR(255) NOT NULL,
    to_email VARCHAR(255) NOT NULL,
    cc VARCHAR(1000),
    bcc VARCHAR(1000),
    subject VARCHAR(500),
    body TEXT,
    html_body TEXT,
    is_read BOOLEAN DEFAULT FALSE,
    is_starred BOOLEAN DEFAULT FALSE,
    folder VARCHAR(50),
    received_at TIMESTAMP,
    sent_at TIMESTAMP,
    size INTEGER DEFAULT 0,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    archived_reason VARCHAR(255)
);

-- Create archived attachments table
CREATE TABLE IF NOT EXISTS archived_attachments (
    id SERIAL PRIMARY KEY,
    original_attachment_id INTEGER,
    archived_email_id INTEGER REFERENCES archived_emails(id) ON DELETE CASCADE,
    email_id INTEGER,
    filename VARCHAR(255) NOT NULL,
    content_type VARCHAR(100),
    size INTEGER,
    storage_path VARCHAR(500),
    created_at TIMESTAMP,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for archived tables
CREATE INDEX IF NOT EXISTS idx_archived_emails_user_email ON archived_emails(user_email);
CREATE INDEX IF NOT EXISTS idx_archived_emails_archived_at ON archived_emails(archived_at DESC);
CREATE INDEX IF NOT EXISTS idx_archived_emails_message_id ON archived_emails(message_id);
CREATE INDEX IF NOT EXISTS idx_archived_attachments_archived_email_id ON archived_attachments(archived_email_id);
