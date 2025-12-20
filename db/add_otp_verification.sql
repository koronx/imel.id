-- Table for OTP verification
CREATE TABLE IF NOT EXISTS secondary_email_otp (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    email VARCHAR(255) NOT NULL,
    otp VARCHAR(6) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    is_verified BOOLEAN DEFAULT FALSE
);

CREATE INDEX idx_otp_user_email ON secondary_email_otp(user_id, email);
CREATE INDEX idx_otp_expires ON secondary_email_otp(expires_at);
