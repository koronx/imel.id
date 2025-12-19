-- Add external email rate limiting table to existing database
-- Run this script to add rate limiting feature to existing installations

-- External email rate limiting table
CREATE TABLE IF NOT EXISTS external_email_log (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    to_email VARCHAR(255) NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add indexes for performance
CREATE INDEX IF NOT EXISTS idx_external_email_log_user_id ON external_email_log(user_id);
CREATE INDEX IF NOT EXISTS idx_external_email_log_sent_at ON external_email_log(sent_at);

-- Display success message
SELECT 'Rate limiting table created successfully!' as message;
