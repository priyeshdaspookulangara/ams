<?php
include_once 'auth_check.php';
require_login(['admin']); // Only admins can access settings

include 'config.php'; // Establishes $conn and selects DB_NAME

$message = '';
$message_type = ''; // 'success' or 'error'

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $notification_type = mysqli_real_escape_string($conn, $_POST['notification_type']);
    $sms_template_single_absence = mysqli_real_escape_string($conn, $_POST['sms_template_single_absence']);
    $sms_template_multiple_absences = mysqli_real_escape_string($conn, $_POST['sms_template_multiple_absences']);
    $email_template_single_absence = mysqli_real_escape_string($conn, $_POST['email_template_single_absence']);
    $email_template_multiple_absences = mysqli_real_escape_string($conn, $_POST['email_template_multiple_absences']);
    $consecutive_absence_threshold = (int)$_POST['consecutive_absence_threshold'];
    $office_number = mysqli_real_escape_string($conn, $_POST['office_number']);

    // Settings are always updated for id=1
    $sql_update_settings = "UPDATE settings SET
                                notification_type = '$notification_type',
                                sms_template_single_absence = '$sms_template_single_absence',
                                sms_template_multiple_absences = '$sms_template_multiple_absences',
                                email_template_single_absence = '$email_template_single_absence',
                                email_template_multiple_absences = '$email_template_multiple_absences',
                                consecutive_absence_threshold = $consecutive_absence_threshold,
                                office_number = '$office_number'
                            WHERE id = 1";

    if (mysqli_query($conn, $sql_update_settings)) {
        $message = "Settings updated successfully!";
        $message_type = 'success';
    } else {
        $message = "Error updating settings: " . mysqli_error($conn);
        $message_type = 'error';
    }
}

// Fetch current settings (should always be id=1 as per setup_db.php)
$sql_get_settings = "SELECT * FROM settings WHERE id = 1";
$result = mysqli_query($conn, $sql_get_settings);

