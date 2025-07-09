<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

$page_title = "Absentee List";

include 'config.php';
include 'functions.php';

$current_user_id = current_user_id();
$current_role = current_user_role();

$output_format = (isset($_GET['output']) && $_GET['output'] == 'pdf' && isset($_GET['view_report'])) ? 'pdf' : 'html';
$selected_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');
$selected_class_section_id = isset($_GET['class_section_id']) ? $_GET['class_section_id'] : 'all';

$report_data = [];
$class_section_name_header = 'All Accessible Sections';
$total_absentees = 0;
$message = '';
$message_type = '';
$report_html_content = ''; // For PDF and HTML display

$class_sections_for_dropdown = [];
if ($current_role == 'admin') {
    $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
} else {
    $accessible_section_ids = get_teacher_attendance_accessible_sections($current_user_id, $conn);
    if (!empty($accessible_section_ids)) {
        $ids_str = implode(',', array_map('intval', $accessible_section_ids));
        $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id WHERE cs.id IN ($ids_str) ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
    } else { $cs_sql = ""; }
}
if (!empty($cs_sql)) {
    $cs_res = mysqli_query($conn, $cs_sql);
    if ($cs_res) while ($row = mysqli_fetch_assoc($cs_res)) $class_sections_for_dropdown[] = $row;
}

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
                $sn_q = mysqli_query($conn, "SELECT IFNULL(cs.section_name, CONCAT(gr.grade_name, ' - ', dv.division_name, ' (', cs.academic_year, ')')) as name FROM class_sections cs JOIN grades gr ON cs.grade_id = gr.id JOIN divisions dv ON cs.division_id = dv.id WHERE cs.id = ".(int)$selected_class_section_id);
                if($sn_q && mysqli_num_rows($sn_q)>0) $class_section_name_header = mysqli_fetch_assoc($sn_q)['name']; else $class_section_name_header = "Selected Section";
            } else { $class_section_name_header = "All Sections"; }
        } elseif ($current_role == 'teacher') {
            // $accessible_section_ids already fetched
            if (!empty($accessible_section_ids)) {
                if ($selected_class_section_id !== 'all' && $selected_class_section_id > 0) {
                    if (in_array($selected_class_section_id, $accessible_section_ids)) {
                        $report_sql .= " AND s.class_section_id = " . (int)$selected_class_section_id;
                        $permission_to_proceed = true;
                        $sn_q = mysqli_query($conn, "SELECT IFNULL(cs.section_name, CONCAT(gr.grade_name, ' - ', dv.division_name, ' (', cs.academic_year, ')')) as name FROM class_sections cs JOIN grades gr ON cs.grade_id = gr.id JOIN divisions dv ON cs.division_id = dv.id WHERE cs.id = ".(int)$selected_class_section_id);
                        if($sn_q && mysqli_num_rows($sn_q)>0) $class_section_name_header = mysqli_fetch_assoc($sn_q)['name']; else $class_section_name_header = "Selected Section";
                    } else { $message = "No permission for selected section."; $message_type = 'error'; }
                } else {
                    $ids_str = implode(',', array_map('intval', $accessible_section_ids));
                    $report_sql .= " AND s.class_section_id IN ($ids_str)";
                    $permission_to_proceed = true;
                    $class_section_name_header = "Your Accessible Sections";
                }
            } else { $message = "No access to any class sections."; $message_type = 'error'; }
        }

        if($permission_to_proceed && empty($message_type == 'error')){
            $report_sql .= " ORDER BY cs_main.academic_year DESC, g.grade_name, d.division_name, s.roll_number, s.name";
            $report_res = mysqli_query($conn, $report_sql);
            if ($report_res) {
                while ($row = mysqli_fetch_assoc($report_res)) $report_data[] = $row;
                $total_absentees = count($report_data);
                if($total_absentees == 0 && empty($message)) { $message = "No absentees found."; $message_type = 'info';}

                // Generate HTML content for the report
                ob_start();
                if (!empty($report_data)) {
                ?>
                    <h2 class="mt-4">Absentees for: <?php echo htmlspecialchars($class_section_name_header); ?> on <?php echo date("D, M j, Y", strtotime($selected_date)); ?></h2>
                    <p><strong>Total Absentees Found: <?php echo $total_absentees; ?></strong></p>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered table-hover report-table">
                            <thead class="table-light"><tr><th>Roll No.</th><th>Student Name</th><th>Class Section</th><th>Teacher Notes</th><th>Parent Submitted Reason</th></tr></thead>
                            <tbody>
                            <?php foreach ($report_data as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                    <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['class_section_display']); ?></td>
                                    <td><?php echo !empty($student['teacher_notes']) ? nl2br(htmlspecialchars($student['teacher_notes'])) : '-'; ?></td>
                                    <td>
                                        <?php if (!empty($student['parent_reason'])): ?>
                                            <div class="parent-reason-report"><small>
                                                <strong><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$student['reason_status']))); ?>:</strong>
                                                <?php echo nl2br(htmlspecialchars($student['parent_reason'])); ?>
                                                (By: <?php echo htmlspecialchars($student['reason_submitter'] ?: 'Parent'); ?>)</small>
                                            </div>
                                        <?php else: echo '<small>-</small>'; endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php
                } elseif(isset($_GET['view_report'])) {
                     echo '<p class="alert alert-info mt-3">No absentees found for the selected criteria.</p>';
                }
                $report_html_content = ob_get_clean();

            } else { if(empty($message)) {$message = "Error generating report: " . mysqli_error($conn); $message_type = 'error';} }
        }
    }
}

