-- =============================================================
-- Thesis Management DB (MySQL 8.0)
-- Full Consolidated Schema + Business Rules + Fixed Triggers
-- =============================================================
CREATE DATABASE IF NOT EXISTS thesis_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE thesis_db;

-- =============================================================
-- CLEAN DROP (optional for idempotency in dev)
-- =============================================================
DROP TRIGGER IF EXISTS trg_thesis_status_log;
DROP TRIGGER IF EXISTS trg_enforce_status_flow;
DROP TRIGGER IF EXISTS trg_thesis_add_supervisor;
DROP TRIGGER IF EXISTS trg_inv_to_member;
DROP TRIGGER IF EXISTS trg_inv_accept_promote;
DROP TRIGGER IF EXISTS trg_complete_requirements;
DROP TRIGGER IF EXISTS trg_grades_total_bi;
DROP TRIGGER IF EXISTS trg_grades_total_bu;
DROP TRIGGER IF EXISTS trg_presentation_validate_bi;
DROP TRIGGER IF EXISTS trg_presentation_validate_bu;

DROP VIEW IF EXISTS vw_public_presentations;
DROP VIEW IF EXISTS vw_teacher_stats;

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS policies;
DROP TABLE IF EXISTS student_eligibility_snapshot;
DROP TABLE IF EXISTS events_log;
DROP TABLE IF EXISTS exam_minutes;
DROP TABLE IF EXISTS grades;
DROP TABLE IF EXISTS grading_rubrics;
DROP TABLE IF EXISTS presentation;
DROP TABLE IF EXISTS resources;
DROP TABLE IF EXISTS notes;
DROP TABLE IF EXISTS committee_members;
DROP TABLE IF EXISTS committee_invitations;
DROP TABLE IF EXISTS theses;
DROP TABLE IF EXISTS topics;
DROP TABLE IF EXISTS persons;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS=1;

-- =============================================================
-- USERS (internal: students/teachers/secretariat)
-- =============================================================
CREATE TABLE users (
  id              CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  role            ENUM('student','teacher','secretariat') NOT NULL,
  student_number  VARCHAR(50),
  name            VARCHAR(255) NOT NULL,
  email           VARCHAR(255) UNIQUE NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  address         TEXT,
  phone_mobile    VARCHAR(50),
  phone_landline  VARCHAR(50),
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_student_number_role (student_number, role)
) ENGINE=InnoDB;

