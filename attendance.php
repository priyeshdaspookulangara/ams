<?php
include_once 'auth_check.php'; // Ensures session_start() and user functions
if (!is_logged_in()) {
    header("Location: login.php");
    exit;
}
// Specific role/permission checks will be done contextually below

$page_title = "Take/View Attendance"; // Set page title for layout

include 'config.php';
include 'functions.php';

$message = '';
$message_type = ''; // 'success', 'error', 'info'
$notification_messages_display = [];

$current_user_id = current_user_id();
$current_role = current_user_role();

$selected_date = isset($_REQUEST['attendance_date']) ? $_REQUEST['attendance_date'] : date('Y-m-d');
$selected_class_section_id = isset($_REQUEST['class_section_id']) ? (int)$_REQUEST['class_section_id'] : 0;

$students_for_attendance = [];
$class_sections_for_dropdown = [];

// Fetch class sections for the dropdown based on user role/permissions
$cs_dropdown_sql = "";
if ($current_role == 'admin') {
    $cs_dropdown_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                        FROM class_sections cs
                        JOIN grades g ON cs.grade_id = g.id
                        JOIN divisions d ON cs.division_id = d.id
                        ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
} elseif ($current_role == 'teacher') {
    $accessible_section_ids = get_teacher_attendance_accessible_sections($current_user_id, $conn);
    if (!empty($accessible_section_ids)) {
        $ids_str = implode(',', array_map('intval', $accessible_section_ids));
        $cs_dropdown_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                            FROM class_sections cs
                            JOIN grades g ON cs.grade_id = g.id
                            JOIN divisions d ON cs.division_id = d.id
                            WHERE cs.id IN ($ids_str)
                            ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
    }
}

if (!empty($cs_dropdown_sql)) {
    $cs_res = mysqli_query($conn, $cs_dropdown_sql);
    if ($cs_res) while ($row = mysqli_fetch_assoc($cs_res)) $class_sections_for_dropdown[] = $row;
    else { $message = "DB Error (cs_dropdown): " . mysqli_error($conn); $message_type = 'error'; }
}


