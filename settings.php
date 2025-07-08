<?php
session_start();
include 'config.php'; // Establishes $conn and selects DB_NAME

$message = '';
$message_type = ''; // 'success' or 'error'

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $notification_type = mysqli_real_escape_string($conn, $_POST['notification_type']);
    $sms_template_single_absence = mysqli_real_escape_string($conn, $_POST['sms_template_single_absence']);
    $sms_template_multiple_absences = mysqli_real_escape_string($conn, $_POST['sms_template_multiple_absences']);
    $consecutive_absence_threshold = (int)$_POST['consecutive_absence_threshold'];
    $office_number = mysqli_real_escape_string($conn, $_POST['office_number']);

    // Settings are always updated for id=1
    $sql_update_settings = "UPDATE settings SET
                                notification_type = '$notification_type',
                                sms_template_single_absence = '$sms_template_single_absence',
                                sms_template_multiple_absences = '$sms_template_multiple_absences',
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
        'consecutive_absence_threshold' => 3,
        'office_number' => ''
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
    <nav class="top-nav">
        <a href="index.php">Home</a>
        <a href="students.php">Manage Students</a>
        <a href="attendance.php">Take/View Attendance</a>
        <a href="settings.php">Settings</a>
    </nav>
    <div class="container">
        <h1>Application Settings</h1>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <form action="settings.php" method="POST">
            <label for="notification_type">Notification Type:</label>
            <select name="notification_type" id="notification_type">
                <option value="none" <?php echo ($settings['notification_type'] == 'none') ? 'selected' : ''; ?>>None</option>
                <option value="sms" <?php echo ($settings['notification_type'] == 'sms') ? 'selected' : ''; ?>>SMS Only</option>
                <option value="whatsapp" <?php echo ($settings['notification_type'] == 'whatsapp') ? 'selected' : ''; ?>>WhatsApp Only</option>
                <option value="both" <?php echo ($settings['notification_type'] == 'both') ? 'selected' : ''; ?>>SMS and WhatsApp</option>
            </select>

            <label for="sms_template_single_absence">SMS/Notification Template (Single Absence):</label>
            <textarea name="sms_template_single_absence" id="sms_template_single_absence" rows="4"><?php echo htmlspecialchars($settings['sms_template_single_absence']); ?></textarea>
            <div class="placeholders-info">
                <strong>Available Placeholders:</strong>
                <code>{parent_name}</code>, <code>{student_name}</code>, <code>{student_rollnumber}</code>, <code>{current_date}</code>, <code>{office_number}</code>
            </div>


            <label for="sms_template_multiple_absences">SMS/Notification Template (Multiple Consecutive Absences):</label>
            <textarea name="sms_template_multiple_absences" id="sms_template_multiple_absences" rows="4"><?php echo htmlspecialchars($settings['sms_template_multiple_absences']); ?></textarea>
             <div class="placeholders-info">
                <strong>Available Placeholders:</strong>
                <code>{parent_name}</code>, <code>{student_name}</code>, <code>{student_rollnumber}</code>, <code>{current_date}</code>, <code>{office_number}</code>, <code>{consecutive_days}</code>
            </div>

            <label for="consecutive_absence_threshold">Consecutive Absence Threshold (days):</label>
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
