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
    notes TEXT,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY unique_attendance (student_id, attendance_date) -- Ensures one record per student per day
)";

// Added CHECK (id = 1) to ensure only one row with id 1
$sql_settings = "CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY DEFAULT 1,
    notification_type VARCHAR(10) DEFAULT 'none' CHECK (notification_type IN ('sms', 'whatsapp', 'both', 'none')),
    sms_template_single_absence TEXT,
    sms_template_multiple_absences TEXT,
    consecutive_absence_threshold INT DEFAULT 3,
    office_number VARCHAR(20),
    CONSTRAINT enforce_single_row CHECK (id = 1)
)";

$queries = [
    "students" => $sql_students,
    "parent_guardians" => $sql_parent_guardians,
    "attendance_records" => $sql_attendance_records,
    "settings" => $sql_settings
];

echo "Attempting to connect to database and setup tables for database: " . DB_NAME . "<br>";

foreach ($queries as $table_name => $sql) {
    if (mysqli_query($conn, $sql)) {
        echo "Table '$table_name' created successfully or already exists.<br>";
    } else {
        echo "Error creating table '$table_name': " . mysqli_error($conn) . "<br>";
    }
}

// Attempt to insert default settings row if it doesn't exist
$check_settings_exist = "SELECT id FROM settings WHERE id = 1";
$result = mysqli_query($conn, $check_settings_exist);

if ($result && mysqli_num_rows($result) == 0) {
    $default_template_single = "Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) was absent on {current_date}. Contact office: {office_number}.";
    $default_template_multiple = "Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) has been absent for {consecutive_days} days. Please contact office: {office_number} urgently.";
    $default_office_number = "123-456-7890"; // Default office number

    // Escape strings for SQL
    $escaped_template_single = mysqli_real_escape_string($conn, $default_template_single);
    $escaped_template_multiple = mysqli_real_escape_string($conn, $default_template_multiple);
    $escaped_office_number = mysqli_real_escape_string($conn, $default_office_number);

    $insert_default_settings = "INSERT INTO settings (id, notification_type, sms_template_single_absence, sms_template_multiple_absences, consecutive_absence_threshold, office_number)
                                VALUES (1, 'none', '$escaped_template_single', '$escaped_template_multiple', 3, '$escaped_office_number')";

    if (mysqli_query($conn, $insert_default_settings)) {
        echo "Default settings inserted successfully.<br>";
    } else {
        // If insertion fails, it might be because another process inserted it. Double check.
        $retry_result = mysqli_query($conn, $check_settings_exist);
        if ($retry_result && mysqli_num_rows($retry_result) > 0) {
            echo "Default settings were inserted by another process or already existed upon retry.<br>";
        } else {
            echo "Error inserting default settings: " . mysqli_error($conn) . "<br>";
        }
    }
} else if (!$result) {
    echo "Error checking for existing settings: " . mysqli_error($conn) . "<br>";
}
else {
    echo "Settings row (id=1) already exists.<br>";
}


mysqli_close($conn);
?>
