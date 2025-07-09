<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

$page_title = "Daily Attendance Report";

include 'config.php';
include 'functions.php';

$current_user_id = current_user_id();
$current_role = current_user_role();

$output_format = (isset($_GET['output']) && $_GET['output'] == 'pdf' && isset($_GET['view_report'])) ? 'pdf' : 'html';

$selected_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');
$selected_class_section_id = isset($_GET['class_section_id']) ? (int)$_GET['class_section_id'] : 0;

$report_data = [];
$class_section_name = '';
$total_students = 0;
$total_present = 0;
$total_absent = 0;
$message = '';
$message_type = '';

$class_sections_for_dropdown = [];
if ($current_role == 'admin') {
    $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
               FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id
               ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
} else {
    $accessible_section_ids = get_teacher_attendance_accessible_sections($current_user_id, $conn);
    if (!empty($accessible_section_ids)) {
        $ids_str = implode(',', array_map('intval', $accessible_section_ids));
        $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                   FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id
                   WHERE cs.id IN ($ids_str) ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
    } else { $cs_sql = ""; }
}
if (!empty($cs_sql)) {
    $cs_res = mysqli_query($conn, $cs_sql);
    if ($cs_res) while ($row = mysqli_fetch_assoc($cs_res)) $class_sections_for_dropdown[] = $row;
}

// Report Data Generation Logic
$report_html_content = ""; // This will store the core HTML of the report for PDF or display

if ( (isset($_GET['view_report']) || $output_format == 'pdf') && $selected_class_section_id > 0 && !empty($selected_date) ) {
    $can_view_report = false;
    if ($current_role == 'admin') $can_view_report = true;
    elseif ($current_role == 'teacher') {
        if (is_class_teacher_of_section($current_user_id, $selected_class_section_id, $conn) ||
            has_delegated_permission($current_user_id, $selected_class_section_id, 'can_take_attendance', $conn)) {
            $can_view_report = true;
        }
    }

    if ($can_view_report) {
        $section_name_sql = "SELECT IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                             FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id
                             WHERE cs.id = $selected_class_section_id";
        $section_name_res = mysqli_query($conn, $section_name_sql);
        if ($section_name_res && mysqli_num_rows($section_name_res) > 0) {
            $class_section_name = mysqli_fetch_assoc($section_name_res)['display_name'];
        }

        $report_sql = "SELECT s.id as student_id, s.name as student_name, s.roll_number,
                              ar.is_present, ar.notes as teacher_notes,
                              abr.reason_text as parent_reason, abr.status as reason_status, u.username as reason_submitter
                       FROM students s
                       LEFT JOIN attendance_records ar ON s.id = ar.student_id AND ar.attendance_date = '$selected_date'
                       LEFT JOIN absence_reasons abr ON ar.id = abr.attendance_record_id
                       LEFT JOIN users u ON abr.submitted_by_user_id = u.id
                       WHERE s.class_section_id = $selected_class_section_id
                       ORDER BY s.roll_number, s.name";

        $report_res = mysqli_query($conn, $report_sql);
        if ($report_res) {
            $total_students = mysqli_num_rows($report_res);
            while ($row = mysqli_fetch_assoc($report_res)) {
                $report_data[] = $row; // Keep for HTML display logic too
                if (isset($row['is_present'])) {
                    if ($row['is_present'] == 1) $total_present++; else $total_absent++;
                } else { $total_absent++; }
            }
            if($total_students == 0 && empty($message)){ $message = "No students in section."; $message_type = 'info';}

            // Generate HTML for the report content (table and summary)
            ob_start();
            if (!empty($report_data)) {
            ?>
                <h2 class="mt-4">Report for: <?php echo htmlspecialchars($class_section_name); ?> on <?php echo date("D, M j, Y", strtotime($selected_date)); ?></h2>
                <div class="report-summary mt-3 mb-3 p-2 bg-light border rounded">
                    <strong>Total Students:</strong> <?php echo $total_students; ?> |
                    <strong>Present:</strong> <?php echo $total_present; ?> |
                    <strong>Absent / Not Marked:</strong> <?php echo $total_absent; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered report-table">
                        <thead class="table-light">
                            <tr><th>Roll No.</th><th>Student Name</th><th>Status</th><th>Teacher Notes</th><th>Parent Submitted Reason</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report_data as $student): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                <td class="<?php if (!isset($student['is_present'])) echo 'status-notmarked'; elseif ($student['is_present']) echo 'status-present'; else echo 'status-absent';?>">
                                    <?php if (!isset($student['is_present'])) echo 'Not Marked'; elseif ($student['is_present']) echo 'Present'; else echo 'ABSENT'; ?>
                                </td>
                                <td><?php echo !empty($student['teacher_notes']) ? nl2br(htmlspecialchars($student['teacher_notes'])) : '-'; ?></td>
                                <td>
                                    <?php if (!$student['is_present'] && !empty($student['parent_reason'])): ?>
                                        <div class="parent-reason-report"><small>
                                            <strong><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$student['reason_status']))); ?>:</strong>
                                            <?php echo nl2br(htmlspecialchars($student['parent_reason'])); ?>
                                            (By: <?php echo htmlspecialchars($student['reason_submitter'] ?: 'Parent'); ?>) </small>
                                        </div>
                                    <?php elseif (!$student['is_present'] && isset($student['is_present'])) : echo '<small>-</small>'; endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php
            } elseif(isset($_GET['view_report'])) { // Only show "no absentees" if actively viewed, not just for PDF check
                 echo '<p class="alert alert-info mt-3">No attendance data or students found for the selected criteria.</p>';
            }
            $report_html_content = ob_get_clean();

        } else { $message = "Error generating report data: " . mysqli_error($conn); $message_type = 'error'; }
    } else { $message = "No permission for class section."; $message_type = 'error'; }
} elseif (isset($_GET['view_report'])) {
    $message = "Please select date and class section."; $message_type = 'error';
}


