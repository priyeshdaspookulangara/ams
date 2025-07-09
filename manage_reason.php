<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

$page_title = "Manage Absence Reason";

include 'config.php';
// include 'functions.php'; // Already included by auth_check.php if it includes functions.php, or ensure here.
// functions.php is not strictly needed by this page directly unless for get_settings etc.

$message = '';
$message_type = '';
$reason_details = null;
$attendance_details = null;
$student_details = null; // Will contain name, roll, grade
$back_link_params = []; // For constructing back link

$reason_id = isset($_GET['reason_id']) ? (int)$_GET['reason_id'] : 0;
$att_id = isset($_GET['att_id']) ? (int)$_GET['att_id'] : 0;

if ($reason_id <= 0 && $att_id <= 0) {
    $message = "No reason ID or attendance ID provided."; $message_type = 'error';
} else {
    if ($reason_id > 0) {
        $sql_reason = "SELECT abr.*, u.username as submitted_by_username
                       FROM absence_reasons abr
                       JOIN users u ON abr.submitted_by_user_id = u.id
                       WHERE abr.id = $reason_id";
        $res_reason = mysqli_query($conn, $sql_reason);
        if ($res_reason && mysqli_num_rows($res_reason) > 0) {
            $reason_details = mysqli_fetch_assoc($res_reason);
            $att_id = $reason_details['attendance_record_id'];
        } else { $message = "Absence reason not found."; $message_type = 'error'; $reason_id = 0; }
    }

    if ($att_id > 0) {
        $sql_att = "SELECT ar.*, s.name as student_name, s.roll_number, s.class_section_id,
                           IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display
                    FROM attendance_records ar
                    JOIN students s ON ar.student_id = s.id
                    JOIN class_sections cs ON s.class_section_id = cs.id
                    JOIN grades g ON cs.grade_id = g.id
                    JOIN divisions d ON cs.division_id = d.id
                    WHERE ar.id = $att_id";
        $res_att = mysqli_query($conn, $sql_att);
        if ($res_att && mysqli_num_rows($res_att) > 0) {
            $attendance_details = mysqli_fetch_assoc($res_att);
            $student_details = [
                'name' => $attendance_details['student_name'],
                'roll_number' => $attendance_details['roll_number'],
                'class_section_display' => $attendance_details['class_section_display']
            ];
            $back_link_params = ['attendance_date' => $attendance_details['attendance_date'], 'class_section_id' => $attendance_details['class_section_id'], 'fetch_students'=>1];

            // Permission Check: Can current user manage this student's section?
            $can_manage_this_section = false;
            if(current_user_role() == 'admin') $can_manage_this_section = true;
            elseif(current_user_role() == 'teacher'){
                if(is_class_teacher_of_section(current_user_id(), $attendance_details['class_section_id'], $conn) ||
                   has_delegated_permission(current_user_id(), $attendance_details['class_section_id'], 'can_take_attendance', $conn)){ // Assuming attendance rights means can manage reason
                    $can_manage_this_section = true;
                }
            }
            if(!$can_manage_this_section && empty($message)){ // Only set error if no prior error
                 $message = "You do not have permission to manage reasons for this student's class section."; $message_type = 'error';
                 $attendance_details = null; // Prevent display/action
            }


            if (!$reason_details && $reason_id == 0 && $attendance_details){ // If reason_id was not passed or invalid, try to find one
                $sql_find_reason = "SELECT abr.*, u.username as submitted_by_username
                                    FROM absence_reasons abr JOIN users u ON abr.submitted_by_user_id = u.id
                                    WHERE abr.attendance_record_id = $att_id ORDER BY abr.submitted_at DESC LIMIT 1";
                $res_find_reason = mysqli_query($conn, $sql_find_reason);
                if($res_find_reason && mysqli_num_rows($res_find_reason) > 0){
                    $reason_details = mysqli_fetch_assoc($res_find_reason); $reason_id = $reason_details['id'];
                }
            }
        } else { if(empty($message)) {$message = "Attendance record not found."; $message_type = 'error';} $att_id = 0;}
    } else if ($reason_id > 0 && !$att_id && empty($message)) {
         $message = "Attendance record linked to the reason not found."; $message_type = 'error';
    }

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && $attendance_details /* Ensure we have context and permission */) {
        if (isset($_POST['update_reason_status']) && $reason_id > 0) {
            $new_status = mysqli_real_escape_string($conn, $_POST['reason_status']);
            $teacher_notes_update = mysqli_real_escape_string($conn, trim($_POST['teacher_notes']));

            $sql_update_status = "UPDATE absence_reasons SET status = '$new_status' WHERE id = $reason_id";
            $update_status_success = mysqli_query($conn, $sql_update_status);

            $sql_update_notes = "UPDATE attendance_records SET notes = '$teacher_notes_update' WHERE id = $att_id";
            $update_notes_success = mysqli_query($conn, $sql_update_notes);

            if ($update_status_success && $update_notes_success) {
                $message = "Reason status and teacher notes updated successfully."; $message_type = 'success';
                // Re-fetch details after update
                if ($reason_id > 0) { $res_reason_refetch = mysqli_query($conn, $sql_reason); if ($res_reason_refetch) $reason_details = mysqli_fetch_assoc($res_reason_refetch); }
                if ($att_id > 0) { $res_att_refetch = mysqli_query($conn, $sql_att); if ($res_att_refetch) $attendance_details = mysqli_fetch_assoc($res_att_refetch); }
            } else {
                $message = "Error updating: ";
                if (!$update_status_success) $message .= "Reason status error: " . mysqli_error($conn) . " ";
                if (!$update_notes_success) $message .= "Teacher notes error: " . mysqli_error($conn);
                $message_type = 'error';
            }
        } elseif (isset($_POST['update_teacher_notes_only']) && $att_id > 0) {
            $teacher_notes_update = mysqli_real_escape_string($conn, trim($_POST['teacher_notes']));
            $sql_update_notes = "UPDATE attendance_records SET notes = '$teacher_notes_update' WHERE id = $att_id";
            if (mysqli_query($conn, $sql_update_notes)) {
                $message = "Teacher notes updated successfully."; $message_type = 'success';
                if ($att_id > 0) { $res_att_refetch = mysqli_query($conn, $sql_att); if ($res_att_refetch) $attendance_details = mysqli_fetch_assoc($res_att_refetch); }
            } else { $message = "Error updating teacher notes: " . mysqli_error($conn); $message_type = 'error'; }
        }
    }
}
$available_statuses = ['pending_review', 'approved', 'rejected', 'viewed'];

