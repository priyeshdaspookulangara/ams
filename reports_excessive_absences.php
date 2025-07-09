<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

$page_title = "Excessive Absences Report";

include 'config.php';
include 'functions.php';

$current_user_id = current_user_id();
$current_role = current_user_role();

$output_format = (isset($_GET['output']) && $_GET['output'] == 'pdf' && isset($_GET['view_report'])) ? 'pdf' : 'html';
$default_end_date = date('Y-m-d');
$default_start_date = date('Y-m-d', strtotime('-29 days', strtotime($default_end_date)));
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $default_start_date;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : $default_end_date;
$absence_threshold = isset($_GET['threshold']) ? (int)$_GET['threshold'] : 5;
$selected_class_section_id = isset($_GET['class_section_id']) ? $_GET['class_section_id'] : 'all';

$report_data = [];
$filter_details_header = '';
$message = '';
$message_type = '';
$report_html_content = ''; // For PDF and HTML display

$class_sections_for_dropdown = []; // Populated similar to reports_absentee_list.php
if ($current_role == 'admin') {
    $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
} else {
    $accessible_section_ids = get_teacher_attendance_accessible_sections($current_user_id, $conn); // Using attendance access
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
    if (empty($start_date) || empty($end_date) || $absence_threshold <= 0) {
        $message = "Valid date range & positive threshold required."; $message_type = 'error';
    } elseif (strtotime($end_date) < strtotime($start_date)) {
        $message = "End date cannot be before start date."; $message_type = 'error';
    } else {
        $filter_details_header = "Period: " . date("M j, Y", strtotime($start_date)) . " to " . date("M j, Y", strtotime($end_date)) . " | Threshold: &ge; " . $absence_threshold . " absences";
        $report_sql = "SELECT s.id as student_id, s.name as student_name, s.roll_number, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display, COUNT(ar.id) as total_absences
                       FROM students s JOIN class_sections cs ON s.class_section_id = cs.id JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id JOIN attendance_records ar ON s.id = ar.student_id
                       WHERE ar.is_present = 0 AND ar.attendance_date BETWEEN '$start_date' AND '$end_date'";
        $permission_to_proceed = false;
        if ($current_role == 'admin') {
            $permission_to_proceed = true;
            if ($selected_class_section_id !== 'all' && $selected_class_section_id > 0) {
                $report_sql .= " AND s.class_section_id = " . (int)$selected_class_section_id;
                // Get section name for header
                $cs_name_q = mysqli_query($conn, "SELECT IFNULL(csn.section_name, CONCAT(gr.grade_name, ' - ', div.division_name, ' (', csn.academic_year, ')')) as name FROM class_sections csn JOIN grades gr ON csn.grade_id = gr.id JOIN divisions div ON csn.division_id = div.id WHERE csn.id = ".(int)$selected_class_section_id);
                $cs_name_for_header = ($cs_name_q && mysqli_num_rows($cs_name_q)>0) ? mysqli_fetch_assoc($cs_name_q)['name'] : "Selected Section";
                $filter_details_header .= " | Class: " . htmlspecialchars($cs_name_for_header);
            } else { $filter_details_header .= " | Class: All Sections"; }
        } elseif ($current_role == 'teacher') {
            // $accessible_section_ids already fetched for dropdown
            if (!empty($accessible_section_ids)) {
                if ($selected_class_section_id !== 'all' && $selected_class_section_id > 0) {
                    if (in_array($selected_class_section_id, $accessible_section_ids)) {
                        $report_sql .= " AND s.class_section_id = " . (int)$selected_class_section_id;
                        $permission_to_proceed = true;
                         $cs_name_q = mysqli_query($conn, "SELECT IFNULL(csn.section_name, CONCAT(gr.grade_name, ' - ', div.division_name, ' (', csn.academic_year, ')')) as name FROM class_sections csn JOIN grades gr ON csn.grade_id = gr.id JOIN divisions div ON csn.division_id = div.id WHERE csn.id = ".(int)$selected_class_section_id);
                        $cs_name_for_header = ($cs_name_q && mysqli_num_rows($cs_name_q)>0) ? mysqli_fetch_assoc($cs_name_q)['name'] : "Selected Section";
                        $filter_details_header .= " | Class: " . htmlspecialchars($cs_name_for_header);
                    } else { $message = "No permission for selected section."; $message_type = 'error'; }
                } else {
                    $ids_str = implode(',', array_map('intval', $accessible_section_ids));
                    $report_sql .= " AND s.class_section_id IN ($ids_str)";
                    $permission_to_proceed = true;
                    $filter_details_header .= " | Class: Your Accessible Sections";
                }
            } else { $message = "No access to any class sections."; $message_type = 'error'; }
        }

        if($permission_to_proceed && empty($message_type == 'error')){
            $report_sql .= " GROUP BY s.id, s.name, s.roll_number, class_section_display HAVING COUNT(ar.id) >= $absence_threshold ORDER BY total_absences DESC, cs.academic_year DESC, g.grade_name, d.division_name, s.name";
            $report_res = mysqli_query($conn, $report_sql);
            if ($report_res) {
                while ($row = mysqli_fetch_assoc($report_res)) $report_data[] = $row;
                if(count($report_data) == 0 && empty($message)) { $message = "No students found meeting criteria."; $message_type = 'info';}

                // Generate HTML content for the report
                ob_start();
                if(!empty($report_data)){
                ?>
                    <h2 class="mt-4">Report Details: <?php echo htmlspecialchars($filter_details_header); ?></h2>
                    <p>Total Students Found: <?php echo count($report_data); ?></p>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered table-hover report-table">
                            <thead class="table-light"><tr><th>Roll No.</th><th>Student Name</th><th>Class Section</th><th class="text-center">Total Absences</th></tr></thead>
                            <tbody>
                            <?php foreach ($report_data as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                    <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['class_section_display']); ?></td>
                                    <td class="text-center"><?php echo $student['total_absences']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php
                } elseif(isset($_GET['view_report'])) {
                    echo '<p class="alert alert-info mt-3">No students found meeting the excessive absence criteria.</p>';
                }
                $report_html_content = ob_get_clean();

            } else { if(empty($message)) {$message = "Error generating report: " . mysqli_error($conn); $message_type = 'error';} }
        }
    }
}

