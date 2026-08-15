<?php

/**
 * SQLite 연결(지연 생성). 첫 호출에서 스키마를 보장한다.
 */
function wiki_db( $action = null )
{
    static $db = null;
    if ($action === "close") {
        if ($db instanceof PDO) {
            try {
                $db->exec("PRAGMA wal_checkpoint(TRUNCATE)");
            } catch ( Throwable $error ) {
                error_log("wikiman: sqlite checkpoint failed - " . $error->getMessage());
            }
        }
        $db = null;
        return null;
    }
    if ($db !== null) {
        return $db;
    }

    wiki_make_dir(wiki_config("data_dir"));
    wiki_make_dir(wiki_config("uploads"));

    if (!extension_loaded("pdo_sqlite")) {
        wiki_abort(500, "PHP pdo_sqlite 확장이 필요합니다.");
    }

    try {
        $db = new PDO("sqlite:" . wiki_config("database"), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch ( PDOException $e ) {
        error_log("wikiman: sqlite open failed - " . $e->getMessage());
        wiki_abort(500, "데이터베이스를 열 수 없습니다.");
    }

    $db->exec("PRAGMA journal_mode = WAL");
    $db->exec("PRAGMA foreign_keys = ON");
    $db->exec("PRAGMA busy_timeout = 5000");

    wiki_ensure_schema($db);
    return $db;
}

/**
 * 트랜잭션 실행 도우미. 실패하면 롤백 후 500 으로 종료한다.
 */
function wiki_transaction( $callback )
{
    $db = wiki_db();
    $db->beginTransaction();
    try {
        $result = $callback($db);
        $db->commit();
        return $result;
    } catch ( Throwable $e ) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("wikiman: transaction failed - " . $e);
        wiki_abort(500, wiki_config("debug") ? $e->getMessage() : "서버 오류가 발생했습니다.");
    }
}

function wiki_ensure_schema( PDO $db )
{
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'reader' CHECK(role IN ('writer', 'reader')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  parent_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  visibility TEXT NOT NULL DEFAULT 'public' CHECK(visibility IN ('public', 'private')),
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS posts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
  author_id INTEGER NOT NULL REFERENCES users(id),
  visibility TEXT NOT NULL DEFAULT 'public' CHECK(visibility IN ('public', 'private')),
  status TEXT NOT NULL DEFAULT 'published' CHECK(status IN ('draft', 'published')),
  editor_type TEXT NOT NULL DEFAULT 'ckeditor'
    CHECK(editor_type IN ('textarea', 'ckeditor', 'summernote', 'tui', 'editorjs', 'markdown', 'html')),
  content TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  deleted_at TEXT
);
CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS homepage_posts (
  post_id INTEGER PRIMARY KEY REFERENCES posts(id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS upload_refs (
  post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  stored_name TEXT NOT NULL,
  PRIMARY KEY (post_id, stored_name)
);
CREATE TABLE IF NOT EXISTS post_keywords (
  post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  keyword TEXT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (post_id, keyword)
);
CREATE TABLE IF NOT EXISTS post_attachments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  stored_name TEXT NOT NULL,
  original_name TEXT NOT NULL,
  mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
  size INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS top_menu_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL,
  post_id INTEGER REFERENCES posts(id) ON DELETE CASCADE,
  url TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS quick_posts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  author_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  content TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS link_preview_cache (
  url TEXT PRIMARY KEY,
  final_url TEXT NOT NULL DEFAULT '',
  title TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  image TEXT NOT NULL DEFAULT '',
  site_name TEXT NOT NULL DEFAULT '',
  fetched_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS link_preview_rate (
  client_key TEXT NOT NULL,
  hit_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS plantuml_rate (
  client_key TEXT NOT NULL,
  hit_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_homepage_posts_sort ON homepage_posts(sort_order);
CREATE INDEX IF NOT EXISTS idx_upload_refs_name ON upload_refs(stored_name);
CREATE INDEX IF NOT EXISTS idx_post_keywords_keyword ON post_keywords(keyword);
CREATE INDEX IF NOT EXISTS idx_post_attachments_post_id ON post_attachments(post_id);
CREATE INDEX IF NOT EXISTS idx_top_menu_items_sort ON top_menu_items(sort_order, id);
CREATE INDEX IF NOT EXISTS idx_quick_posts_author_updated ON quick_posts(author_id, updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_link_preview_cache_fetched ON link_preview_cache(fetched_at);
CREATE INDEX IF NOT EXISTS idx_link_preview_rate_client ON link_preview_rate(client_key, hit_at);
CREATE INDEX IF NOT EXISTS idx_plantuml_rate_client ON plantuml_rate(client_key, hit_at);
CREATE INDEX IF NOT EXISTS idx_posts_list ON posts(deleted_at, status, visibility, updated_at);
CREATE INDEX IF NOT EXISTS idx_posts_category ON posts(category_id, deleted_at);
CREATE INDEX IF NOT EXISTS idx_posts_author ON posts(author_id);
CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(status, deleted_at);
SQL);

    $defaults = [
        "schema_version" => "11",
        "site_title" => "Wikiman",
        "theme" => "light",
        "plantuml_server" => "https://www.plantuml.com/plantuml",
        "default_editor" => "ckeditor",
        "default_editor_mobile" => "ckeditor",
        "favicon" => "",
        "max_attachment_mb" => "20",
        "category_tree_expand" => "expanded",
        "category_tree_side" => "left",
        "font_scale" => "100",
        "top_menu_visible" => "1",
        "mobile_quick_post_enabled" => "0",
        "quick_post_promote_source_mode" => "ask",
        "quick_post_promote_editor" => "ask",
        "link_preview_cache_ttl_days" => "10",
        "link_preview_failure_ttl_days" => "1",
    ];

    $insert = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    foreach ($defaults as $key => $value) {
        $insert->execute([$key, $value]);
    }
}
