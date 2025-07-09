<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

$page_title = "Student Individual Attendance Record";

include 'config.php';
include 'functions.php';

$current_user_id = current_user_id();
$current_role = current_user_role();

$output_format = (isset($_GET['output']) && $_GET['output'] == 'pdf' && isset($_GET['view_report'])) ? 'pdf' : 'html';
$selected_class_section_id = isset($_GET['class_section_id']) ? (int)$_GET['class_section_id'] : 0;
$selected_student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

$report_data_list = []; // Changed name to avoid conflict with $report_data in some scopes
$student_info = null;
$summary_stats = ['total_school_days' => 0, 'total_present' => 0, 'total_absent' => 0, 'attendance_percentage' => 0];
$message = '';
$message_type = '';
$report_html_content = ''; // For PDF and HTML display

// Fetch class sections for the first dropdown
$class_sections_for_dropdown = [];
$cs_sql = "";
if ($current_role == 'admin') {
    $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
} else {
    $accessible_section_ids = get_teacher_attendance_accessible_sections($current_user_id, $conn);
    if (!empty($accessible_section_ids)) {
        $ids_str = implode(',', array_map('intval', $accessible_section_ids));
        $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id WHERE cs.id IN ($ids_str) ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
    }
}
if (!empty($cs_sql)) {
    $cs_res = mysqli_query($conn, $cs_sql);
    if ($cs_res) while ($row = mysqli_fetch_assoc($cs_res)) $class_sections_for_dropdown[] = $row;
}

// Fetch students for the selected class section
$students_for_dropdown = [];
if ($selected_class_section_id > 0) {
    $can_access_selected_section = false; // Verify current user can access this section
    if($current_role == 'admin') $can_access_selected_section = true;
    else { foreach($class_sections_for_dropdown as $cs_option){ if($cs_option['id'] == $selected_class_section_id) {$can_access_selected_section = true; break;} } }

    if($can_access_selected_section){
        $student_sql = "SELECT id, name, roll_number FROM students WHERE class_section_id = $selected_class_section_id ORDER BY name";
        $student_res = mysqli_query($conn, $student_sql);
        if ($student_res) while ($row = mysqli_fetch_assoc($student_res)) $students_for_dropdown[] = $row;
    } else { if(empty($message)) {$message = "No permission for selected section."; $message_type="error";} $selected_class_section_id = 0;}
}


