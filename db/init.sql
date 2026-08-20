-- =====================================================================
--  imel.id — Gmail-like mail platform : PostgreSQL schema
-- =====================================================================
SET client_min_messages TO WARNING;

CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- ---------------------------------------------------------------- users
CREATE TABLE IF NOT EXISTS users (
    id             SERIAL PRIMARY KEY,
    email          VARCHAR(320) UNIQUE NOT NULL,
    password       VARCHAR(255) NOT NULL,
    full_name      VARCHAR(255) NOT NULL,
    avatar_color   VARCHAR(9)   NOT NULL DEFAULT '#1a73e8',
    recovery_email VARCHAR(320),
    quota_bytes    BIGINT       NOT NULL DEFAULT 16106127360, -- 15 GB
    used_bytes     BIGINT       NOT NULL DEFAULT 0,
    is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    last_login     TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_users_email ON users (lower(email));

-- ------------------------------------------------------- user settings
CREATE TABLE IF NOT EXISTS user_settings (
    user_id            INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    theme              VARCHAR(16)  NOT NULL DEFAULT 'light',    -- light|dark|system
    density            VARCHAR(16)  NOT NULL DEFAULT 'default',  -- default|comfortable|compact
    reading_pane       VARCHAR(16)  NOT NULL DEFAULT 'none',     -- none|right|bottom
    per_page           INTEGER      NOT NULL DEFAULT 50,
    conversation_view  BOOLEAN      NOT NULL DEFAULT TRUE,
    undo_send_seconds  INTEGER      NOT NULL DEFAULT 5,
    signature          TEXT         NOT NULL DEFAULT '',
    signature_enabled  BOOLEAN      NOT NULL DEFAULT FALSE,
    vacation_enabled   BOOLEAN      NOT NULL DEFAULT FALSE,
    vacation_subject   VARCHAR(255) NOT NULL DEFAULT '',
    vacation_body      TEXT         NOT NULL DEFAULT '',
    language           VARCHAR(8)   NOT NULL DEFAULT 'id',
    updated_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- ------------------------------------------------------------ sessions
CREATE TABLE IF NOT EXISTS sessions (
    id          BIGSERIAL PRIMARY KEY,
    jti         VARCHAR(64) UNIQUE NOT NULL,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    user_agent  VARCHAR(255) NOT NULL DEFAULT '',
    ip_address  VARCHAR(64)  NOT NULL DEFAULT '',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at  TIMESTAMPTZ NOT NULL,
    revoked     BOOLEAN NOT NULL DEFAULT FALSE
);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);

-- -------------------------------------------------------------- labels
CREATE TABLE IF NOT EXISTS labels (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    slug         VARCHAR(120) NOT NULL,
    name         VARCHAR(120) NOT NULL,
    color        VARCHAR(9)   NOT NULL DEFAULT '#5f6368',
    type         VARCHAR(16)  NOT NULL DEFAULT 'user',   -- system|category|user
    position     INTEGER      NOT NULL DEFAULT 0,
    show_in_list BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, slug)
);
CREATE INDEX IF NOT EXISTS idx_labels_user ON labels(user_id);

