<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

include 'config.php';
include 'functions.php';

$current_user_id = current_user_id();
$current_role = current_user_role();

$output_format = (isset($_GET['output']) && $_GET['output'] == 'pdf' && isset($_GET['view_report'])) ? 'pdf' : 'html';

$selected_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');
$selected_class_section_id = isset($_GET['class_section_id']) ? $_GET['class_section_id'] : 'all'; // Default to 'all' for admin, or first accessible for teacher

$report_data = [];
$class_section_name_header = 'All Accessible Sections';
$total_absentees = 0;
$message = '';
$message_type = '';

// Fetch class sections for the dropdown
$class_sections_for_dropdown = [];
if ($current_role == 'admin') {
    $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
               FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id
               ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
} else { // Teacher
    $accessible_section_ids = get_teacher_attendance_accessible_sections($current_user_id, $conn);
    if (!empty($accessible_section_ids)) {
        $ids_str = implode(',', array_map('intval', $accessible_section_ids));
        $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                   FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id
                   WHERE cs.id IN ($ids_str) ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
        if ($selected_class_section_id === 'all' && count($accessible_section_ids) > 0 && !in_array('all', $accessible_section_ids)) {
            // If teacher's default is 'all' (accessible), but they have specific sections, don't force a single one unless chosen
        } elseif ($selected_class_section_id === 'all' && count($accessible_section_ids) == 1) {
             //$selected_class_section_id = $accessible_section_ids[0]; // Default to first if only one
        }

    } else { $cs_sql = ""; }
}
if (!empty($cs_sql)) {
    $cs_res = mysqli_query($conn, $cs_sql);
    if ($cs_res) while ($row = mysqli_fetch_assoc($cs_res)) $class_sections_for_dropdown[] = $row;
}


// --- Logic to generate report data (if filters are set) ---
if (isset($_GET['view_report']) || $output_format == 'pdf') {
    if (empty($selected_date)) {
        $message = "Please select a date."; $message_type = 'error';
    } else {
        $report_sql = "SELECT s.id as student_id, s.name as student_name, s.roll_number,
                              ar.notes as teacher_notes,
                              IFNULL(cs_main.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs_main.academic_year, ')')) as class_section_display,
                              abr.reason_text as parent_reason, abr.status as reason_status, u.username as reason_submitter
                       FROM students s
                       JOIN attendance_records ar ON s.id = ar.student_id
                       JOIN class_sections cs_main ON s.class_section_id = cs_main.id
                       JOIN grades g ON cs_main.grade_id = g.id
                       JOIN divisions d ON cs_main.division_id = d.id
                       LEFT JOIN absence_reasons abr ON ar.id = abr.attendance_record_id
                       LEFT JOIN users u ON abr.submitted_by_user_id = u.id
                       WHERE ar.is_present = 0 AND ar.attendance_date = '$selected_date'";

        $permission_to_proceed = false;
        if ($current_role == 'admin') {
            $permission_to_proceed = true;
            if ($selected_class_section_id !== 'all' && $selected_class_section_id > 0) {
                $report_sql .= " AND s.class_section_id = " . (int)$selected_class_section_id;
                // Fetch selected class section name for header
                $sn_q = mysqli_query($conn, "SELECT IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as name FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id WHERE cs.id = ".(int)$selected_class_section_id);
                if($sn_q && mysqli_num_rows($sn_q)>0) $class_section_name_header = mysqli_fetch_assoc($sn_q)['name']; else $class_section_name_header = "Selected Section";
            } else {
                 $class_section_name_header = "All Sections";
            }
        } elseif ($current_role == 'teacher') {
            $accessible_section_ids = get_teacher_attendance_accessible_sections($current_user_id, $conn);
            if (!empty($accessible_section_ids)) {
                if ($selected_class_section_id !== 'all' && $selected_class_section_id > 0) {
                    if (in_array($selected_class_section_id, $accessible_section_ids)) {
                        $report_sql .= " AND s.class_section_id = " . (int)$selected_class_section_id;
                        $permission_to_proceed = true;
                        $sn_q = mysqli_query($conn, "SELECT IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as name FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id WHERE cs.id = ".(int)$selected_class_section_id);
                        if($sn_q && mysqli_num_rows($sn_q)>0) $class_section_name_header = mysqli_fetch_assoc($sn_q)['name']; else $class_section_name_header = "Selected Section";
                    } else {
                        $message = "You do not have permission for the selected class section."; $message_type = 'error';
                    }
                } else { // 'all' accessible sections for teacher
                    $ids_str = implode(',', array_map('intval', $accessible_section_ids));
                    $report_sql .= " AND s.class_section_id IN ($ids_str)";
                    $permission_to_proceed = true;
                    $class_section_name_header = "Your Accessible Sections";
                }
            } else {
                $message = "You do not have access to any class sections for attendance."; $message_type = 'error';
            }
        }

        if($permission_to_proceed){
            $report_sql .= " ORDER BY cs_main.academic_year DESC, g.grade_name, d.division_name, s.roll_number, s.name";
            $report_res = mysqli_query($conn, $report_sql);
            if ($report_res) {
                while ($row = mysqli_fetch_assoc($report_res)) $report_data[] = $row;
                $total_absentees = count($report_data);
                if($total_absentees == 0 && empty($message)) { $message = "No absentees found for the selected criteria."; $message_type = 'info';}
            } else { $message = "Error generating report: " . mysqli_error($conn); $message_type = 'error'; }
        }
    }
}

