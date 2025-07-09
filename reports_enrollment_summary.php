<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

$page_title = "Student Enrollment Summary";

include 'config.php';
// functions.php not directly needed by this page's core logic after auth_check

$current_role = current_user_role();
$current_user_id = current_user_id();

$output_format = (isset($_GET['output']) && $_GET['output'] == 'pdf' && isset($_GET['view_report'])) ? 'pdf' : 'html';
$enrollment_data = [];
$overall_summary = ['total_students' => 0]; // Simplified overall summary for this report
$filter_academic_year = isset($_GET['filter_ay']) ? mysqli_real_escape_string($conn, $_GET['filter_ay']) : 'all';
$filter_details_header = '';
$message = '';
$message_type = '';

$academic_years_for_filter = [];
$ay_filter_sql = "SELECT DISTINCT academic_year FROM class_sections ORDER BY academic_year DESC";
$ay_filter_res = mysqli_query($conn, $ay_filter_sql);
if ($ay_filter_res) while ($row = mysqli_fetch_assoc($ay_filter_res)) $academic_years_for_filter[] = $row['academic_year'];


if (isset($_GET['view_report']) || $output_format == 'pdf') {
    if ($filter_academic_year == 'all' && empty($_GET['filter_ay'])) { // Check if a specific year was intended if form submitted without explicit 'all'
        // No specific year selected, show for all or prompt. For now, show all.
         $filter_details_header = "All Academic Years";
    } elseif ($filter_academic_year !== 'all') {
         $filter_details_header = "Academic Year: " . htmlspecialchars($filter_academic_year);
    } else {
         $filter_details_header = "All Academic Years"; // Explicitly selected 'all'
    }


    $sql = "SELECT
                cs.academic_year,
                IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display,
                COUNT(s.id) as student_count
            FROM class_sections cs
            JOIN grades g ON cs.grade_id = g.id
            JOIN divisions d ON cs.division_id = d.id
            LEFT JOIN students s ON cs.id = s.class_section_id
            WHERE 1=1";

    if ($filter_academic_year !== 'all' && !empty($filter_academic_year)) {
        $sql .= " AND cs.academic_year = '$filter_academic_year'";
    }

    // Teacher role restriction: For this summary, it's often school-wide.
    // If teachers should only see summaries of *their* sections, this SQL needs filtering.
    // For now, keeping it school-wide for both admin/teacher as per original implementation.
    // A more complex version could use get_teacher_attendance_accessible_sections and filter cs.id IN (...)

    $sql .= " GROUP BY cs.id, cs.academic_year, class_section_display
              ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";

    $result = mysqli_query($conn, $sql);

    if ($result) {
        $current_ay_for_total = null;
        $ay_subtotal = 0;
        $grand_total_students = 0;

        while ($row = mysqli_fetch_assoc($result)) {
            $enrollment_data[] = $row;
            $grand_total_students += (int)$row['student_count'];
        }
        $overall_summary['total_students'] = $grand_total_students;

        if(empty($enrollment_data) && empty($message)) { $message = "No enrollment data found for the selected criteria."; $message_type = 'info';}
    } else { $message = "Error fetching enrollment data: " . mysqli_error($conn); $message_type = 'error'; }
}

