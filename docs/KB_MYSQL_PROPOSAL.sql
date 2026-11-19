-- HISTORICAL PROPOSAL. Execution and cutover are suspended under the legacy-first decision.
-- Target: MySQL 8.0.16+ with InnoDB and a dedicated, approved KB schema.
-- Select the schema separately. This file has no USE, grants, import or EWO table changes.

CREATE TABLE kb_articles (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    slug VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(150) NOT NULL,
    summary TEXT NOT NULL,
    category ENUM('it-support','engineering','housekeeping','general-how-to') NOT NULL,
    keywords JSON NOT NULL,
    symptoms JSON NOT NULL,
    causes JSON NOT NULL,
    resolution_steps JSON NOT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    visibility ENUM('public','internal') NOT NULL DEFAULT 'internal',
    created_at DATETIME(6) NOT NULL COMMENT 'UTC',
    updated_at DATETIME(6) NOT NULL COMMENT 'UTC optimistic concurrency token',
    PRIMARY KEY (id),
    UNIQUE KEY kb_articles_slug (slug),
    KEY kb_articles_public_order (status, visibility, updated_at, id),
    KEY kb_articles_public_category (status, visibility, category, updated_at, id),
    CHECK (JSON_TYPE(keywords) = 'ARRAY'),
    CHECK (JSON_TYPE(symptoms) = 'ARRAY'),
    CHECK (JSON_TYPE(causes) = 'ARRAY'),
    CHECK (JSON_TYPE(resolution_steps) = 'ARRAY'),
    CHECK (status <> 'published' OR JSON_LENGTH(resolution_steps) > 0),
    CHECK (updated_at >= created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE kb_article_related (
    article_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    related_slug VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (article_id, position),
    UNIQUE KEY kb_article_related_slug (article_id, related_slug),
    CONSTRAINT kb_article_related_source FOREIGN KEY (article_id)
        REFERENCES kb_articles (id) ON DELETE CASCADE,
    CHECK (position BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional read-only projection, subject to hosting permissions for CREATE VIEW.
-- Keywords are searchable; the API only serializes its existing response fields.
CREATE VIEW kb_public_articles AS
SELECT id, slug, title, summary, category, keywords, symptoms, causes, resolution_steps, created_at, updated_at
FROM kb_articles
WHERE status = 'published' AND visibility = 'public';

-- Related lists resolve only edges between two public, published articles.
CREATE VIEW kb_public_article_related AS
SELECT source.slug AS source_slug, relation.position, target.slug AS related_slug,
       target.title, target.summary, target.category, target.updated_at
FROM kb_article_related AS relation
JOIN kb_public_articles AS source ON source.id = relation.article_id
JOIN kb_public_articles AS target ON target.slug = relation.related_slug;