-- =============================================================
-- PERSONS (internal/external committee members)
-- =============================================================
CREATE TABLE persons (
  id            CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  is_internal   BOOLEAN NOT NULL DEFAULT TRUE,
  user_id       CHAR(36) NULL,
  first_name    VARCHAR(80) NOT NULL,
  last_name     VARCHAR(80) NOT NULL,
  email         VARCHAR(255),
  affiliation   VARCHAR(255),
  role_category ENUM('DEP','EEP','EDIP','ETEP','RESEARCH_A','RESEARCH_B','RESEARCH_C') NOT NULL,
  has_phd       BOOLEAN NOT NULL DEFAULT TRUE,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_person_email (email),
  UNIQUE KEY uq_person_user (user_id),
  CONSTRAINT fk_person_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- =============================================================
-- TOPICS
-- =============================================================
CREATE TABLE topics (
  id              CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  supervisor_id   CHAR(36) NOT NULL, -- users.id (teacher)
  title           VARCHAR(255) NOT NULL,
  summary         TEXT,
  pdf_path        TEXT,
  academic_year   VARCHAR(9),        -- e.g. "2024-2025"
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_topics_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- =============================================================
-- THESES
-- =============================================================
CREATE TABLE theses (
  id                         CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  student_id                 CHAR(36) NOT NULL,
  topic_id                   CHAR(36) NOT NULL,
  supervisor_id              CHAR(36) NOT NULL, -- users.id
  status  ENUM('under_assignment','active','under_review','completed','canceled')
          NOT NULL DEFAULT 'under_assignment',
  assigned_at                TIMESTAMP NULL,
  committee_submission_at    TIMESTAMP NULL,    -- when content was submitted to committee
  approval_gs_number         VARCHAR(50),
  approval_gs_year           INT,
  canceled_reason            TEXT,
  canceled_gs_number         VARCHAR(50),
  canceled_gs_year           INT,
  nimeritis_url              TEXT,
  nimeritis_deposit_date     DATE NULL,
  nimeritis_receipt_path     VARCHAR(255) NULL,
  central_grade_submitted_at TIMESTAMP NULL,
  created_at                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_student_topic (student_id, topic_id),
  CONSTRAINT fk_theses_student    FOREIGN KEY (student_id)    REFERENCES users(id),
  CONSTRAINT fk_theses_topic      FOREIGN KEY (topic_id)      REFERENCES topics(id),
  CONSTRAINT fk_theses_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE INDEX idx_theses_status_supervisor ON theses(status, supervisor_id);

-- =============================================================
-- COMMITTEE INVITATIONS & MEMBERS
-- =============================================================
CREATE TABLE committee_invitations (
  id            CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  thesis_id     CHAR(36) NOT NULL,
  person_id     CHAR(36) NOT NULL, -- persons.id
  invited_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status        ENUM('pending','accepted','declined','canceled') NOT NULL DEFAULT 'pending',
  responded_at  TIMESTAMP NULL,
  UNIQUE KEY uq_invitation (thesis_id, person_id),
  CONSTRAINT fk_inv_thesis FOREIGN KEY (thesis_id) REFERENCES theses(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_person FOREIGN KEY (person_id) REFERENCES persons(id)
) ENGINE=InnoDB;
CREATE INDEX idx_invitations_thesis_status ON committee_invitations(thesis_id, status);

CREATE TABLE committee_members (
  id                 CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  thesis_id          CHAR(36) NOT NULL,
  person_id          CHAR(36) NOT NULL,
  role_in_committee  ENUM('supervisor','member') NOT NULL DEFAULT 'member',
  added_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_member (thesis_id, person_id),
  CONSTRAINT fk_mem_thesis FOREIGN KEY (thesis_id) REFERENCES theses(id) ON DELETE CASCADE,
  CONSTRAINT fk_mem_person FOREIGN KEY (person_id) REFERENCES persons(id)
) ENGINE=InnoDB;
CREATE INDEX idx_members_thesis_role ON committee_members(thesis_id, role_in_committee);

-- =============================================================
-- NOTES
-- =============================================================
CREATE TABLE notes (
  id          CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  thesis_id   CHAR(36) NOT NULL,
  author_id   CHAR(36) NOT NULL, -- users.id
  text        VARCHAR(300) NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notes_thesis FOREIGN KEY (thesis_id) REFERENCES theses(id) ON DELETE CASCADE,
  CONSTRAINT fk_notes_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================================
-- RESOURCES
-- =============================================================
CREATE TABLE resources (
  id          CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  thesis_id   CHAR(36) NOT NULL,
  kind        ENUM('draft','code','video','image','other') NOT NULL,
  url_or_path TEXT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_resources_thesis FOREIGN KEY (thesis_id) REFERENCES theses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================================
-- PRESENTATION (exam + public announcement)
-- =============================================================
CREATE TABLE presentation (
  id                CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  thesis_id         CHAR(36) NOT NULL UNIQUE,
  when_dt           DATETIME NOT NULL,
  mode              ENUM('in_person','online') NOT NULL,
  room_or_link      VARCHAR(255) NOT NULL,
  published_at      TIMESTAMP NULL,
  announcement_html TEXT,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_presentation_thesis FOREIGN KEY (thesis_id) REFERENCES theses(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE INDEX idx_presentation_when ON presentation(when_dt);

-- =============================================================
-- GRADING (rubric-based 60/15/15/10)
-- =============================================================
CREATE TABLE grading_rubrics (
  id           CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  code         VARCHAR(50) UNIQUE NOT NULL,
  title        VARCHAR(255) NOT NULL,
  weights_json JSON NOT NULL,  -- {"goals":0.60,"duration":0.15,"text":0.15,"presentation":0.10}
  effective_from DATE NOT NULL,
  effective_to   DATE NULL
) ENGINE=InnoDB;

CREATE TABLE grades (
  id                   CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  thesis_id            CHAR(36) NOT NULL,
  person_id            CHAR(36) NOT NULL,  -- persons.id (committee member)
  rubric_id            CHAR(36) NOT NULL,
  criteria_scores_json JSON NOT NULL,      -- {"goals":9.0,"duration":10,"text":8.5,"presentation":9.2}
  total                DECIMAL(5,2),
  created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_grade (thesis_id, person_id),
  CONSTRAINT fk_grades_thesis FOREIGN KEY (thesis_id) REFERENCES theses(id) ON DELETE CASCADE,
  CONSTRAINT fk_grades_person FOREIGN KEY (person_id) REFERENCES persons(id),
  CONSTRAINT fk_grades_rubric FOREIGN KEY (rubric_id) REFERENCES grading_rubrics(id)
) ENGINE=InnoDB;
CREATE INDEX idx_grades_thesis ON grades(thesis_id);

-- =============================================================
-- EXAM MINUTES (committee minutes / πρακτικό)
-- =============================================================
CREATE TABLE exam_minutes (
  thesis_id       CHAR(36) PRIMARY KEY,
  ga_session_no   VARCHAR(50),
  ga_session_date DATE,
  location        VARCHAR(120),
  exam_datetime   TIMESTAMP,
  decision_text   TEXT,
  final_grade     DECIMAL(5,2),
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_minutes_thesis FOREIGN KEY (thesis_id) REFERENCES theses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================================
-- EVENTS LOG
-- =============================================================
CREATE TABLE events_log (
  id          CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  thesis_id   CHAR(36) NOT NULL,
  actor_id    CHAR(36),
  event_type  VARCHAR(50) NOT NULL,
  from_status ENUM('under_assignment','active','under_review','completed','canceled'),
  to_status   ENUM('under_assignment','active','under_review','completed','canceled'),
  details     JSON,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ev_thesis FOREIGN KEY (thesis_id) REFERENCES theses(id) ON DELETE CASCADE,
  CONSTRAINT fk_ev_actor  FOREIGN KEY (actor_id)  REFERENCES users(id)
) ENGINE=InnoDB;
CREATE INDEX idx_events_thesis_created ON events_log(thesis_id, created_at);

-- =============================================================
-- STUDENT ELIGIBILITY SNAPSHOT (audit at assignment)
-- =============================================================
CREATE TABLE student_eligibility_snapshot (
  thesis_id     CHAR(36) PRIMARY KEY,
  student_id    CHAR(36) NOT NULL,
  recorded_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  owed_ects     SMALLINT,
  owed_courses  SMALLINT,
  is_5th_year   BOOLEAN,
  notes         VARCHAR(255),
  CONSTRAINT fk_elig_thesis  FOREIGN KEY (thesis_id)  REFERENCES theses(id),
  CONSTRAINT fk_elig_student FOREIGN KEY (student_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- =============================================================
-- POLICIES (parametric rules: gaps/notice)
-- =============================================================
CREATE TABLE policies (
  key_name   VARCHAR(64) PRIMARY KEY,
  value_json JSON NOT NULL
) ENGINE=InnoDB;

INSERT INTO policies(key_name, value_json) VALUES
  ('exam_gap_days', JSON_OBJECT('min', 21, 'max', 60)),
  ('announcement_min_notice_days', JSON_OBJECT('min', 7))
ON DUPLICATE KEY UPDATE value_json=VALUES(value_json);

-- =============================================================
-- VIEWS
-- =============================================================
CREATE VIEW vw_public_presentations AS
SELECT p.when_dt, p.mode, p.room_or_link, p.published_at,
       t.id AS thesis_id, tp.title AS topic_title,
       stu.name AS student_name,
       sup.name AS supervisor_name
FROM presentation p
JOIN theses t   ON t.id = p.thesis_id
JOIN topics tp  ON tp.id = t.topic_id
JOIN users  stu ON stu.id = t.student_id
JOIN users  sup ON sup.id = t.supervisor_id;

CREATE VIEW vw_teacher_stats AS
SELECT te.id AS teacher_id, te.name AS teacher_name,
       (SELECT COUNT(*) FROM theses tt WHERE tt.supervisor_id = te.id AND tt.status = 'completed') AS count_supervised,
       (SELECT COUNT(*)
          FROM committee_members cm
          JOIN persons pr ON pr.id = cm.person_id
          JOIN theses  tt ON tt.id = cm.thesis_id
         WHERE pr.user_id = te.id
           AND cm.role_in_committee = 'member'
           AND tt.status = 'completed') AS count_as_member,
       (SELECT AVG(gavg.total)
          FROM ( SELECT t2.id, AVG(g.total) AS total
                   FROM theses t2
                   JOIN committee_members cm2 ON cm2.thesis_id = t2.id
                   JOIN persons pr2          ON pr2.id = cm2.person_id
                   JOIN grades g             ON g.thesis_id = t2.id AND g.person_id = cm2.person_id
                  WHERE t2.status = 'completed'
                    AND (t2.supervisor_id = te.id OR pr2.user_id = te.id)
                  GROUP BY t2.id
               ) gavg
       ) AS avg_grade_related
FROM users te
WHERE te.role = 'teacher';

-- =============================================================
-- TRIGGERS
-- =============================================================
DELIMITER $$

-- Log thesis status changes
CREATE TRIGGER trg_thesis_status_log
AFTER UPDATE ON theses
FOR EACH ROW
BEGIN
  IF NEW.status <> OLD.status THEN
    INSERT INTO events_log(thesis_id, actor_id, event_type, from_status, to_status, details)
    VALUES (NEW.id, NULL, 'status_change', OLD.status, NEW.status, NULL);
  END IF;
END$$

-- Enforce status flow
CREATE TRIGGER trg_enforce_status_flow
BEFORE UPDATE ON theses
FOR EACH ROW
BEGIN
  IF NEW.status = 'under_review' AND OLD.status <> 'active' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid transition: must be ACTIVE before UNDER_REVIEW';
  END IF;
  IF NEW.status = 'completed' AND OLD.status <> 'under_review' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid transition: must be UNDER_REVIEW before COMPLETED';
  END IF;
END$$

-- On thesis creation: ensure supervisor is a person & member (role=supervisor)
CREATE TRIGGER trg_thesis_add_supervisor
AFTER INSERT ON theses
FOR EACH ROW
BEGIN
  INSERT INTO persons(id, is_internal, user_id, first_name, last_name, email, affiliation, role_category, has_phd)
  SELECT UUID(), TRUE, u.id,
         SUBSTRING_INDEX(u.name, ' ', 1),
         TRIM(SUBSTRING(u.name, LENGTH(SUBSTRING_INDEX(u.name, ' ', 1)) + 1)),
         u.email, 'Department', 'DEP', TRUE
  FROM users u
  WHERE u.id = NEW.supervisor_id
    AND NOT EXISTS (SELECT 1 FROM persons p WHERE p.user_id = u.id)
  LIMIT 1;

  INSERT IGNORE INTO committee_members (id, thesis_id, person_id, role_in_committee, added_at)
  SELECT UUID(), NEW.id, p.id, 'supervisor', NOW()
  FROM persons p
  WHERE p.user_id = NEW.supervisor_id
  LIMIT 1;
END$$

-- Invitation accepted -> create committee member (member)
CREATE TRIGGER trg_inv_to_member
AFTER UPDATE ON committee_invitations
FOR EACH ROW
BEGIN
  IF NEW.status = 'accepted' AND OLD.status <> 'accepted' THEN
    INSERT IGNORE INTO committee_members(thesis_id, person_id, role_in_committee, added_at)
    VALUES (NEW.thesis_id, NEW.person_id, 'member', NOW());
  END IF;
END$$

-- Promote to ACTIVE when supervisor exists + at least 2 accepted members
CREATE TRIGGER trg_inv_accept_promote
AFTER UPDATE ON committee_invitations
FOR EACH ROW
BEGIN
  DECLARE supervisor_cnt INT DEFAULT 0;
  DECLARE members_acc_cnt INT DEFAULT 0;

  IF NEW.status = 'accepted' AND OLD.status <> 'accepted' THEN
    SELECT COUNT(*) INTO supervisor_cnt
      FROM committee_members
     WHERE thesis_id = NEW.thesis_id AND role_in_committee = 'supervisor';

    SELECT COUNT(*) INTO members_acc_cnt
      FROM committee_invitations
     WHERE thesis_id = NEW.thesis_id AND status = 'accepted';

    IF supervisor_cnt = 1 AND members_acc_cnt >= 2 THEN
      UPDATE theses
         SET status = 'active',
             assigned_at = COALESCE(assigned_at, NOW())
       WHERE id = NEW.thesis_id AND status = 'under_assignment';

      UPDATE committee_invitations
         SET status = 'canceled'
       WHERE thesis_id = NEW.thesis_id AND status = 'pending';
    END IF;
  END IF;
END$$

-- Completion requirements (1 supervisor + 2 members, 3 grades, Nimeritis, GS)
CREATE TRIGGER trg_complete_requirements
BEFORE UPDATE ON theses
FOR EACH ROW
BEGIN
  DECLARE member_cnt INT DEFAULT 0;
  DECLARE supervisor_cnt INT DEFAULT 0;
  DECLARE grades_cnt INT DEFAULT 0;

  IF NEW.status = 'completed' THEN
    SELECT COUNT(*) INTO supervisor_cnt
      FROM committee_members
     WHERE thesis_id = NEW.id AND role_in_committee = 'supervisor';

    SELECT COUNT(*) INTO member_cnt
      FROM committee_members
     WHERE thesis_id = NEW.id AND role_in_committee = 'member';

    SELECT COUNT(*) INTO grades_cnt
      FROM grades
     WHERE thesis_id = NEW.id;

    IF supervisor_cnt <> 1 OR member_cnt < 2 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completion requires 1 supervisor + 2 committee members.';
    END IF;
    IF grades_cnt < 3 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completion requires 3 grades.';
    END IF;
    IF NEW.nimeritis_url IS NULL OR NEW.nimeritis_url = '' OR NEW.nimeritis_deposit_date IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completion requires Nimeritis URL & deposit date.';
    END IF;
    IF NEW.approval_gs_number IS NULL OR NEW.approval_gs_year IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completion requires GS approval number & year.';
    END IF;
  END IF;
END$$

-- Weighted grade total based on rubric (60/15/15/10)
CREATE TRIGGER trg_grades_total_bi
BEFORE INSERT ON grades
FOR EACH ROW
BEGIN
  DECLARE w_goals, w_duration, w_text, w_presentation DECIMAL(6,4);
  DECLARE s_goals, s_duration, s_text, s_presentation DECIMAL(5,2);

  SELECT JSON_EXTRACT(weights_json, '$.goals'),
         JSON_EXTRACT(weights_json, '$.duration'),
         JSON_EXTRACT(weights_json, '$.text'),
         JSON_EXTRACT(weights_json, '$.presentation')
    INTO w_goals, w_duration, w_text, w_presentation
  FROM grading_rubrics WHERE id = NEW.rubric_id;

  SET s_goals        = JSON_EXTRACT(NEW.criteria_scores_json, '$.goals');
  SET s_duration     = JSON_EXTRACT(NEW.criteria_scores_json, '$.duration');
  SET s_text         = JSON_EXTRACT(NEW.criteria_scores_json, '$.text');
  SET s_presentation = JSON_EXTRACT(NEW.criteria_scores_json, '$.presentation');

  SET NEW.total = ROUND(
      COALESCE(s_goals,0)        * COALESCE(w_goals,0)
    + COALESCE(s_duration,0)     * COALESCE(w_duration,0)
    + COALESCE(s_text,0)         * COALESCE(w_text,0)
    + COALESCE(s_presentation,0) * COALESCE(w_presentation,0), 2);
END$$

CREATE TRIGGER trg_grades_total_bu
BEFORE UPDATE ON grades
FOR EACH ROW
BEGIN
  DECLARE w_goals, w_duration, w_text, w_presentation DECIMAL(6,4);
  DECLARE s_goals, s_duration, s_text, s_presentation DECIMAL(5,2);

  SELECT JSON_EXTRACT(weights_json, '$.goals'),
         JSON_EXTRACT(weights_json, '$.duration'),
         JSON_EXTRACT(weights_json, '$.text'),
         JSON_EXTRACT(weights_json, '$.presentation')
    INTO w_goals, w_duration, w_text, w_presentation
  FROM grading_rubrics WHERE id = NEW.rubric_id;

  SET s_goals        = JSON_EXTRACT(NEW.criteria_scores_json, '$.goals');
  SET s_duration     = JSON_EXTRACT(NEW.criteria_scores_json, '$.duration');
  SET s_text         = JSON_EXTRACT(NEW.criteria_scores_json, '$.text');
  SET s_presentation = JSON_EXTRACT(NEW.criteria_scores_json, '$.presentation');

  SET NEW.total = ROUND(
      COALESCE(s_goals,0)        * COALESCE(w_goals,0)
    + COALESCE(s_duration,0)     * COALESCE(w_duration,0)
    + COALESCE(s_text,0)         * COALESCE(w_text,0)
    + COALESCE(s_presentation,0) * COALESCE(w_presentation,0), 2);
END$$

-- Validate presentation deadlines (BI)
CREATE TRIGGER trg_presentation_validate_bi
BEFORE INSERT ON presentation
FOR EACH ROW
BEGIN
  DECLARE min_gap INT DEFAULT 21;
  DECLARE max_gap INT DEFAULT 60;
  DECLARE min_notice INT DEFAULT 7;
  DECLARE gap INT;
  DECLARE notice INT;
  DECLARE t_committee_sub TIMESTAMP;

  SELECT
    CAST(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.min')) AS UNSIGNED),
    CAST(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.max')) AS UNSIGNED)
  INTO min_gap, max_gap
  FROM policies
  WHERE key_name='exam_gap_days'
  LIMIT 1;

  SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.min')) AS UNSIGNED)
  INTO min_notice
  FROM policies
  WHERE key_name='announcement_min_notice_days'
  LIMIT 1;

  IF NEW.published_at IS NOT NULL THEN
    SET notice = TIMESTAMPDIFF(DAY, NEW.published_at, NEW.when_dt);
    IF notice < min_notice THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Announcement must be published sufficiently before the exam date.';
    END IF;
  END IF;

  SELECT committee_submission_at
  INTO t_committee_sub
  FROM theses
  WHERE id = NEW.thesis_id
  LIMIT 1;

  IF t_committee_sub IS NOT NULL THEN
    SET gap = TIMESTAMPDIFF(DAY, t_committee_sub, NEW.when_dt);
    IF gap < min_gap OR gap > max_gap THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Exam date must be 21-60 days after committee submission.';
    END IF;
  END IF;
END$$

-- Validate presentation deadlines (BU)
CREATE TRIGGER trg_presentation_validate_bu
BEFORE UPDATE ON presentation
FOR EACH ROW
BEGIN
  DECLARE min_gap INT DEFAULT 21;
  DECLARE max_gap INT DEFAULT 60;
  DECLARE min_notice INT DEFAULT 7;
  DECLARE gap INT;
  DECLARE notice INT;
  DECLARE t_committee_sub TIMESTAMP;

  SELECT
    CAST(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.min')) AS UNSIGNED),
    CAST(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.max')) AS UNSIGNED)
  INTO min_gap, max_gap
  FROM policies
  WHERE key_name='exam_gap_days'
  LIMIT 1;

  SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.min')) AS UNSIGNED)
  INTO min_notice
  FROM policies
  WHERE key_name='announcement_min_notice_days'
  LIMIT 1;

  IF NEW.published_at IS NOT NULL THEN
    SET notice = TIMESTAMPDIFF(DAY, NEW.published_at, NEW.when_dt);
    IF notice < min_notice THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Announcement must be published sufficiently before the exam date.';
    END IF;
  END IF;

  SELECT committee_submission_at
  INTO t_committee_sub
  FROM theses
  WHERE id = NEW.thesis_id
  LIMIT 1;

  IF t_committee_sub IS NOT NULL THEN
    SET gap = TIMESTAMPDIFF(DAY, t_committee_sub, NEW.when_dt);
    IF gap < min_gap OR gap > max_gap THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Exam date must be 21-60 days after committee submission.';
    END IF;
  END IF;
END$$

DELIMITER ;

-- =============================================================
-- SEED: default rubric (60/15/15/10)
-- =============================================================
INSERT INTO grading_rubrics(code, title, weights_json, effective_from)
VALUES ('TMIYP-4CRIT-2024','Standard 4-criteria rubric',
        JSON_OBJECT('goals',0.60,'duration',0.15,'text',0.15,'presentation',0.10),
        CURRENT_DATE)
ON DUPLICATE KEY UPDATE title=VALUES(title), weights_json=VALUES(weights_json);
