<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

$page_title = "Student Contact Information";

include 'config.php';
// functions.php for get_teacher_student_manageable_sections
if (!function_exists('get_teacher_student_manageable_sections')) {
    include_once 'functions.php';
}

$current_user_id = current_user_id();
$current_role = current_user_role();

$output_format = (isset($_GET['output']) && $_GET['output'] == 'pdf' && isset($_GET['view_report'])) ? 'pdf' : 'html';
$selected_class_section_id = isset($_GET['class_section_id']) ? $_GET['class_section_id'] : 'all';

$report_data = [];
$filter_details_header = '';
$message = '';
$message_type = '';
$report_html_content = ''; // For PDF and HTML display

$class_sections_for_dropdown = [];
if ($current_role == 'admin') {
    $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
} else {
    $manageable_section_ids = get_teacher_student_manageable_sections($current_user_id, $conn); // Use student manageable sections
    if (!empty($manageable_section_ids)) {
        $ids_str = implode(',', array_map('intval', $manageable_section_ids));
        $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id WHERE cs.id IN ($ids_str) ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
    } else { $cs_sql = ""; }
}
if (!empty($cs_sql)) {
    $cs_res = mysqli_query($conn, $cs_sql);
    if ($cs_res) while ($row = mysqli_fetch_assoc($cs_res)) $class_sections_for_dropdown[] = $row;
}


if (isset($_GET['view_report']) || $output_format == 'pdf') {
    $report_sql = "SELECT s.id as student_id, s.name as student_name, s.roll_number,
                          IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display
                   FROM students s
                   JOIN class_sections cs ON s.class_section_id = cs.id
                   JOIN grades g ON cs.grade_id = g.id
                   JOIN divisions d ON cs.division_id = d.id
                   WHERE 1=1";
    $permission_to_proceed = false;
    if ($current_role == 'admin') {
        $permission_to_proceed = true;
        if ($selected_class_section_id !== 'all' && $selected_class_section_id > 0) {
            $report_sql .= " AND s.class_section_id = " . (int)$selected_class_section_id;
            $cs_name_q = mysqli_query($conn, "SELECT IFNULL(csn.section_name, CONCAT(gr.grade_name, ' - ', div.division_name, ' (', csn.academic_year, ')')) as name FROM class_sections csn JOIN grades gr ON csn.grade_id = gr.id JOIN divisions div ON csn.division_id = div.id WHERE csn.id = ".(int)$selected_class_section_id);
            $filter_details_header = ($cs_name_q && mysqli_num_rows($cs_name_q)>0) ? mysqli_fetch_assoc($cs_name_q)['name'] : "Selected Section";
        } else { $filter_details_header = "All Sections"; }
    } elseif ($current_role == 'teacher') {
        // $manageable_section_ids already fetched for dropdown
        if (!empty($manageable_section_ids)) {
            if ($selected_class_section_id !== 'all' && $selected_class_section_id > 0) {
                if (in_array($selected_class_section_id, $manageable_section_ids)) {
                    $report_sql .= " AND s.class_section_id = " . (int)$selected_class_section_id;
                    $permission_to_proceed = true;
                    $cs_name_q = mysqli_query($conn, "SELECT IFNULL(csn.section_name, CONCAT(gr.grade_name, ' - ', div.division_name, ' (', csn.academic_year, ')')) as name FROM class_sections csn JOIN grades gr ON csn.grade_id = gr.id JOIN divisions div ON csn.division_id = div.id WHERE csn.id = ".(int)$selected_class_section_id);
                    $filter_details_header = ($cs_name_q && mysqli_num_rows($cs_name_q)>0) ? mysqli_fetch_assoc($cs_name_q)['name'] : "Selected Section";
                } else { $message = "No permission for selected section."; $message_type = 'error'; }
            } else {
                $ids_str = implode(',', array_map('intval', $manageable_section_ids));
                $report_sql .= " AND s.class_section_id IN ($ids_str)";
                $permission_to_proceed = true;
                $filter_details_header = "Your Accessible Sections";
            }
        } else { $message = "No access to any class sections."; $message_type = 'error'; }
    }

    if($permission_to_proceed && empty($message_type == 'error')){
        $report_sql .= " ORDER BY cs.academic_year DESC, g.grade_name, d.division_name, s.roll_number, s.name";
        $report_res = mysqli_query($conn, $report_sql);
        if ($report_res) {
            while ($student_row = mysqli_fetch_assoc($report_res)) {
                $pg_sql = "SELECT parent_name, phone_number, email, relationship FROM parent_guardians WHERE student_id = " . $student_row['student_id'] . " ORDER BY relationship";
                $pg_res = mysqli_query($conn, $pg_sql);
                $student_row['parents'] = [];
                if ($pg_res) while ($parent_row = mysqli_fetch_assoc($pg_res)) $student_row['parents'][] = $parent_row;
                $report_data[] = $student_row;
            }
            if(empty($report_data) && empty($message)) { $message = "No students found for criteria."; $message_type = 'info';}

            // Generate HTML for report content
            ob_start();
            if(!empty($report_data)){
            ?>
                <h2 class="mt-4">Contact List For: <?php echo htmlspecialchars($filter_details_header); ?></h2>
                <p>Total Students Found: <?php echo count($report_data); ?></p>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover report-table">
                        <thead class="table-light"><tr><th>Roll No.</th><th>Student Name</th><th>Class Section</th><th>Parent/Guardian Contacts</th></tr></thead>
                        <tbody>
                        <?php foreach ($report_data as $student): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($student['class_section_display']); ?></td>
                                <td>
                                    <?php if (!empty($student['parents'])): ?>
                                        <ul class="list-unstyled mb-0 small">
                                        <?php foreach ($student['parents'] as $parent): ?>
                                            <li class="mb-2 border-bottom pb-1">
                                                <strong><?php echo htmlspecialchars($parent['parent_name']); ?></strong> (<?php echo htmlspecialchars($parent['relationship']); ?>)<br>
                                                Ph: <?php echo htmlspecialchars($parent['phone_number']); ?>
                                                <?php echo $parent['email'] ? "<br>Em: ".htmlspecialchars($parent['email']) : ''; ?>
                                            </li>
                                        <?php endforeach; ?>
                                        </ul>
                                    <?php else: echo '<small class="text-muted">No contacts listed.</small>'; endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php
            } elseif(isset($_GET['view_report'])) {
                 echo '<p class="alert alert-info mt-3">No students found for the selected criteria.</p>';
            }
            $report_html_content = ob_get_clean();

        } else { if(empty($message)) {$message = "Error generating report: " . mysqli_error($conn); $message_type = 'error';} }
    }
}