-- ------------------------------------------------------------ messages
CREATE TABLE IF NOT EXISTS messages (
    id             BIGSERIAL PRIMARY KEY,
    user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    message_id     VARCHAR(512),          -- RFC 5322 Message-ID
    thread_key     VARCHAR(64)  NOT NULL, -- conversation grouping key
    in_reply_to    VARCHAR(512),
    reference_ids  TEXT         NOT NULL DEFAULT '',
    from_email     VARCHAR(320) NOT NULL,
    from_name      VARCHAR(255) NOT NULL DEFAULT '',
    to_json        TEXT         NOT NULL DEFAULT '[]',
    cc_json        TEXT         NOT NULL DEFAULT '[]',
    bcc_json       TEXT         NOT NULL DEFAULT '[]',
    reply_to       VARCHAR(320) NOT NULL DEFAULT '',
    subject        VARCHAR(998) NOT NULL DEFAULT '',
    snippet        VARCHAR(400) NOT NULL DEFAULT '',
    body_text      TEXT         NOT NULL DEFAULT '',
    body_html      TEXT         NOT NULL DEFAULT '',
    raw_path       VARCHAR(500) NOT NULL DEFAULT '',
    folder         VARCHAR(32)  NOT NULL DEFAULT 'inbox', -- inbox|sent|drafts|trash|spam|archive
    is_read        BOOLEAN      NOT NULL DEFAULT FALSE,
    is_starred     BOOLEAN      NOT NULL DEFAULT FALSE,
    is_important   BOOLEAN      NOT NULL DEFAULT FALSE,
    is_draft       BOOLEAN      NOT NULL DEFAULT FALSE,
    has_attachment BOOLEAN      NOT NULL DEFAULT FALSE,
    size_bytes     INTEGER      NOT NULL DEFAULT 0,
    internal_date  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    sent_at        TIMESTAMPTZ,
    search_tsv     tsvector GENERATED ALWAYS AS (
        to_tsvector('simple'::regconfig,
            coalesce(subject, '')    || ' ' ||
            coalesce(from_email, '') || ' ' ||
            coalesce(from_name, '')  || ' ' ||
            coalesce(to_json, '')    || ' ' ||
            coalesce(body_text, '')
        )
    ) STORED
);
CREATE INDEX IF NOT EXISTS idx_msg_user_folder  ON messages(user_id, folder, internal_date DESC);
CREATE INDEX IF NOT EXISTS idx_msg_thread       ON messages(user_id, thread_key);
CREATE INDEX IF NOT EXISTS idx_msg_starred      ON messages(user_id, is_starred) WHERE is_starred;
CREATE INDEX IF NOT EXISTS idx_msg_unread       ON messages(user_id, is_read)    WHERE NOT is_read;
CREATE INDEX IF NOT EXISTS idx_msg_search       ON messages USING GIN (search_tsv);
CREATE INDEX IF NOT EXISTS idx_msg_subject_trgm ON messages USING GIN (subject gin_trgm_ops);
CREATE UNIQUE INDEX IF NOT EXISTS uq_msg_user_rfcid
    ON messages(user_id, message_id) WHERE message_id IS NOT NULL;

-- ------------------------------------------------------ message labels
CREATE TABLE IF NOT EXISTS message_labels (
    message_id BIGINT  NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    label_id   INTEGER NOT NULL REFERENCES labels(id)   ON DELETE CASCADE,
    PRIMARY KEY (message_id, label_id)
);
CREATE INDEX IF NOT EXISTS idx_mlabels_label ON message_labels(label_id);

