-- Import into an empty database using phpMyAdmin. MySQL 8 / MariaDB 10.6+, utf8mb4.
SET NAMES utf8mb4;
CREATE TABLE users (
 id BIGINT PRIMARY KEY, username VARCHAR(64) NULL, first_seen DATETIME NOT NULL,
 last_seen DATETIME NOT NULL, membership_checked_at DATETIME NULL, membership_passed_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE drafts (
 user_id BIGINT PRIMARY KEY, step VARCHAR(20) NOT NULL DEFAULT 'name', full_name VARCHAR(200) NULL,
 startup VARCHAR(200) NULL, phone VARCHAR(32) NULL, pending_file LONGTEXT NULL,
 started_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, cancelled TINYINT NOT NULL DEFAULT 0,
 max_step INT NOT NULL DEFAULT 1, FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE admins (
 id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
 role ENUM('owner','reviewer') NOT NULL, active TINYINT NOT NULL DEFAULT 1, session_version INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE applications (
 id INT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(30) NOT NULL UNIQUE, user_id BIGINT NOT NULL UNIQUE,
 full_name VARCHAR(200) NOT NULL, startup VARCHAR(200) NOT NULL, phone VARCHAR(32) NOT NULL,
 file_key VARCHAR(80) NOT NULL, file_name VARCHAR(255) NOT NULL, file_mime VARCHAR(120) NOT NULL, file_size INT NOT NULL,
 status ENUM('new','under review','shortlisted','accepted','rejected') NOT NULL DEFAULT 'new',
 assigned_to INT NULL, submitted_at DATETIME NOT NULL, review_due_at DATETIME NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(assigned_to) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE notes (
 id INT AUTO_INCREMENT PRIMARY KEY, application_id INT NOT NULL, admin_id INT NOT NULL,
 body TEXT NOT NULL, created_at DATETIME NOT NULL,
 FOREIGN KEY(application_id) REFERENCES applications(id), FOREIGN KEY(admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE ratings (
 application_id INT NOT NULL, admin_id INT NOT NULL, score INT NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(application_id,admin_id), CHECK(score BETWEEN 1 AND 5),
 FOREIGN KEY(application_id) REFERENCES applications(id), FOREIGN KEY(admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE audit (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, admin_id INT NULL, application_id INT NULL,
 action VARCHAR(80) NOT NULL, details TEXT NOT NULL, created_at DATETIME NOT NULL,
 INDEX(application_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE updates (
 id BIGINT PRIMARY KEY, payload LONGTEXT NOT NULL, received_at DATETIME NOT NULL,
 processed_at DATETIME NULL, attempts INT NOT NULL DEFAULT 0, next_attempt_at DATETIME NOT NULL, last_error VARCHAR(255) NULL,
 INDEX(processed_at,next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE notifications (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, dedupe_key VARCHAR(190) NOT NULL UNIQUE,
 user_id BIGINT NOT NULL, application_id INT NULL, method VARCHAR(40) NOT NULL DEFAULT 'sendMessage',
 payload LONGTEXT NOT NULL, kind VARCHAR(30) NOT NULL DEFAULT 'bot', status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
 attempts INT NOT NULL DEFAULT 0, available_at DATETIME NOT NULL, locked_at DATETIME NULL,
 created_at DATETIME NOT NULL, sent_at DATETIME NULL, last_error VARCHAR(255) NULL, telegram_message_id BIGINT NULL,
 INDEX(status,available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE settings (`key` VARCHAR(80) PRIMARY KEY, value LONGTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO settings (`key`,value) VALUES
 ('registration_open','1'),('deadline','2026-11-18 23:59:59'),('support','@pivotuzbekistan'),('holidays','[]'),
 ('event_title','Grand Demo Day'),('event_date','2026-11-19'),('event_time','14:00–18:00'),('venue','Yandex Uzbekistan ofisi, Toshkent'),
 ('notification_template','Assalomu alaykum, {name}! {reference} raqamli arizangiz holati: {status}. Pivot jamoasi 🚀');
CREATE TABLE reminders (
 application_id INT PRIMARY KEY, created_at DATETIME NOT NULL, FOREIGN KEY(application_id) REFERENCES applications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE login_attempts (
 identity_hash CHAR(64) PRIMARY KEY, attempts INT NOT NULL, window_started DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
