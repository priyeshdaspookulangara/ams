<?php
include 'config.php'; // Includes DB_NAME and establishes $conn

// SQL to create tables

$sql_students_old_def_for_reference_only = "
-- This is the old definition, will be modified below.
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL UNIQUE,
    grade VARCHAR(50) NOT NULL, -- This will be removed
    class_section_id INT, -- This will be added
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    -- FOREIGN KEY (class_section_id) REFERENCES class_sections(id) ON DELETE SET NULL -- Added later
);";

$sql_parent_guardians = "CREATE TABLE IF NOT EXISTS parent_guardians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    parent_name VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    email VARCHAR(255) NULL,
    relationship VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
)";

$sql_attendance_records = "CREATE TABLE IF NOT EXISTS attendance_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    is_present BOOLEAN NOT NULL DEFAULT 0,
    notes TEXT,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY unique_attendance (student_id, attendance_date)
)";

$sql_settings = "CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY DEFAULT 1,
    notification_type VARCHAR(10) DEFAULT 'none' CHECK (notification_type IN ('sms', 'whatsapp', 'both', 'none')),
    sms_template_single_absence TEXT,
    sms_template_multiple_absences TEXT,
    email_template_single_absence TEXT NULL,
    email_template_multiple_absences TEXT NULL,
    consecutive_absence_threshold INT DEFAULT 3,
    office_number VARCHAR(20),
    CONSTRAINT enforce_single_row CHECK (id = 1)
)";

$sql_users = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role IN ('admin', 'teacher', 'parent')),
    entity_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_role (role),
    KEY idx_entity_id (entity_id)
)";

$sql_absence_reasons = "CREATE TABLE IF NOT EXISTS absence_reasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attendance_record_id INT NOT NULL,
    submitted_by_user_id INT NOT NULL,
    reason_text TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending_review' CHECK (status IN ('pending_review', 'approved', 'rejected', 'viewed')),
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (attendance_record_id) REFERENCES attendance_records(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE CASCADE
)";

$sql_user_student_links = "CREATE TABLE IF NOT EXISTS user_student_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    student_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY unique_link (user_id, student_id)
)";

// --- New Tables for Academic Structure & Delegation ---
$sql_grades = "CREATE TABLE IF NOT EXISTS grades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grade_name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

$sql_divisions = "CREATE TABLE IF NOT EXISTS divisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    division_name VARCHAR(50) NOT NULL UNIQUE, -- e.g., 'A', 'B', 'None'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

// This table represents a specific class, e.g., Grade 10-A for 2023-2024
$sql_class_sections = "CREATE TABLE IF NOT EXISTS class_sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grade_id INT NOT NULL,
    division_id INT NOT NULL,
    class_teacher_user_id INT NULL, -- FK to users.id (teacher role)
    academic_year VARCHAR(20) NOT NULL DEFAULT '2023-2024', -- Example default, can be managed
    section_name VARCHAR(150), -- Optional: e.g., 'Grade 10 - A (2023-2024)' for display, can be auto-generated
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE,
    FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE,
    FOREIGN KEY (class_teacher_user_id) REFERENCES users(id) ON DELETE SET NULL, -- If teacher user is deleted, set to NULL
    UNIQUE KEY unique_class_section (grade_id, division_id, academic_year)
)";

// Modified students table
// We need to handle the alteration carefully if data exists.
// For a fresh setup, it's easier.
// Step 1: Create the new students table definition (or alter existing)
$sql_students_new = "CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL, -- Roll number might now be unique PER class_section, not globally
    class_section_id INT NULL, -- This student belongs to which specific class section
    date_of_birth DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- FOREIGN KEY (class_section_id) REFERENCES class_sections(id) ON DELETE SET NULL, -- If class section is deleted
    -- Consider UNIQUE KEY (roll_number, class_section_id) if roll numbers are per-class
    CONSTRAINT fk_student_class_section FOREIGN KEY (class_section_id) REFERENCES class_sections(id) ON DELETE SET NULL
)";
// Note: The foreign key from students to class_sections is added *after* class_sections table is created.

$sql_alter_students_add_dob = "ALTER TABLE students ADD COLUMN date_of_birth DATE NULL AFTER class_section_id";