if ($output_format == 'pdf' && empty($message_type == 'error') && !empty($report_data) ) {
    $pdf_title = "<h1>Absentee List</h1>";
    $pdf_title .= "<h2>Report for: " . htmlspecialchars($class_section_name_header) . " on " . date("D, M j, Y", strtotime($selected_date)) . "</h2>";
    $pdf_css = "<style> body { font-family: Arial, sans-serif; font-size: 9pt; } table { width: 100%; border-collapse: collapse; margin-top: 5px; } th, td { border: 1px solid #ccc; padding: 3px; text-align: left; vertical-align:top; word-wrap:break-word;} th { background-color: #f0f0f0; font-weight: bold; } .parent-reason-report { font-size:0.85em; } h1,h2 {text-align:center; margin-bottom:5px;} </style>";
    $full_html_for_pdf = "<html><head><meta charset='UTF-8'>{$pdf_css}</head><body>";
    $full_html_for_pdf .= $pdf_title;
    $full_html_for_pdf .= $report_html_content; // Already contains summary and table
    $full_html_for_pdf .= "</body></html>";
    generate_and_stream_pdf($full_html_for_pdf, "absentee_list_" . $selected_date, 'L');
}

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
                    <button type="submit" name="output" value="pdf" formaction="reports_absentee_list.php?<?php echo http_build_query(array_merge($_GET, ['output'=>'pdf', 'view_report'=>'pdf']));?>" class="btn btn-secondary" <?php if(empty($report_data)) echo "disabled";?>>Download PDF</button>
                </div>
                <div class="col-md-auto">
                    <button type="button" onclick="window.print();" class="btn btn-info" <?php if(empty($report_data)) echo "disabled";?>>Print HTML</button>
                </div>
            </div>
        </form>
    </div>

    <?php
    if ($output_format == 'html' && isset($_GET['view_report']) && empty($message_type == 'error')) {
        echo $report_html_content;
    } elseif ($output_format == 'html' && isset($_GET['view_report']) && !empty($message) && $message_type != 'error' && empty($report_data)) {
        echo '<p class="alert alert-info mt-3">' . htmlspecialchars($message) . '</p>';
    }
    ?>
</div>
<?php
$page_content_html = ob_get_clean();
if(isset($conn) && $output_format == 'html') mysqli_close($conn);
include 'layout_authenticated.php';
?>
