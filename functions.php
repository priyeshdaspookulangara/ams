<?php

// Function to fetch current settings
function get_settings($conn) {
    $sql_get_settings = "SELECT * FROM settings WHERE id = 1";
    $result = mysqli_query($conn, $sql_get_settings);
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    return [
        'notification_type' => 'none',
        'sms_template_single_absence' => 'Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) was absent on {current_date}. Contact office: {office_number}.',
        'sms_template_multiple_absences' => 'Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) has been absent for {consecutive_days} days, including today ({current_date}). Please contact office: {office_number} urgently.',
        'consecutive_absence_threshold' => 3,
        'office_number' => 'N/A'
    ];
}

// Function to format notification messages
function format_notification_message($template, $data) {
    foreach ($data as $key => $value) {
        $template = str_replace('{' . $key . '}', $value, $template);
    }
    return $template;
}

// Function to send notifications (SMS & WhatsApp via Twilio)
function send_notification($phoneNumber, $message, $type, $studentNameForLog = "N/A") {
    $log_prefix = "[" . date("Y-m-d H:i:s") . "] [Student: " . htmlspecialchars($studentNameForLog) . "] ";
    $detailed_log_message = "Type: " . strtoupper($type) . " | To: " . htmlspecialchars($phoneNumber) . "\nMessage: " . htmlspecialchars($message) . "\n";

    // Common check for Twilio credentials
    if (!defined('TWILIO_ACCOUNT_SID') || TWILIO_ACCOUNT_SID === 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' ||
        !defined('TWILIO_AUTH_TOKEN') || TWILIO_AUTH_TOKEN === 'your_auth_token_xxxxxxxxxxxxxxx') {

        $detailed_log_message .= "Status: SKIPPED (Twilio Account SID/Auth Token not configured - using placeholder log)\n";
        $_SESSION['notification_log'][] = nl2br($log_prefix . strtoupper($type) . " (Placeholder - Configure Twilio SID/Token): To: " . htmlspecialchars($phoneNumber) . " Msg: " . htmlspecialchars($message));
        // Log to file if needed: file_put_contents('notification_activity_log.txt', $log_prefix . $detailed_log_message . "-------------------------------------------------\n", FILE_APPEND);
        return true; // Simulate success for placeholder
    }

    $account_sid = TWILIO_ACCOUNT_SID;
    $auth_token = TWILIO_AUTH_TOKEN;
    $twilio_api_url = "https://api.twilio.com/2010-04-01/Accounts/$account_sid/Messages.json";
    $data = [];

    if ($type == 'sms') {
        if (!defined('TWILIO_PHONE_NUMBER') || TWILIO_PHONE_NUMBER === '+1234567890') {
            $detailed_log_message .= "Status: SKIPPED (Twilio Phone Number for SMS not configured - using placeholder log)\n";
            $_SESSION['notification_log'][] = nl2br($log_prefix . "SMS (Placeholder - Configure Twilio Phone #): To: " . htmlspecialchars($phoneNumber) . " Msg: " . htmlspecialchars($message));
            // Log to file: file_put_contents('notification_activity_log.txt', $log_prefix . $detailed_log_message . "-------------------------------------------------\n", FILE_APPEND);
            return true;
        }
        $data = [
            'To' => $phoneNumber, // Standard E.164 format for SMS
            'From' => TWILIO_PHONE_NUMBER,
            'Body' => $message
        ];
    } elseif ($type == 'whatsapp') {
        if (!defined('TWILIO_WHATSAPP_SENDER') || TWILIO_WHATSAPP_SENDER === 'whatsapp:+14155238886' || empty(TWILIO_WHATSAPP_SENDER)) {
             // Allow default sandbox number if it's not the placeholder value for "empty"
            if (TWILIO_WHATSAPP_SENDER !== 'whatsapp:+14155238886' && empty(TWILIO_WHATSAPP_SENDER)) {
                 $detailed_log_message .= "Status: SKIPPED (Twilio WhatsApp Sender not configured - using placeholder log)\n";
                $_SESSION['notification_log'][] = nl2br($log_prefix . "WhatsApp (Placeholder - Configure Twilio WhatsApp Sender): To: " . htmlspecialchars($phoneNumber) . " Msg: " . htmlspecialchars($message));
                // Log to file: file_put_contents('notification_activity_log.txt', $log_prefix . $detailed_log_message . "-------------------------------------------------\n", FILE_APPEND);
                return true;
            }
        }
        // For WhatsApp, phone numbers need to be prefixed with "whatsapp:"
        $data = [
            'To' => 'whatsapp:' . $phoneNumber, // Ensure $phoneNumber is in E.164 format
            'From' => TWILIO_WHATSAPP_SENDER, // This is your Twilio WhatsApp enabled number or Sandbox number
            'Body' => $message
        ];
        // Note: For non-sandbox, WhatsApp often requires pre-approved message templates for business-initiated messages.
        // Freeform messages like this are typically allowed only within a 24-hour customer care window
        // after the user messages the business number first, or if using the Twilio Sandbox for WhatsApp.
    } else {
        $detailed_log_message .= "Status: FAILED (Unknown notification type: " . htmlspecialchars($type) . ")\n";
        $_SESSION['notification_log'][] = nl2br($log_prefix . "Unknown notification type: " . htmlspecialchars($type));
        // Log to file: file_put_contents('notification_activity_log.txt', $log_prefix . $detailed_log_message . "-------------------------------------------------\n", FILE_APPEND);
        return false;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $twilio_api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$account_sid:$auth_token");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        $detailed_log_message .= "Status: FAILED (cURL Error: " . htmlspecialchars($curl_error) . ")\n";
        $_SESSION['notification_log'][] = nl2br($log_prefix . strtoupper($type) . " Gateway Error: cURL failed - " . htmlspecialchars($curl_error));
    } else {
        $response_data = json_decode($response, true);
        if ($http_code >= 200 && $http_code < 300) {
            $detailed_log_message .= "Status: SUCCESS (Twilio SID: " . htmlspecialchars($response_data['sid'] ?? 'N/A') . ", Status: " . htmlspecialchars($response_data['status'] ?? 'N/A') . ")\n";
            $_SESSION['notification_log'][] = nl2br($log_prefix . strtoupper($type) . " sent successfully to " . htmlspecialchars($data['To']) . " (SID: " . htmlspecialchars($response_data['sid'] ?? 'N/A') . ")");
        } else {
            $error_msg = $response_data['message'] ?? 'Unknown API error';
            $error_code = $response_data['code'] ?? 'N/A';
            $detailed_log_message .= "Status: FAILED (HTTP: $http_code, Twilio Code: $error_code, Message: " . htmlspecialchars($error_msg) . ")\n";
            $_SESSION['notification_log'][] = nl2br($log_prefix . strtoupper($type) . " sending FAILED to " . htmlspecialchars($data['To']) . " - HTTP $http_code - " . htmlspecialchars($error_msg));
        }
    }
    // Log to file: file_put_contents('notification_activity_log.txt', $log_prefix . $detailed_log_message . "-------------------------------------------------\n", FILE_APPEND);
    return ($http_code >= 200 && $http_code < 300 && !$curl_error);
}