// --- PDF Output Logic ---
if ($output_format == 'pdf' && empty($message_type == 'error') && !empty($report_data) ) {
    $pdf_title = "<h1>Excessive Absences Report</h1>";
    $pdf_title .= "<h2>" . htmlspecialchars($filter_details_header) . "</h2>";
    $pdf_css = "<style> body { font-family: Arial, sans-serif; font-size: 10pt; } table { width: 100%; border-collapse: collapse; margin-top: 10px; } th, td { border: 1px solid #ddd; padding: 4px; text-align: left; } th { background-color: #f2f2f2; font-weight: bold; } td.num, th.num {text-align:center;} h1,h2 {text-align:center; margin-bottom:5px;} </style>";
    $full_html_for_pdf = "<html><head><meta charset='UTF-8'>{$pdf_css}</head><body>";
    $full_html_for_pdf .= $pdf_title;
    $full_html_for_pdf .= $report_html_content; // Contains table
    $full_html_for_pdf .= "</body></html>";
    generate_and_stream_pdf($full_html_for_pdf, "excessive_absences_report", 'P');
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
        <form action="reports_excessive_absences.php" method="GET">
             <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="start_date" class="form-label">Start Date:</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" required>
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label">End Date:</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" required>
                </div>
                <div class="col-md-2">
                    <label for="threshold" class="form-label">Abs. Threshold (&ge;):</label>
                    <input type="number" name="threshold" id="threshold" class="form-control" value="<?php echo htmlspecialchars($absence_threshold); ?>" min="1" required>
                </div>
                <div class="col-md-4">
                    <label for="class_section_id" class="form-label">Class Section:</label>
                    <select name="class_section_id" id="class_section_id" class="form-select">
                        <option value="all">All Accessible Sections</option>
                        <?php foreach ($class_sections_for_dropdown as $cs): ?>
                            <option value="<?php echo $cs['id']; ?>" <?php echo ($selected_class_section_id == $cs['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cs['display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-12 mt-3 text-center">
                    <button type="submit" name="view_report" value="html" class="btn btn-primary">View Report</button>
                    <button type="submit" name="output" value="pdf" formaction="reports_excessive_absences.php?<?php echo http_build_query(array_merge($_GET, ['output'=>'pdf', 'view_report'=>'pdf']));?>" class="btn btn-secondary ms-2" <?php if(empty($report_data)) echo "disabled";?>>Download PDF</button>
                    <button type="button" onclick="window.print();" class="btn btn-info ms-2" <?php if(empty($report_data)) echo "disabled";?>>Print HTML</button>
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
