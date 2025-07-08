<?php
session_start();
include 'config.php'; // Establishes $conn
include 'functions.php'; // Includes our new helper functions

$message = '';
$message_type = ''; // 'success' or 'error'
$notification_messages_display = []; // To store messages about notifications

$selected_date = isset($_REQUEST['attendance_date']) ? $_REQUEST['attendance_date'] : date('Y-m-d');
$selected_grade = isset($_REQUEST['grade']) ? mysqli_real_escape_string($conn, $_REQUEST['grade']) : '';

$students_for_attendance = [];
$grades = [];

// Fetch distinct grades for the dropdown
$grades_query = "SELECT DISTINCT grade FROM students ORDER BY grade";
$grades_result = mysqli_query($conn, $grades_query);
if ($grades_result) {
    while ($row = mysqli_fetch_assoc($grades_result)) {
        $grades[] = $row['grade'];
    }
}

// Handle saving attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_attendance'])) {
    $attendance_date = mysqli_real_escape_string($conn, $_POST['attendance_date']);
    $posted_grade = mysqli_real_escape_string($conn, $_POST['filter_grade_hidden']);

    $selected_grade = $posted_grade;
    $selected_date = $attendance_date;

    if (empty($attendance_date) || empty($posted_grade)) {
        $message = "Date and Grade are required to submit attendance.";
        $message_type = 'error';
    } else {
        $submitted_students_ids = isset($_POST['student_ids']) ? $_POST['student_ids'] : [];
        $statuses = isset($_POST['status']) ? $_POST['status'] : [];
        $notes_list = isset($_POST['notes']) ? $_POST['notes'] : [];

        $success_count = 0;
        $error_count = 0;
        $absent_student_data_for_notification = []; // Store details of students marked absent

        foreach ($submitted_students_ids as $student_id) {
            $student_id_clean = (int)$student_id;
            $is_present = isset($statuses[$student_id_clean]) && $statuses[$student_id_clean] == 'present' ? 1 : 0;
            $note = isset($notes_list[$student_id_clean]) ? mysqli_real_escape_string($conn, $notes_list[$student_id_clean]) : '';

            $check_sql = "SELECT id FROM attendance_records WHERE student_id = $student_id_clean AND attendance_date = '$attendance_date'";
            $check_result = mysqli_query($conn, $check_sql);

            $operation_successful = false;
            if ($check_result && mysqli_num_rows($check_result) > 0) {
                $existing_record = mysqli_fetch_assoc($check_result);
                $record_id = $existing_record['id'];
                $update_sql = "UPDATE attendance_records SET is_present = $is_present, notes = '$note' WHERE id = $record_id";
                if (mysqli_query($conn, $update_sql)) {
                    $success_count++;
                    $operation_successful = true;
                } else {
                    $error_count++;
                    $message .= "Error updating for student ID $student_id_clean: " . mysqli_error($conn) . "<br>";
                }
            } else {
                $insert_sql = "INSERT INTO attendance_records (student_id, attendance_date, is_present, notes)
                               VALUES ($student_id_clean, '$attendance_date', $is_present, '$note')";
                if (mysqli_query($conn, $insert_sql)) {
                    $success_count++;
                    $operation_successful = true;
                } else {
                    $error_count++;
                    $message .= "Error inserting for student ID $student_id_clean: " . mysqli_error($conn) . "<br>";
                }
            }

            if ($operation_successful && !$is_present) {
                // Fetch student details for notification processing later
                $student_query_sql = "SELECT name, roll_number FROM students WHERE id = $student_id_clean";
                $student_res = mysqli_query($conn, $student_query_sql);
                if($student_res && mysqli_num_rows($student_res) > 0){
                    $absent_student_data_for_notification[$student_id_clean] = mysqli_fetch_assoc($student_res);
                }
            }
        }

        if ($error_count > 0) {
            $message_type = 'error';
            // $message is already populated with specific errors
            $message = "Attendance submission partially failed with $error_count errors. $success_count records processed. <br>" . $message;
        } else if ($success_count > 0) {
            $message = "Attendance for $success_count students recorded/updated successfully for $posted_grade on $attendance_date.";
            $message_type = 'success';
        } else if (empty($submitted_students_ids)) {
            $message = "No students were selected or found for attendance submission.";
            $message_type = 'info';
        } else {
            $message = "No attendance data was processed.";
            $message_type = 'error';
        }


        // --- NOTIFICATION LOGIC (SINGLE & MULTIPLE CONSECUTIVE) ---
        $current_settings = get_settings($conn);
        if (!empty($absent_student_data_for_notification) &&
            ($current_settings['notification_type'] == 'sms' ||
             $current_settings['notification_type'] == 'whatsapp' ||
             $current_settings['notification_type'] == 'both')) {

            foreach ($absent_student_data_for_notification as $absent_student_id => $student_details) {

                $consecutive_absences = check_consecutive_absences($absent_student_id, $attendance_date, $conn);
                $send_multiple_absence_notification = false;

                if ($consecutive_absences >= $current_settings['consecutive_absence_threshold']) {
                    $send_multiple_absence_notification = true;
                    $template_to_use = $current_settings['sms_template_multiple_absences'];
                    $notification_type_log_prefix = "MULTIPLE (" . $consecutive_absences . " days) ";
                } else {
                    $template_to_use = $current_settings['sms_template_single_absence'];
                    $notification_type_log_prefix = "SINGLE ";
                }

                // Fetch parent/guardian details
                $parents_query_sql = "SELECT parent_name, phone_number FROM parent_guardians WHERE student_id = $absent_student_id";
                $parents_res = mysqli_query($conn, $parents_query_sql);

                if ($parents_res && mysqli_num_rows($parents_res) > 0) {
                    while ($parent = mysqli_fetch_assoc($parents_res)) {
                        $data_for_message = [
                            'parent_name' => $parent['parent_name'],
                            'student_name' => $student_details['name'],
                            'student_rollnumber' => $student_details['roll_number'],
                            'current_date' => date("d-m-Y", strtotime($attendance_date)),
                            'office_number' => $current_settings['office_number'],
                            'consecutive_days' => $consecutive_absences // Available for both templates, though only used in multiple
                        ];
                        $formatted_message = format_notification_message($template_to_use, $data_for_message);

                        $notification_sent_summary = $notification_type_log_prefix . "Notification attempt for " . $student_details['name'] . " to " . $parent['parent_name'] . " (" . $parent['phone_number'] . "): ";

                        if ($current_settings['notification_type'] == 'sms' || $current_settings['notification_type'] == 'both') {
                            if (send_notification($parent['phone_number'], $formatted_message, 'sms', $student_details['name'])) {
                                $notification_messages_display[] = $notification_sent_summary . "SMS logged.";
                            } else {
                                $notification_messages_display[] = $notification_sent_summary . "SMS failed (placeholder).";
                            }
                        }
                        if ($current_settings['notification_type'] == 'whatsapp' || $current_settings['notification_type'] == 'both') {
                             if (send_notification($parent['phone_number'], $formatted_message, 'whatsapp', $student_details['name'])) {
                                $notification_messages_display[] = $notification_sent_summary . "WhatsApp logged.";
                            } else {
                                $notification_messages_display[] = $notification_sent_summary . "WhatsApp failed (placeholder).";
                            }
                        }
                    }
                } else {
                     $notification_messages_display[] = "No parent/guardian phone numbers found for absent student: " . $student_details['name'] . " (ID: $absent_student_id). Notification not sent.";
                }
            }
        }
        // --- END NOTIFICATION LOGIC ---
    }
}


