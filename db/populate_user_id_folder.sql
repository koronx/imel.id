-- Populate user_id and folder for existing emails

-- For inbox emails: set user_id based on to_email
UPDATE emails e
SET user_id = u.id, folder = 'inbox'
FROM users u
WHERE e.to_email = u.email 
  AND e.user_id IS NULL;

-- For sent emails: set user_id based on from_email
-- First create duplicate entries for sent folder
INSERT INTO emails (user_id, from_email, to_email, subject, body, folder, received_at, is_read)
SELECT u.id, e.from_email, e.to_email, e.subject, e.body, 'sent', e.received_at, true
FROM emails e
JOIN users u ON e.from_email = u.email
WHERE e.folder = 'inbox';

-- Show results
SELECT folder, COUNT(*) as count FROM emails GROUP BY folder;
