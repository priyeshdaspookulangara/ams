<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

$page_title = "Student Master List";

include 'config.php';
// functions.php is included via auth_check.php or should be if needed for get_teacher_... functions
if (!function_exists('get_teacher_student_manageable_sections')) {
    include_once 'functions.php';
}


$current_role = current_user_role();
$current_user_id = current_user_id();

$output_format = (isset($_GET['output']) && $_GET['output'] == 'pdf' && isset($_GET['view_report'])) ? 'pdf' : 'html';
$students_data = [];
$filter_class_section_id = isset($_GET['filter_cs_id']) ? $_GET['filter_cs_id'] : 'all';
$filter_academic_year = isset($_GET['filter_ay']) ? mysqli_real_escape_string($conn, $_GET['filter_ay']) : 'all';
$filter_details_header = "";
$message = '';
$message_type = '';


$class_sections_for_filter = [];
$cs_filter_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                  FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id
                  ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
$cs_filter_res = mysqli_query($conn, $cs_filter_sql);
if ($cs_filter_res) while ($row = mysqli_fetch_assoc($cs_filter_res)) $class_sections_for_filter[] = $row;

$academic_years_for_filter = [];
$ay_filter_sql = "SELECT DISTINCT academic_year FROM class_sections ORDER BY academic_year DESC";
$ay_filter_res = mysqli_query($conn, $ay_filter_sql);
if ($ay_filter_res) while ($row = mysqli_fetch_assoc($ay_filter_res)) $academic_years_for_filter[] = $row['academic_year'];


if (isset($_GET['view_report']) || $output_format == 'pdf') {
    $sql = "SELECT s.id as student_id, s.name as student_name, s.roll_number, s.date_of_birth,
                   cs.academic_year,
                   IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display
            FROM students s
            JOIN class_sections cs ON s.class_section_id = cs.id
            JOIN grades g ON cs.grade_id = g.id
            JOIN divisions d ON cs.division_id = d.id
            WHERE 1=1";

    $header_parts = [];
    if ($filter_academic_year !== 'all' && !empty($filter_academic_year)) {
        $sql .= " AND cs.academic_year = '$filter_academic_year'";
        $header_parts[] = "AY: " . htmlspecialchars($filter_academic_year);
    }
    if ($filter_class_section_id !== 'all' && $filter_class_section_id > 0) {
        $sql .= " AND s.class_section_id = " . (int)$filter_class_section_id;
        // Get name for header
        $cs_name_q = mysqli_query($conn, "SELECT IFNULL(csn.section_name, CONCAT(gr.grade_name, ' - ', div.division_name, ' (', csn.academic_year, ')')) as name FROM class_sections csn JOIN grades gr ON csn.grade_id = gr.id JOIN divisions div ON csn.division_id = div.id WHERE csn.id = ".(int)$filter_class_section_id);
        if($cs_name_q && mysqli_num_rows($cs_name_q)>0) $header_parts[] = "Class: " . htmlspecialchars(mysqli_fetch_assoc($cs_name_q)['name']);
    }
     $filter_details_header = empty($header_parts) ? "All Students" : implode(" | ", $header_parts);


    if ($current_role == 'teacher') {
        $manageable_cs_ids = get_teacher_student_manageable_sections($current_user_id, $conn);
        if (!empty($manageable_cs_ids)) {
            $ids_str = implode(',', $manageable_cs_ids);
            $sql .= " AND s.class_section_id IN ($ids_str)";
            if ($filter_class_section_id !== 'all' && !in_array($filter_class_section_id, $manageable_cs_ids)) {
                 $sql .= " AND 1=0"; // Teacher filtered for a section they cannot manage - show nothing
                 if(empty($message)) {$message = "Selected class section is not accessible."; $message_type="error";}
            }
        } else { $sql .= " AND 1=0"; if(empty($message)) {$message = "No sections accessible."; $message_type="info";} }
    }

    $sql .= " ORDER BY cs.academic_year DESC, g.grade_name, d.division_name, s.roll_number, s.name";

    if(empty($message_type == 'error')){ // Proceed if no permission error
        $result = mysqli_query($conn, $sql);
        if ($result) {
            while ($student_row = mysqli_fetch_assoc($result)) {
                $pg_sql = "SELECT parent_name, phone_number, email, relationship FROM parent_guardians WHERE student_id = " . $student_row['student_id'] . " ORDER BY relationship";
                $pg_res = mysqli_query($conn, $pg_sql);
                $student_row['parents'] = [];
                if ($pg_res) while ($parent_row = mysqli_fetch_assoc($pg_res)) $student_row['parents'][] = $parent_row;
                $students_data[] = $student_row;
            }
            if(empty($students_data) && empty($message)) { $message = "No students found matching criteria."; $message_type = 'info';}
        } else { $message = "Error fetching student data: " . mysqli_error($conn); $message_type = 'error'; }
    }
}


