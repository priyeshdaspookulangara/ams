<?php
include_once 'auth_check.php';
require_login(['admin']); // Restricted to Admin for now

include 'config.php';
include 'functions.php';

$current_user_id = current_user_id();
$current_role = current_user_role();

$output_format = (isset($_GET['output']) && $_GET['output'] == 'pdf' && isset($_GET['view_report'])) ? 'pdf' : 'html';

// Default date range: current month
$default_start_date = date('Y-m-01');
$default_end_date = date('Y-m-t');

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $default_start_date;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : $default_end_date;
// $group_by = isset($_GET['group_by']) ? $_GET['group_by'] : 'class_section'; // Future enhancement

$report_data = [];
$overall_summary = ['total_marked_days' => 0, 'total_present_days' => 0, 'total_absent_days' => 0, 'overall_percentage' => 0];
$filter_details_header = '';
$message = '';
$message_type = '';


// --- Logic to generate report data ---
if (isset($_GET['view_report']) || $output_format == 'pdf') {
    if (empty($start_date) || empty($end_date)) {
        $message = "Please select a valid date range."; $message_type = 'error';
    } elseif (strtotime($end_date) < strtotime($start_date)) {
        $message = "End date cannot be before start date."; $message_type = 'error';
    } else {
        $filter_details_header = "Period: " . date("M j, Y", strtotime($start_date)) . " to " . date("M j, Y", strtotime($end_date));

        // For each class section, calculate its summary
        $class_sections_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display, cs.academic_year
                               FROM class_sections cs
                               JOIN grades g ON cs.grade_id = g.id
                               JOIN divisions d ON cs.division_id = d.id
                               ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
        $cs_result = mysqli_query($conn, $class_sections_sql);

        if ($cs_result) {
            while ($cs_row = mysqli_fetch_assoc($cs_result)) {
                $cs_id = $cs_row['id'];
                $section_summary_sql = "SELECT
                                            COUNT(ar.id) as marked_days,
                                            SUM(CASE WHEN ar.is_present = 1 THEN 1 ELSE 0 END) as present_days
                                        FROM attendance_records ar
                                        JOIN students s ON ar.student_id = s.id
                                        WHERE s.class_section_id = $cs_id
                                        AND ar.attendance_date BETWEEN '$start_date' AND '$end_date'";

                $summary_res = mysqli_query($conn, $section_summary_sql);
                if ($summary_res && mysqli_num_rows($summary_res) > 0) {
                    $summary_row = mysqli_fetch_assoc($summary_res);
                    $marked_days = (int)$summary_row['marked_days'];
                    $present_days = (int)$summary_row['present_days'];

                    if ($marked_days > 0) { // Only include sections with attendance data in the period
                        $absent_days = $marked_days - $present_days;
                        $percentage = round(($present_days / $marked_days) * 100, 2);

                        $report_data[] = [
                            'class_section_display' => $cs_row['class_section_display'],
                            'academic_year' => $cs_row['academic_year'],
                            'marked_days' => $marked_days,
                            'present_days' => $present_days,
                            'absent_days' => $absent_days,
                            'percentage' => $percentage
                        ];

                        $overall_summary['total_marked_days'] += $marked_days;
                        $overall_summary['total_present_days'] += $present_days;
                        $overall_summary['total_absent_days'] += $absent_days;
                    }
                }
            }
            if ($overall_summary['total_marked_days'] > 0) {
                $overall_summary['overall_percentage'] = round(($overall_summary['total_present_days'] / $overall_summary['total_marked_days']) * 100, 2);
            }

            if(empty($report_data) && empty($message)) { $message = "No attendance data found for any class section in the selected period."; $message_type = 'info';}

        } else { $message = "Error fetching class sections: " . mysqli_error($conn); $message_type = 'error'; }
    }
}

