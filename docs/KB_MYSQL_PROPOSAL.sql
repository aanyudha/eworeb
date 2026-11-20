-- Dedicated Knowledge Base storage on MySQL 8.0. No changes to legacy tables.
CREATE TABLE kb_articles (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    slug VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('draft','published') NOT NULL,
    visibility ENUM('public','internal') NOT NULL,
    content JSON NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY kb_articles_slug (slug),
    KEY kb_articles_public (status, visibility),
    CHECK (JSON_TYPE(content) = 'OBJECT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