// --- PDF Output Logic ---
if ($output_format == 'pdf' && empty($message_type == 'error') && !empty($students_data) ) {
    $pdf_html_content = "<h1>Student Master List</h1>";
    $pdf_html_content .= "<h2>For: " . htmlspecialchars($filter_details_header) . "</h2>";
    $pdf_html_content .= "<p>Total Students: " . count($students_data) . "</p>";
    $pdf_html_content .= "<style> body { font-family: Arial, sans-serif; font-size: 9pt; } table { width: 100%; border-collapse: collapse; margin-top: 5px; } th, td { border: 1px solid #ccc; padding: 3px; text-align: left; vertical-align:top; } th { background-color: #f0f0f0; font-weight: bold; } .parent-info { list-style-type: none; padding-left: 0; margin:0;} .parent-info li {font-size:0.9em; margin-bottom:2px;} h1,h2 {text-align:center;} </style>";
    $pdf_html_content .= "<table><thead><tr><th>ID</th><th>Name</th><th>Roll No.</th><th>DOB</th><th>Class Section</th><th>Academic Year</th><th>Parent/Guardian Info</th></tr></thead><tbody>";
    foreach ($students_data as $student) {
        $pdf_html_content .= "<tr><td>" . $student['student_id'] . "</td><td>" . htmlspecialchars($student['student_name']) . "</td><td>" . htmlspecialchars($student['roll_number']) . "</td><td>" . ($student['date_of_birth'] ? date("Y-m-d", strtotime($student['date_of_birth'])) : '-') . "</td><td>" . htmlspecialchars($student['class_section_display']) . "</td><td>" . htmlspecialchars($student['academic_year']) . "</td><td>";
        if (!empty($student['parents'])) {
            $pdf_html_content .= "<ul class='parent-info'>";
            foreach($student['parents'] as $parent){
                $pdf_html_content .= "<li><strong>" . htmlspecialchars($parent['parent_name']) . "</strong> (" . htmlspecialchars($parent['relationship']) . ") Ph: " . htmlspecialchars($parent['phone_number']) . ($parent['email'] ? " Em: ".htmlspecialchars($parent['email']) : "") . "</li>";
            }
            $pdf_html_content .= "</ul>";
        } else { $pdf_html_content .= "No contacts."; }
        $pdf_html_content .= "</td></tr>";
    }
    $pdf_html_content .= "</tbody></table>";
    generate_and_stream_pdf($pdf_html_content, "student_master_list", 'L'); // Landscape
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
        <form action="reports_student_master.php" method="GET">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="filter_ay" class="form-label">Academic Year:</label>
                    <select name="filter_ay" id="filter_ay" class="form-select">
                        <option value="all">All Years</option>
                        <?php foreach ($academic_years_for_filter as $ay): ?>
                            <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo ($filter_academic_year == $ay) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ay); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label for="filter_cs_id" class="form-label">Class Section:</label>
                    <select name="filter_cs_id" id="filter_cs_id" class="form-select">
                        <option value="all">All Accessible Sections</option>
                        <?php
                        // For teacher, dropdown should only show their accessible sections if not admin
                        $cs_options_for_this_user = ($current_role == 'admin') ? $class_sections_for_filter : [];
                        if($current_role == 'teacher'){
                            $teacher_manageable_ids = get_teacher_student_manageable_sections($current_user_id, $conn);
                            foreach($class_sections_for_filter as $cs_all){ // $class_sections_for_filter has ALL sections
                                if(in_array($cs_all['id'], $teacher_manageable_ids)) $cs_options_for_this_user[] = $cs_all;
                            }
                        }

                        foreach ($cs_options_for_this_user as $cs): ?>
                            <option value="<?php echo $cs['id']; ?>" <?php echo ($filter_class_section_id == $cs['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cs['display_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-auto">
                    <button type="submit" name="view_report" value="html" class="btn btn-primary">View Report</button>
                </div>
                <div class="col-md-auto">
                    <button type="submit" name="output" value="pdf" formaction="reports_student_master.php?<?php echo http_build_query(array_merge($_GET, ['output'=>'pdf', 'view_report'=>'pdf']));?>" class="btn btn-secondary">Download PDF</button>
                </div>
                <div class="col-md-auto">
                    <button type="button" onclick="window.print();" class="btn btn-info">Print HTML</button>
                </div>
            </div>
        </form>
    </div>

    <?php if ((isset($_GET['view_report']) || $output_format == 'pdf_preview_debug') && empty($message_type == 'error')): ?>
        <?php if (!empty($students_data)): ?>
            <h3 class="mt-4">Student List: <small class="text-muted"><?php echo htmlspecialchars($filter_details_header); ?></small></h3>
            <p>Total Students Found: <?php echo count($students_data); ?></p>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover report-table">
                    <thead class="table-light">
                        <tr>
                            <th>Std. ID</th>
                            <th>Name</th>
                            <th>Roll No.</th>
                            <th>DOB</th>
                            <th>Class Section</th>
                            <th>Acad. Year</th>
                            <th>Parent/Guardian Info</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students_data as $student): ?>
                            <tr>
                                <td><?php echo $student['student_id']; ?></td>
                                <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                <td><?php echo $student['date_of_birth'] ? date("M j, Y", strtotime($student['date_of_birth'])) : '-'; ?></td>
                                <td><?php echo htmlspecialchars($student['class_section_display']); ?></td>
                                <td><?php echo htmlspecialchars($student['academic_year']); ?></td>
                                <td>
                                    <?php if (!empty($student['parents'])): ?>
                                        <ul class="list-unstyled mb-0 small">
                                        <?php foreach ($student['parents'] as $parent): ?>
                                            <li class="mb-1">
                                                <strong><?php echo htmlspecialchars($parent['parent_name']); ?></strong>
                                                (<?php echo htmlspecialchars($parent['relationship']); ?>)<br>
                                                Ph: <?php echo htmlspecialchars($parent['phone_number']); ?>
                                                <?php echo $parent['email'] ? "<br>Em: ".htmlspecialchars($parent['email']) : ''; ?>
                                            </li>
                                        <?php endforeach; ?>
                                        </ul>
                                    <?php else: echo '<small class="text-muted">No contact info.</small>'; endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif(isset($_GET['view_report'])): ?>
            <p class="alert alert-info mt-3">No students found matching the selected criteria.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
$page_content_html = ob_get_clean();
if(isset($conn) && $output_format == 'html') mysqli_close($conn);
include 'layout_authenticated.php';
?>
