-- Add rate limit settings table
CREATE TABLE IF NOT EXISTS rate_limit_settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT INTO rate_limit_settings (setting_key, setting_value, description) VALUES
    ('default_daily_external_limit', '100', 'Default daily limit untuk email eksternal per user')
ON CONFLICT (setting_key) DO NOTHING;

-- Add daily_external_limit column to users table if not exists
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='daily_external_limit') THEN
        ALTER TABLE users ADD COLUMN daily_external_limit INTEGER DEFAULT 100;
    END IF;
END $$;

-- Update existing users to have default limit
UPDATE users SET daily_external_limit = 100 WHERE daily_external_limit IS NULL;
