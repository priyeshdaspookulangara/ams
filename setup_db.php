<?php
include 'config.php'; // Includes DB_NAME and establishes $conn

// SQL to create tables

$sql_students = "CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL UNIQUE,
    grade VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

$sql_parent_guardians = "CREATE TABLE IF NOT EXISTS parent_guardians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    parent_name VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    relationship VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
)";

$sql_attendance_records = "CREATE TABLE IF NOT EXISTS attendance_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    is_present BOOLEAN NOT NULL DEFAULT 0, -- 0 for absent, 1 for present
    notes TEXT, -- Teacher notes
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY unique_attendance (student_id, attendance_date)
)";

$sql_settings = "CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY DEFAULT 1,
    notification_type VARCHAR(10) DEFAULT 'none' CHECK (notification_type IN ('sms', 'whatsapp', 'both', 'none')),
    sms_template_single_absence TEXT,
    sms_template_multiple_absences TEXT,
    consecutive_absence_threshold INT DEFAULT 3,
    office_number VARCHAR(20),
    CONSTRAINT enforce_single_row CHECK (id = 1)
)";

// New table: users
$sql_users = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL, -- Will store hashed passwords
    role VARCHAR(20) NOT NULL CHECK (role IN ('admin', 'teacher', 'parent')), -- Define roles
    entity_id INT NULL, -- For parents, this can link to student_id. For teachers, to a potential teacher_id.
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_role (role),
    KEY idx_entity_id (entity_id)
    -- If entity_id for parents links to students.id, a FOREIGN KEY could be added:
    -- CONSTRAINT fk_user_student FOREIGN KEY (entity_id) REFERENCES students(id) ON DELETE SET NULL
    -- However, entity_id is generic for now. Explicit linking logic will be in PHP.
)";

// New table: absence_reasons
$sql_absence_reasons = "CREATE TABLE IF NOT EXISTS absence_reasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attendance_record_id INT NOT NULL,
    submitted_by_user_id INT NOT NULL, -- FK to users.id (parent user)
    reason_text TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending_review' CHECK (status IN ('pending_review', 'approved', 'rejected', 'viewed')),
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (attendance_record_id) REFERENCES attendance_records(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE CASCADE
)";


$queries = [
    "students" => $sql_students,
    "parent_guardians" => $sql_parent_guardians,
    "attendance_records" => $sql_attendance_records,
    "settings" => $sql_settings,
    "users" => $sql_users,
    "absence_reasons" => $sql_absence_reasons,
    // New table for linking parents (users) to students
    "user_student_links" => "CREATE TABLE IF NOT EXISTS user_student_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        student_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        UNIQUE KEY unique_link (user_id, student_id)
    )"
];

echo "Attempting to connect to database and setup tables for database: " . DB_NAME . "<br>";

foreach ($queries as $table_name => $sql) {
    if (mysqli_query($conn, $sql)) {
        echo "Table '$table_name' created successfully or already exists.<br>";
    } else {
        echo "Error creating table '$table_name': " . mysqli_error($conn) . "<br>";
    }
}

// Check and insert default settings
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

// Check and insert a default admin user if no users exist
$check_users_exist = "SELECT id FROM users LIMIT 1";
$result_users = mysqli_query($conn, $check_users_exist);
if ($result_users && mysqli_num_rows($result_users) == 0) {
    $admin_username = "admin";
    // IMPORTANT: Use a strong default password in a real scenario or prompt for one.
    // For this development setup, using a simple password and then immediately advising to change it.
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
    echo "Error checking for existing users: " . mysqli_error($conn) . "<br>";
} else {
    echo "Users table already has entries or an admin user likely exists.<br>";
}


mysqli_close($conn);
?>