if ( (isset($_GET['view_report']) || $output_format == 'pdf') && $selected_student_id > 0 && !empty($start_date) && !empty($end_date)) {
    if (strtotime($end_date) < strtotime($start_date)) {
        $message = "End date cannot be before start date."; $message_type = 'error';
    } else {
        $can_view_student_report = false;
        if ($current_role == 'admin') $can_view_student_report = true;
        elseif ($current_role == 'teacher') {
            $student_section_q = mysqli_query($conn, "SELECT class_section_id FROM students WHERE id = $selected_student_id");
            if ($student_section_q && mysqli_num_rows($student_section_q) > 0) {
                $student_actual_cs_id = mysqli_fetch_assoc($student_section_q)['class_section_id'];
                if (is_class_teacher_of_section($current_user_id, $student_actual_cs_id, $conn) ||
                    has_delegated_permission($current_user_id, $student_actual_cs_id, 'can_take_attendance', $conn)) {
                    $can_view_student_report = true;
                }
            }
        }

        if ($can_view_student_report) {
            $student_info_sql = "SELECT s.name as student_name, s.roll_number, s.date_of_birth, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display FROM students s JOIN class_sections cs ON s.class_section_id = cs.id JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id WHERE s.id = $selected_student_id";
            $student_info_res = mysqli_query($conn, $student_info_sql);
            if ($student_info_res && mysqli_num_rows($student_info_res) > 0) {
                $student_info = mysqli_fetch_assoc($student_info_res);
                $current_loop_date = new DateTime($start_date); $end_loop_date = new DateTime($end_date);
                $school_days_in_period = 0;
                while ($current_loop_date <= $end_loop_date) {
                    $date_str = $current_loop_date->format('Y-m-d'); $day_of_week = $current_loop_date->format('N');
                    // TODO: Implement actual school working days check if a calendar exists. For now, counting all days in range.
                    $school_days_in_period++;
                    $att_sql = "SELECT ar.is_present, ar.notes as teacher_notes, abr.reason_text as parent_reason, abr.status as reason_status, u.username as reason_submitter FROM attendance_records ar LEFT JOIN absence_reasons abr ON ar.id = abr.attendance_record_id LEFT JOIN users u ON abr.submitted_by_user_id = u.id WHERE ar.student_id = $selected_student_id AND ar.attendance_date = '$date_str'";
                    $att_res = mysqli_query($conn, $att_sql);
                    $day_data = ['date' => $date_str, 'day_of_week' => $current_loop_date->format('l'), 'status' => 'Not Marked', 'is_present' => null, 'teacher_notes' => '', 'parent_reason' => '', 'reason_status' => '', 'reason_submitter' => ''];
                    if ($att_res && mysqli_num_rows($att_res) > 0) {
                        $att_row = mysqli_fetch_assoc($att_res);
                        $day_data = array_merge($day_data, $att_row); // Merge fetched data
                        $day_data['status'] = $att_row['is_present'] ? 'Present' : 'Absent';
                    }
                    $report_data_list[] = $day_data;
                    if ($day_data['is_present'] === 1) $summary_stats['total_present']++;
                    elseif ($day_data['is_present'] === 0) $summary_stats['total_absent']++;
                    else $summary_stats['total_absent']++; // Count not marked as absent for percentage
                    $current_loop_date->modify('+1 day');
                }
                $summary_stats['total_school_days'] = $school_days_in_period;
                if ($summary_stats['total_school_days'] > 0) $summary_stats['attendance_percentage'] = round(($summary_stats['total_present'] / $summary_stats['total_school_days']) * 100, 2);

                // Generate HTML content for the report
                ob_start();
                if($student_info && !empty($report_data_list)){
                ?>
                    <div class="report-header mt-4">
                        <h3>Attendance Record For:</h3>
                        <p><strong>Student:</strong> <?php echo htmlspecialchars($student_info['student_name']); ?> (Roll: <?php echo htmlspecialchars($student_info['roll_number']); ?>)</p>
                        <p><strong>Class Section:</strong> <?php echo htmlspecialchars($student_info['class_section_display']); ?></p>
                        <p><strong>DOB:</strong> <?php echo $student_info['date_of_birth'] ? date("M j, Y", strtotime($student_info['date_of_birth'])) : '-'; ?></p>
                        <p><strong>Period:</strong> <?php echo date("M j, Y", strtotime($start_date)); ?> to <?php echo date("M j, Y", strtotime($end_date)); ?></p>
                    </div>
                    <div class="report-summary-stats alert alert-secondary">
                        <strong>Total Days in Period:</strong> <?php echo $summary_stats['total_school_days']; ?> |
                        <strong>Present:</strong> <?php echo $summary_stats['total_present']; ?> |
                        <strong>Absent/Not Marked:</strong> <?php echo $summary_stats['total_absent']; ?> |
                        <strong>Attendance:</strong> <?php echo $summary_stats['attendance_percentage']; ?>%
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered report-table">
                            <thead class="table-light"><tr><th>Date</th><th>Day</th><th>Status</th><th>Teacher Notes</th><th>Parent Reason</th></tr></thead>
                            <tbody>
                            <?php foreach ($report_data_list as $record): ?>
                                <tr>
                                    <td><?php echo date("M j, Y", strtotime($record['date'])); ?></td>
                                    <td><?php echo htmlspecialchars($record['day_of_week']); ?></td>
                                    <td class="status-<?php echo str_replace(' ','',htmlspecialchars($record['status'])); ?>"><?php echo htmlspecialchars($record['status']); ?></td>
                                    <td><?php echo !empty($record['teacher_notes']) ? nl2br(htmlspecialchars($record['teacher_notes'])) : '-'; ?></td>
                                    <td>
                                        <?php if ($record['is_present'] === 0 && !empty($record['parent_reason'])): ?>
                                            <small><strong><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$record['reason_status']))); ?>:</strong> <?php echo nl2br(htmlspecialchars($record['parent_reason'])); ?> (By: <?php echo htmlspecialchars($record['reason_submitter'] ?: 'Parent'); ?>)</small>
                                        <?php elseif($record['is_present'] === 0): echo '<small>-</small>'; endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php
                } elseif (isset($_GET['view_report'])) { // No data but report was requested
                     echo '<p class="alert alert-info mt-3">No attendance records found for this student in the selected period.</p>';
                }
                $report_html_content = ob_get_clean();

            } else { if(empty($message)) {$message = "Student details not found."; $message_type = 'error';} }
        } else { if(empty($message)) {$message = "No permission for student's report."; $message_type = 'error';} }
    }
} elseif (isset($_GET['view_report'])) { if(empty($message)) {$message = "Please select all filters."; $message_type = 'error';} }


