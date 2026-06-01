PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS owners (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    renewal_date TEXT NOT NULL,
    owner_email TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_url TEXT NOT NULL,
    admin_user TEXT NOT NULL,
    app_password_encrypted TEXT NOT NULL,
    label TEXT NULL,
    owner_id INTEGER NULL REFERENCES owners(id) ON DELETE SET NULL,
    last_status TEXT NOT NULL DEFAULT 'unknown',
    pending_plugins INTEGER NOT NULL DEFAULT 0,
    pending_themes INTEGER NOT NULL DEFAULT 0,
    pending_core INTEGER NOT NULL DEFAULT 0,
    active_plugins INTEGER NOT NULL DEFAULT 0,
    inactive_plugins INTEGER NOT NULL DEFAULT 0,
    active_themes INTEGER NOT NULL DEFAULT 0,
    inactive_themes INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    last_sync_at TEXT NULL,
    pending_updates_json TEXT NULL,
    site_snapshot_json TEXT NULL,
    last_connection_test_ok INTEGER NULL,
    last_connection_test_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(site_url)
);

CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    item_name TEXT NULL,
    old_version TEXT NULL,
    new_version TEXT NULL,
    message TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS dashboard_users (
    username TEXT PRIMARY KEY,
    password_hash TEXT NOT NULL
);

INSERT OR IGNORE INTO settings (key, value) VALUES ('agency_name', 'My Agency');
INSERT OR IGNORE INTO settings (key, value) VALUES ('logo_path', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('report_color_hex', '#2563eb');

CREATE TABLE IF NOT EXISTS plugin_packages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    original_filename TEXT NOT NULL,
    disk_name TEXT NOT NULL,
    slug_hint TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS snapshot_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    label TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS site_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    batch_id INTEGER NOT NULL REFERENCES snapshot_batches(id) ON DELETE CASCADE,
    site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    snapshot_json TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_site_snapshots_batch ON site_snapshots(batch_id);
CREATE INDEX IF NOT EXISTS idx_site_snapshots_site ON site_snapshots(site_id);
