<?php
include_once 'auth_check.php';
require_login(['parent']); // Only parents can access this page

include 'config.php'; // Establishes $conn
include 'functions.php'; // For get_settings or other helpers

$parent_user_id = current_user_id();
$linked_students_data = [];
$message = '';
$message_type = '';

// Fetch linked students for this parent
$sql_linked_students = "SELECT s.id, s.name, s.roll_number, s.grade
                        FROM students s
                        JOIN user_student_links usl ON s.id = usl.student_id
                        WHERE usl.user_id = $parent_user_id
                        ORDER BY s.name";
$result_linked_students = mysqli_query($conn, $sql_linked_students);
if ($result_linked_students && mysqli_num_rows($result_linked_students) > 0) {
    while ($student = mysqli_fetch_assoc($result_linked_students)) {
        // For each student, fetch their attendance records and any submitted reasons
        $student_id = $student['id'];
        $sql_attendance = "SELECT ar.id as attendance_record_id, ar.attendance_date, ar.is_present, ar.notes as teacher_notes,
                                  abr.id as absence_reason_id, abr.reason_text, abr.status as reason_status
                           FROM attendance_records ar
                           LEFT JOIN absence_reasons abr ON ar.id = abr.attendance_record_id AND abr.submitted_by_user_id = $parent_user_id
                           WHERE ar.student_id = $student_id
                           ORDER BY ar.attendance_date DESC
                           LIMIT 30"; // Limit to recent records for performance

        $result_attendance = mysqli_query($conn, $sql_attendance);
        $student['attendance_history'] = [];
        if ($result_attendance) {
            while ($att_row = mysqli_fetch_assoc($result_attendance)) {
                $student['attendance_history'][] = $att_row;
            }
        }
        $linked_students_data[] = $student;
    }
} else {
    $message = "No students are currently linked to your account. Please contact the school administration.";
    $message_type = 'info';
}

// Handle reason submission (this could be a separate submit_reason.php, but integrated for simplicity now)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_reason'])) {
    $attendance_record_id = (int)$_POST['attendance_record_id'];
    $reason_text = mysqli_real_escape_string($conn, trim($_POST['reason_text']));
    $student_id_for_reason = (int)$_POST['student_id_for_reason']; // To refresh the correct student's data

    if (empty($reason_text)) {
        $message = "Reason text cannot be empty.";
        $message_type = 'error';
    } elseif ($attendance_record_id <= 0) {
        $message = "Invalid attendance record ID.";
        $message_type = 'error';
    } else {
        // Check if student is actually linked to this parent to prevent unauthorized submissions
        $is_linked = false;
        foreach($linked_students_data as $student_check) {
            if($student_check['id'] == $student_id_for_reason) {
                foreach($student_check['attendance_history'] as $att_hist) {
                    if($att_hist['attendance_record_id'] == $attendance_record_id) {
                        $is_linked = true;
                        break 2;
                    }
                }
            }
        }

        if (!$is_linked) {
            $message = "Error: You are not authorized to submit a reason for this attendance record.";
            $message_type = 'error';
        } else {
            // Check if a reason already exists by this parent for this record
            $check_reason_sql = "SELECT id FROM absence_reasons WHERE attendance_record_id = $attendance_record_id AND submitted_by_user_id = $parent_user_id";
            $check_reason_result = mysqli_query($conn, $check_reason_sql);

            if ($check_reason_result && mysqli_num_rows($check_reason_result) > 0) {
                // Update existing reason (if policy allows, e.g., if status is 'pending_review')
                $existing_reason = mysqli_fetch_assoc($check_reason_result);
                $reason_id_to_update = $existing_reason['id'];
                // For now, let's assume updating is allowed if it's pending. More complex status logic can be added.
                $update_reason_sql = "UPDATE absence_reasons SET reason_text = '$reason_text', status = 'pending_review', submitted_at = NOW()
                                      WHERE id = $reason_id_to_update";
                if (mysqli_query($conn, $update_reason_sql)) {
                    $message = "Absence reason updated successfully.";
                    $message_type = 'success';
                } else {
                    $message = "Error updating reason: " . mysqli_error($conn);
                    $message_type = 'error';
                }
            } else {
                // Insert new reason
                $insert_reason_sql = "INSERT INTO absence_reasons (attendance_record_id, submitted_by_user_id, reason_text, status)
                                      VALUES ($attendance_record_id, $parent_user_id, '$reason_text', 'pending_review')";
                if (mysqli_query($conn, $insert_reason_sql)) {
                    $message = "Absence reason submitted successfully.";
                    $message_type = 'success';
                } else {
                    $message = "Error submitting reason: " . mysqli_error($conn);
                    $message_type = 'error';
                }
            }
            // Refresh data after submission
            // This is a simplified refresh. For a cleaner UX, AJAX or more targeted refresh would be better.
            header("Location: parent_dashboard.php?student_id_refreshed=" . $student_id_for_reason . "&msg=" . urlencode($message) . "&msg_type=" . $message_type);
            exit;
        }
    }
}

