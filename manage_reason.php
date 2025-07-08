<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']); // Admins and Teachers can manage reasons

include 'config.php'; // Establishes $conn
include 'functions.php';

$message = '';
$message_type = '';
$reason_details = null;
$attendance_details = null;
$student_details = null;

$reason_id = isset($_GET['reason_id']) ? (int)$_GET['reason_id'] : 0;
$att_id = isset($_GET['att_id']) ? (int)$_GET['att_id'] : 0; // Attendance Record ID

if ($reason_id <= 0 && $att_id <= 0) {
    $message = "No reason ID or attendance ID provided.";
    $message_type = 'error';
} else {
    // Fetch reason details if reason_id is provided
    if ($reason_id > 0) {
        $sql_reason = "SELECT abr.*, u.username as submitted_by_username
                       FROM absence_reasons abr
                       JOIN users u ON abr.submitted_by_user_id = u.id
                       WHERE abr.id = $reason_id";
        $res_reason = mysqli_query($conn, $sql_reason);
        if ($res_reason && mysqli_num_rows($res_reason) > 0) {
            $reason_details = mysqli_fetch_assoc($res_reason);
            $att_id = $reason_details['attendance_record_id']; // Ensure att_id is set from reason
        } else {
            $message = "Absence reason not found.";
            $message_type = 'error';
            $reason_id = 0; // Invalidate if not found
        }
    }

    // Fetch attendance and student details using $att_id
    // This is always needed to show context, even if no reason was submitted yet (though link implies a reason exists)
    if ($att_id > 0) {
        $sql_att = "SELECT ar.*, s.name as student_name, s.roll_number, s.grade
                    FROM attendance_records ar
                    JOIN students s ON ar.student_id = s.id
                    WHERE ar.id = $att_id";
        $res_att = mysqli_query($conn, $sql_att);
        if ($res_att && mysqli_num_rows($res_att) > 0) {
            $attendance_details = mysqli_fetch_assoc($res_att);
            $student_details = [ // Populate for display
                'name' => $attendance_details['student_name'],
                'roll_number' => $attendance_details['roll_number'],
                'grade' => $attendance_details['grade']
            ];
            // If reason_details is null but att_id is valid, it means we are managing an absence
            // that might not have a parent reason yet, or the direct link was to att_id.
            // This page is primarily for *managing submitted reasons*, so reason_id is key.
            if (!$reason_details && $reason_id == 0){ // If reason_id was not passed or invalid, try to find one for this att_id
                $sql_find_reason = "SELECT abr.*, u.username as submitted_by_username
                                    FROM absence_reasons abr
                                    JOIN users u ON abr.submitted_by_user_id = u.id
                                    WHERE abr.attendance_record_id = $att_id ORDER BY abr.submitted_at DESC LIMIT 1";
                $res_find_reason = mysqli_query($conn, $sql_find_reason);
                if($res_find_reason && mysqli_num_rows($res_find_reason) > 0){
                    $reason_details = mysqli_fetch_assoc($res_find_reason);
                    $reason_id = $reason_details['id']; // Update reason_id
                } else {
                     // $message = "No parent reason submitted for this absence yet.";
                     // $message_type = 'info'; // Allow teacher to add notes though.
                }
            }

        } else {
            $message = "Attendance record not found.";
            $message_type = 'error';
            $att_id = 0; // Invalidate
        }
    } else if ($reason_id > 0 && !$att_id) { // Reason found, but its att_id was somehow invalid
         $message = "Attendance record linked to the reason not found.";
         $message_type = 'error';
    }


    // Handle status update
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_reason_status']) && $reason_id > 0) {
        $new_status = mysqli_real_escape_string($conn, $_POST['reason_status']);
        $teacher_notes_update = mysqli_real_escape_string($conn, $_POST['teacher_notes']); // Teacher can add/update their notes here too

        // Update reason status
        $sql_update_status = "UPDATE absence_reasons SET status = '$new_status' WHERE id = $reason_id";
        $update_status_success = mysqli_query($conn, $sql_update_status);

        // Update teacher notes on the attendance record
        $sql_update_notes = "UPDATE attendance_records SET notes = '$teacher_notes_update' WHERE id = $att_id";
        $update_notes_success = mysqli_query($conn, $sql_update_notes);

        if ($update_status_success && $update_notes_success) {
            $message = "Reason status and teacher notes updated successfully.";
            $message_type = 'success';
            // Refresh reason_details and attendance_details
            $res_reason = mysqli_query($conn, $sql_reason); // Re-fetch reason
            if ($res_reason) $reason_details = mysqli_fetch_assoc($res_reason);
            $res_att = mysqli_query($conn, $sql_att); // Re-fetch attendance
            if ($res_att) $attendance_details = mysqli_fetch_assoc($res_att);

        } else {
            $message = "Error updating: ";
            if (!$update_status_success) $message .= "Reason status error: " . mysqli_error($conn) . " ";
            if (!$update_notes_success) $message .= "Teacher notes error: " . mysqli_error($conn);
            $message_type = 'error';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_teacher_notes_only']) && $att_id > 0) {
        // This part handles if only teacher notes are updated, without a parent reason present
        $teacher_notes_update = mysqli_real_escape_string($conn, $_POST['teacher_notes']);
        $sql_update_notes = "UPDATE attendance_records SET notes = '$teacher_notes_update' WHERE id = $att_id";
        if (mysqli_query($conn, $sql_update_notes)) {
            $message = "Teacher notes updated successfully.";
            $message_type = 'success';
            $res_att = mysqli_query($conn, $sql_att); // Re-fetch attendance
            if ($res_att) $attendance_details = mysqli_fetch_assoc($res_att);
        } else {
            $message = "Error updating teacher notes: " . mysqli_error($conn);
            $message_type = 'error';
        }
    }
}