// --- PDF Output Logic ---
if ($output_format == 'pdf' && empty($message_type == 'error') && !empty($report_data) ) {
    $pdf_title = "<h1>Daily Attendance Report</h1>";
    $pdf_title .= "<h2>For: " . htmlspecialchars($class_section_name) . " on " . date("D, M j, Y", strtotime($selected_date)) . "</h2>";

    $pdf_css = "<style> body { font-family: Arial, sans-serif; font-size: 9pt; } table { width: 100%; border-collapse: collapse; margin-top: 10px; } th, td { border: 1px solid #ccc; padding: 3px; text-align: left; vertical-align:top; word-wrap:break-word;} th { background-color: #f0f0f0; font-weight: bold; } .status-present { color: green; } .status-absent { color: red; font-weight:bold; } .status-notmarked {color: orange;} .parent-reason-report { font-size: 0.85em; color: #444; } .report-summary { margin-bottom: 10px; font-size:10pt; } h1,h2 {text-align:center; margin-bottom:5px;} </style>";

    $full_html_for_pdf = "<html><head><meta charset='UTF-8'>{$pdf_css}</head><body>";
    $full_html_for_pdf .= $pdf_title;
    // The $report_html_content already contains the summary and table.
    $full_html_for_pdf .= $report_html_content; // This contains the summary and table.
    $full_html_for_pdf .= "</body></html>";

    generate_and_stream_pdf($full_html_for_pdf, "daily_attendance_" . str_replace(' ', '_', $class_section_name) . "_" . $selected_date, 'L');
}


// --- HTML Output Logic (if not PDF) ---
ob_start();
?>
<div class="container-fluid mt-3">
    <h1><?php echo htmlspecialchars($page_title); ?></h1>
    <?php if ($message && $output_format == 'html'): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : 'info'; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card p-3 mb-3 bg-light no-print">
        <form action="reports_daily_attendance.php" method="GET">
             <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="report_date" class="form-label">Date:</label>
                    <input type="date" name="report_date" id="report_date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                </div>
                <div class="col-md-5">
                    <label for="class_section_id" class="form-label">Class Section:</label>
                    <select name="class_section_id" id="class_section_id" class="form-select" required>
                        <option value="">-- Select Class Section --</option>
                        <?php foreach ($class_sections_for_dropdown as $cs): ?>
                            <option value="<?php echo $cs['id']; ?>" <?php echo ($selected_class_section_id == $cs['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cs['display_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-auto">
                    <button type="submit" name="view_report" value="html" class="btn btn-primary">View Report</button>
                </div>
                 <div class="col-md-auto">
                    <button type="submit" name="output" value="pdf" formaction="reports_daily_attendance.php?<?php echo http_build_query(array_merge($_GET, ['output'=>'pdf', 'view_report'=>'pdf']));?>" class="btn btn-secondary">Download PDF</button>
                </div>
                <div class="col-md-auto">
                    <button type="button" onclick="window.print();" class="btn btn-info">Print HTML</button>
                </div>
            </div>
        </form>
    </div>

    <?php
    // Display the generated HTML content if not PDF output and view_report was clicked
    if ($output_format == 'html' && isset($_GET['view_report']) && empty($message_type == 'error')) {
        echo $report_html_content; // This contains the H2, summary, and table if data exists
    } elseif ($output_format == 'html' && isset($_GET['view_report']) && !empty($message) && $message_type != 'error') {
        // If there was an info message like "no students found" but not a hard error
        echo '<p class="alert alert-info mt-3">' . htmlspecialchars($message) . '</p>';
    }
    ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn) && $output_format == 'html') mysqli_close($conn);
include 'layout_authenticated.php';
?>
