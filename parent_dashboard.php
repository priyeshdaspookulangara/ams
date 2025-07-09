<?php
include_once 'auth_check.php';
require_login(['parent']);

$page_title = "Parent Dashboard";

include 'config.php';
include 'functions.php';

$parent_user_id = current_user_id();
$linked_students_data = [];
$message = ''; // For general page messages
$message_type = '';
$form_submission_message = ''; // For messages specific to form submissions (passed via GET)
$form_submission_message_type = '';
$today_date_string = date('Y-m-d');

if(isset($_GET['form_msg'])){
    $form_submission_message = htmlspecialchars(urldecode($_GET['form_msg']));
    $form_submission_message_type = isset($_GET['form_msg_type']) ? htmlspecialchars($_GET['form_msg_type']) : 'info';
}

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
        $student_att_record_id_today = null; // To store att_record_id for today if student is absent

        $today_att_sql = "SELECT ar.id as attendance_record_id, ar.is_present, abr.reason_text, abr.status as reason_status
                          FROM attendance_records ar
                          LEFT JOIN absence_reasons abr ON ar.id = abr.attendance_record_id AND abr.submitted_by_user_id = $parent_user_id
                          WHERE ar.student_id = $student_id AND ar.attendance_date = '$today_date_string'";
        $today_att_res = mysqli_query($conn, $today_att_sql);
        $student['todays_attendance'] = null;
        if($today_att_res && mysqli_num_rows($today_att_res) > 0){
            $student['todays_attendance'] = mysqli_fetch_assoc($today_att_res);
            if($student['todays_attendance']['is_present'] == 0){ // If absent today
                $student_att_record_id_today = $student['todays_attendance']['attendance_record_id'];
            }
        }
        // Store the att_record_id for today's absence to use in form if needed
        $student['attendance_record_id_for_today_if_absent'] = $student_att_record_id_today;


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

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_reason'])) {
    $attendance_record_id = (int)$_POST['attendance_record_id'];
    $reason_text = mysqli_real_escape_string($conn, trim($_POST['reason_text']));
    $student_id_for_reason = (int)$_POST['student_id_for_reason'];
    $student_name_for_msg = "this student"; // Default

    // Find student name for message
    foreach($linked_students_data as $s_check) if($s_check['id'] == $student_id_for_reason) $student_name_for_msg = $s_check['name'];

    if (empty($reason_text)) {
        $form_submission_message = "Reason text cannot be empty."; $form_submission_message_type = 'error';
    } elseif ($attendance_record_id <= 0) {
        $form_submission_message = "Invalid attendance record ID."; $form_submission_message_type = 'error';
    } else {
        $is_authorized_for_record = false;
        // Check if attendance_record_id belongs to one of the parent's linked students
        $auth_sql = "SELECT s.id FROM students s JOIN attendance_records ar ON s.id = ar.student_id JOIN user_student_links usl ON s.id = usl.student_id WHERE ar.id = $attendance_record_id AND usl.user_id = $parent_user_id";
        $auth_res = mysqli_query($conn, $auth_sql);
        if($auth_res && mysqli_num_rows($auth_res) > 0) $is_authorized_for_record = true;

        if (!$is_authorized_for_record) {
            $form_submission_message = "Error: You are not authorized for this attendance record."; $form_submission_message_type = 'error';
        } else {
            $check_reason_sql = "SELECT id, status FROM absence_reasons WHERE attendance_record_id = $attendance_record_id AND submitted_by_user_id = $parent_user_id";
            $check_reason_result = mysqli_query($conn, $check_reason_sql);

            if ($check_reason_result && mysqli_num_rows($check_reason_result) > 0) {
                $existing_reason = mysqli_fetch_assoc($check_reason_result);
                if($existing_reason['status'] == 'pending_review' || $existing_reason['status'] == 'rejected') {
                    $reason_id_to_update = $existing_reason['id'];
                    $update_reason_sql = "UPDATE absence_reasons SET reason_text = '$reason_text', status = 'pending_review', submitted_at = NOW() WHERE id = $reason_id_to_update";
                    if (mysqli_query($conn, $update_reason_sql)) {
                        $form_submission_message = "Absence reason for $student_name_for_msg updated successfully."; $form_submission_message_type = 'success';
                    } else { $form_submission_message = "Error updating reason: " . mysqli_error($conn); $form_submission_message_type = 'error';}
                } else {
                     $form_submission_message = "Absence reason for $student_name_for_msg cannot be updated as it's already " . htmlspecialchars($existing_reason['status']) . "."; $form_submission_message_type = 'info';
                }
            } else {
                $insert_reason_sql = "INSERT INTO absence_reasons (attendance_record_id, submitted_by_user_id, reason_text, status)
                                      VALUES ($attendance_record_id, $parent_user_id, '$reason_text', 'pending_review')";
                if (mysqli_query($conn, $insert_reason_sql)) {
                    $form_submission_message = "Absence reason for $student_name_for_msg submitted successfully."; $form_submission_message_type = 'success';
                } else { $form_submission_message = "Error submitting reason: " . mysqli_error($conn); $form_submission_message_type = 'error'; }
            }
            header("Location: parent_dashboard.php?form_msg=" . urlencode($form_submission_message) . "&form_msg_type=" . $form_submission_message_type . "#student-" . $student_id_for_reason);
            exit;
        }
    }
     // If errors occurred before redirect, set them to be displayed on current page load
    if(!empty($form_submission_message)){
        $message = $form_submission_message;
        $message_type = $form_submission_message_type;
    }
}