// --- PDF Output Logic ---
if ($output_format == 'pdf' && empty($message_type == 'error') && !empty($enrollment_data) ) {
    $pdf_html_content = "<h1>Student Enrollment Summary</h1>";
    $pdf_html_content .= "<h2>" . htmlspecialchars($filter_details_header) . "</h2>";
    $pdf_html_content .= "<style> body { font-family: Arial, sans-serif; font-size: 10pt; } table { width: 100%; border-collapse: collapse; margin-top: 10px; } th, td { border: 1px solid #ddd; padding: 5px; text-align: left; } th { background-color: #f2f2f2; font-weight: bold; } td.num, th.num {text-align:right;} h1,h2 {text-align:center;} </style>";
    $pdf_html_content .= "<table><thead><tr><th>Academic Year</th><th>Class Section</th><th class='num'>Number of Students</th></tr></thead><tbody>";

    $loop_current_ay = null;
    $loop_ay_subtotal = 0;

    foreach ($enrollment_data as $idx => $row) {
        if ($loop_current_ay !== null && $row['academic_year'] !== $loop_current_ay) {
            $pdf_html_content .= "<tr><td colspan='2' style='text-align:right; font-weight:bold;'>Subtotal for " . htmlspecialchars($loop_current_ay) . ":</td><td class='num' style='font-weight:bold;'>" . $loop_ay_subtotal . "</td></tr>";
            $loop_ay_subtotal = 0;
        }
        $loop_current_ay = $row['academic_year'];
        $loop_ay_subtotal += (int)$row['student_count'];

        $pdf_html_content .= "<tr>";
        $pdf_html_content .= "<td>" . htmlspecialchars($row['academic_year']) . "</td>";
        $pdf_html_content .= "<td>" . htmlspecialchars($row['class_section_display']) . "</td>";
        $pdf_html_content .= "<td class='num'>" . $row['student_count'] . "</td>";
        $pdf_html_content .= "</tr>";

        if ($idx === count($enrollment_data) - 1) { // Last row, print its subtotal
             $pdf_html_content .= "<tr><td colspan='2' style='text-align:right; font-weight:bold;'>Subtotal for " . htmlspecialchars($loop_current_ay) . ":</td><td class='num' style='font-weight:bold;'>" . $loop_ay_subtotal . "</td></tr>";
        }
    }
    $pdf_html_content .= "</tbody><tfoot>";
    $pdf_html_content .= "<tr><th colspan='2' style='text-align:right;'>Grand Total Students:</th>";
    $pdf_html_content .= "<th class='num'>" . $overall_summary['total_students'] . "</th></tr>";
    $pdf_html_content .= "</tfoot></table>";
    generate_and_stream_pdf($pdf_html_content, "enrollment_summary", 'P');
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
        <form action="reports_enrollment_summary.php" method="GET">
             <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label for="filter_ay" class="form-label">Filter by Academic Year:</label>
                    <select name="filter_ay" id="filter_ay" class="form-select" onchange="this.form.submit()">
                        <option value="all">All Academic Years</option>
                        <?php foreach ($academic_years_for_filter as $ay): ?>
                            <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo ($filter_academic_year == $ay) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ay); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" name="view_report" value="html_noscript_filter" class="btn btn-sm btn-outline-secondary mt-1">Apply Filter</button></noscript>
                </div>
                <div class="col-md-auto">
                    <button type="submit" name="view_report" value="html" class="btn btn-primary">View Report</button>
                </div>
                 <div class="col-md-auto">
                    <button type="submit" name="output" value="pdf" formaction="reports_enrollment_summary.php?<?php echo http_build_query(array_merge($_GET, ['output'=>'pdf', 'view_report'=>'pdf']));?>" class="btn btn-secondary">Download PDF</button>
                </div>
                <div class="col-md-auto">
                    <button type="button" onclick="window.print();" class="btn btn-info">Print HTML</button>
                </div>
            </div>
        </form>
    </div>

    <?php if ((isset($_GET['view_report']) || $output_format == 'pdf_preview_debug') && empty($message_type == 'error') ): ?>
        <?php if (!empty($enrollment_data)): ?>
            <h2 class="mt-4">Summary for: <?php echo htmlspecialchars($filter_details_header); ?></h2>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover report-table">
                    <thead class="table-light">
                        <tr>
                            <th>Academic Year</th>
                            <th>Class Section</th>
                            <th class="text-end">Number of Students</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $current_ay_for_total_html = null;
                        $ay_subtotal_html = 0;
                        foreach ($enrollment_data as $index_html => $row):
                            if ($current_ay_for_total_html !== null && $row['academic_year'] !== $current_ay_for_total_html) {
                                echo "<tr class='table-group-divider fw-semibold'><td colspan='2' class='text-end'>Subtotal for " . htmlspecialchars($current_ay_for_total_html) . ":</td><td class='text-end'>" . $ay_subtotal_html . "</td></tr>";
                                $ay_subtotal_html = 0;
                            }
                            $current_ay_for_total_html = $row['academic_year'];
                            $ay_subtotal_html += (int)$row['student_count'];
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['academic_year']); ?></td>
                                <td><?php echo htmlspecialchars($row['class_section_display']); ?></td>
                                <td class="text-end"><?php echo $row['student_count']; ?></td>
                            </tr>
                        <?php
                            if ($index_html === count($enrollment_data) - 1) { // Last row, print its subtotal
                                 echo "<tr class='table-group-divider fw-semibold'><td colspan='2' class='text-end'>Subtotal for " . htmlspecialchars($current_ay_for_total_html) . ":</td><td class='text-end'>" . $ay_subtotal_html . "</td></tr>";
                            }
                        endforeach;
                        ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <th colspan="2" class="text-end">Grand Total Students:</th>
                            <th class="text-end"><?php echo $overall_summary['total_students']; ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php elseif(isset($_GET['view_report'])): ?>
            <p class="alert alert-info mt-3">No enrollment data found to summarize for the selected criteria.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn) && $output_format == 'html') mysqli_close($conn);
include 'layout_authenticated.php';
?>