// Function to check for consecutive absences (implementation remains the same)
function check_consecutive_absences($student_id, $current_attendance_date_str, $conn) {
    $consecutive_days = 0;
    $current_date = date('Y-m-d', strtotime($current_attendance_date_str));

    for ($i = 0; ; $i++) {
        $check_date_obj = new DateTime($current_date);
        $check_date_obj->modify("-$i days");
        $check_date_str = $check_date_obj->format('Y-m-d');

        $sql = "SELECT is_present FROM attendance_records
                WHERE student_id = $student_id AND attendance_date = '$check_date_str'";
        $result = mysqli_query($conn, $sql);

        if ($result && mysqli_num_rows($result) > 0) {
            $record = mysqli_fetch_assoc($result);
            if ($record['is_present'] == 0) {
                $consecutive_days++;
            } else {
                break;
            }
        } else {
            if ($i > 0) break;
        }
    }
    return $consecutive_days;
}

// --- PHPMailer Integration for Email Notifications ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Function to send email notifications
function send_email_notification($to_email, $to_name, $subject, $html_body) {
    // Ensure PHPMailer classes are loaded.
    // This might require explicit require_once if not using an autoloader.
    // Assuming files are in lib/PHPMailer/src/
    $phpmailer_base_path = __DIR__ . '/../lib/PHPMailer/'; // Adjust if functions.php moves
    if (!class_exists(PHPMailer::class)) { // Check if already loaded
        require_once $phpmailer_base_path . 'Exception.php';
        require_once $phpmailer_base_path . 'PHPMailer.php';
        require_once $phpmailer_base_path . 'SMTP.php';
    }

    $mail = new PHPMailer(true); // Passing `true` enables exceptions

    $log_prefix = "[" . date("Y-m-d H:i:s") . "] [Email] ";
    $detailed_log_message = "To: " . htmlspecialchars($to_email) . " | Subject: " . htmlspecialchars($subject) . "\n";

    // Check if SMTP credentials are set and not placeholders
    if (!defined('SMTP_HOST') || SMTP_HOST === 'smtp.example.com' || empty(SMTP_HOST) ||
        !defined('SMTP_USERNAME') || SMTP_USERNAME === 'your_email@example.com' ||
        !defined('SMTP_PASSWORD') || SMTP_PASSWORD === 'your_smtp_password' ||
        !defined('EMAIL_FROM_ADDRESS') || EMAIL_FROM_ADDRESS === 'noreply@yourschoolattendance.com') {

        $detailed_log_message .= "Status: SKIPPED (SMTP settings not fully configured - using placeholder log)\n";
        if (!isset($_SESSION['notification_log'])) $_SESSION['notification_log'] = [];
        $_SESSION['notification_log'][] = nl2br($log_prefix . "Email (Placeholder - Configure SMTP): To: " . htmlspecialchars($to_email) . " Subject: " . htmlspecialchars($subject));
        // file_put_contents('notification_activity_log.txt', $log_prefix . $detailed_log_message . "-------------------------------------------------\n", FILE_APPEND);
        return true; // Simulate success for placeholder
    }

    try {
        // Server settings
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Enable verbose debug output for troubleshooting
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        if (defined('SMTP_SECURE') && !empty(SMTP_SECURE)) {
            $mail->SMTPSecure = SMTP_SECURE; // PHPMailer::ENCRYPTION_SMTPS or PHPMailer::ENCRYPTION_STARTTLS
        }
        $mail->Port       = SMTP_PORT;

        // Recipients
        $mail->setFrom(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);
        $mail->addAddress($to_email, $to_name); // Add a recipient

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->AltBody = strip_tags($html_body); // Plain text version

        $mail->send();
        $detailed_log_message .= "Status: SUCCESS (Message sent)\n";
        if (!isset($_SESSION['notification_log'])) $_SESSION['notification_log'] = [];
        $_SESSION['notification_log'][] = nl2br($log_prefix . "Email sent successfully to " . htmlspecialchars($to_email));
        // file_put_contents('notification_activity_log.txt', $log_prefix . $detailed_log_message . "-------------------------------------------------\n", FILE_APPEND);
        return true;
    } catch (Exception $e) {
        $detailed_log_message .= "Status: FAILED (PHPMailer Error: " . htmlspecialchars($mail->ErrorInfo) . ")\n";
        if (!isset($_SESSION['notification_log'])) $_SESSION['notification_log'] = [];
        $_SESSION['notification_log'][] = nl2br($log_prefix . "Email sending FAILED to " . htmlspecialchars($to_email) . " - Error: " . htmlspecialchars($mail->ErrorInfo));
        // file_put_contents('notification_activity_log.txt', $log_prefix . $detailed_log_message . "-------------------------------------------------\n", FILE_APPEND);
        return false;
    }
}

?>
