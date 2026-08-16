<?php

/**
 * 설정된 PDO 데이터베이스에 지연 연결하고 첫 호출에서 스키마를 보장한다.
 */
function wiki_db( $action = null )
{
    static $db = null;
    if ($action === "close") {
        if ($db instanceof PDO) {
            if (wiki_db_driver() === "sqlite") {
                try {
                    $db->exec("PRAGMA wal_checkpoint(TRUNCATE)");
                } catch ( Throwable $error ) {
                    error_log("wikiman: sqlite checkpoint failed - " . $error->getMessage());
                }
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

    if (!wiki_is_installed()) {
        wiki_abort(503, "INSTALL_REQUIRED", ["installUrl" => "/install.php"]);
    }

    $driver = wiki_db_driver();
    if ($driver === "sqlite" && !extension_loaded("pdo_sqlite")) {
        wiki_abort(500, "SQLITE_EXTENSION_REQUIRED");
    }
    if ($driver === "mysql" && !extension_loaded("pdo_mysql")) {
        wiki_abort(500, "MYSQL_EXTENSION_REQUIRED");
    }

    try {
        $db_config = wiki_config("db");
        $dsn = $driver === "mysql"
            ? "mysql:host=" . $db_config["host"]
                . ";port=" . $db_config["port"]
                . ";dbname=" . $db_config["database"]
                . ";charset=" . $db_config["charset"]
            : "sqlite:" . wiki_config("database");
        $db = new PDO($dsn,
            $driver === "mysql" ? $db_config["username"] : null,
            $driver === "mysql" ? $db_config["password"] : null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch ( PDOException $e ) {
        error_log("wikiman: database open failed - " . $e->getMessage());
        wiki_abort(503, "INSTALL_REQUIRED", ["installUrl" => "/install.php"]);
    }

    if ($driver === "sqlite") {
        $db->exec("PRAGMA journal_mode = WAL");
        $db->exec("PRAGMA foreign_keys = ON");
        $db->exec("PRAGMA busy_timeout = 5000");
    } else {
        $db->exec("SET NAMES " . $db_config["charset"]);
    }

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
        wiki_abort(500, "SERVER_ERROR");
    }
}

function wiki_ensure_schema( PDO $db )
{
    if (wiki_db_driver() === "mysql") {
        wiki_ensure_schema_mysql($db);
        return;
    }

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
        "site_language" => "ko-KR",
        "theme" => "light",
        "plantuml_server" => "https://www.plantuml.com/plantuml",
        "default_editor" => "ckeditor",
        "default_editor_mobile" => "ckeditor",
        "favicon" => "",
        "max_attachment_mb" => "20",
        "category_tree_expand" => "expanded",
        "category_tree_side" => "left",
        "right_menu_default_open" => "1",
        "font_scale" => "100",
        "top_menu_visible" => "1",
        "mobile_quick_post_enabled" => "0",
        "blog_mode" => "0",
        "blog_show_homepage" => "0",
        "blog_posts_per_page" => "10",
        "code_line_numbers" => "0",
        "quick_post_editor" => "tui",
        "quick_post_promote_source_mode" => "ask",
        "quick_post_promote_editor" => "ask",
        "link_preview_cache_ttl_days" => "10",
        "link_preview_failure_ttl_days" => "1",
    ];

    $insert = $db->prepare("INSERT OR IGNORE INTO settings (`key`, `value`) VALUES (?, ?)");
    foreach ($defaults as $key => $value) {
        $insert->execute([$key, $value]);
    }
}

function wiki_ensure_schema_mysql( PDO $db )
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS users (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          username VARCHAR(32) NOT NULL UNIQUE,
          password_hash VARCHAR(255) NOT NULL,
          role VARCHAR(16) NOT NULL DEFAULT 'reader',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS categories (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          parent_id BIGINT UNSIGNED NULL,
          sort_order INT NOT NULL DEFAULT 0,
          visibility VARCHAR(16) NOT NULL DEFAULT 'public',
          created_by BIGINT UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
          CONSTRAINT fk_categories_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
          INDEX idx_categories_parent (parent_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS posts (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          title VARCHAR(500) NOT NULL,
          slug VARCHAR(255) NOT NULL UNIQUE,
          category_id BIGINT UNSIGNED NULL,
          author_id BIGINT UNSIGNED NOT NULL,
          visibility VARCHAR(16) NOT NULL DEFAULT 'public',
          status VARCHAR(16) NOT NULL DEFAULT 'published',
          editor_type VARCHAR(20) NOT NULL DEFAULT 'ckeditor',
          content LONGTEXT NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          deleted_at DATETIME NULL,
          CONSTRAINT fk_posts_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
          CONSTRAINT fk_posts_author FOREIGN KEY (author_id) REFERENCES users(id),
          INDEX idx_posts_list (deleted_at, status, visibility, updated_at),
          INDEX idx_posts_category (category_id, deleted_at),
          INDEX idx_posts_author (author_id),
          FULLTEXT INDEX idx_posts_fulltext (title, content)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS settings (
          `key` VARCHAR(191) NOT NULL PRIMARY KEY,
          `value` TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS homepage_posts (
          post_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
          sort_order INT NOT NULL DEFAULT 0,
          CONSTRAINT fk_homepage_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
          INDEX idx_homepage_posts_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS upload_refs (
          post_id BIGINT UNSIGNED NOT NULL,
          stored_name VARCHAR(255) NOT NULL,
          PRIMARY KEY (post_id, stored_name),
          CONSTRAINT fk_upload_refs_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
          INDEX idx_upload_refs_name (stored_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS post_keywords (
          post_id BIGINT UNSIGNED NOT NULL,
          keyword VARCHAR(191) NOT NULL,
          sort_order INT NOT NULL DEFAULT 0,
          PRIMARY KEY (post_id, keyword),
          CONSTRAINT fk_post_keywords_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
          INDEX idx_post_keywords_keyword (keyword)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS post_attachments (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          post_id BIGINT UNSIGNED NOT NULL,
          stored_name VARCHAR(255) NOT NULL,
          original_name VARCHAR(255) NOT NULL,
          mime_type VARCHAR(191) NOT NULL DEFAULT 'application/octet-stream',
          size BIGINT NOT NULL DEFAULT 0,
          sort_order INT NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_post_attachments_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
          INDEX idx_post_attachments_post_id (post_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS top_menu_items (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          label VARCHAR(30) NOT NULL,
          post_id BIGINT UNSIGNED NULL,
          url VARCHAR(500) NULL,
          sort_order INT NOT NULL DEFAULT 0,
          CONSTRAINT fk_top_menu_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
          INDEX idx_top_menu_items_sort (sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS quick_posts (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          author_id BIGINT UNSIGNED NOT NULL,
          content LONGTEXT NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_quick_posts_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
          INDEX idx_quick_posts_author_updated (author_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS link_preview_cache (
          url VARCHAR(768) NOT NULL PRIMARY KEY,
          final_url TEXT NOT NULL,
          title TEXT NOT NULL,
          description TEXT NOT NULL,
          image TEXT NOT NULL,
          site_name VARCHAR(255) NOT NULL DEFAULT '',
          fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_link_preview_cache_fetched (fetched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS link_preview_rate (
          client_key VARCHAR(191) NOT NULL,
          hit_at BIGINT NOT NULL,
          INDEX idx_link_preview_rate_client (client_key, hit_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS plantuml_rate (
          client_key VARCHAR(191) NOT NULL,
          hit_at BIGINT NOT NULL,
          INDEX idx_plantuml_rate_client (client_key, hit_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $sql) $db->exec($sql);

    $defaults = [
        "schema_version" => "11", "site_title" => "Wikiman", "site_language" => "ko-KR",
        "theme" => "light", "plantuml_server" => "https://www.plantuml.com/plantuml",
        "default_editor" => "ckeditor", "default_editor_mobile" => "ckeditor",
        "favicon" => "", "max_attachment_mb" => "20", "category_tree_expand" => "expanded",
        "category_tree_side" => "left", "right_menu_default_open" => "1", "font_scale" => "100",
        "top_menu_visible" => "1", "mobile_quick_post_enabled" => "0", "blog_mode" => "0",
        "blog_show_homepage" => "0", "blog_posts_per_page" => "10", "code_line_numbers" => "0",
        "quick_post_editor" => "tui", "quick_post_promote_source_mode" => "ask",
        "quick_post_promote_editor" => "ask", "link_preview_cache_ttl_days" => "10",
        "link_preview_failure_ttl_days" => "1",
    ];
    $insert = $db->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)");
    foreach ($defaults as $key => $value) $insert->execute([$key, $value]);
}