if ($output_format == 'pdf' && empty($message_type == 'error') && !empty($report_data) ) {
    $pdf_title = "<h1>Student Contact Information Report</h1>";
    $pdf_title .= "<h2>For: " . htmlspecialchars($filter_details_header) . "</h2>";
    $pdf_css = "<style> body { font-family: Arial, sans-serif; font-size: 9pt; } table { width: 100%; border-collapse: collapse; margin-top: 10px; } th, td { border: 1px solid #ccc; padding: 4px; text-align: left; vertical-align:top; word-wrap:break-word;} th { background-color: #f0f0f0; font-weight: bold; } .parent-block { margin-bottom: 3px; padding-left:5px; font-size:0.9em;} .parent-block strong {display: inline-block; min-width: 50px;} h1,h2 {text-align:center; margin-bottom:5px;} </style>";
    $full_html_for_pdf = "<html><head><meta charset='UTF-8'>{$pdf_css}</head><body>";
    $full_html_for_pdf .= $pdf_title;
    $full_html_for_pdf .= $report_html_content; // Contains the table
    $full_html_for_pdf .= "</body></html>";
    generate_and_stream_pdf($full_html_for_pdf, "student_contact_report", 'P');
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
        <form action="reports_student_contacts.php" method="GET">
             <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label for="class_section_id" class="form-label">Filter by Class Section:</label>
                    <select name="class_section_id" id="class_section_id" class="form-select">
                        <option value="all">All Accessible Sections</option>
                        <?php foreach ($class_sections_for_dropdown as $cs): ?>
                            <option value="<?php echo $cs['id']; ?>" <?php echo ($selected_class_section_id == $cs['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cs['display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-auto"><button type="submit" name="view_report" value="html" class="btn btn-primary">View Report</button></div>
                <div class="col-md-auto"><button type="submit" name="output" value="pdf" formaction="reports_student_contacts.php?<?php echo http_build_query(array_merge($_GET, ['output'=>'pdf', 'view_report'=>'pdf']));?>" class="btn btn-secondary" <?php if(empty($report_data)) echo "disabled";?>>Download PDF</button></div>
                <div class="col-md-auto"><button type="button" onclick="window.print();" class="btn btn-info" <?php if(empty($report_data)) echo "disabled";?>>Print HTML</button></div>
            </div>
        </form>
    </div>

    <?php
    if ($output_format == 'html' && isset($_GET['view_report']) && empty($message_type == 'error') ) {
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