if ($result && mysqli_num_rows($result) > 0) {
    $settings = mysqli_fetch_assoc($result);
} else {
    // This case should ideally not happen if setup_db.php was run correctly
    // and inserted the default settings.
    $message = "Error: Settings not found. Please run setup_db.php.";
    $message_type = 'error';
    // Initialize $settings with default values to prevent errors in the form
    $settings = [
        'notification_type' => 'none',
        'sms_template_single_absence' => 'Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) was absent on {current_date}. Contact office: {office_number}.',
        'sms_template_multiple_absences' => 'Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) has been absent for multiple days. Please contact office: {office_number} urgently.',
        'email_template_single_absence' => '<p>Dear {parent_name},</p><p>This email is to inform you that your child, <strong>{student_name}</strong> (Roll No: {student_rollnumber}), was marked absent on {current_date}.</p><p>Please contact the school office at {office_number} if you have any questions.</p><p>Thank you.</p>',
        'email_template_multiple_absences' => '<p>Dear {parent_name},</p><p>This email is to inform you that your child, <strong>{student_name}</strong> (Roll No: {student_rollnumber}), has been marked absent for {consecutive_days} consecutive days, including today ({current_date}).</p><p>Please contact the school office at {office_number} urgently to discuss this matter.</p><p>Thank you.</p>',
        'consecutive_absence_threshold' => 3,
        'office_number' => '123-456-7890'
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 15px; text-decoration: none; font-weight: bold; }
        .top-nav a:hover { text-decoration: underline; }
        .container { width: 80%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #f4f4f4; padding-bottom: 10px; }
        .message { padding: 15px; margin-bottom: 20px; border-radius: 5px; font-size: 1.1em; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        form label { display: block; margin-top: 15px; font-weight: bold; color: #555; }
        form input[type="text"], form input[type="number"], form select, form textarea {
            width: calc(100% - 22px);
            padding: 10px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box; /* Important for width calculation */
        }
        form textarea { min-height: 100px; resize: vertical; }
        form input[type="submit"] {
            background-color: #007bff;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 25px;
            font-size: 1.1em;
        }
        form input[type="submit"]:hover { background-color: #0056b3; }
        .info { font-size: 0.9em; color: #666; margin-top: 5px; }
        .placeholders-info {
            background-color: #e9ecef;
            padding: 10px;
            border-radius: 4px;
            margin-top: 5px;
            font-size: 0.9em;
        }
        .placeholders-info strong { display: block; margin-bottom: 5px; }
    </style>
</head>
<body>
    <nav class="top-nav no-print">
        <a href="index.php">Home</a>
        <?php if (is_logged_in()): ?>
            <?php if (in_array(current_user_role(), ['admin', 'teacher'])): ?>
                <a href="students.php">Manage Students</a>
                <a href="attendance.php">Take/View Attendance</a>
                <div style="display:inline-block; position:relative;" class="nav-dropdown-container">
                    <a href="#">Reports &#9662;</a>
                    <div style="position:absolute; background-color:#333; display:none; min-width:160px; box-shadow:0px 8px 16px 0px rgba(0,0,0,0.2); z-index:1;" class="dropdown-content">
                        <a href="reports_student_master.php" style="display:block; padding:8px 10px; text-align:left;">Student Master List</a>
                        <a href="reports_enrollment_summary.php" style="display:block; padding:8px 10px; text-align:left;">Enrollment Summary</a>
                        <!-- More reports here -->
                    </div>
                </div>
            <?php endif; ?>
            <?php if (current_user_role() == 'admin'): ?>
                <a href="settings.php">Settings</a>
                <a href="manage_users.php">Manage Users</a>
                <a href="manage_grades.php">Manage Grades</a>
                <a href="manage_divisions.php">Manage Divisions</a>
                <a href="manage_class_sections.php">Manage Class Sections</a>
                <a href="delegate_tasks.php">Delegate Tasks</a>
            <?php elseif (current_user_role() == 'teacher'): ?>
                <a href="delegate_tasks.php">Delegate Tasks</a>
            <?php endif; ?>
            <?php if (current_user_role() == 'parent'): ?>
                <a href="parent_dashboard.php">Parent Dashboard</a>
            <?php endif; ?>
            <span class="user-info">Logged in as: <?php echo htmlspecialchars(current_username()); ?> (<?php echo htmlspecialchars(current_user_role()); ?>)</span>
            <a href="logout.php" style="float:right;">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="register.php">Register (Teacher)</a>
        <?php endif; ?>
    </nav>
    <script> // Simple dropdown for nav - ensure this script is present or moved to a global JS
        document.querySelectorAll('.nav-dropdown-container').forEach(item => {
            item.addEventListener('mouseover', () => { item.querySelector('.dropdown-content').style.display = 'block'; });
            item.addEventListener('mouseout', () => { item.querySelector('.dropdown-content').style.display = 'none'; });
        });
    </script>
    <div class="container">
        <h1>Application Settings</h1>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <form action="settings.php" method="POST">
            <label for="notification_type">Notification Type:</label>
            <select name="notification_type" id="notification_type" class="form-select">
                <option value="none" <?php echo ($settings['notification_type'] == 'none') ? 'selected' : ''; ?>>None</option>
                <option value="sms" <?php echo ($settings['notification_type'] == 'sms') ? 'selected' : ''; ?>>SMS Only</option>
                <option value="email" <?php echo ($settings['notification_type'] == 'email') ? 'selected' : ''; ?>>Email Only</option>
                <option value="whatsapp" <?php echo ($settings['notification_type'] == 'whatsapp') ? 'selected' : ''; ?>>WhatsApp Only</option>
                <option value="sms_email" <?php echo ($settings['notification_type'] == 'sms_email') ? 'selected' : ''; ?>>SMS and Email</option>
                <option value="sms_whatsapp" <?php echo ($settings['notification_type'] == 'sms_whatsapp') ? 'selected' : ''; ?>>SMS and WhatsApp</option>
                <option value="email_whatsapp" <?php echo ($settings['notification_type'] == 'email_whatsapp') ? 'selected' : ''; ?>>Email and WhatsApp</option>
                <option value="all" <?php echo ($settings['notification_type'] == 'all') ? 'selected' : ''; ?>>SMS, Email, and WhatsApp</option>
            </select>

            <h3 class="mt-4">SMS/WhatsApp Templates</h3>
            <label for="sms_template_single_absence">SMS/WhatsApp Template (Single Absence):</label>
            <textarea name="sms_template_single_absence" id="sms_template_single_absence" class="form-control" rows="3"><?php echo htmlspecialchars($settings['sms_template_single_absence']); ?></textarea>
            <div class="placeholders-info">
                <strong>Placeholders:</strong> <code>{parent_name}</code>, <code>{student_name}</code>, <code>{student_rollnumber}</code>, <code>{current_date}</code>, <code>{office_number}</code>
            </div>

            <label for="sms_template_multiple_absences" class="mt-3">SMS/WhatsApp Template (Multiple Consecutive Absences):</label>
            <textarea name="sms_template_multiple_absences" id="sms_template_multiple_absences" class="form-control" rows="3"><?php echo htmlspecialchars($settings['sms_template_multiple_absences']); ?></textarea>
             <div class="placeholders-info">
                <strong>Placeholders:</strong> <code>{parent_name}</code>, <code>{student_name}</code>, <code>{student_rollnumber}</code>, <code>{current_date}</code>, <code>{office_number}</code>, <code>{consecutive_days}</code>
            </div>

            <h3 class="mt-4">Email Templates (HTML Allowed)</h3>
            <label for="email_template_single_absence">Email Template (Single Absence):</label>
            <textarea name="email_template_single_absence" id="email_template_single_absence" class="form-control" rows="6"><?php echo htmlspecialchars($settings['email_template_single_absence']); ?></textarea>
            <div class="placeholders-info">
                <strong>Placeholders:</strong> (Same as above, use within your HTML structure)
            </div>

            <label for="email_template_multiple_absences" class="mt-3">Email Template (Multiple Consecutive Absences):</label>
            <textarea name="email_template_multiple_absences" id="email_template_multiple_absences" class="form-control" rows="6"><?php echo htmlspecialchars($settings['email_template_multiple_absences']); ?></textarea>
            <div class="placeholders-info">
                <strong>Placeholders:</strong> (Same as above, use within your HTML structure)
            </div>

            <h3 class="mt-4">General Settings</h3>
            <label for="consecutive_absence_threshold" class="mt-3">Consecutive Absence Threshold (days):</label>
            <input type="number" name="consecutive_absence_threshold" id="consecutive_absence_threshold" value="<?php echo (int)$settings['consecutive_absence_threshold']; ?>" min="1">
            <p class="info">Number of consecutive absences to trigger the 'multiple absences' notification.</p>

            <label for="office_number">Office Contact Number:</label>
            <input type="text" name="office_number" id="office_number" value="<?php echo htmlspecialchars($settings['office_number']); ?>">
            <p class="info">This number will be used in the notification templates if the {office_number} placeholder is present.</p>

            <input type="submit" value="Save Settings">
        </form>
    </div>
</body>
</html>
<?php
mysqli_close($conn);
?>