ob_start();
?>

<div class="container-fluid mt-3">
    <div class="row mb-3">
        <div class="col-md-8">
            <h1 class="display-5"><?php echo htmlspecialchars($page_title); ?></h1>
            <p class="lead">Welcome, <?php echo htmlspecialchars(current_username()); ?>! View your child(ren)'s attendance information below.</p>
        </div>
        <div class="col-md-4 align-self-center">
             <div class="card bg-light">
                <div class="card-body text-center">
                    <h5 class="card-title text-primary"><i class="bi bi-envelope-paper-fill me-1"></i> Submit Absence Reason</h5>
                    <p class="card-text small text-muted">If your child is marked absent, you can submit a reason next to the specific date in their attendance history below.</p>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : ($message_type == 'success' ? 'success' : ($message_type == 'info' ? 'info' : 'secondary')); ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($linked_students_data)): ?>
        <?php foreach ($linked_students_data as $student): ?>
            <div class="card student-section mb-4 shadow-sm" id="student-<?php echo $student['id']; ?>">
                <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center p-3">
                    <h2 class="h4 mb-0 text-primary">
                        <i class="bi bi-person-fill me-2"></i><?php echo htmlspecialchars($student['name']); ?>
                        <small class="text-muted fs-6 d-block d-sm-inline">(Roll: <?php echo htmlspecialchars($student['roll_number']); ?>, <?php echo htmlspecialchars($student['class_section_display']); ?>)</small>
                    </h2>
                    <div>
                        <?php if ($student['todays_attendance']): ?>
                            <?php if ($student['todays_attendance']['is_present']): ?>
                                <span class="badge bg-success fs-6"><i class="bi bi-check-circle-fill me-1"></i>Today: Present</span>
                            <?php else: ?>
                                <span class="badge bg-danger fs-6"><i class="bi bi-x-circle-fill me-1"></i>Today: ABSENT</span>
                                <?php if (!empty($student['todays_attendance']['reason_text'])): ?>
                                     <small class="ms-2 text-muted fst-italic">(Your reason: <?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$student['todays_attendance']['reason_status']))); ?>)</small>
                                <?php elseif ($student['attendance_record_id_for_today_if_absent']): // If absent today and no reason from THIS parent, offer to add ?>
                                     <button class="btn btn-sm btn-outline-warning py-0 px-1 ms-2" data-bs-toggle="collapse" data-bs-target="#reasonFormToday_<?php echo $student['attendance_record_id_for_today_if_absent']; ?>" style="font-size:0.8em;">Add Reason for Today</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark fs-6"><i class="bi bi-hourglass-split me-1"></i>Today: Not Marked Yet</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($student['todays_attendance'] && !$student['todays_attendance']['is_present'] && !$student['todays_attendance']['reason_text'] && $student['attendance_record_id_for_today_if_absent']): ?>
                        <div class="collapse mb-3" id="reasonFormToday_<?php echo $student['attendance_record_id_for_today_if_absent']; ?>">
                            <form action="parent_dashboard.php#student-<?php echo $student['id']; ?>" method="POST" class="reason-form p-2 border rounded bg-light-subtle">
                                <input type="hidden" name="attendance_record_id" value="<?php echo $student['attendance_record_id_for_today_if_absent']; ?>">
                                <input type="hidden" name="student_id_for_reason" value="<?php echo $student['id']; ?>">
                                <div class="mb-2">
                                <label for="reason_text_today_<?php echo $student['attendance_record_id_for_today_if_absent']; ?>" class="form-label small">Reason for today's (<?php echo date("M j");?>) absence:</label>
                                <textarea name="reason_text" id="reason_text_today_<?php echo $student['attendance_record_id_for_today_if_absent']; ?>" class="form-control form-control-sm" placeholder="Enter reason..." required></textarea>
                                </div>
                                <button type="submit" name="submit_reason" class="btn btn-sm btn-success">Submit Reason</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <h5 class="h6 text-muted"><i class="bi bi-calendar3 me-1"></i> Recent Attendance History</h5>
                    <?php if (!empty($student['attendance_history'])): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover attendance-history small">
                                <thead class="table-light">
                                    <tr><th>Date</th><th>Day</th><th>Status</th><th>Teacher Notes</th><th>Your Submitted Reason</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($student['attendance_history'] as $record): ?>
                                        <tr>
                                            <td><?php echo date("M j, Y", strtotime($record['attendance_date'])); ?></td>
                                            <td><?php echo date("D", strtotime($record['attendance_date'])); ?></td>
                                            <td class="<?php echo $record['is_present'] ? 'text-success' : 'text-danger'; ?> fw-bold"><?php echo $record['is_present'] ? 'Present' : 'ABSENT'; ?></td>
                                            <td><?php echo !empty($record['teacher_notes']) ? nl2br(htmlspecialchars($record['teacher_notes'])) : '-'; ?></td>
                                            <td>
                                                <?php if ($record['is_present'] == 0): ?>
                                                    <?php if (!empty($record['reason_text'])): ?>
                                                        <div class="submitted-reason p-1 rounded bg-light-subtle border-start border-4 reason-<?php echo strtolower(htmlspecialchars($record['reason_status'])); ?>">
                                                            <small><strong><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $record['reason_status']))); ?>:</strong><br><?php echo nl2br(htmlspecialchars($record['reason_text'])); ?></small>
                                                            <?php if ($record['reason_status'] == 'pending_review' || $record['reason_status'] == 'rejected'): ?>
                                                                <button class="btn btn-link py-0 px-1 edit-reason-btn" style="font-size:0.8em;" data-bs-toggle="collapse" data-bs-target="#editReasonForm_<?php echo $record['attendance_record_id']; ?>">Edit</button>
                                                                <div class="collapse mt-1" id="editReasonForm_<?php echo $record['attendance_record_id']; ?>">
                                                                    <form action="parent_dashboard.php#student-<?php echo $student['id']; ?>" method="POST" class="reason-form">
                                                                        <input type="hidden" name="attendance_record_id" value="<?php echo $record['attendance_record_id']; ?>">
                                                                        <input type="hidden" name="student_id_for_reason" value="<?php echo $student['id']; ?>">
                                                                        <textarea name="reason_text" class="form-control form-control-sm" required><?php echo htmlspecialchars($record['reason_text']); ?></textarea>
                                                                        <button type="submit" name="submit_reason" class="btn btn-sm btn-primary mt-1">Update</button>
                                                                    </form>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <form action="parent_dashboard.php#student-<?php echo $student['id']; ?>" method="POST" class="reason-form">
                                                            <input type="hidden" name="attendance_record_id" value="<?php echo $record['attendance_record_id']; ?>">
                                                            <input type="hidden" name="student_id_for_reason" value="<?php echo $student['id']; ?>">
                                                            <textarea name="reason_text" class="form-control form-control-sm" placeholder="Submit reason..." required></textarea>
                                                            <button type="submit" name="submit_reason" class="btn btn-sm btn-success mt-1 py-0 px-1" style="font-size:0.8em;">Submit</button>
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
                        <p class="text-muted fst-italic">No recent attendance records found.</p>
                    <?php endif; ?>
                    <div class="mt-3">
                        <div class="card border-warning">
                            <div class="card-header bg-warning-subtle text-dark-emphasis small py-2"><i class="bi bi-exclamation-triangle-fill me-1"></i> Recent Notifications/Warnings (Placeholder)</div>
                            <div class="card-body py-2"><p class="card-text small text-muted mb-0"><em>This section will show a log of recent SMS/Email warnings sent regarding this child's attendance. (Future Feature)</em></p></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php elseif (empty($message)) : ?>
        <div class="alert alert-info"><i class="bi bi-info-circle-fill me-2"></i>No student data to display. If you are expecting to see your child's information, please contact the school administration to ensure your account is correctly linked.</div>
    <?php endif; ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