// --- PDF Output Logic ---
if ($output_format == 'pdf' && empty($message_type == 'error') && $student_info && !empty($report_data_list) ) {
    $pdf_css = "<style> body { font-family: Arial, sans-serif; font-size: 9pt; } table { width: 100%; border-collapse: collapse; margin-top: 10px; } th, td { border: 1px solid #ccc; padding: 3px; text-align: left; vertical-align:top; word-wrap:break-word;} th { background-color: #f0f0f0; font-weight: bold; } .status-Present { color: green; } .status-Absent { color: red; font-weight:bold; } .status-NotMarked {color: orange;} h1,h2,h3,p { margin-bottom: 5px;} .report-header p, .report-summary-stats {font-size:10pt;} h1,h2,h3 {text-align:center;} </style>";
    $full_html_for_pdf = "<html><head><meta charset='UTF-8'>{$pdf_css}</head><body>";
    $full_html_for_pdf .= "<h1>Student Individual Attendance Record</h1>";
    // $report_html_content already contains the header, summary, and table for this specific report.
    $full_html_for_pdf .= $report_html_content;
    $full_html_for_pdf .= "</body></html>";
    generate_and_stream_pdf($full_html_for_pdf, "student_attendance_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $student_info['student_name']), 'P');
}

ob_start();
?>
<div class="container-fluid mt-3">
    <h1><?php echo htmlspecialchars($page_title); ?></h1>
    <?php if ($message && $output_format == 'html'): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : 'info'; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?> <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card p-3 mb-3 bg-light no-print">
        <form action="reports_student_individual_attendance.php" method="GET" id="filterForm">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="class_section_id" class="form-label">Class Section:</label>
                    <select name="class_section_id" id="class_section_id" class="form-select" required onchange="document.getElementById('student_id').value=''; this.form.submit();">
                        <option value="">-- Select Class --</option>
                        <?php foreach ($class_sections_for_dropdown as $cs): ?>
                            <option value="<?php echo $cs['id']; ?>" <?php echo ($selected_class_section_id == $cs['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cs['display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="student_id" class="form-label">Student:</label>
                    <select name="student_id" id="student_id" class="form-select" required <?php if(empty($students_for_dropdown) && $selected_class_section_id > 0) echo "disabled"; else if(empty($selected_class_section_id)) echo "disabled"; ?>>
                        <option value="">-- Select Student --</option>
                        <?php foreach ($students_for_dropdown as $student_opt): ?>
                            <option value="<?php echo $student_opt['id']; ?>" <?php echo ($selected_student_id == $student_opt['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($student_opt['name']) . " (Roll: " . htmlspecialchars($student_opt['roll_number']) . ")"; ?></option>
                        <?php endforeach; ?>
                         <?php if(empty($students_for_dropdown) && $selected_class_section_id > 0): ?>
                            <option value="" disabled selected>No students in section</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="start_date" class="form-label">Start Date:</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" required>
                </div>
                <div class="col-md-2">
                    <label for="end_date" class="form-label">End Date:</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" required>
                </div>
                <div class="col-md-auto">
                    <button type="submit" name="view_report" value="html" class="btn btn-primary">View Report</button>
                </div>
                <div class="col-md-auto">
                    <button type="submit" name="output" value="pdf" formaction="reports_student_individual_attendance.php?<?php echo http_build_query(array_merge($_GET, ['output'=>'pdf', 'view_report'=>'pdf']));?>" class="btn btn-secondary" <?php if(empty($report_data_list) || !$student_info) echo "disabled";?>>Download PDF</button>
                </div>
                 <div class="col-md-auto">
                    <button type="button" onclick="window.print();" class="btn btn-info" <?php if(empty($report_data_list) || !$student_info) echo "disabled";?>>Print HTML</button>
                </div>
            </div>
        </form>
    </div>

    <?php
    if ($output_format == 'html' && isset($_GET['view_report']) && empty($message_type == 'error')) {
        echo $report_html_content; // Display the buffered report HTML
    } elseif ($output_format == 'html' && isset($_GET['view_report']) && !empty($message) && $message_type != 'error' && empty($report_data_list)) {
        // If there was an info message like "no students found" but not a hard error, and report data is empty
        echo '<p class="alert alert-info mt-3">' . htmlspecialchars($message) . '</p>';
    }
    ?>
</div>
<?php
$page_content_html = ob_get_clean();
if(isset($conn) && $output_format == 'html') mysqli_close($conn);
include 'layout_authenticated.php';
?>
