-- =====================================================================
-- CFS-SIS schema for MariaDB 10.3+ (XAMPP). Converted from the PostgreSQL schema.sql.
-- Changes vs the Postgres draft:
--   * uuid ids -> INT AUTO_INCREMENT (the API reads ids as integers)
--   * enums -> ENUM columns; jsonb/text[] -> JSON; timestamptz -> TIMESTAMP
--   * documents gets remarks + verified_at (the API writes both)
--   * application_no is filled by a trigger using a SEQUENCE
--   * "only one current school year" uses a generated column + UNIQUE
--   * generate_fee_items is a stored PROCEDURE (the API calls CALL generate_fee_items(?))
-- Import in phpMyAdmin: Import tab (no need to select a database first).
-- =====================================================================
-- !! WIPES the old cfs_sis database completely (all tables, data, procedures) and rebuilds it. !!
DROP DATABASE IF EXISTS cfs_sis;
CREATE DATABASE cfs_sis CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE cfs_sis;

CREATE SEQUENCE application_no_seq START WITH 1 INCREMENT BY 1;

CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255),
  full_name     VARCHAR(150) NOT NULL,
  mobile        VARCHAR(30),
  role          ENUM('guardian','registrar','admin') NOT NULL DEFAULT 'guardian',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE school_years (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  label           VARCHAR(20) NOT NULL UNIQUE,
  starts_on       DATE NOT NULL,
  ends_on         DATE NOT NULL,
  enrollment_open TINYINT(1) NOT NULL DEFAULT 0,
  is_current      TINYINT(1) NOT NULL DEFAULT 0,
  current_marker  TINYINT AS (CASE WHEN is_current = 1 THEN 1 END) STORED,
  UNIQUE KEY one_current_school_year (current_marker),   -- only one current school year
  CHECK (ends_on > starts_on)
);

CREATE TABLE students (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  guardian_id INT NOT NULL,
  first_name  VARCHAR(100) NOT NULL,
  last_name   VARCHAR(100) NOT NULL,
  birthdate   DATE NOT NULL,
  sex         ENUM('male','female') NULL,
  address     VARCHAR(255),
  lrn         VARCHAR(20) UNIQUE,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (guardian_id) REFERENCES users(id),
  INDEX (guardian_id)
);

CREATE TABLE enrollment_applications (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  application_no  VARCHAR(30) NOT NULL UNIQUE,            -- filled by trigger below
  school_year_id  INT NOT NULL,
  student_id      INT NOT NULL,
  guardian_id     INT NOT NULL,
  enrollment_type ENUM('new','continuing') NOT NULL,
  grade_level     TINYINT NOT NULL,
  status          ENUM('draft','submitted','pending_verification','needs_revision','approved','rejected') NOT NULL DEFAULT 'draft',
  submitted_at    DATETIME NULL,
  reviewed_by     INT NULL,
  reviewed_at     DATETIME NULL,
  remarks         TEXT NULL,
  guardian_relationship    ENUM('mother','father','legal_guardian') NULL,   -- from wizard step 2
  emergency_contact_name   VARCHAR(150) NULL,
  emergency_contact_mobile VARCHAR(30)  NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (school_year_id) REFERENCES school_years(id),
  FOREIGN KEY (student_id)     REFERENCES students(id),
  FOREIGN KEY (guardian_id)    REFERENCES users(id),
  FOREIGN KEY (reviewed_by)    REFERENCES users(id),
  UNIQUE KEY uq_student_sy (student_id, school_year_id),   -- one application per child per school year
  INDEX (guardian_id), INDEX (status),
  CHECK (grade_level BETWEEN 1 AND 6),                     -- Gr.1-6 only
  CHECK (enrollment_type <> 'new' OR grade_level = 1)      -- new students enter at Grade 1
);

DELIMITER //
CREATE TRIGGER trg_application_no BEFORE INSERT ON enrollment_applications
FOR EACH ROW
BEGIN
  IF NEW.application_no IS NULL OR NEW.application_no = '' THEN
    SET NEW.application_no = CONCAT('CFS-', YEAR(NOW()), '-', LPAD(NEXTVAL(application_no_seq), 5, '0'));
  END IF;
END//
DELIMITER ;

CREATE TABLE screening_requests (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  guardian_id        INT NOT NULL,
  child_name         VARCHAR(200) NOT NULL,
  birthdate          DATE NOT NULL,
  screening_type     ENUM('kindergarten','transferee') NOT NULL,
  previous_school    VARCHAR(200),
  target_grade       TINYINT NULL,
  preferred_schedule VARCHAR(200),
  status             ENUM('requested','scheduled','passed','failed','cancelled') NOT NULL DEFAULT 'requested',
  scheduled_at       DATETIME NULL,
  notes              TEXT,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (guardian_id) REFERENCES users(id),
  CHECK (target_grade IS NULL OR target_grade BETWEEN 1 AND 6),
  CHECK (screening_type <> 'transferee' OR (previous_school IS NOT NULL AND target_grade IS NOT NULL))
);

CREATE TABLE documents (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  doc_type       ENUM('psa_birth_certificate','report_card','good_moral','id_photo','other') NOT NULL,
  file_path      VARCHAR(255) NOT NULL,          -- relative path in storage/, never the file itself
  status         ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  remarks        TEXT NULL,                      -- added: API writes it
  verified_by    INT NULL,
  verified_at    DATETIME NULL,                  -- added: API writes it
  uploaded_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES enrollment_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (verified_by)    REFERENCES users(id),
  UNIQUE KEY uq_app_doctype (application_id, doc_type)
);