// Fetch students if date and grade are selected
if (!empty($selected_date) && !empty($selected_grade)) {
    $sql_fetch_students = "SELECT s.id, s.name, s.roll_number, ar.is_present, ar.notes
                           FROM students s
                           LEFT JOIN attendance_records ar ON s.id = ar.student_id AND ar.attendance_date = '$selected_date'
                           WHERE s.grade = '$selected_grade'
                           ORDER BY s.roll_number, s.name";
    $result_students = mysqli_query($conn, $sql_fetch_students);
    if ($result_students) {
        while ($row = mysqli_fetch_assoc($result_students)) {
            $students_for_attendance[] = $row;
        }
    } else {
        $message .= " Error fetching students: " . mysqli_error($conn);
        $message_type = 'error';
    }
}

$session_notification_log = [];
if (isset($_SESSION['notification_log']) && is_array($_SESSION['notification_log'])) {
    $session_notification_log = $_SESSION['notification_log'];
    unset($_SESSION['notification_log']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Take Attendance</title>
     <style>
        body { font-family: Arial, sans-serif; margin: 0; padding:0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 15px; text-decoration: none; font-weight: bold; }
        .top-nav a:hover { text-decoration: underline; }
        .container { width: 90%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px;}
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; word-wrap: break-word; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .filter-form, .attendance-form { margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border-radius: 5px; }
        .filter-form label, .attendance-form label { margin-right: 10px; font-weight: bold; }
        input[type="date"], select, input[type="text"] { padding: 8px; margin-right: 10px; border-radius: 4px; border: 1px solid #ccc; box-sizing: border-box;}
        input[type="submit"], button { padding: 10px 15px; border-radius: 4px; border: 1px solid; cursor: pointer; font-weight: bold; }
        input[type="submit"].primary, button.primary { background-color: #007bff; color: white; border-color: #007bff;}
        input[type="submit"].primary:hover, button.primary:hover { background-color: #0056b3; }
        input[type="submit"].secondary { background-color: #28a745; color: white; border-color: #28a745; }
        input[type="submit"].secondary:hover { background-color: #218838; }
        .radio-group label { margin-right: 15px; font-weight: normal; }
        .notes-field { width: 90%; }
        .no-students { text-align: center; padding: 15px; color: #777; }
        .notification-log-display { margin-top: 20px; padding: 10px; background-color: #f0f0f0; border: 1px solid #ccc; border-radius: 5px; max-height: 300px; overflow-y: auto; font-size: 0.9em;}
        .notification-log-display h3 { margin-top: 0; }
        .notification-log-display p { font-family: monospace; white-space: pre-wrap; margin-bottom: 5px; border-bottom: 1px dashed #ccc; padding-bottom: 5px; word-wrap: break-word;}
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
        <h1>Take/View Attendance</h1>

        <?php if ($message): // Main status message for attendance ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if (!empty($notification_messages_display)): // Summary of notification attempts ?>
            <div class="message info">
                <strong>Notification Attempts Summary:</strong><br>
                <?php foreach ($notification_messages_display as $notif_msg): ?>
                    <?php echo htmlspecialchars($notif_msg); ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($session_notification_log)): // Detailed placeholder log from send_notification() ?>
            <div class="notification-log-display">
                <h3>Notification Log (Placeholder Output):</h3>
                <?php foreach ($session_notification_log as $log_entry): ?>
                    <p><?php echo $log_entry; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="attendance.php" method="POST" class="filter-form">
            <label for="attendance_date">Select Date:</label>
            <input type="date" name="attendance_date" id="attendance_date" value="<?php echo htmlspecialchars($selected_date); ?>" required>

            <label for="grade">Select Grade/Class:</label>
            <select name="grade" id="grade" required>
                <option value="">-- Select Grade --</option>
                <?php foreach ($grades as $grade_item): ?>
                    <option value="<?php echo htmlspecialchars($grade_item); ?>" <?php echo ($selected_grade == $grade_item) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($grade_item); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="fetch_students" class="primary">Fetch Students</button>
        </form>

        <?php if (!empty($students_for_attendance)): ?>
            <h2>Mark Attendance for Grade: <?php echo htmlspecialchars($selected_grade); ?> on <?php echo htmlspecialchars($selected_date); ?></h2>
            <form action="attendance.php" method="POST" class="attendance-form">
                <input type="hidden" name="attendance_date" value="<?php echo htmlspecialchars($selected_date); ?>">
                <input type="hidden" name="filter_grade_hidden" value="<?php echo htmlspecialchars($selected_grade); ?>">
                <table>
                    <thead>
                        <tr>
                            <th>Roll No.</th>
                            <th>Student Name</th>
                            <th>Status</th>
                            <th>Notes (Optional)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students_for_attendance as $student): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                <td><?php echo htmlspecialchars($student['name']); ?></td>
                                <td class="radio-group">
                                    <input type="hidden" name="student_ids[]" value="<?php echo $student['id']; ?>">
                                    <label>
                                        <input type="radio" name="status[<?php echo $student['id']; ?>]" value="present"
                                            <?php echo (isset($student['is_present']) && $student['is_present'] == 1) ? 'checked' : ''; ?> required> Present
                                    </label>
                                    <label>
                                        <input type="radio" name="status[<?php echo $student['id']; ?>]" value="absent"
                                            <?php echo (isset($student['is_present']) && $student['is_present'] == 0) ? 'checked' : ''; ?>
                                            <?php echo (!isset($student['is_present'])) ? 'checked' : ''; ?>
                                            required> Absent
                                    </label>
                                </td>
                                <td>
                                    <input type="text" name="notes[<?php echo $student['id']; ?>]" class="notes-field"
                                           value="<?php echo isset($student['notes']) ? htmlspecialchars($student['notes']) : ''; ?>"
                                           placeholder="e.g., Sick leave, Half day">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <input type="submit" name="submit_attendance" value="Submit Attendance & Send Notifications" class="secondary">
            </form>
        <?php elseif (($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['fetch_students'])) && empty($students_for_attendance) && !empty($selected_grade)): ?>
            <p class="no-students">No students found for the selected grade (<?php echo htmlspecialchars($selected_grade);?>). Please add students to this grade via the "Manage Students" page.</p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
mysqli_close($conn);
?>
