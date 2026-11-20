-- IBM Notas · MySQL 8 / MariaDB 10.6 · esquema derivado de las migraciones PostgreSQL 001–012.

CREATE TABLE users (
    id INT NOT NULL AUTO_INCREMENT,
    ci VARCHAR(255),
    name text NOT NULL,
    role text NOT NULL,
    password text,
    must_change integer DEFAULT 1 NOT NULL,
    blocked integer DEFAULT 0 NOT NULL,
    CONSTRAINT users_credentials_complete CHECK ((((role IN ('student', 'teacher')) AND (ci IS NULL) AND (password IS NULL)) OR ((ci IS NOT NULL) AND (password IS NOT NULL)))),
    CONSTRAINT users_role_check CHECK ((role IN ('admin', 'teacher', 'student'))),
    UNIQUE (ci),
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE careers (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    UNIQUE (name),
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE career_names_by_year (
    career_id integer NOT NULL,
    effective_year VARCHAR(255) NOT NULL,
    name text NOT NULL,
    CONSTRAINT career_names_by_year_effective_year_check CHECK ((effective_year REGEXP '^20[0-9]{2}$')),
    CONSTRAINT career_names_by_year_name_check CHECK (((char_length(name) >= 1) AND (char_length(name) <= 160))),
    PRIMARY KEY (career_id, effective_year),
    CONSTRAINT career_names_by_year_career_id_fkey FOREIGN KEY (career_id) REFERENCES careers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE shifts (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    UNIQUE (name),
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE years (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    UNIQUE (name),
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE subjects (
    id INT NOT NULL AUTO_INCREMENT,
    name text NOT NULL,
    career_id integer NOT NULL,
    level integer NOT NULL,
    code VARCHAR(255),
    active boolean DEFAULT true NOT NULL,
    catalog_order integer,
    CONSTRAINT subjects_catalog_order_check CHECK (((catalog_order IS NULL) OR (catalog_order > 0))),
    CONSTRAINT subjects_level_check CHECK (((level >= 1) AND (level <= 3))),
    PRIMARY KEY (id),
    CONSTRAINT subjects_career_id_fkey FOREIGN KEY (career_id) REFERENCES careers(id),
    code_key VARCHAR(255) GENERATED ALWAYS AS (UPPER(code)) STORED,
    UNIQUE (career_id, level, code_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE modules (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    year_id integer NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    admission_year_id integer,
    CONSTRAINT modules_check CHECK ((start_date <= end_date)),
    PRIMARY KEY (id),
    CONSTRAINT modules_admission_year_id_fkey FOREIGN KEY (admission_year_id) REFERENCES years(id),
    CONSTRAINT modules_year_id_fkey FOREIGN KEY (year_id) REFERENCES years(id),
    calendar_key INT GENERATED ALWAYS AS (COALESCE(admission_year_id, 0)) STORED,
    UNIQUE (year_id, calendar_key, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE students (
    user_id integer NOT NULL,
    career_id integer NOT NULL,
    shift_id integer NOT NULL,
    admission_year_id integer NOT NULL,
    level integer NOT NULL,
    name_pending boolean DEFAULT false NOT NULL,
    CONSTRAINT students_level_check CHECK (((level >= 1) AND (level <= 3))),
    PRIMARY KEY (user_id),
    CONSTRAINT students_admission_year_id_fkey FOREIGN KEY (admission_year_id) REFERENCES years(id),
    CONSTRAINT students_career_id_fkey FOREIGN KEY (career_id) REFERENCES careers(id),
    CONSTRAINT students_shift_id_fkey FOREIGN KEY (shift_id) REFERENCES shifts(id),
    CONSTRAINT students_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE subject_series (
    id INT NOT NULL AUTO_INCREMENT,
    subject_id integer NOT NULL,
    shift_id integer NOT NULL,
    part_count integer NOT NULL,
    completed boolean DEFAULT false NOT NULL,
    CONSTRAINT subject_series_part_count_check CHECK ((part_count >= 2)),
    UNIQUE (id, subject_id, shift_id),
    PRIMARY KEY (id),
    CONSTRAINT subject_series_shift_id_fkey FOREIGN KEY (shift_id) REFERENCES shifts(id),
    CONSTRAINT subject_series_subject_id_fkey FOREIGN KEY (subject_id) REFERENCES subjects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE offerings (
    id INT NOT NULL AUTO_INCREMENT,
    subject_id integer NOT NULL,
    module_id integer NOT NULL,
    shift_id integer NOT NULL,
    teacher_id integer NOT NULL,
    unlocked_until DATETIME(6),
    series_id integer,
    part_number integer,
    part_recovery boolean DEFAULT false NOT NULL,
    CONSTRAINT offerings_part_valid CHECK ((((series_id IS NULL) AND (part_number IS NULL) AND (NOT part_recovery)) OR ((series_id IS NOT NULL) AND (part_number IS NOT NULL) AND (part_number >= 1)))),
    PRIMARY KEY (id),
    UNIQUE (subject_id, module_id, shift_id),
    CONSTRAINT offerings_module_id_fkey FOREIGN KEY (module_id) REFERENCES modules(id),
    CONSTRAINT offerings_series_identity FOREIGN KEY (series_id, subject_id, shift_id) REFERENCES subject_series(id, subject_id, shift_id),
    CONSTRAINT offerings_shift_id_fkey FOREIGN KEY (shift_id) REFERENCES shifts(id),
    CONSTRAINT offerings_subject_id_fkey FOREIGN KEY (subject_id) REFERENCES subjects(id),
    CONSTRAINT offerings_teacher_id_fkey FOREIGN KEY (teacher_id) REFERENCES users(id),
    original_part INT GENERATED ALWAYS AS (IF(part_recovery, NULL, part_number)) STORED,
    UNIQUE (series_id, original_part)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE enrollments (
    id INT NOT NULL AUTO_INCREMENT,
    student_id integer NOT NULL,
    offering_id integer NOT NULL,
    grade DOUBLE,
    updated_at DATETIME(6),
    saber DOUBLE,
    hacer DOUBLE,
    ser DOUBLE,
    is_retake boolean DEFAULT false NOT NULL,
    CONSTRAINT enrollments_grade_check CHECK (((grade >= 1) AND (grade <= 100))),
    CONSTRAINT enrollments_hacer_check CHECK (((hacer >= 0) AND (hacer <= 100))),
    CONSTRAINT enrollments_saber_check CHECK (((saber >= 0) AND (saber <= 100))),
    CONSTRAINT enrollments_ser_check CHECK (((ser >= 0) AND (ser <= 100))),
    PRIMARY KEY (id),
    UNIQUE (student_id, offering_id),
    CONSTRAINT enrollments_offering_id_fkey FOREIGN KEY (offering_id) REFERENCES offerings(id),
    CONSTRAINT enrollments_student_id_fkey FOREIGN KEY (student_id) REFERENCES students(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE audit (
    id INT NOT NULL AUTO_INCREMENT,
    actor_id integer,
    action text NOT NULL,
    detail text NOT NULL,
    created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT audit_actor_id_fkey FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE teacher_directory (
    id INT NOT NULL AUTO_INCREMENT,
    name text NOT NULL,
    name_key VARCHAR(255) NOT NULL,
    user_id integer,
    UNIQUE (name_key),
    PRIMARY KEY (id),
    UNIQUE (user_id),
    CONSTRAINT teacher_directory_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE academic_groups (
    id INT NOT NULL AUTO_INCREMENT,
    subject_id integer NOT NULL,
    year_id integer NOT NULL,
    admission_year_id integer NOT NULL,
    shift_id integer NOT NULL,
    offering_id integer,
    UNIQUE (offering_id),
    PRIMARY KEY (id),
    CONSTRAINT academic_groups_admission_year_id_fkey FOREIGN KEY (admission_year_id) REFERENCES years(id),
    CONSTRAINT academic_groups_offering_id_fkey FOREIGN KEY (offering_id) REFERENCES offerings(id),
    CONSTRAINT academic_groups_shift_id_fkey FOREIGN KEY (shift_id) REFERENCES shifts(id),
    CONSTRAINT academic_groups_subject_id_fkey FOREIGN KEY (subject_id) REFERENCES subjects(id),
    CONSTRAINT academic_groups_year_id_fkey FOREIGN KEY (year_id) REFERENCES years(id),
    pending_key INT GENERATED ALWAYS AS (IF(offering_id IS NULL, 1, NULL)) STORED,
    UNIQUE (subject_id, year_id, admission_year_id, shift_id, pending_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE academic_group_students (
    group_id integer NOT NULL,
    student_id integer NOT NULL,
    PRIMARY KEY (group_id, student_id),
    CONSTRAINT academic_group_students_group_id_fkey FOREIGN KEY (group_id) REFERENCES academic_groups(id),
    CONSTRAINT academic_group_students_student_id_fkey FOREIGN KEY (student_id) REFERENCES students(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE study_plans (
    id INT NOT NULL AUTO_INCREMENT,
    title text NOT NULL,
    career_id integer NOT NULL,
    level integer NOT NULL,
    original_name text NOT NULL,
    content LONGBLOB NOT NULL,
    size_bytes integer NOT NULL,
    uploaded_by integer NOT NULL,
    created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6) NOT NULL,
    CONSTRAINT study_plans_check CHECK ((octet_length(content) = size_bytes)),
    CONSTRAINT study_plans_level_check CHECK (((level >= 1) AND (level <= 3))),
    CONSTRAINT study_plans_original_name_check CHECK (((char_length(original_name) >= 1) AND (char_length(original_name) <= 200))),
    CONSTRAINT study_plans_size_bytes_check CHECK (((size_bytes >= 16) AND (size_bytes <= 20971520))),
    CONSTRAINT study_plans_title_check CHECK (((char_length(title) >= 1) AND (char_length(title) <= 160))),
    PRIMARY KEY (id),
    CONSTRAINT study_plans_career_id_fkey FOREIGN KEY (career_id) REFERENCES careers(id),
    CONSTRAINT study_plans_uploaded_by_fkey FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE INDEX academic_group_student_idx ON academic_group_students (student_id);

CREATE INDEX audit_actor_idx ON audit (actor_id);

CREATE INDEX enrollments_offering_idx ON enrollments (offering_id, student_id);

CREATE INDEX modules_calendar_dates_idx ON modules (year_id, admission_year_id, start_date, end_date);

CREATE INDEX modules_year_idx ON modules (year_id, start_date, end_date);

CREATE INDEX offerings_module_idx ON offerings (module_id);

CREATE INDEX offerings_series_idx ON offerings (series_id, part_number);

CREATE INDEX offerings_shift_idx ON offerings (shift_id);

CREATE INDEX offerings_teacher_idx ON offerings (teacher_id, module_id);

CREATE INDEX students_admission_idx ON students (admission_year_id);

CREATE INDEX students_career_shift_idx ON students (career_id, shift_id);

CREATE INDEX study_plans_career_level_idx ON study_plans (career_id, level, created_at DESC);

CREATE INDEX subjects_career_idx ON subjects (career_id, level);

CREATE TABLE login_attempts (client_key CHAR(64) PRIMARY KEY, failures INT NOT NULL, expires_at BIGINT NOT NULL, INDEX (expires_at)) ENGINE=InnoDB;