// Handle saving attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_attendance'])) {
    require_login(['admin', 'teacher']); // Ensure only authorized roles can submit
    $attendance_date_posted = mysqli_real_escape_string($conn, $_POST['attendance_date']);
    $class_section_id_posted = (int)$_POST['filter_class_section_hidden'];

    $selected_class_section_id = $class_section_id_posted;
    $selected_date = $attendance_date_posted;

    $can_take_this_attendance = false;
    if ($current_role == 'admin') {
        $can_take_this_attendance = true;
    } else if ($current_role == 'teacher') {
        if (is_class_teacher_of_section($current_user_id, $class_section_id_posted, $conn) ||
            has_delegated_permission($current_user_id, $class_section_id_posted, 'can_take_attendance', $conn)) {
            $can_take_this_attendance = true;
        }
    }

    if (!$can_take_this_attendance) {
        $message = "You do not have permission to take attendance for this class section.";
        $message_type = 'error';
    } elseif (empty($attendance_date_posted) || $class_section_id_posted == 0) {
        $message = "Date and Class Section are required to submit attendance.";
        $message_type = 'error';
    } else {
        $submitted_students_ids = isset($_POST['student_ids']) ? $_POST['student_ids'] : [];
        $statuses = isset($_POST['status']) ? $_POST['status'] : [];
        $notes_list = isset($_POST['notes']) ? $_POST['notes'] : [];
        $success_count = 0; $error_count = 0; $absent_student_data_for_notification = [];
        foreach ($submitted_students_ids as $student_id) {
            $student_id_clean = (int)$student_id;
            $is_present = isset($statuses[$student_id_clean]) && $statuses[$student_id_clean] == 'present' ? 1 : 0;
            $teacher_note = isset($notes_list[$student_id_clean]) ? mysqli_real_escape_string($conn, $notes_list[$student_id_clean]) : '';
            $check_sql = "SELECT id FROM attendance_records WHERE student_id = $student_id_clean AND attendance_date = '$attendance_date_posted'";
            $check_result = mysqli_query($conn, $check_sql);
            $operation_successful = false; $current_attendance_record_id = null;
            if ($check_result && mysqli_num_rows($check_result) > 0) {
                $existing_record = mysqli_fetch_assoc($check_result); $record_id = $existing_record['id'];
                $current_attendance_record_id = $record_id;
                $update_sql = "UPDATE attendance_records SET is_present = $is_present, notes = '$teacher_note' WHERE id = $record_id";
                if (mysqli_query($conn, $update_sql)) { $success_count++; $operation_successful = true; }
                else { $error_count++; $message .= "Error updating for student ID $student_id_clean: " . mysqli_error($conn) . "<br>"; }
            } else {
                $insert_sql = "INSERT INTO attendance_records (student_id, attendance_date, is_present, notes) VALUES ($student_id_clean, '$attendance_date_posted', $is_present, '$teacher_note')";
                if (mysqli_query($conn, $insert_sql)) {
                    $current_attendance_record_id = mysqli_insert_id($conn); $success_count++; $operation_successful = true;
                } else { $error_count++; $message .= "Error inserting for student ID $student_id_clean: " . mysqli_error($conn) . "<br>"; }
            }
            if ($operation_successful && !$is_present) {
                $student_query_sql = "SELECT name, roll_number FROM students WHERE id = $student_id_clean";
                $student_res = mysqli_query($conn, $student_query_sql);
                if($student_res && mysqli_num_rows($student_res) > 0){
                    $absent_student_data_for_notification[$student_id_clean] = mysqli_fetch_assoc($student_res);
                    $absent_student_data_for_notification[$student_id_clean]['attendance_record_id'] = $current_attendance_record_id;
                }
            }
        }
        if ($error_count > 0) { $message_type = 'error'; $message = "Attendance submission partially failed. $success_count records processed. <br>" . $message; }
        else if ($success_count > 0) {
            $selected_cs_info_q = mysqli_query($conn, "SELECT IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id WHERE cs.id = $class_section_id_posted");
            $selected_cs_name = ($selected_cs_info_q && mysqli_num_rows($selected_cs_info_q)>0) ? mysqli_fetch_assoc($selected_cs_info_q)['display_name'] : "Selected Section";
            $message = "Attendance for $success_count students recorded/updated successfully for $selected_cs_name on " . date("d-m-Y", strtotime($attendance_date_posted)) .".";
            $message_type = 'success';
        } else if (empty($submitted_students_ids)) { $message = "No students selected."; $message_type = 'info'; }
        else { $message = "No attendance data processed."; $message_type = 'error';}

        $current_settings = get_settings($conn);
        if (!empty($absent_student_data_for_notification) && ($current_settings['notification_type'] != 'none')) {
            foreach ($absent_student_data_for_notification as $absent_student_id => $student_details) {
                $consecutive_absences = check_consecutive_absences($absent_student_id, $attendance_date_posted, $conn);
                $template_to_use = ($consecutive_absences >= $current_settings['consecutive_absence_threshold']) ? $current_settings['sms_template_multiple_absences'] : $current_settings['sms_template_single_absence'];
                $log_prefix_type = ($consecutive_absences >= $current_settings['consecutive_absence_threshold']) ? "MULTIPLE ($consecutive_absences days) " : "SINGLE ";
                $parents_q_sql = "SELECT parent_name, phone_number FROM parent_guardians WHERE student_id = $absent_student_id";
                $parents_res = mysqli_query($conn, $parents_q_sql);
                if ($parents_res && mysqli_num_rows($parents_res) > 0) {
                    while ($parent = mysqli_fetch_assoc($parents_res)) {
                        $msg_data = ['parent_name' => $parent['parent_name'], 'student_name' => $student_details['name'], 'student_rollnumber' => $student_details['roll_number'], 'current_date' => date("d-m-Y", strtotime($attendance_date_posted)), 'office_number' => $current_settings['office_number'], 'consecutive_days' => $consecutive_absences];
                        $fmt_msg = format_notification_message($template_to_use, $msg_data);
                        $notif_summary = $log_prefix_type . "Notif for " . $student_details['name'] . " to " . $parent['parent_name'] . ": ";
                        if ($current_settings['notification_type'] == 'sms' || $current_settings['notification_type'] == 'both') {
                            if(send_notification($parent['phone_number'], $fmt_msg, 'sms', $student_details['name'])) $notification_messages_display[] = $notif_summary . "SMS OK."; else $notification_messages_display[] = $notif_summary . "SMS FAIL.";
                        }
                        if ($current_settings['notification_type'] == 'whatsapp' || $current_settings['notification_type'] == 'both') {
                             if(send_notification($parent['phone_number'], $fmt_msg, 'whatsapp', $student_details['name'])) $notification_messages_display[] = $notif_summary . "WA OK."; else $notification_messages_display[] = $notif_summary . "WA FAIL.";
                        }
                    }
                } else { $notification_messages_display[] = "No parent phone for " . $student_details['name'] . ". Not sent.";}
            }
        }
    }
}