$sql_teacher_delegations = "CREATE TABLE IF NOT EXISTS teacher_delegations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_section_id INT NOT NULL,
    delegated_to_user_id INT NOT NULL, -- FK to users.id (teacher role)
    can_take_attendance BOOLEAN DEFAULT 0,
    can_manage_students BOOLEAN DEFAULT 0,
    delegated_by_user_id INT NOT NULL, -- FK to users.id (class teacher who delegated)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_section_id) REFERENCES class_sections(id) ON DELETE CASCADE,
    FOREIGN KEY (delegated_to_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (delegated_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_delegation (class_section_id, delegated_to_user_id)
)";
// --- End New Tables ---


// Order of table creation matters due to Foreign Keys
$queries = [
    "grades" => $sql_grades,
    "divisions" => $sql_divisions,
    "users" => $sql_users, // Users table needed before class_sections (for class_teacher_user_id)
    "class_sections" => $sql_class_sections,
    // students table: handle alteration or creation carefully
    // For this script, we'll assume we can drop and recreate if it's for initial setup
    // In a production migration, this would be an ALTER TABLE statement.
    "students_temp_drop_if_exists" => "DROP TABLE IF EXISTS students_temp_backup_for_alter", // Safety
    "students_rename_old" => "ALTER TABLE students RENAME TO students_temp_backup_for_alter", // Rename if exists
    "students_create_new" => $sql_students_new, // Create with new structure
    // The FK constraint from students to class_sections is in $sql_students_new.
    // Other tables that depend on students (parent_guardians, attendance_records, user_student_links)
    // might need their FKs temporarily dropped and re-added if 'students' table is fully dropped and recreated.
    // Given this is a setup script, the simpler path is to ensure dependent tables are created after students.
    // However, students itself depends on class_sections.
    // The order below should work for a fresh setup.
    // For existing data, a proper migration script is needed.

    "parent_guardians" => $sql_parent_guardians, // Depends on students
    "attendance_records" => $sql_attendance_records, // Depends on students
    "user_student_links" => $sql_user_student_links, // Depends on students and users
    "settings" => $sql_settings,
    "absence_reasons" => $sql_absence_reasons, // Depends on attendance_records and users
    "teacher_delegations" => $sql_teacher_delegations // Depends on class_sections and users
];

echo "Attempting to connect to database and setup tables for database: " . DB_NAME . "<br><hr>";
echo "<strong>IMPORTANT:</strong> This script will attempt to rename an existing 'students' table to 'students_temp_backup_for_alter' and create a new 'students' table with an updated schema. If you have existing student data, ensure you have a proper backup or migration strategy.<br><hr>";

// Check if old students table exists to decide on rename or direct create
$check_students_table_exists_sql = "SHOW TABLES LIKE 'students'";
$res_students_exist = mysqli_query($conn, $check_students_table_exists_sql);
$old_students_table_exists = (mysqli_num_rows($res_students_exist) > 0);

if ($old_students_table_exists) {
    // Check if it's already the new schema (has class_section_id)
    $check_column_sql = "SHOW COLUMNS FROM students LIKE 'class_section_id'";
    $res_column_check = mysqli_query($conn, $check_column_sql);
    if (mysqli_num_rows($res_column_check) > 0) {
        echo "'students' table already appears to have the new schema (contains class_section_id). Skipping rename/recreate.<br>";
        unset($queries['students_temp_drop_if_exists']);
        unset($queries['students_rename_old']);
        unset($queries['students_create_new']); // Don't run the CREATE TABLE students if it's already new
         // Add a simple ALTER to ensure FK if not present (idempotent way)
        $queries['students_ensure_fk'] = "ALTER TABLE students
            ADD CONSTRAINT fk_student_class_section_if_not_exists
            FOREIGN KEY IF NOT EXISTS (class_section_id) REFERENCES class_sections(id) ON DELETE SET NULL";

    } else {
        echo "'students' table has old schema. Proceeding with rename and recreate.<br>";
        // The rename/recreate queries are already in $queries array
    }
} else {
    echo "'students' table does not exist. Will be created with new schema.<br>";
    // No old table, so remove rename steps
    unset($queries['students_temp_drop_if_exists']);
    unset($queries['students_rename_old']);
    // Keep 'students_create_new'
}


foreach ($queries as $table_name => $sql) {
    if (empty($sql)) continue; // Skip if a query was unset

    // Special handling for ALTER TABLE RENAME for students
    if ($table_name === 'students_rename_old') {
        if ($old_students_table_exists) { // Only run rename if old table actually exists
             // Check if it's already the new schema (has class_section_id)
            $check_column_sql = "SHOW COLUMNS FROM students LIKE 'class_section_id'";
            $res_column_check = mysqli_query($conn, $check_column_sql);
            if (mysqli_num_rows($res_column_check) > 0) { // Already new
                echo "Skipping rename of 'students' as it seems to be new schema.<br>";
                continue;
            }
            // Drop dependent FKs before renaming students table (if they point to `students.id`)
            // This is complex. For simplicity, this setup script might fail here if FKs exist from other tables to students.
            // A robust migration needs careful FK handling.
            // For now, we assume this setup script is run when such FKs can be managed or are not yet an issue.
            echo "Attempting to rename old 'students' table...<br>";
        } else {
            echo "Skipping rename of 'students' as it does not exist.<br>";
            continue; // Skip this query if table doesn't exist
        }
    }
     if ($table_name === 'students_temp_drop_if_exists' && !$old_students_table_exists) {
        continue; // Don't try to drop backup if old didn't exist
    }


    if (mysqli_query($conn, $sql)) {
        echo "Executed: '$table_name' successfully.<br>";
    } else {
        echo "Error executing '$table_name': " . mysqli_error($conn) . "<br>";
    }
}
echo "<hr>";

// Seed default grades and divisions if they are empty
$seed_data = [
    'grades' => [
        ['grade_name' => 'Grade 1'], ['grade_name' => 'Grade 2'], ['grade_name' => 'Grade 3'],
        ['grade_name' => 'Grade 4'], ['grade_name' => 'Grade 5'], ['grade_name' => 'Grade 6'],
        ['grade_name' => 'Grade 7'], ['grade_name' => 'Grade 8'], ['grade_name' => 'Grade 9'],
        ['grade_name' => 'Grade 10'], ['grade_name' => 'Grade 11'], ['grade_name' => 'Grade 12'],
    ],
    'divisions' => [
        ['division_name' => 'A'], ['division_name' => 'B'], ['division_name' => 'C'],
        ['division_name' => 'None'] // A default for grades without divisions
    ]
];

foreach ($seed_data as $table => $entries) {
    $check_empty_sql = "SELECT id FROM $table LIMIT 1";
    $res_empty = mysqli_query($conn, $check_empty_sql);
    if ($res_empty && mysqli_num_rows($res_empty) == 0) {
        echo "Seeding data for '$table'...<br>";
        foreach ($entries as $entry) {
            $cols = implode(", ", array_keys($entry));
            $vals = [];
            foreach(array_values($entry) as $val) {
                $vals[] = "'" . mysqli_real_escape_string($conn, $val) . "'";
            }
            $vals_str = implode(", ", $vals);
            $insert_sql = "INSERT INTO $table ($cols) VALUES ($vals_str)";
            if (mysqli_query($conn, $insert_sql)) {
                echo "Inserted into $table: " . htmlspecialchars(implode(", ", $entry)) . "<br>";
            } else {
                echo "Error inserting into $table: " . mysqli_error($conn) . "<br>";
            }
        }
    } else {
        echo "Table '$table' already has data or error checking. Skipping seed.<br>";
    }
}
echo "<hr>";

// Check and insert default settings (idempotent)
// (Code for settings and admin user insertion remains the same as before)
$check_settings_exist = "SELECT id FROM settings WHERE id = 1";
$result_settings = mysqli_query($conn, $check_settings_exist);
if ($result_settings && mysqli_num_rows($result_settings) == 0) {
    $default_template_single = "Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) was absent on {current_date}. Contact office: {office_number}.";
    $default_template_multiple = "Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) has been absent for {consecutive_days} days, including today ({current_date}). Please contact office: {office_number} urgently.";
    $default_office_number = "123-456-7890";
    $escaped_template_single = mysqli_real_escape_string($conn, $default_template_single);
    $escaped_template_multiple = mysqli_real_escape_string($conn, $default_template_multiple);
    $escaped_office_number = mysqli_real_escape_string($conn, $default_office_number);
    $default_email_template_single = "<p>Dear {parent_name},</p><p>This email is to inform you that your child, <strong>{student_name}</strong> (Roll No: {student_rollnumber}), was marked absent on {current_date}.</p><p>Please contact the school office at {office_number} if you have any questions.</p><p>Thank you.</p>";
    $default_email_template_multiple = "<p>Dear {parent_name},</p><p>This email is to inform you that your child, <strong>{student_name}</strong> (Roll No: {student_rollnumber}), has been marked absent for {consecutive_days} consecutive days, including today ({current_date}).</p><p>Please contact the school office at {office_number} urgently to discuss this matter.</p><p>Thank you.</p>";
    $escaped_email_template_single = mysqli_real_escape_string($conn, $default_email_template_single);
    $escaped_email_template_multiple = mysqli_real_escape_string($conn, $default_email_template_multiple);

    $insert_default_settings = "INSERT INTO settings (id, notification_type, sms_template_single_absence, sms_template_multiple_absences, email_template_single_absence, email_template_multiple_absences, consecutive_absence_threshold, office_number)
                                VALUES (1, 'none', '$escaped_template_single', '$escaped_template_multiple', '$escaped_email_template_single', '$escaped_email_template_multiple', 3, '$escaped_office_number')";
    if (mysqli_query($conn, $insert_default_settings)) {
        echo "Default settings (including basic email templates) inserted successfully.<br>";
    } else {
        echo "Error inserting default settings: " . mysqli_error($conn) . "<br>";
    }
} else if (!$result_settings) {
    echo "Error checking for existing settings: " . mysqli_error($conn) . "<br>";
} else {
    echo "Settings row (id=1) already exists.<br>";
}

// Check and insert default settings (idempotent)
// (Code for settings and admin user insertion remains the same as before)
$check_settings_exist = "SELECT id FROM settings WHERE id = 1";
$result_settings = mysqli_query($conn, $check_settings_exist);
if ($result_settings && mysqli_num_rows($result_settings) == 0) {
    $default_template_single = "Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) was absent on {current_date}. Contact office: {office_number}.";
    $default_template_multiple = "Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) has been absent for {consecutive_days} days, including today ({current_date}). Please contact office: {office_number} urgently.";
    $default_office_number = "123-456-7890";
    $escaped_template_single = mysqli_real_escape_string($conn, $default_template_single);
    $escaped_template_multiple = mysqli_real_escape_string($conn, $default_template_multiple);
    $escaped_office_number = mysqli_real_escape_string($conn, $default_office_number);
    $insert_default_settings = "INSERT INTO settings (id, notification_type, sms_template_single_absence, sms_template_multiple_absences, consecutive_absence_threshold, office_number)
                                VALUES (1, 'none', '$escaped_template_single', '$escaped_template_multiple', 3, '$escaped_office_number')";
    if (mysqli_query($conn, $insert_default_settings)) {
        echo "Default settings inserted successfully.<br>";
    } else {
        echo "Error inserting default settings: " . mysqli_error($conn) . "<br>";
    }
} else if (!$result_settings) {
    echo "Error checking for existing settings: " . mysqli_error($conn) . "<br>";
} else {
    echo "Settings row (id=1) already exists.<br>";
}

// Ensure 'date_of_birth' column exists in 'students' table after potential recreation
$check_dob_column_sql = "SHOW COLUMNS FROM students LIKE 'date_of_birth'";
$res_dob_column = mysqli_query($conn, $check_dob_column_sql);
if ($res_dob_column && mysqli_num_rows($res_dob_column) == 0) {
    echo "Attempting to add 'date_of_birth' column to 'students' table...<br>";
    // Check if class_section_id exists to place it after, otherwise just add
    $check_cs_id_column_sql = "SHOW COLUMNS FROM students LIKE 'class_section_id'";
    $res_cs_id_column = mysqli_query($conn, $check_cs_id_column_sql);
    $alter_dob_sql = "";
    if ($res_cs_id_column && mysqli_num_rows($res_cs_id_column) > 0) {
        $alter_dob_sql = "ALTER TABLE students ADD COLUMN date_of_birth DATE NULL AFTER class_section_id";
    } else { // Should not happen if new schema is created, but as a fallback
        $alter_dob_sql = "ALTER TABLE students ADD COLUMN date_of_birth DATE NULL";
    }

    if (mysqli_query($conn, $alter_dob_sql)) {
        echo "'date_of_birth' column added successfully to 'students' table.<br>";
    } else {
        echo "Error adding 'date_of_birth' column to 'students' table: " . mysqli_error($conn) . "<br>";
    }
} else if ($res_dob_column && mysqli_num_rows($res_dob_column) > 0) {
    echo "'date_of_birth' column already exists in 'students' table.<br>";
} else if (!$res_dob_column) {
    echo "Error checking for 'date_of_birth' column: " . mysqli_error($conn) . "<br>";
}
echo "<hr>";


// Check and insert a default admin user if no users exist (idempotent)
$check_users_exist = "SELECT id FROM users WHERE username = 'admin' LIMIT 1"; // More specific check
$result_users = mysqli_query($conn, $check_users_exist);
if ($result_users && mysqli_num_rows($result_users) == 0) {
    $admin_username = "admin";
    $admin_password_plain = "admin123";
    $admin_password_hashed = password_hash($admin_password_plain, PASSWORD_DEFAULT);
    $admin_role = "admin";
    $insert_admin_sql = "INSERT INTO users (username, password, role) VALUES (
        '" . mysqli_real_escape_string($conn, $admin_username) . "',
        '" . mysqli_real_escape_string($conn, $admin_password_hashed) . "',
        '" . mysqli_real_escape_string($conn, $admin_role) . "'
    )";
    if (mysqli_query($conn, $insert_admin_sql)) {
        echo "Default admin user ('admin' / 'admin123') created successfully. PLEASE CHANGE THE PASSWORD IMMEDIATELY.<br>";
    } else {
        echo "Error creating default admin user: " . mysqli_error($conn) . "<br>";
    }
} else if (!$result_users) {
    echo "Error checking for existing admin user: " . mysqli_error($conn) . "<br>";
} else {
    echo "Default admin user ('admin') already exists or error checking.<br>";
}


mysqli_close($conn);
?>