-- --------------------------------------------------------- attachments
CREATE TABLE IF NOT EXISTS attachments (
    id           BIGSERIAL PRIMARY KEY,
    message_id   BIGINT REFERENCES messages(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    filename     VARCHAR(255) NOT NULL,
    content_type VARCHAR(150) NOT NULL DEFAULT 'application/octet-stream',
    size_bytes   INTEGER NOT NULL DEFAULT 0,
    storage_path VARCHAR(500) NOT NULL,
    content_id   VARCHAR(255) NOT NULL DEFAULT '',
    is_inline    BOOLEAN NOT NULL DEFAULT FALSE,
    token        VARCHAR(64),          -- set while still an unattached upload
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_att_message ON attachments(message_id);
CREATE INDEX IF NOT EXISTS idx_att_token   ON attachments(token);

-- ------------------------------------------------------------ contacts
CREATE TABLE IF NOT EXISTS contacts (
    id                SERIAL PRIMARY KEY,
    user_id           INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    email             VARCHAR(320) NOT NULL,
    name              VARCHAR(255) NOT NULL DEFAULT '',
    times_contacted   INTEGER NOT NULL DEFAULT 0,
    last_contacted_at TIMESTAMPTZ,
    UNIQUE (user_id, email)
);

-- ------------------------------------------------------ outbound queue
CREATE TABLE IF NOT EXISTS outbound_queue (
    id              BIGSERIAL PRIMARY KEY,
    user_id         INTEGER REFERENCES users(id) ON DELETE CASCADE,
    message_row_id  BIGINT REFERENCES messages(id) ON DELETE SET NULL,
    from_email      VARCHAR(320) NOT NULL,
    recipients      TEXT NOT NULL,           -- JSON array
    raw_path        VARCHAR(500) NOT NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'queued', -- queued|sending|sent|failed
    attempts        INTEGER NOT NULL DEFAULT 0,
    last_error      TEXT NOT NULL DEFAULT '',
    next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_queue_pending ON outbound_queue(status, next_attempt_at);

-- ================================ helper: bootstrap labels for a user ==
CREATE OR REPLACE FUNCTION bootstrap_user(p_user_id INTEGER) RETURNS VOID AS $fn$
BEGIN
    INSERT INTO user_settings (user_id) VALUES (p_user_id)
        ON CONFLICT (user_id) DO NOTHING;

    INSERT INTO labels (user_id, slug, name, color, type, position) VALUES
        (p_user_id, 'inbox',      'Inbox',      '#1a73e8', 'system',   1),
        (p_user_id, 'starred',    'Starred',    '#f4b400', 'system',   2),
        (p_user_id, 'snoozed',    'Snoozed',    '#5f6368', 'system',   3),
        (p_user_id, 'sent',       'Sent',       '#5f6368', 'system',   4),
        (p_user_id, 'drafts',     'Drafts',     '#5f6368', 'system',   5),
        (p_user_id, 'important',  'Important',  '#f4b400', 'system',   6),
        (p_user_id, 'archive',    'All Mail',   '#5f6368', 'system',   7),
        (p_user_id, 'spam',       'Spam',       '#d93025', 'system',   8),
        (p_user_id, 'trash',      'Trash',      '#5f6368', 'system',   9),
        (p_user_id, 'social',     'Social',     '#1a73e8', 'category', 10),
        (p_user_id, 'promotions', 'Promotions', '#188038', 'category', 11),
        (p_user_id, 'updates',    'Updates',    '#e37400', 'category', 12),
        (p_user_id, 'work',       'Work',       '#d93025', 'user',     20),
        (p_user_id, 'personal',   'Personal',   '#673ab7', 'user',     21),
        (p_user_id, 'notes',      'Notes',      '#0b8043', 'user',     22)
        ON CONFLICT (user_id, slug) DO NOTHING;
END;
$fn$ LANGUAGE plpgsql;

-- ============================================================ seed data
-- password for every seeded account: password123
INSERT INTO users (email, password, full_name, avatar_color) VALUES
 ('admin@imel.id',   '$2y$10$4r15u54aPnYylebE8Wrlweb7oszNrnj/QML.6z5Obj203tJFVx3w.', 'Administrator', '#1a73e8'),
 ('fakhul@imel.id',  '$2y$10$4r15u54aPnYylebE8Wrlweb7oszNrnj/QML.6z5Obj203tJFVx3w.', 'Fakhul Huda',   '#0b8043'),
 ('support@imel.id', '$2y$10$4r15u54aPnYylebE8Wrlweb7oszNrnj/QML.6z5Obj203tJFVx3w.', 'Tim Support',   '#d93025')
ON CONFLICT (email) DO NOTHING;

SELECT bootstrap_user(id) FROM users;

-- Demo conversations for admin@imel.id so the UI is not empty on first run
INSERT INTO messages
 (user_id, message_id, thread_key, from_email, from_name, to_json, subject, snippet,
  body_text, body_html, folder, is_read, is_starred, is_important, has_attachment,
  size_bytes, internal_date)
SELECT u.id, m.mid, m.tkey, m.femail, m.fname,
       '["admin@imel.id"]', m.subject, left(m.body, 300), m.body,
       '<p>' || m.body || '</p>', 'inbox', m.is_read, m.is_starred, m.is_important, FALSE,
       length(m.body), NOW() - (m.age || ' hours')::interval
FROM users u
CROSS JOIN (VALUES
 ('<seed1@imel.id>', 'seed-1', 'susanto@barata.co.id', 'Susanto Susanto',
  'Document shared with you: "10 Formulir Laporan Incident Switch CBN Down"',
  'Susanto membagikan dokumen laporan incident switch CBN kepada Anda. Buka dokumen untuk melihat detail gangguan akses jaringan.', FALSE, FALSE, TRUE, 4),
 ('<seed2@imel.id>', 'seed-2', 'noreply@analytics.google.com', 'Google Analytics',
  'Your Google Analytics performance report is in for July 23rd - August 19th',
  'Discover your latest metrics and gain key insights into how your site performed over the last month.', FALSE, FALSE, FALSE, 7),
 ('<seed3@imel.id>', 'seed-3', 'giovanna@biznetgio.com', 'Giovanna dari BiznetGio',
  'Flash Sale VPS Terlewat? Tenang, Masih Ada Diskon 17%',
  'Nikmati promo NEO Lite dan NEO Lite Pro tanpa menunggu kuota flash sale. Berlaku sampai akhir bulan.', TRUE, FALSE, FALSE, 20),
 ('<seed4@imel.id>', 'seed-4', 'portal@biznetgio.com', 'PT Biznet Gio Nusantara',
  'Berhasil Login Portal',
  'Halo Fakhul, kami mendeteksi bahwa Anda berhasil login dengan perangkat di bawah ini. IP Address 103.10.20.30.', TRUE, TRUE, FALSE, 30),
 ('<seed5@imel.id>', 'seed-5', 'portal@biznetgio.com', 'PT Biznet Gio Nusantara',
  'Kode Autentikasi Login Anda',
  'Halo Fakhul, terima kasih telah menggunakan layanan Biznet Gio. Saat ini terdapat permintaan autentikasi login.', TRUE, FALSE, FALSE, 34),
 ('<seed6@imel.id>', 'seed-6', 'business@xlsmart.co.id', 'XLSMART for BUSINESS',
  'Dirgahayu ke-81 Republik Indonesia',
  'Terus melangkah di titik baru bersama konektivitas terbaik untuk digitalisasi Indonesia.', FALSE, FALSE, FALSE, 50),
 ('<seed7@imel.id>', 'seed-7', 'noreply@openai.com', 'ChatGPT',
  'Ask anything - really',
  'Start with any question and let the assistant help you get things done faster.', FALSE, FALSE, FALSE, 55),
 ('<seed8@imel.id>', 'seed-8', 'do-not-reply@sophos.com', 'do-not-reply',
  '[HIGH] Alert for Sophos Central: A macOS device does not meet prerequisites',
  'A managed macOS endpoint in your estate is missing required prerequisites for full protection.', FALSE, FALSE, TRUE, 80),
 ('<seed9@imel.id>', 'seed-9', 'news@hostinger.com', 'Hostinger',
  'Semakin banyak cara untuk mengembangkan bisnis',
  'Intip fitur dan kabar terbaru Hostinger untuk mengembangkan website bisnis Anda.', TRUE, FALSE, FALSE, 130),
 ('<seed10@imel.id>', 'seed-10', 'support@imel.id', 'Tim Support',
  'Selamat datang di imel.id',
  'Akun Anda siap digunakan. Gunakan tombol Compose untuk menulis email pertama Anda, atau coba pencarian dengan operator seperti from:, has:attachment, dan is:unread.', FALSE, TRUE, TRUE, 1)
) AS m(mid, tkey, femail, fname, subject, body, is_read, is_starred, is_important, age)
WHERE u.email = 'admin@imel.id'
ON CONFLICT DO NOTHING;

-- Attach category labels to a few of the seeded messages
INSERT INTO message_labels (message_id, label_id)
SELECT m.id, l.id
FROM messages m
JOIN labels l ON l.user_id = m.user_id AND l.slug = 'promotions'
WHERE m.thread_key IN ('seed-3', 'seed-9')
ON CONFLICT DO NOTHING;

INSERT INTO message_labels (message_id, label_id)
SELECT m.id, l.id
FROM messages m
JOIN labels l ON l.user_id = m.user_id AND l.slug = 'updates'
WHERE m.thread_key IN ('seed-2', 'seed-4', 'seed-5')
ON CONFLICT DO NOTHING;

INSERT INTO contacts (user_id, email, name, times_contacted, last_contacted_at)
SELECT u.id, c.email, c.name, 3, NOW()
FROM users u CROSS JOIN (VALUES
 ('fakhul@imel.id', 'Fakhul Huda'),
 ('support@imel.id', 'Tim Support'),
 ('susanto@barata.co.id', 'Susanto Susanto')
) AS c(email, name)
WHERE u.email = 'admin@imel.id'
ON CONFLICT DO NOTHING;
