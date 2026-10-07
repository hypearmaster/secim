-- Seçim / Katılım Takip — şema (MySQL 8 / MariaDB 10.4+)
-- Tüm zamanlar UTC saklanır; ekranda Europe/Istanbul gösterilir.

SET NAMES utf8mb4 COLLATE utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(64)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  full_name      VARCHAR(120) NOT NULL,
  role           ENUM('admin','yonetici','onayci') NOT NULL DEFAULT 'onayci',
  region         VARCHAR(120) NULL COMMENT 'NULL = tüm bölgeler; doluysa onayci sadece bu bölgeyi görür',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Kullanıcı silinmez, pasife alınır (geçmiş işaretler bozulmasın)',
  last_login_at  DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS events (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(160) NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Aynı anda tek aktif etkinlik (kodda garanti edilir)',
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_events_active (is_active),
  CONSTRAINT fk_events_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS import_batches (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id       INT UNSIGNED NOT NULL,
  filename       VARCHAR(255) NOT NULL,
  row_count      INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_count  INT UNSIGNED NOT NULL DEFAULT 0,
  user_id        INT UNSIGNED NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_batches_event FOREIGN KEY (event_id) REFERENCES events(id),
  CONSTRAINT fk_batches_user  FOREIGN KEY (user_id)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS people (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id     INT UNSIGNED NOT NULL,
  batch_id     INT UNSIGNED NULL,
  ref_no       VARCHAR(64)  NULL COMMENT 'Excel''de benzersiz numara varsa (opsiyonel)',
  full_name    VARCHAR(160) NOT NULL,
  authority    VARCHAR(120) NULL COMMENT 'Yetki / görev',
  region       VARCHAR(120) NULL COMMENT 'Bölge',
  group_name   VARCHAR(120) NULL COMMENT 'Grup; NULL = grupsuz',
  extra        LONGTEXT     NULL COMMENT 'Excel''deki eşlenmeyen sütunlar (JSON)',
  search_key   VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Türkçe karaktersiz küçük harf ad+ref (search_key())',
  attended_at  DATETIME     NULL,
  attended_by  INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_people_event_region (event_id, region),
  KEY ix_people_event_name   (event_id, full_name),
  KEY ix_people_event_att    (event_id, attended_at),
  CONSTRAINT fk_people_event FOREIGN KEY (event_id)    REFERENCES events(id),
  CONSTRAINT fk_people_batch FOREIGN KEY (batch_id)    REFERENCES import_batches(id),
  CONSTRAINT fk_people_user  FOREIGN KEY (attended_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS attendance_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id    INT UNSIGNED NOT NULL,
  person_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  action      ENUM('mark','unmark') NOT NULL,
  ip          VARBINARY(16) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_log_event_time (event_id, created_at),
  KEY ix_log_person (person_id),
  CONSTRAINT fk_log_event  FOREIGN KEY (event_id)  REFERENCES events(id),
  CONSTRAINT fk_log_person FOREIGN KEY (person_id) REFERENCES people(id),
  CONSTRAINT fk_log_user   FOREIGN KEY (user_id)   REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username    VARCHAR(64)   NOT NULL,
  ip          VARBINARY(16) NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_attempts_user (username, created_at),
  KEY ix_attempts_ip   (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
