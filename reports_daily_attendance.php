<?php
// This file needs to be included first to ensure session and user functions are available.
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

include 'config.php';
include 'functions.php';

$current_user_id = current_user_id();
$current_role = current_user_role();

$output_format = (isset($_GET['output']) && $_GET['output'] == 'pdf' && isset($_GET['view_report'])) ? 'pdf' : 'html';

$selected_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');
$selected_class_section_id = isset($_GET['class_section_id']) ? (int)$_GET['class_section_id'] : 0;

$report_data = [];
$class_section_name = '';
$page_specific_css = ''; // For CSS specific to this report, especially for PDF
$student_table_html = ''; // To store the HTML of the student table for PDF
$summary_html = ''; // To store summary HTML

$total_students = 0;
$total_present = 0;
$total_absent = 0;
$message = '';
$message_type = '';

// Fetch class sections for the dropdown (same as before)
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

// --- Logic to generate report data (if filters are set) ---
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
                $report_data[] = $row;
                if (isset($row['is_present'])) {
                    if ($row['is_present'] == 1) $total_present++; else $total_absent++;
                } else { $total_absent++; }
            }
            if($total_students == 0){ $message = "No students found in class section."; $message_type = 'info';}
        } else { $message = "Error generating report data: " . mysqli_error($conn); $message_type = 'error'; }
    } else { $message = "No permission for this class section."; $message_type = 'error'; }
} elseif (isset($_GET['view_report']) || $output_format == 'pdf') { // If view_report or output=pdf is set, but filters are incomplete
    $message = "Please select a date and a class section."; $message_type = 'error';
}


// --- Generate HTML for the report table and summary (used for both HTML display and PDF) ---
if (!empty($report_data) && empty($message_type == 'error') ) { // Check message_type to avoid generating table on error
    ob_start(); // Start buffer for student table
?>
    <table class="table table-sm table-bordered report-table">
        <thead class="table-light">
            <tr>
                <th>Roll No.</th>
                <th>Student Name</th>
                <th>Status</th>
                <th>Teacher Notes</th>
                <th>Parent Submitted Reason</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($report_data as $student): ?>
                <tr>
                    <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                    <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                    <td class="<?php
                        if (!isset($student['is_present'])) echo 'status-notmarked';
                        elseif ($student['is_present']) echo 'status-present';
                        else echo 'status-absent';
                    ?>">
                        <?php
                            if (!isset($student['is_present'])) echo 'Not Marked';
                            elseif ($student['is_present']) echo 'Present';
                            else echo 'ABSENT';
                        ?>
                    </td>
                    <td><?php echo !empty($student['teacher_notes']) ? nl2br(htmlspecialchars($student['teacher_notes'])) : '-'; ?></td>
                    <td>
                        <?php if (!$student['is_present'] && !empty($student['parent_reason'])): ?>
                            <div class="parent-reason-report">
                                <strong><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$student['reason_status']))); ?>:</strong>
                                <?php echo nl2br(htmlspecialchars($student['parent_reason'])); ?>
                                <br><small>(By: <?php echo htmlspecialchars($student['reason_submitter'] ?: 'Parent'); ?>)</small>
                            </div>
                        <?php elseif (!$student['is_present'] && isset($student['is_present'])) : ?> <small>-</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php
    $student_table_html = ob_get_clean();

    ob_start(); // Start buffer for summary
?>
    <div class="report-summary mt-3 mb-3 p-2 bg-light border rounded">
        <strong>Total Students:</strong> <?php echo $total_students; ?> |
        <strong>Present:</strong> <?php echo $total_present; ?> |
        <strong>Absent / Not Marked:</strong> <?php echo $total_absent; ?>
    </div>
<?php
    $summary_html = ob_get_clean();
}
// --- End Report Data Generation ---


// --- PDF Output Logic ---
if ($output_format == 'pdf') {
    if (!empty($report_data) && empty($message_type == 'error')) {
        $pdf_html_header = "<h1>Daily Attendance Report</h1>";
        $pdf_html_header .= "<h2>Report for: " . htmlspecialchars($class_section_name) . " on " . date("D, M j, Y", strtotime($selected_date)) . "</h2>";

        // Basic CSS for PDF (Dompdf has limitations, so keep it simple or link external carefully)
        // Best to use inline styles or very simple selectors for HTML passed to Dompdf.
        // Or rely on @media print styles in custom.css if Dompdf picks them up well.
        $pdf_css = "<style>
            body { font-family: Arial, sans-serif; font-size: 10pt; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th, td { border: 1px solid #ddd; padding: 4px; text-align: left; vertical-align:top; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .status-present { color: green; } .status-absent { color: red; font-weight:bold; } .status-notmarked {color: orange;}
            .parent-reason-report { font-size: 0.9em; color: #444; margin-top:3px; }
            .report-summary { margin-top: 15px; padding: 10px; background-color: #e9ecef; border-radius: 4px;}
            h1, h2 { text-align:center; border-bottom: 1px solid #ccc; padding-bottom: 5px; margin-bottom:10px;}
        </style>";

        $full_html_for_pdf = "<html><head><meta charset='UTF-8'>{$pdf_css}</head><body>";
        $full_html_for_pdf .= $pdf_html_header;
        $full_html_for_pdf .= $summary_html;
        $full_html_for_pdf .= $student_table_html;
        $full_html_for_pdf .= "</body></html>";

        generate_and_stream_pdf($full_html_for_pdf, "daily_attendance_" . str_replace(' ', '_', $class_section_name) . "_" . $selected_date, 'L'); // Landscape
        // generate_and_stream_pdf will exit, so no more output after this.
    } else {
        // If PDF requested but no data or error, we fall through to HTML display to show the error message.
        // Or redirect back with an error message:
        // $_SESSION['error_message_for_reports'] = $message ? $message : "No data to generate PDF.";
        // header("Location: reports_daily_attendance.php?" . http_build_query(['report_date'=>$selected_date, 'class_section_id'=>$selected_class_section_id]));
        // exit;
        // For now, let HTML part handle error display
    }
}


// --- HTML Output Logic (if not PDF) ---
$page_title = "Daily Attendance Report"; // For layout
ob_start(); // Start final output buffering for the HTML page
?>

<div class="container-fluid mt-3">
    <h1>Daily Attendance Report</h1>
    <?php if ($message && $output_format == 'html'): // Show messages only on HTML view ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : ($message_type == 'success' ? 'success' : 'info'); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card p-3 mb-3 bg-light no-print">
        <form action="reports_daily_attendance.php" method="GET">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
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

    <?php if (isset($_GET['view_report']) && !empty($report_data) && empty($message_type == 'error')): ?>
        <h2>Report for: <?php echo htmlspecialchars($class_section_name); ?> on <?php echo date("D, M j, Y", strtotime($selected_date)); ?></h2>
        <?php
            echo $summary_html; // Display summary
            echo $student_table_html; // Display student table
        ?>
    <?php elseif (isset($_GET['view_report']) && $selected_class_section_id > 0 && empty($message_type == 'error')): ?>
        <p class="alert alert-info">No attendance data or students found for the selected criteria.</p>
    <?php endif; ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
