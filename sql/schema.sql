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
  auth_version   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Artınca bu kullanıcının tüm oturumları düşer (şifre değişti, cihazlardan çıkar)',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS events (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(160) NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Aynı anda tek aktif etkinlik (kodda garanti edilir)',
  vote_ver    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Oy sayacı sürümü: her +1/geri al/sıfırla artırır (yanıt sırası)',
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
  phone        VARCHAR(20)  NULL COMMENT 'Arama için telefon (+905xxxxxxxxx); Excel Gsm/Tel ya da elle',
  full_name    VARCHAR(160) NOT NULL,
  authority    VARCHAR(255) NULL COMMENT 'Yetkili kişi(ler)',
  region       VARCHAR(120) NULL COMMENT 'Bölge',
  group_name   VARCHAR(120) NULL COMMENT 'Grup; NULL = grupsuz',
  stance       VARCHAR(32)  NULL COMMENT 'Bizimle mi? (config stance_options anahtarı)',
  extra        LONGTEXT     NULL COMMENT 'Excel''deki eşlenmeyen sütunlar (JSON)',
  search_key   VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Türkçe karaktersiz küçük harf ad+ref (search_key())',
  firm_key     VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Aynı firma/şubeler tek kart (firm_key())',
  attended_at  DATETIME     NULL,
  attended_by  INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_people_event_region (event_id, region),
  KEY ix_people_event_name   (event_id, full_name),
  KEY ix_people_event_att    (event_id, attended_at),
  KEY ix_people_event_stance (event_id, stance),
  KEY ix_people_event_firm   (event_id, firm_key),
  CONSTRAINT fk_people_event FOREIGN KEY (event_id)    REFERENCES events(id),
  CONSTRAINT fk_people_batch FOREIGN KEY (batch_id)    REFERENCES import_batches(id),
  CONSTRAINT fk_people_user  FOREIGN KEY (attended_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS attendance_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id    INT UNSIGNED NOT NULL,
  person_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  action      ENUM('mark','unmark','stance') NOT NULL,
  note        VARCHAR(64)  NULL COMMENT 'stance için yeni değer',
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

-- "Bu cihazda açık kalsın": tarayıcı kapanınca da oturum sürer. Çerezde selector:validator,
-- DB'de sadece validator'ın SHA-256'sı. Her kullanımda validator yenilenir (çalınan eski çerez işe yaramaz).
CREATE TABLE IF NOT EXISTS remember_tokens (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  selector        CHAR(24)     NOT NULL,
  validator_hash  CHAR(64)     NOT NULL,
  prev_hash       CHAR(64)     NULL COMMENT 'Yenilemeden önceki; aynı anda gelen istekler için kısa süre geçerli',
  rotated_at      DATETIME     NULL,
  user_agent      VARCHAR(190) NULL,
  ip              VARBINARY(16) NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at    DATETIME     NULL,
  expires_at      DATETIME     NOT NULL,
  UNIQUE KEY uq_remember_selector (selector),
  KEY ix_remember_user (user_id),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Denetim kaydı: yönetim işlemleri (giriş, kullanıcı, liste, dışa aktarma...). Şifre ASLA yazılmaz.
CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED  NULL COMMENT 'NULL = giriş yapmamış (örn. hatalı giriş)',
  action      VARCHAR(40)   NOT NULL,
  target      VARCHAR(190)  NULL COMMENT 'Kısa özet: kullanıcı adı, dosya adı, liste adı',
  detail      TEXT          NULL COMMENT 'JSON ayrıntı',
  ip          VARBINARY(16) NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_audit_time (created_at),
  KEY ix_audit_action (action, created_at),
  KEY ix_audit_user (user_id, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Oy sayacı: her tıklama bir satır (geri alma + denetim izi + kim saydı). Sayımlar COUNT(*) ile.
CREATE TABLE IF NOT EXISTS vote_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id    INT UNSIGNED NOT NULL,
  option_key  VARCHAR(32)  NOT NULL COMMENT 'config vote_options anahtarı (mavi, kirmizi...)',
  user_id     INT UNSIGNED NOT NULL,
  ip          VARBINARY(16) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_vote_event_opt (event_id, option_key),
  KEY ix_vote_event_id  (event_id, id),
  CONSTRAINT fk_vote_event FOREIGN KEY (event_id) REFERENCES events(id),
  CONSTRAINT fk_vote_user  FOREIGN KEY (user_id)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Hediye: bağımsız isim listesi (sadece ad) + verdik/vermedik. Üye listesiyle eşleşmez.
CREATE TABLE IF NOT EXISTS gifts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id    INT UNSIGNED NOT NULL,
  name        VARCHAR(160) NOT NULL,
  search_key  VARCHAR(255) NOT NULL DEFAULT '',
  note        VARCHAR(190) NULL COMMENT 'opsiyonel: firma / açıklama',
  given_at    DATETIME     NULL,
  given_by    INT UNSIGNED NULL,
  batch_id    INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_gifts_event (event_id, given_at),
  KEY ix_gifts_key   (event_id, search_key),
  CONSTRAINT fk_gifts_event FOREIGN KEY (event_id) REFERENCES events(id),
  CONSTRAINT fk_gifts_user  FOREIGN KEY (given_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- "Bizimkiler": bizimle olan kişilerin isim listesi ve üye listesiyle eşleşmesi (etkinliğe bağlı).
CREATE TABLE IF NOT EXISTS supporters (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id    INT UNSIGNED NOT NULL,
  name        VARCHAR(160) NOT NULL,
  search_key  VARCHAR(255) NOT NULL DEFAULT '',
  status      ENUM('matched','suggested','none') NOT NULL DEFAULT 'none'
              COMMENT 'matched = onaylı eşleşme; suggested = sistem tahmini, admin onayı bekler; none = bulunamadı',
  source      ENUM('list','manual') NOT NULL DEFAULT 'list'
              COMMENT 'list = yüklenen isim listesinden; manual = onay ekranında elle Bizimle işaretlenen',
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sup_event (event_id, status),
  CONSTRAINT fk_sup_event FOREIGN KEY (event_id) REFERENCES events(id),
  CONSTRAINT fk_sup_user  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Bir isim birden çok firmanın yetkilisi olabilir (ya da tahmin birden çok aday verir).
CREATE TABLE IF NOT EXISTS supporter_people (
  supporter_id INT UNSIGNED NOT NULL,
  person_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (supporter_id, person_id),
  KEY ix_sp_person (person_id),
  CONSTRAINT fk_sp_sup    FOREIGN KEY (supporter_id) REFERENCES supporters(id) ON DELETE CASCADE,
  CONSTRAINT fk_sp_person FOREIGN KEY (person_id)    REFERENCES people(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