// Fetch students if date and class_section_id are selected for display
if (isset($_POST['fetch_students']) || isset($_POST['submit_attendance'])) { // Also re-fetch after submit
    // $selected_date and $selected_class_section_id are already set from request or POST
}

if (!empty($selected_date) && $selected_class_section_id > 0) {
    $can_view_this_attendance = false;
     if ($current_role == 'admin') $can_view_this_attendance = true;
     else if ($current_role == 'teacher') {
        if (is_class_teacher_of_section($current_user_id, $selected_class_section_id, $conn) ||
            has_delegated_permission($current_user_id, $selected_class_section_id, 'can_take_attendance', $conn)) {
            $can_view_this_attendance = true;
        }
     }

    if ($can_view_this_attendance) {
        $sql_fetch_students = "SELECT s.id, s.name, s.roll_number,
                                    ar.id as attendance_record_id, ar.is_present, ar.notes as teacher_notes,
                                    abr.id as absence_reason_id, abr.reason_text as parent_reason, abr.status as reason_status, u.username as reason_submitter
                            FROM students s
                            JOIN class_sections cs ON s.class_section_id = cs.id
                            LEFT JOIN attendance_records ar ON s.id = ar.student_id AND ar.attendance_date = '$selected_date'
                            LEFT JOIN absence_reasons abr ON ar.id = abr.attendance_record_id
                            LEFT JOIN users u ON abr.submitted_by_user_id = u.id
                            WHERE s.class_section_id = $selected_class_section_id
                            ORDER BY s.roll_number, s.name";
        $result_students = mysqli_query($conn, $sql_fetch_students);
        if ($result_students) {
            while ($row = mysqli_fetch_assoc($result_students)) $students_for_attendance[] = $row;
        } else { $message .= " Error fetching students: " . mysqli_error($conn); $message_type = 'error';}
    } else {
        $is_option_valid_for_teacher = false;
        foreach($class_sections_for_dropdown as $cs_opt) { if($cs_opt['id'] == $selected_class_section_id) $is_option_valid_for_teacher = true;}
        if($current_role == 'teacher' && !$is_option_valid_for_teacher && $selected_class_section_id > 0){
             $message = "Invalid class section selected or you do not have permission.";
        } else if ($selected_class_section_id > 0) {
             $message = "You do not have permission to view attendance for this class section.";
        }
        if($selected_class_section_id > 0 && empty($message_type)) $message_type = 'error';
        $students_for_attendance = [];
    }
}

$session_notification_log = [];
if (isset($_SESSION['notification_log']) && is_array($_SESSION['notification_log'])) {
    $session_notification_log = $_SESSION['notification_log'];
    unset($_SESSION['notification_log']);
}

// Start output buffering
ob_start();
?>

