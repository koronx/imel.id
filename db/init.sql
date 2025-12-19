-- Database initialization script for imel.id

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP
);

-- Emails table
CREATE TABLE IF NOT EXISTS emails (
    id SERIAL PRIMARY KEY,
    message_id VARCHAR(255) UNIQUE,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    from_email VARCHAR(255) NOT NULL,
    to_email VARCHAR(255) NOT NULL,
    cc VARCHAR(1000),
    bcc VARCHAR(1000),
    subject VARCHAR(500),
    body TEXT,
    html_body TEXT,
    is_read BOOLEAN DEFAULT FALSE,
    is_starred BOOLEAN DEFAULT FALSE,
    folder VARCHAR(50) DEFAULT 'inbox',
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP,
    size INTEGER DEFAULT 0
);

-- Attachments table
CREATE TABLE IF NOT EXISTS attachments (
    id SERIAL PRIMARY KEY,
    email_id INTEGER REFERENCES emails(id) ON DELETE CASCADE,
    filename VARCHAR(255) NOT NULL,
    content_type VARCHAR(100),
    size INTEGER,
    storage_path VARCHAR(500) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for better performance
CREATE INDEX idx_emails_user_id ON emails(user_id);
CREATE INDEX idx_emails_folder ON emails(folder);
CREATE INDEX idx_emails_received_at ON emails(received_at DESC);
CREATE INDEX idx_emails_message_id ON emails(message_id);
CREATE INDEX idx_attachments_email_id ON attachments(email_id);
CREATE INDEX idx_users_email ON users(email);

-- Insert default test user (password: password123)
-- Password hash is bcrypt of "password123"
INSERT INTO users (email, password, full_name) 
VALUES ('admin@imel.id', '$2y$10$4r15u54aPnYylebE8Wrlweb7oszNrnj/QML.6z5Obj203tJFVx3w.', 'Administrator')
ON CONFLICT (email) DO NOTHING;
