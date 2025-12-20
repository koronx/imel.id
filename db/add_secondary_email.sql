-- Add secondary email column for 2FA and password recovery
ALTER TABLE users ADD COLUMN IF NOT EXISTS secondary_email VARCHAR(255);

-- Create table for forgot password rate limiting
CREATE TABLE IF NOT EXISTS forgot_password_attempts (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45)
);

CREATE INDEX IF NOT EXISTS idx_email_time ON forgot_password_attempts(email, attempted_at);

-- Create table for password reset tokens
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