ob_start();
?>

<div class="container-fluid mt-3">
    <h1><?php echo htmlspecialchars($page_title); ?></h1>
    <a href="attendance.php?<?php echo http_build_query($back_link_params); ?>" class="btn btn-sm btn-outline-secondary mb-3">
        <i class="bi bi-arrow-left"></i> Back to Attendance
    </a>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : ($message_type == 'success' ? 'success' : 'info'); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($attendance_details && $student_details): ?>
        <div class="card mb-3">
            <div class="card-header">Absence Details</div>
            <div class="card-body">
                <p><strong>Student:</strong> <?php echo htmlspecialchars($student_details['name']); ?> (Roll: <?php echo htmlspecialchars($student_details['roll_number']); ?>)</p>
                <p><strong>Class Section:</strong> <?php echo htmlspecialchars($student_details['class_section_display']); ?></p>
                <p><strong>Date of Absence:</strong> <?php echo date("D, M j, Y", strtotime($attendance_details['attendance_date'])); ?></p>
                <p><strong>Marked As:</strong> <?php echo $attendance_details['is_present'] ? '<span class="badge bg-success">Present</span>' : '<span class="badge bg-danger">Absent</span>'; ?></p>
            </div>
        </div>

        <?php if ($reason_details): ?>
            <div class="card mb-3">
                <div class="card-header">Parent Submitted Reason</div>
                <div class="card-body">
                    <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($reason_details['submitted_by_username']); ?> on <?php echo date("M j, Y, g:i a", strtotime($reason_details['submitted_at'])); ?></p>
                    <p><strong>Current Status:</strong>
                        <span class="fw-bold text-<?php
                            echo $reason_details['status'] == 'approved' ? 'success' : ($reason_details['status'] == 'rejected' ? 'danger' : ($reason_details['status'] == 'pending_review' ? 'warning' : 'secondary'));
                        ?>">
                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $reason_details['status']))); ?>
                        </span>
                    </p>
                    <div class="p-2 border rounded bg-light">
                        <?php echo nl2br(htmlspecialchars($reason_details['reason_text'])); ?>
                    </div>
                </div>
            </div>
            <form action="manage_reason.php?reason_id=<?php echo $reason_id; ?>&att_id=<?php echo $att_id; ?>" method="POST" class="card p-3">
                <div class="mb-3">
                    <label for="reason_status" class="form-label">Update Reason Status:</label>
                    <select name="reason_status" id="reason_status" class="form-select">
                        <?php foreach($available_statuses as $status_option): ?>
                            <option value="<?php echo $status_option; ?>" <?php echo ($reason_details['status'] == $status_option) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $status_option))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="teacher_notes" class="form-label">Teacher Notes (for this attendance record):</label>
                    <textarea name="teacher_notes" id="teacher_notes" class="form-control" rows="3"><?php echo htmlspecialchars($attendance_details['notes'] ?? ''); ?></textarea>
                </div>
                <button type="submit" name="update_reason_status" class="btn btn-primary">Update Status & Teacher Notes</button>
            </form>
        <?php elseif ($attendance_details && !$attendance_details['is_present']): // Absent but no parent reason found ?>
             <p class="alert alert-info">No parent-submitted reason found for this absence record (ID: <?php echo $att_id; ?>).</p>
             <form action="manage_reason.php?att_id=<?php echo $att_id; ?>" method="POST" class="card p-3">
                <div class="mb-3">
                    <label for="teacher_notes" class="form-label">Teacher Notes (for this attendance record):</label>
                    <textarea name="teacher_notes" id="teacher_notes" class="form-control" rows="3"><?php echo htmlspecialchars($attendance_details['notes'] ?? ''); ?></textarea>
                </div>
                <button type="submit" name="update_teacher_notes_only" class="btn btn-primary">Save Teacher Notes</button>
            </form>
        <?php elseif ($attendance_details && $attendance_details['is_present']): ?>
             <p class="alert alert-success">Student was marked PRESENT on this day. No reason management applicable.</p>
        <?php endif; ?>

    <?php elseif (!$message && ($reason_id > 0 || $att_id > 0) ) : ?>
        <p class="alert alert-warning">Could not load full absence or student details. The record might be incomplete or an error occurred.</p>
    <?php endif; ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