// --- PDF Output Logic ---
if ($output_format == 'pdf' && empty($message_type == 'error') && !empty($report_data) ) {
    $pdf_html_content = "<h1>Overall Institution Attendance Summary</h1>";
    $pdf_html_content .= "<h2>" . htmlspecialchars($filter_details_header) . "</h2>";
    $pdf_html_content .= "<style> body { font-family: Arial, sans-serif; font-size: 10pt; } table { width: 100%; border-collapse: collapse; margin-top: 10px; } th, td { border: 1px solid #ddd; padding: 5px; text-align: left; } th { background-color: #f2f2f2; font-weight: bold; } td.num, th.num {text-align:right;} h1,h2 {text-align:center;} </style>";
    $pdf_html_content .= "<table><thead><tr><th>Academic Year</th><th>Class Section</th><th class='num'>Marked Days</th><th class='num'>Present Days</th><th class='num'>Absent Days</th><th class='num'>Attendance %</th></tr></thead><tbody>";
    foreach ($report_data as $row) {
        $pdf_html_content .= "<tr>";
        $pdf_html_content .= "<td>" . htmlspecialchars($row['academic_year']) . "</td>";
        $pdf_html_content .= "<td>" . htmlspecialchars($row['class_section_display']) . "</td>";
        $pdf_html_content .= "<td class='num'>" . $row['marked_days'] . "</td>";
        $pdf_html_content .= "<td class='num'>" . $row['present_days'] . "</td>";
        $pdf_html_content .= "<td class='num'>" . $row['absent_days'] . "</td>";
        $pdf_html_content .= "<td class='num'>" . $row['percentage'] . "%</td>";
        $pdf_html_content .= "</tr>";
    }
    $pdf_html_content .= "</tbody><tfoot>";
    $pdf_html_content .= "<tr><th colspan='2' style='text-align:right;'>Overall Total:</th>";
    $pdf_html_content .= "<th class='num'>" . $overall_summary['total_marked_days'] . "</th>";
    $pdf_html_content .= "<th class='num'>" . $overall_summary['total_present_days'] . "</th>";
    $pdf_html_content .= "<th class='num'>" . $overall_summary['total_absent_days'] . "</th>";
    $pdf_html_content .= "<th class='num'>" . $overall_summary['overall_percentage'] . "%</th></tr>";
    $pdf_html_content .= "</tfoot></table>";
    generate_and_stream_pdf($pdf_html_content, "overall_attendance_summary", 'P'); // Portrait
}


// --- HTML Output Logic ---
$page_title = "Overall Institution Attendance Summary";
ob_start();
?>
<div class="container-fluid mt-3">
    <h1><?php echo htmlspecialchars($page_title); ?></h1>
    <?php if ($message && $output_format == 'html'): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : ($message_type == 'success' ? 'success' : 'info'); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card p-3 mb-3 bg-light no-print">
        <form action="reports_overall_attendance_summary.php" method="GET">
             <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="start_date" class="form-label">Start Date:</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="end_date" class="form-label">End Date:</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" required>
                </div>
                <!-- Grouping option can be added later if needed -->
                <div class="col-md-auto">
                    <button type="submit" name="view_report" value="html" class="btn btn-primary">View Report</button>
                </div>
                 <div class="col-md-auto">
                    <button type="submit" name="output" value="pdf" formaction="reports_overall_attendance_summary.php?<?php echo http_build_query(array_merge($_GET, ['output'=>'pdf', 'view_report'=>'pdf']));?>" class="btn btn-secondary">Download PDF</button>
                </div>
                <div class="col-md-auto">
                    <button type="button" onclick="window.print();" class="btn btn-info">Print HTML</button>
                </div>
            </div>
        </form>
    </div>

    <?php if ((isset($_GET['view_report']) || $output_format == 'pdf_preview_debug') && empty($message_type == 'error') ): ?>
        <?php if (!empty($report_data)): ?>
            <h2 class="mt-4">Summary for: <?php echo htmlspecialchars($filter_details_header); ?></h2>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover report-table">
                    <thead class="table-light">
                        <tr>
                            <th>Academic Year</th>
                            <th>Class Section</th>
                            <th class="text-end">Marked Days</th>
                            <th class="text-end">Present Days</th>
                            <th class="text-end">Absent Days</th>
                            <th class="text-end">Attendance %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['academic_year']); ?></td>
                                <td><?php echo htmlspecialchars($row['class_section_display']); ?></td>
                                <td class="text-end"><?php echo $row['marked_days']; ?></td>
                                <td class="text-end"><?php echo $row['present_days']; ?></td>
                                <td class="text-end"><?php echo $row['absent_days']; ?></td>
                                <td class="text-end"><?php echo $row['percentage']; ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-group-divider fw-bold">
                        <tr>
                            <td colspan="2" class="text-end">Overall Total:</td>
                            <td class="text-end"><?php echo $overall_summary['total_marked_days']; ?></td>
                            <td class="text-end"><?php echo $overall_summary['total_present_days']; ?></td>
                            <td class="text-end"><?php echo $overall_summary['total_absent_days']; ?></td>
                            <td class="text-end"><?php echo $overall_summary['overall_percentage']; ?>%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php elseif(isset($_GET['view_report'])): ?>
            <p class="alert alert-info mt-3">No attendance data found to summarize for the selected period.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn) && $output_format == 'html') mysqli_close($conn);
include 'layout_authenticated.php';
?>