<!-- Page specific content starts here -->
<div class="container-fluid mt-3"> <!-- Using Bootstrap container-fluid and margin-top -->
    <h1><?php echo htmlspecialchars($page_title); ?></h1>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : ($message_type == 'success' ? 'success' : 'info'); ?> alert-dismissible fade show" role="alert">
            <?php echo $message; // Message may contain HTML like <br> from DB errors, consider sanitizing if not trusted. Here it's mostly system messages. ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($notification_messages_display)): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <strong>Notification Attempts Summary:</strong><br>
            <?php foreach ($notification_messages_display as $notif_msg) echo htmlspecialchars($notif_msg)."<br>"; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($session_notification_log)): ?>
        <div class="alert alert-secondary p-2" id="notificationLogDisplay">
            <h5 class="alert-heading">Notification Log (API/Placeholder):</h5>
            <div style="max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 0.85em; white-space: pre-wrap;">
            <?php foreach ($session_notification_log as $log_entry) echo "<p class='mb-1 border-bottom border-secondary pb-1'>".$log_entry."</p>"; ?>
            </div>
        </div>
    <?php endif; ?>

    <form action="attendance.php" method="POST" class="card p-3 mb-3 bg-light">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="attendance_date" class="form-label">Select Date:</label>
                <input type="date" name="attendance_date" id="attendance_date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" required>
            </div>
            <div class="col-md-6">
                <label for="class_section_id" class="form-label">Select Class Section:</label>
                <select name="class_section_id" id="class_section_id" class="form-select" required>
                    <option value="">-- Select Class Section --</option>
                    <?php foreach ($class_sections_for_dropdown as $cs): ?>
                        <option value="<?php echo $cs['id']; ?>" <?php echo ($selected_class_section_id == $cs['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cs['display_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" name="fetch_students" class="btn btn-primary w-100">Fetch Students</button>
            </div>
        </div>
    </form>

    <?php if (!empty($students_for_attendance)):
        $current_cs_name_q = mysqli_query($conn, "SELECT IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id WHERE cs.id = $selected_class_section_id");
        $current_cs_name = ($current_cs_name_q && mysqli_num_rows($current_cs_name_q)>0) ? mysqli_fetch_assoc($current_cs_name_q)['display_name'] : "Selected Section";
    ?>
        <h2 class="mt-4">Mark Attendance for: <?php echo htmlspecialchars($current_cs_name); ?> on <?php echo date("D, M j, Y", strtotime($selected_date)); ?></h2>
        <form action="attendance.php" method="POST" class="attendance-form">
            <input type="hidden" name="attendance_date" value="<?php echo htmlspecialchars($selected_date); ?>">
            <input type="hidden" name="filter_class_section_hidden" value="<?php echo htmlspecialchars($selected_class_section_id); ?>">
            <div class="table-responsive">
                <table class="table table-striped table-bordered table-hover">
                    <colgroup> <col style="width: 8%;"> <col style="width: 17%;"> <col style="width: 25%;"> <col style="width: 25%;"> <col style="width: 25%;"> </colgroup>
                    <thead class="table-light"><tr><th>Roll No.</th><th>Student Name</th><th>Status</th><th>Teacher Notes</th><th>Parent Submitted Reason</th></tr></thead>
                    <tbody>
                        <?php foreach ($students_for_attendance as $student): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                <td><?php echo htmlspecialchars($student['name']); ?></td>
                                <td >
                                    <input type="hidden" name="student_ids[]" value="<?php echo $student['id']; ?>">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="status[<?php echo $student['id']; ?>]" id="status_present_<?php echo $student['id']; ?>" value="present" <?php echo (isset($student['is_present']) && $student['is_present'] == 1) ? 'checked' : ''; ?> required>
                                        <label class="form-check-label" for="status_present_<?php echo $student['id']; ?>">Present</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="status[<?php echo $student['id']; ?>]" id="status_absent_<?php echo $student['id']; ?>" value="absent" <?php echo (isset($student['is_present']) && $student['is_present'] == 0) ? 'checked' : ''; echo (!isset($student['is_present']) && $student['attendance_record_id'] === null) ? 'checked' : '';?> required>
                                        <label class="form-check-label text-danger" for="status_absent_<?php echo $student['id']; ?>">Absent</label>
                                    </div>
                                </td>
                                <td><input type="text" name="notes[<?php echo $student['id']; ?>]" class="form-control form-control-sm notes-field" value="<?php echo isset($student['teacher_notes']) ? htmlspecialchars($student['teacher_notes']) : ''; ?>" placeholder="e.g., Sick leave"></td>
                                <td>
                                    <?php if (!empty($student['parent_reason'])): ?>
                                        <div class="parent-reason p-1 rounded bg-light border reason-<?php echo htmlspecialchars(strtolower($student['reason_status'])); ?>">
                                            <small><strong><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$student['reason_status']))); ?>:</strong> <?php echo nl2br(htmlspecialchars($student['parent_reason'])); ?>
                                            <br>(By: <?php echo htmlspecialchars($student['reason_submitter'] ?: 'Parent'); ?>)</small>
                                            <?php if (in_array($current_role, ['admin', 'teacher']) && $student['absence_reason_id']): ?>
                                                <br><a href="manage_reason.php?reason_id=<?php echo $student['absence_reason_id']; ?>&att_id=<?php echo $student['attendance_record_id']; ?>" class="btn btn-sm btn-outline-secondary mt-1 py-0 px-1" style="font-size:0.8em;">Manage Reason</a>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($student['is_present'] == 0 && $student['attendance_record_id'] !== null) : ?> <small class="text-muted">No reason submitted.</small>
                                    <?php else: echo '<small class="text-muted">-</small>'; endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" name="submit_attendance" class="btn btn-success mt-3">Submit Attendance & Send Notifications</button>
        </form>
    <?php elseif ( (isset($_POST['fetch_students']) || isset($_GET['fetch_students']) ) && empty($students_for_attendance) && $selected_class_section_id > 0): ?>
        <p class="alert alert-warning">No students found for the selected class section. Please add students via "Manage Students" page.</p>
    <?php elseif(empty($class_sections_for_dropdown) && $current_role == 'teacher'): ?>
        <p class="alert alert-info">You are not currently assigned or delegated to take attendance for any class sections.</p>
    <?php endif; ?>
</div>
<!-- Page specific content ends here -->

<?php
$page_content_html = ob_get_clean(); // Get buffered content
if(isset($conn)) mysqli_close($conn); // Close DB connection before including layout

include 'layout_authenticated.php'; // Include the main layout
?>
