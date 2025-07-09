<?php
include_once 'auth_check.php';
require_login(['parent']);

$page_title = "Parent Dashboard";

include 'config.php';
include 'functions.php';

$parent_user_id = current_user_id();
$linked_students_data = [];
$message = '';
$message_type = '';
$today_date_string = date('Y-m-d');

// Fetch linked students for this parent
$sql_linked_students = "SELECT s.id, s.name, s.roll_number, s.date_of_birth,
                               IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display
                        FROM students s
                        JOIN user_student_links usl ON s.id = usl.student_id
                        JOIN class_sections cs ON s.class_section_id = cs.id
                        JOIN grades g ON cs.grade_id = g.id
                        JOIN divisions d ON cs.division_id = d.id
                        WHERE usl.user_id = $parent_user_id
                        ORDER BY s.name";
$result_linked_students = mysqli_query($conn, $sql_linked_students);

if ($result_linked_students && mysqli_num_rows($result_linked_students) > 0) {
    while ($student = mysqli_fetch_assoc($result_linked_students)) {
        $student_id = $student['id'];

        // Fetch today's attendance status for prominent display
        $today_att_sql = "SELECT ar.is_present, abr.reason_text, abr.status as reason_status
                          FROM attendance_records ar
                          LEFT JOIN absence_reasons abr ON ar.id = abr.attendance_record_id AND abr.submitted_by_user_id = $parent_user_id
                          WHERE ar.student_id = $student_id AND ar.attendance_date = '$today_date_string'";
        $today_att_res = mysqli_query($conn, $today_att_sql);
        $student['todays_attendance'] = null;
        if($today_att_res && mysqli_num_rows($today_att_res) > 0){
            $student['todays_attendance'] = mysqli_fetch_assoc($today_att_res);
        }

        // For each student, fetch their attendance records and any submitted reasons by this parent
        $sql_attendance = "SELECT ar.id as attendance_record_id, ar.attendance_date, ar.is_present, ar.notes as teacher_notes,
                                  abr.id as absence_reason_id, abr.reason_text, abr.status as reason_status
                           FROM attendance_records ar
                           LEFT JOIN absence_reasons abr ON ar.id = abr.attendance_record_id AND abr.submitted_by_user_id = $parent_user_id
                           WHERE ar.student_id = $student_id
                           ORDER BY ar.attendance_date DESC
                           LIMIT 30";

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

// Handle reason submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_reason'])) {
    // ... (existing reason submission logic remains the same) ...
    $attendance_record_id = (int)$_POST['attendance_record_id'];
    $reason_text = mysqli_real_escape_string($conn, trim($_POST['reason_text']));
    $student_id_for_reason = (int)$_POST['student_id_for_reason'];

    if (empty($reason_text)) {
        $message = "Reason text cannot be empty."; $message_type = 'error';
    } elseif ($attendance_record_id <= 0) {
        $message = "Invalid attendance record ID."; $message_type = 'error';
    } else {
        $is_linked = false;
        $student_name_for_msg = "this student";
        foreach($linked_students_data as $student_check) { // Re-check against current data
            if($student_check['id'] == $student_id_for_reason) {
                 $student_name_for_msg = $student_check['name'];
                foreach($student_check['attendance_history'] as $att_hist) { // Check if att_record_id is valid for this student
                    if($att_hist['attendance_record_id'] == $attendance_record_id) {
                        $is_linked = true; break 2;
                    }
                }
                 // If not found in history (e.g. today's not in recent 30), check today's explicit record
                if (!$is_linked && $student_check['todays_attendance'] && isset($student_check['todays_attendance']['attendance_record_id_for_today_if_exists']) && $student_check['todays_attendance']['attendance_record_id_for_today_if_exists'] == $attendance_record_id){
                    $is_linked = true; break;
                }
            }
        }

        if (!$is_linked) {
            $message = "Error: You are not authorized for this attendance record."; $message_type = 'error';
        } else {
            $check_reason_sql = "SELECT id, status FROM absence_reasons WHERE attendance_record_id = $attendance_record_id AND submitted_by_user_id = $parent_user_id";
            $check_reason_result = mysqli_query($conn, $check_reason_sql);

            if ($check_reason_result && mysqli_num_rows($check_reason_result) > 0) {
                $existing_reason = mysqli_fetch_assoc($check_reason_result);
                // Allow update only if pending review, or if a different policy is set
                if($existing_reason['status'] == 'pending_review' || $existing_reason['status'] == 'rejected') { // Let's allow re-submission if rejected
                    $reason_id_to_update = $existing_reason['id'];
                    $update_reason_sql = "UPDATE absence_reasons SET reason_text = '$reason_text', status = 'pending_review', submitted_at = NOW()
                                          WHERE id = $reason_id_to_update";
                    if (mysqli_query($conn, $update_reason_sql)) {
                        $message = "Absence reason for $student_name_for_msg updated successfully."; $message_type = 'success';
                    } else { $message = "Error updating reason: " . mysqli_error($conn); $message_type = 'error';}
                } else {
                     $message = "Absence reason for $student_name_for_msg cannot be updated as it's already " . htmlspecialchars($existing_reason['status']) . "."; $message_type = 'info';
                }
            } else {
                $insert_reason_sql = "INSERT INTO absence_reasons (attendance_record_id, submitted_by_user_id, reason_text, status)
                                      VALUES ($attendance_record_id, $parent_user_id, '$reason_text', 'pending_review')";
                if (mysqli_query($conn, $insert_reason_sql)) {
                    $message = "Absence reason for $student_name_for_msg submitted successfully."; $message_type = 'success';
                } else { $message = "Error submitting reason: " . mysqli_error($conn); $message_type = 'error'; }
            }
            // Use GET for passing messages to avoid form resubmission issues on refresh
            header("Location: parent_dashboard.php?student_id_refreshed=" . $student_id_for_reason . "&form_msg=" . urlencode($message) . "&form_msg_type=" . $message_type . "#student-" . $student_id_for_reason);
            exit;
        }
    }
}
if(isset($_GET['form_msg'])){ // Display messages passed from form submission
    $message = htmlspecialchars(urldecode($_GET['form_msg']));
    $message_type = isset($_GET['form_msg_type']) ? htmlspecialchars($_GET['form_msg_type']) : 'info';
}

// Start output buffering
ob_start();
?>

<div class="container-fluid mt-3">
    <div class="row mb-3">
        <div class="col-md-8">
            <h1><?php echo htmlspecialchars($page_title); ?></h1>
            <p>Welcome, <?php echo htmlspecialchars(current_username()); ?>! View your child(ren)'s attendance information below.</p>
        </div>
        <div class="col-md-4 align-self-center">
            <!-- Quick link / Info area -->
             <div class="card">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-envelope-paper-fill me-1"></i> Submit Absence Reason</h5>
                    <p class="card-text small">If your child is marked absent, you can submit a reason next to the specific date in their attendance history below.</p>
                    <!-- <a href="#some-anchor-or-modal" class="btn btn-sm btn-outline-primary">Learn More</a> -->
                </div>
            </div>
        </div>
    </div>


    <?php if ($message && $output_format == 'html'): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : ($message_type == 'success' ? 'success' : ($message_type == 'info' ? 'info' : 'secondary')); ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($linked_students_data)): ?>
        <?php foreach ($linked_students_data as $student): ?>
            <div class="card student-section mb-4" id="student-<?php echo $student['id']; ?>">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h2 class="h4 mb-0">
                        <i class="bi bi-person-fill me-2"></i><?php echo htmlspecialchars($student['name']); ?>
                        <small class="text-muted fs-6">(Roll: <?php echo htmlspecialchars($student['roll_number']); ?>, <?php echo htmlspecialchars($student['class_section_display']); ?>)</small>
                    </h2>
                    <div>
                        <?php if ($student['todays_attendance']): ?>
                            <?php if ($student['todays_attendance']['is_present']): ?>
                                <span class="badge bg-success fs-6">Today: Present</span>
                            <?php else: ?>
                                <span class="badge bg-danger fs-6">Today: ABSENT</span>
                                <?php if (!empty($student['todays_attendance']['reason_text'])): ?>
                                     <small class="ms-2 text-muted fst-italic">(Reason: <?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$student['todays_attendance']['reason_status']))); ?>)</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark fs-6">Today: Not Marked Yet</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-12">
                             <h5 class="h6"><i class="bi bi-calendar3 me-1"></i> Recent Attendance History (Last 30 Records)</h5>
                            <?php if (!empty($student['attendance_history'])): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover attendance-history">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Day</th>
                                                <th>Status</th>
                                                <th>Teacher Notes</th>
                                                <th>Your Submitted Reason</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($student['attendance_history'] as $record): ?>
                                                <tr>
                                                    <td><?php echo date("M j, Y", strtotime($record['attendance_date'])); ?></td>
                                                    <td><?php echo date("D", strtotime($record['attendance_date'])); ?></td>
                                                    <td class="<?php echo $record['is_present'] ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                                                        <?php echo $record['is_present'] ? 'Present' : 'ABSENT'; ?>
                                                    </td>
                                                    <td><?php echo !empty($record['teacher_notes']) ? nl2br(htmlspecialchars($record['teacher_notes'])) : '-'; ?></td>
                                                    <td>
                                                        <?php if ($record['is_present'] == 0): ?>
                                                            <?php if (!empty($record['reason_text'])): ?>
                                                                <div class="submitted-reason p-2 rounded bg-light border-start border-4 reason-<?php echo strtolower(htmlspecialchars($record['reason_status'])); ?>">
                                                                    <small><strong>Status: <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $record['reason_status']))); ?></strong><br>
                                                                    <?php echo nl2br(htmlspecialchars($record['reason_text'])); ?></small>
                                                                    <?php if ($record['reason_status'] == 'pending_review' || $record['reason_status'] == 'rejected'): ?>
                                                                        <button class="btn btn-sm btn-link py-0 px-1 edit-reason-btn" data-bs-toggle="collapse" data-bs-target="#editReasonForm_<?php echo $record['attendance_record_id']; ?>" aria-expanded="false" aria-controls="editReasonForm_<?php echo $record['attendance_record_id']; ?>">Edit</button>
                                                                        <div class="collapse mt-2" id="editReasonForm_<?php echo $record['attendance_record_id']; ?>">
                                                                            <form action="parent_dashboard.php#student-<?php echo $student['id']; ?>" method="POST" class="reason-form">
                                                                                <input type="hidden" name="attendance_record_id" value="<?php echo $record['attendance_record_id']; ?>">
                                                                                <input type="hidden" name="student_id_for_reason" value="<?php echo $student['id']; ?>">
                                                                                <textarea name="reason_text" class="form-control form-control-sm" required><?php echo htmlspecialchars($record['reason_text']); ?></textarea>
                                                                                <button type="submit" name="submit_reason" class="btn btn-sm btn-primary mt-1">Update Reason</button>
                                                                            </form>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php else: ?>
                                                                <form action="parent_dashboard.php#student-<?php echo $student['id']; ?>" method="POST" class="reason-form">
                                                                    <input type="hidden" name="attendance_record_id" value="<?php echo $record['attendance_record_id']; ?>">
                                                                    <input type="hidden" name="student_id_for_reason" value="<?php echo $student['id']; ?>">
                                                                    <textarea name="reason_text" class="form-control form-control-sm" placeholder="Enter reason for absence..." required></textarea>
                                                                    <button type="submit" name="submit_reason" class="btn btn-sm btn-success mt-1">Submit Reason</button>
                                                                </form>
                                                            <?php endif; ?>
                                                        <?php else: echo '-'; endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted">No recent attendance records found for this student.</p>
                            <?php endif; ?>
                        </div>
                         <!-- Placeholder for SMS/Email Warnings -->
                        <div class="col-md-12 mt-3">
                            <div class="card border-warning">
                                <div class="card-header bg-warning text-dark">
                                   <i class="bi bi-exclamation-triangle-fill me-1"></i> Recent Notifications/Warnings (Placeholder)
                                </div>
                                <div class="card-body">
                                    <p class="card-text small text-muted"><em>This section will show a log of recent SMS/Email warnings sent regarding this child's attendance, if this feature is implemented in the future.</em></p>
                                    <ul>
                                        <li class="small text-muted">Example: Email sent on <?php echo date("M d, Y", strtotime("-1 day")); ?> - Multiple Absences Alert</li>
                                        <li class="small text-muted">Example: SMS sent on <?php echo date("M d, Y", strtotime("-3 days")); ?> - Single Day Absence</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div> <!-- card-body end -->
            </div> <!-- student-section card end -->
        <?php endforeach; ?>
    <?php elseif (empty($message)) : ?>
        <p class="alert alert-info">No student data to display. If you are expecting to see your child's information, please contact the school administration to ensure your account is correctly linked.</p>
    <?php endif; ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