$available_statuses = ['pending_review', 'approved', 'rejected', 'viewed'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Absence Reason</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding:0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 10px; text-decoration: none; font-weight: bold; }
        .top-nav .user-info { float: right; color: #ddd; font-size: 0.9em; margin-right: 20px; line-height: 2.5em;}
        .top-nav a:hover { text-decoration: underline; }

        .container { width: 70%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }

        .details-section { margin-bottom: 20px; }
        .details-section p { margin: 5px 0; font-size: 1.1em; }
        .details-section strong { color: #555; }
        .reason-text { background-color: #f9f9f9; border: 1px solid #eee; padding: 10px; border-radius: 4px; margin-top:5px; }

        form label { display: block; margin-top: 15px; font-weight: bold; }
        form select, form textarea { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        form textarea { min-height: 80px; }
        form input[type="submit"] { background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; margin-top: 15px; }
        form input[type="submit"]:hover { background-color: #0056b3; }
        .back-link { display:inline-block; margin-bottom:20px; color: #007bff; text-decoration:none; }
        .back-link:hover { text-decoration:underline; }
    </style>
</head>
<body>
    <nav class="top-nav">
        <a href="index.php">Home</a>
        <?php if (in_array(current_user_role(), ['admin', 'teacher'])): ?>
            <a href="students.php">Manage Students</a>
            <a href="attendance.php">Take/View Attendance</a>
        <?php endif; ?>
        <?php if (current_user_role() == 'admin'): ?>
            <a href="settings.php">Settings</a>
            <a href="manage_users.php">Manage Users</a>
        <?php endif; ?>
        <!-- No parent dashboard link here as this is an admin/teacher page -->
        <span class="user-info">Logged in as: <?php echo htmlspecialchars(current_username()); ?> (<?php echo htmlspecialchars(current_user_role()); ?>)</span>
        <a href="logout.php" style="float:right;">Logout</a>
    </nav>

    <div class="container">
        <h1>Manage Absence Reason</h1>
        <a href="attendance.php?attendance_date=<?php echo $attendance_details ? htmlspecialchars($attendance_details['attendance_date']) : date('Y-m-d'); ?>&grade=<?php echo $student_details ? htmlspecialchars($student_details['grade']) : ''; ?>" class="back-link">&laquo; Back to Attendance</a>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($attendance_details && $student_details): ?>
            <div class="details-section">
                <h2>Absence Details</h2>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($student_details['name']); ?> (Roll: <?php echo htmlspecialchars($student_details['roll_number']); ?>, Grade: <?php echo htmlspecialchars($student_details['grade']); ?>)</p>
                <p><strong>Date of Absence:</strong> <?php echo date("D, M j, Y", strtotime($attendance_details['attendance_date'])); ?></p>
            </div>

            <?php if ($reason_details): ?>
                <div class="details-section">
                    <h2>Parent Submitted Reason</h2>
                    <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($reason_details['submitted_by_username']); ?> on <?php echo date("M j, Y, g:i a", strtotime($reason_details['submitted_at'])); ?></p>
                    <p><strong>Current Status:</strong> <span style="font-weight:bold; color: <?php
                        echo $reason_details['status'] == 'approved' ? 'green' : ($reason_details['status'] == 'rejected' ? 'red' : ($reason_details['status'] == 'pending_review' ? 'orange' : 'black'));
                        ?>;"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $reason_details['status']))); ?></span></p>
                    <div class="reason-text">
                        <?php echo nl2br(htmlspecialchars($reason_details['reason_text'])); ?>
                    </div>
                </div>
                <form action="manage_reason.php?reason_id=<?php echo $reason_id; ?>&att_id=<?php echo $att_id; ?>" method="POST">
                    <label for="reason_status">Update Reason Status:</label>
                    <select name="reason_status" id="reason_status">
                        <?php foreach($available_statuses as $status_option): ?>
                            <option value="<?php echo $status_option; ?>" <?php echo ($reason_details['status'] == $status_option) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $status_option))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="teacher_notes">Teacher Notes (for this attendance record):</label>
                    <textarea name="teacher_notes" id="teacher_notes"><?php echo htmlspecialchars($attendance_details['notes'] ?? ''); ?></textarea>

                    <input type="submit" name="update_reason_status" value="Update Status & Teacher Notes">
                </form>
            <?php else: ?>
                 <p class="info">No specific parent-submitted reason found for this absence record (ID: <?php echo $att_id; ?>).</p>
                 <form action="manage_reason.php?att_id=<?php echo $att_id; ?>" method="POST">
                    <label for="teacher_notes">Teacher Notes (for this attendance record):</label>
                    <textarea name="teacher_notes" id="teacher_notes"><?php echo htmlspecialchars($attendance_details['notes'] ?? ''); ?></textarea>
                    <input type="submit" name="update_teacher_notes_only" value="Save Teacher Notes">
                </form>
            <?php endif; ?>

        <?php elseif (!$message) : // If no critical error message already shown ?>
            <p class="error">Could not load absence or student details.</p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php if(isset($conn)) mysqli_close($conn); ?>