// --- PDF Output Logic --- (Similar to other reports)
if ($output_format == 'pdf' && empty($message_type == 'error') && !empty($report_data) ) {
    $pdf_html_content = "<h1>Absentee List</h1>";
    $pdf_html_content .= "<h2>Report for: " . htmlspecialchars($class_section_name_header) . " on " . date("D, M j, Y", strtotime($selected_date)) . "</h2>";
    $pdf_html_content .= "<p><strong>Total Absentees: " . $total_absentees . "</strong></p>";
    $pdf_html_content .= "<style> body { font-family: Arial, sans-serif; font-size: 10pt; } table { width: 100%; border-collapse: collapse; margin-top: 10px; } th, td { border: 1px solid #ddd; padding: 4px; text-align: left; vertical-align:top; } th { background-color: #f2f2f2; font-weight: bold; } .parent-reason-report { font-size: 0.9em; color: #444; } h1,h2 {text-align:center;} </style>";
    $pdf_html_content .= "<table><thead><tr><th>Roll No.</th><th>Student Name</th><th>Class Section</th><th>Teacher Notes</th><th>Parent Submitted Reason</th></tr></thead><tbody>";
    foreach ($report_data as $student) {
        $pdf_html_content .= "<tr>";
        $pdf_html_content .= "<td>" . htmlspecialchars($student['roll_number']) . "</td>";
        $pdf_html_content .= "<td>" . htmlspecialchars($student['student_name']) . "</td>";
        $pdf_html_content .= "<td>" . htmlspecialchars($student['class_section_display']) . "</td>";
        $pdf_html_content .= "<td>" . (!empty($student['teacher_notes']) ? nl2br(htmlspecialchars($student['teacher_notes'])) : '-') . "</td>";
        $pdf_html_content .= "<td>";
        if (!empty($student['parent_reason'])) {
            $pdf_html_content .= "<strong>" . htmlspecialchars(ucfirst(str_replace('_',' ',$student['reason_status']))) . ":</strong> " . nl2br(htmlspecialchars($student['parent_reason'])) . "<br><small>(By: " . htmlspecialchars($student['reason_submitter'] ?: 'Parent') . ")</small>";
        } else { $pdf_html_content .= "-"; }
        $pdf_html_content .= "</td></tr>";
    }
    $pdf_html_content .= "</tbody></table>";
    generate_and_stream_pdf($pdf_html_content, "absentee_list_" . $selected_date, 'L'); // Landscape
}


// --- HTML Output Logic ---
$page_title = "Absentee List";
ob_start();
?>
<div class="container-fluid mt-3">
    <h1>Absentee List</h1>
    <?php if ($message && $output_format == 'html'): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : ($message_type == 'success' ? 'success' : 'info'); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card p-3 mb-3 bg-light no-print">
        <form action="reports_absentee_list.php" method="GET">
             <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="report_date" class="form-label">Date:</label>
                    <input type="date" name="report_date" id="report_date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                </div>
                <div class="col-md-5">
                    <label for="class_section_id" class="form-label">Class Section:</label>
                    <select name="class_section_id" id="class_section_id" class="form-select">
                        <option value="all">All Accessible Sections</option>
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
                    <button type="submit" name="output" value="pdf" formaction="reports_absentee_list.php?<?php echo http_build_query(array_merge($_GET, ['output'=>'pdf', 'view_report'=>'pdf']));?>" class="btn btn-secondary">Download PDF</button>
                </div>
                <div class="col-md-auto">
                    <button type="button" onclick="window.print();" class="btn btn-info">Print HTML</button>
                </div>
            </div>
        </form>
    </div>

    <?php if ((isset($_GET['view_report']) || $output_format == 'pdf_preview_debug') && empty($message_type == 'error') ): // Check if report should be displayed ?>
        <?php if (!empty($report_data)): ?>
            <h2>Absentees for: <?php echo htmlspecialchars($class_section_name_header); ?> on <?php echo date("D, M j, Y", strtotime($selected_date)); ?></h2>
            <p><strong>Total Absentees Found: <?php echo $total_absentees; ?></strong></p>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover report-table">
                    <thead class="table-light">
                        <tr>
                            <th>Roll No.</th>
                            <th>Student Name</th>
                            <th>Class Section</th>
                            <th>Teacher Notes</th>
                            <th>Parent Submitted Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $student): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($student['class_section_display']); ?></td>
                                <td><?php echo !empty($student['teacher_notes']) ? nl2br(htmlspecialchars($student['teacher_notes'])) : '-'; ?></td>
                                <td>
                                    <?php if (!empty($student['parent_reason'])): ?>
                                        <div class="parent-reason-report">
                                            <strong><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$student['reason_status']))); ?>:</strong>
                                            <?php echo nl2br(htmlspecialchars($student['parent_reason'])); ?>
                                            <br><small>(By: <?php echo htmlspecialchars($student['reason_submitter'] ?: 'Parent'); ?>)</small>
                                        </div>
                                    <?php else: echo '-'; endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif(isset($_GET['view_report'])): // To show 'no absentees' only if view_report was clicked and no error occurred ?>
            <p class="alert alert-info mt-3">No absentees found for the selected criteria.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn) && $output_format == 'html') mysqli_close($conn); // Close connection only for HTML output
include 'layout_authenticated.php';
?>
