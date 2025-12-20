-- Add quota columns to users table
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS quota_bytes BIGINT DEFAULT 1073741824, -- 1GB in bytes
ADD COLUMN IF NOT EXISTS quota_used BIGINT DEFAULT 0;

-- Update existing users to have default quota
UPDATE users SET quota_bytes = 1073741824 WHERE quota_bytes IS NULL;
UPDATE users SET quota_used = 0 WHERE quota_used IS NULL;

-- Function to calculate user's storage usage
CREATE OR REPLACE FUNCTION calculate_user_storage(user_id_param INTEGER)
RETURNS BIGINT AS $$
DECLARE
    total_size BIGINT;
BEGIN
    SELECT COALESCE(SUM(a.size), 0)
    INTO total_size
    FROM attachments a
    JOIN emails e ON a.email_id = e.id
    WHERE e.user_id = user_id_param;
    
    RETURN total_size;
END;
$$ LANGUAGE plpgsql;

-- Update all users' quota_used
DO $$
DECLARE
    user_record RECORD;
BEGIN
    FOR user_record IN SELECT id FROM users LOOP
        UPDATE users 
        SET quota_used = calculate_user_storage(user_record.id)
        WHERE id = user_record.id;
    END LOOP;
END $$;

-- Show results
SELECT id, email, 
       quota_bytes, 
       quota_used,
       ROUND((quota_used::NUMERIC / quota_bytes::NUMERIC) * 100, 2) as usage_percent
FROM users;