// Handle messages passed via GET after reason submission
if(isset($_GET['msg'])){
    $message = htmlspecialchars(urldecode($_GET['msg']));
    $message_type = isset($_GET['msg_type']) ? htmlspecialchars($_GET['msg_type']) : 'info';
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding:0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 10px; text-decoration: none; font-weight: bold; }
        .top-nav .user-info { float: right; color: #ddd; font-size: 0.9em; margin-right: 20px; line-height: 2.5em;}
        .top-nav a:hover { text-decoration: underline; }

        .container { width: 90%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }

        .student-section { margin-bottom: 30px; padding: 15px; background-color: #f9f9f9; border-radius: 5px;}
        .student-section h2 { margin-top: 0; font-size: 1.5em; }

        table.attendance-history { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .attendance-history th, .attendance-history td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        .attendance-history th { background-color: #e9ecef; }
        .status-present { color: green; font-weight: bold; }
        .status-absent { color: red; font-weight: bold; }

        .reason-form textarea { width: 90%; min-height: 60px; padding: 8px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; }
        .reason-form input[type="submit"] { background-color: #007bff; color: white; padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; margin-top: 5px; }
        .reason-form input[type="submit"]:hover { background-color: #0056b3; }
        .submitted-reason { font-size: 0.9em; color: #555; margin-top: 5px; padding: 8px; background-color: #eef; border-radius: 3px;}
        .reason-pending { border-left: 3px solid #ffc107 !important; }
        .reason-approved { border-left: 3px solid #28a745 !important; }
        .reason-rejected { border-left: 3px solid #dc3545 !important; }
    </style>
</head>
<body>
    <nav class="top-nav">
        <a href="index.php">Home</a>
        <?php if (is_logged_in()): ?>
            <?php if (in_array(current_user_role(), ['admin', 'teacher'])): ?>
                <a href="students.php">Manage Students</a>
                <a href="attendance.php">Take/View Attendance</a>
            <?php endif; ?>
            <?php if (current_user_role() == 'admin'): ?>
                <a href="settings.php">Settings</a>
                <a href="manage_users.php">Manage Users</a>
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

    <div class="container">
        <h1>Parent Dashboard</h1>
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if (!empty($linked_students_data)): ?>
            <?php foreach ($linked_students_data as $student): ?>
                <div class="student-section" id="student-<?php echo $student['id']; ?>">
                    <h2><?php echo htmlspecialchars($student['name']); ?>
                        <small>(Roll: <?php echo htmlspecialchars($student['roll_number']); ?>, Grade: <?php echo htmlspecialchars($student['grade']); ?>)</small>
                    </h2>
                    <h3>Recent Attendance History (Last 30 Records)</h3>
                    <?php if (!empty($student['attendance_history'])): ?>
                        <table class="attendance-history">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Teacher Notes</th>
                                    <th>Your Submitted Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($student['attendance_history'] as $record): ?>
                                    <tr>
                                        <td><?php echo date("D, M j, Y", strtotime($record['attendance_date'])); ?></td>
                                        <td class="<?php echo $record['is_present'] ? 'status-present' : 'status-absent'; ?>">
                                            <?php echo $record['is_present'] ? 'Present' : 'ABSENT'; ?>
                                        </td>
                                        <td><?php echo !empty($record['teacher_notes']) ? nl2br(htmlspecialchars($record['teacher_notes'])) : '-'; ?></td>
                                        <td>
                                            <?php if ($record['is_present'] == 0): // Only show reason form/info if absent ?>
                                                <?php if (!empty($record['reason_text'])): ?>
                                                    <div class="submitted-reason reason-<?php echo strtolower(htmlspecialchars($record['reason_status'])); ?>">
                                                        <strong>Status: <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $record['reason_status']))); ?></strong><br>
                                                        <?php echo nl2br(htmlspecialchars($record['reason_text'])); ?>
                                                        <?php if ($record['reason_status'] == 'pending_review'): // Allow editing if pending ?>
                                                            <details>
                                                                <summary style="cursor:pointer; font-size:0.9em; color:#007bff; margin-top:5px;">Edit Reason</summary>
                                                                <form action="parent_dashboard.php#student-<?php echo $student['id']; ?>" method="POST" class="reason-form" style="margin-top:10px;">
                                                                    <input type="hidden" name="attendance_record_id" value="<?php echo $record['attendance_record_id']; ?>">
                                                                    <input type="hidden" name="student_id_for_reason" value="<?php echo $student['id']; ?>">
                                                                    <textarea name="reason_text" required><?php echo htmlspecialchars($record['reason_text']); ?></textarea>
                                                                    <input type="submit" name="submit_reason" value="Update Reason">
                                                                </form>
                                                            </details>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <form action="parent_dashboard.php#student-<?php echo $student['id']; ?>" method="POST" class="reason-form">
                                                        <input type="hidden" name="attendance_record_id" value="<?php echo $record['attendance_record_id']; ?>">
                                                        <input type="hidden" name="student_id_for_reason" value="<?php echo $student['id']; ?>">
                                                        <textarea name="reason_text" placeholder="Enter reason for absence..." required></textarea>
                                                        <input type="submit" name="submit_reason" value="Submit Reason">
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: echo '-'; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No attendance records found for this student yet.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php elseif (empty($message)) : // Avoid showing this if there's already a "no linked students" message ?>
            <p class="info">No student data to display.</p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php if(isset($conn)) mysqli_close($conn); ?>
