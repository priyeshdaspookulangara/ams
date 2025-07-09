<?php
include_once 'auth_check.php';
require_login(['admin']);

$page_title = "Application Settings"; // Define page title

include 'config.php';

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) { // Added isset check for save_settings
    $notification_type = mysqli_real_escape_string($conn, $_POST['notification_type']);
    $sms_template_single_absence = mysqli_real_escape_string($conn, $_POST['sms_template_single_absence']);
    $sms_template_multiple_absences = mysqli_real_escape_string($conn, $_POST['sms_template_multiple_absences']);
    $email_template_single_absence = mysqli_real_escape_string($conn, $_POST['email_template_single_absence']);
    $email_template_multiple_absences = mysqli_real_escape_string($conn, $_POST['email_template_multiple_absences']);
    $consecutive_absence_threshold = (int)$_POST['consecutive_absence_threshold'];
    $office_number = mysqli_real_escape_string($conn, $_POST['office_number']);

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

// Fetch current settings
$sql_get_settings = "SELECT * FROM settings WHERE id = 1";
$result = mysqli_query($conn, $sql_get_settings);

if ($result && mysqli_num_rows($result) > 0) {
    $settings = mysqli_fetch_assoc($result);
} else {
    $message = "Error: Settings not found. Please ensure setup_db.php was run correctly.";
    $message_type = 'error';
    // Initialize $settings with default values to prevent errors in the form
    $settings = [
        'notification_type' => 'none',
        'sms_template_single_absence' => 'Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) was absent on {current_date}. Contact office: {office_number}.',
        'sms_template_multiple_absences' => 'Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) has been absent for {consecutive_days} days. Please contact office: {office_number} urgently.',
        'email_template_single_absence' => '<p>Dear {parent_name},</p><p>This email is to inform you that your child, <strong>{student_name}</strong> (Roll No: {student_rollnumber}), was marked absent on {current_date}.</p><p>Please contact the school office at {office_number} if you have any questions.</p><p>Thank you.</p>',
        'email_template_multiple_absences' => '<p>Dear {parent_name},</p><p>This email is to inform you that your child, <strong>{student_name}</strong> (Roll No: {student_rollnumber}), has been marked absent for {consecutive_days} consecutive days, including today ({current_date}).</p><p>Please contact the school office at {office_number} urgently to discuss this matter.</p><p>Thank you.</p>',
        'consecutive_absence_threshold' => 3,
        'office_number' => '123-456-7890'
    ];
}

// Start output buffering for page content
ob_start();
?>

<div class="container-fluid mt-3">
    <h1><?php echo htmlspecialchars($page_title); ?></h1>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form action="settings.php" method="POST" class="card p-3">
        <div class="mb-3">
            <label for="notification_type" class="form-label">Notification Type:</label>
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
        </div>

        <h3 class="mt-4 border-bottom pb-2">SMS/WhatsApp Templates</h3>
        <div class="mb-3">
            <label for="sms_template_single_absence" class="form-label">SMS/WhatsApp Template (Single Absence):</label>
            <textarea name="sms_template_single_absence" id="sms_template_single_absence" class="form-control" rows="3"><?php echo htmlspecialchars($settings['sms_template_single_absence']); ?></textarea>
            <div class="form-text">Placeholders: <code>{parent_name}</code>, <code>{student_name}</code>, <code>{student_rollnumber}</code>, <code>{current_date}</code>, <code>{office_number}</code></div>
        </div>

        <div class="mb-3">
            <label for="sms_template_multiple_absences" class="form-label">SMS/WhatsApp Template (Multiple Consecutive Absences):</label>
            <textarea name="sms_template_multiple_absences" id="sms_template_multiple_absences" class="form-control" rows="3"><?php echo htmlspecialchars($settings['sms_template_multiple_absences']); ?></textarea>
             <div class="form-text">Placeholders: <code>{parent_name}</code>, <code>{student_name}</code>, <code>{student_rollnumber}</code>, <code>{current_date}</code>, <code>{office_number}</code>, <code>{consecutive_days}</code></div>
        </div>

        <h3 class="mt-4 border-bottom pb-2">Email Templates (HTML Allowed)</h3>
        <div class="mb-3">
            <label for="email_template_single_absence" class="form-label">Email Template (Single Absence):</label>
            <textarea name="email_template_single_absence" id="email_template_single_absence" class="form-control" rows="6"><?php echo htmlspecialchars($settings['email_template_single_absence']); ?></textarea>
            <div class="form-text">Placeholders: (Same as above, use within your HTML structure)</div>
        </div>

        <div class="mb-3">
            <label for="email_template_multiple_absences" class="form-label">Email Template (Multiple Consecutive Absences):</label>
            <textarea name="email_template_multiple_absences" id="email_template_multiple_absences" class="form-control" rows="6"><?php echo htmlspecialchars($settings['email_template_multiple_absences']); ?></textarea>
            <div class="form-text">Placeholders: (Same as above, use within your HTML structure)</div>
        </div>

        <h3 class="mt-4 border-bottom pb-2">General Settings</h3>
        <div class="mb-3">
            <label for="consecutive_absence_threshold" class="form-label">Consecutive Absence Threshold (days):</label>
            <input type="number" name="consecutive_absence_threshold" id="consecutive_absence_threshold" class="form-control" value="<?php echo (int)$settings['consecutive_absence_threshold']; ?>" min="1">
            <div class="form-text">Number of consecutive absences to trigger the 'multiple absences' notification.</div>
        </div>

        <div class="mb-3">
            <label for="office_number" class="form-label">Office Contact Number:</label>
            <input type="text" name="office_number" id="office_number" class="form-control" value="<?php echo htmlspecialchars($settings['office_number']); ?>">
            <div class="form-text">This number will be used in the notification templates if the {office_number} placeholder is present.</div>
        </div>

        <button type="submit" name="save_settings" class="btn btn-primary">Save Settings</button>
    </form>
</div>

<?php
$page_content_html = ob_get_clean(); // Get buffered content
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