CREATE TABLE fee_schedules (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  school_year_id INT NOT NULL,
  grade_level    TINYINT NOT NULL,
  item_name      VARCHAR(100) NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  due_date       DATE NOT NULL,
  sequence       SMALLINT NOT NULL,
  FOREIGN KEY (school_year_id) REFERENCES school_years(id),
  UNIQUE KEY uq_fee_sched (school_year_id, grade_level, item_name),
  CHECK (grade_level BETWEEN 1 AND 6),
  CHECK (amount >= 0)
);

CREATE TABLE fee_items (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  name           VARCHAR(100) NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  due_date       DATE NOT NULL,
  sequence       SMALLINT NOT NULL,
  FOREIGN KEY (application_id) REFERENCES enrollment_applications(id) ON DELETE CASCADE,
  UNIQUE KEY uq_fee_item (application_id, name),
  INDEX (application_id),
  CHECK (amount >= 0)
);

DELIMITER //
CREATE PROCEDURE generate_fee_items(IN p_application_id INT)
BEGIN
  INSERT INTO fee_items (application_id, name, amount, due_date, sequence)
  SELECT a.id, fs.item_name, fs.amount, fs.due_date, fs.sequence
  FROM enrollment_applications a
  JOIN fee_schedules fs ON fs.school_year_id = a.school_year_id AND fs.grade_level = a.grade_level
  WHERE a.id = p_application_id
  ON DUPLICATE KEY UPDATE name = fee_items.name;   -- already there: do nothing
END//
DELIMITER ;

CREATE TABLE payments (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  fee_item_id    INT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  method         VARCHAR(10) NOT NULL DEFAULT 'gcash',
  gcash_ref_no   VARCHAR(50) NOT NULL UNIQUE,    -- blocks reusing one reference twice
  proof_path     VARCHAR(255) NOT NULL,
  status         ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  verified_by    INT NULL,
  verified_at    DATETIME NULL,
  remarks        TEXT NULL,
  submitted_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES enrollment_applications(id),
  FOREIGN KEY (fee_item_id)    REFERENCES fee_items(id),
  FOREIGN KEY (verified_by)    REFERENCES users(id),
  INDEX (application_id, status),
  CHECK (amount > 0),
  CHECK (method = 'gcash')                       -- GCash only
);

CREATE VIEW v_application_balance AS
SELECT t.application_id, t.application_no, t.total_assessed, t.total_paid,
       t.total_assessed - t.total_paid AS outstanding
FROM (
  SELECT a.id AS application_id, a.application_no,
         COALESCE((SELECT SUM(f.amount) FROM fee_items f WHERE f.application_id = a.id), 0) AS total_assessed,
         COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.application_id = a.id AND p.status = 'verified'), 0) AS total_paid
  FROM enrollment_applications a
) t;

CREATE TABLE content_posts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  title        VARCHAR(255) NOT NULL,
  slug         VARCHAR(255) NOT NULL UNIQUE,
  body         MEDIUMTEXT NOT NULL,
  category     ENUM('news','announcement','event') NOT NULL,
  status       ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  event_date   DATE NULL,
  cover_path   VARCHAR(255),
  author_id    INT NOT NULL,
  published_at DATETIME NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (author_id) REFERENCES users(id),
  INDEX (status, published_at)
);

CREATE TABLE chatbot_faqs (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  question  TEXT NOT NULL,
  answer    TEXT NOT NULL,
  keywords  JSON NOT NULL DEFAULT (JSON_ARRAY()),   -- was text[]; store e.g. ["tuition","fees"]
  is_active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE chatbot_logs (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NULL,                           -- null for anonymous visitors
  message        TEXT NOT NULL,
  response       TEXT NOT NULL,
  matched_faq_id INT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (matched_faq_id) REFERENCES chatbot_faqs(id)
);

CREATE TABLE audit_logs (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  actor_id   INT NULL,
  action     VARCHAR(100) NOT NULL,                  -- e.g. 'payment.verified'
  entity     VARCHAR(50) NOT NULL,
  entity_id  INT NULL,
  details    JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actor_id) REFERENCES users(id),
  INDEX (entity, entity_id)
);

-- ---------- seed data (matches the mockups) ----------
-- Demo registrar account (staff sign-in on the website): registrar@calabrianfs.edu.ph / registrar123
INSERT INTO users (email, password_hash, full_name, role)
VALUES ('registrar@calabrianfs.edu.ph', '$2y$10$oKr6zHH2D5aOwIBbXMDKXODk5A6CWAQ0mPai6eJb8WocHME2u5SZW', 'Aida Bautista', 'registrar');

INSERT INTO school_years (label, starts_on, ends_on, enrollment_open, is_current)
VALUES ('2026-2027', '2026-08-10', '2027-03-31', 1, 1);

INSERT INTO fee_schedules (school_year_id, grade_level, item_name, amount, due_date, sequence)
SELECT sy.id, g.n, v.item_name, v.amount, v.due_date, v.seq
FROM school_years sy
CROSS JOIN (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) g
CROSS JOIN (
  SELECT 'Enrollment Fee' item_name, 5450.00 amount, DATE '2026-08-12' due_date, 1 seq
  UNION ALL SELECT 'Tuition Fee - 1st Quarter', 7500.00, DATE '2026-08-20', 2
  UNION ALL SELECT 'Tuition Fee - 2nd Quarter', 7500.00, DATE '2026-10-15', 3
  UNION ALL SELECT 'Tuition Fee - 3rd Quarter', 7500.00, DATE '2027-01-15', 4
  UNION ALL SELECT 'Tuition Fee - 4th Quarter', 7500.00, DATE '2027-03-15', 5
) v
WHERE sy.label = '2026-2027';
