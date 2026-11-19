-- Synthetic test schema. Only tests/integration/mysql-workspace.cjs may initialize it.
CREATE TABLE users (username VARCHAR(255), first_name VARCHAR(255), last_name VARCHAR(255), id int PRIMARY KEY, email varchar(255) COLLATE utf8mb3_general_ci, password varchar(255) COLLATE utf8mb3_general_ci, status tinyint(1), role varchar(100) COLLATE utf8mb3_general_ci, role_id int, corp_id varchar(11) COLLATE utf8mb3_general_ci, dept_id varchar(11) COLLATE utf8mb3_general_ci, last_seen timestamp, ip_address varchar(100) COLLATE utf8mb3_general_ci) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE roles_permissions (manage_all_posts TINYINT DEFAULT 0, id INT AUTO_INCREMENT PRIMARY KEY, role_name VARCHAR(255) DEFAULT 'Fixture role', categories TINYINT DEFAULT 0, locations TINYINT DEFAULT 0, departments TINYINT DEFAULT 0, users TINYINT DEFAULT 0, role varchar(255) COLLATE utf8mb3_general_ci, admin_panel tinyint(1), add_post tinyint(1)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE users_logs (user_id int, last_seen timestamp, ip_address varchar(100) COLLATE utf8mb3_general_ci) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE general_settings (id int PRIMARY KEY, timezone varchar(255) COLLATE utf8mb3_general_ci, site_lang int) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE settings (lang_id int, site_title varchar(255) COLLATE utf8mb3_general_ci, application_name varchar(255) COLLATE utf8mb3_general_ci) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE posts (intervale INT DEFAULT 7, published_at DATETIME DEFAULT '2026-02-01 10:00:00', subcategory_id INT DEFAULT 6, location_id INT DEFAULT 1, sublocation_id INT DEFAULT 3, assign_to INT, image_small VARCHAR(255) DEFAULT 'synthetic-image.jpg', title VARCHAR(255) DEFAULT 'Fixture WO', summary TEXT, lang_id INT DEFAULT 1, post_type VARCHAR(30) DEFAULT 'article', created_at DATETIME DEFAULT '2026-01-01 10:00:00', edited_at DATETIME, id int PRIMARY KEY, category_id int, role_id int, corp_id int, dept_id int, user_id int, visibility tinyint(1), status tinyint(1), is_scheduled tinyint(1)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE corps (id int PRIMARY KEY, name varchar(255) COLLATE utf8mb3_general_ci, corp_order smallint) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE categories (
    id INT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    parent_id INT NOT NULL,
    lang_id INT NOT NULL,
    category_order INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE locations (
    id INT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    parent_id INT NOT NULL,
    lang_id INT NOT NULL,
    corp_id INT NOT NULL,
    location_order INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE departments (
    id INT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    parent_id INT NOT NULL,
    lang_id INT NOT NULL,
    corp_id INT NOT NULL,
    department_order INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO categories VALUES (1, 'New', 0, 1, 1), (2, 'Progress', 0, 1, 2), (6, 'Fixture issue', 1, 1, 3);
INSERT INTO locations VALUES
    (1, 'Site One', 0, 1, 1, 1), (2, 'Site Two', 0, 1, 2, 1),
    (3, 'Site One Room', 1, 1, 1, 1), (4, 'Site Two Room', 2, 1, 2, 1),
    (5, 'Invalid fixture link', 1, 1, 2, 1);
INSERT INTO departments VALUES (10, 'Engineering One', 0, 1, 1, 1), (20, 'Engineering Two', 0, 1, 2, 1);

