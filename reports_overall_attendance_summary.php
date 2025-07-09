<?php
include_once 'auth_check.php';
require_login(['admin']);

$page_title = "Overall Institution Attendance Summary";

include 'config.php';
// functions.php not strictly needed by this page's core logic after auth_check

$current_user_id = current_user_id(); // Though not used directly in logic for admin-only report
$current_role = current_user_role();

$output_format = (isset($_GET['output']) && $_GET['output'] == 'pdf' && isset($_GET['view_report'])) ? 'pdf' : 'html';
$default_start_date = date('Y-m-01');
$default_end_date = date('Y-m-t');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $default_start_date;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : $default_end_date;

$report_data = [];
$overall_summary = ['total_marked_days' => 0, 'total_present_days' => 0, 'total_absent_days' => 0, 'overall_percentage' => 0];
$filter_details_header = '';
$message = '';
$message_type = '';
$report_html_content = ''; // For PDF and HTML display

$academic_years_for_filter = []; // For potential future filter, not used in query yet
$ay_filter_sql = "SELECT DISTINCT academic_year FROM class_sections ORDER BY academic_year DESC";
$ay_filter_res = mysqli_query($conn, $ay_filter_sql);
if ($ay_filter_res) while ($row = mysqli_fetch_assoc($ay_filter_res)) $academic_years_for_filter[] = $row['academic_year'];


if (isset($_GET['view_report']) || $output_format == 'pdf') {
    if (empty($start_date) || empty($end_date)) {
        $message = "Please select a valid date range."; $message_type = 'error';
    } elseif (strtotime($end_date) < strtotime($start_date)) {
        $message = "End date cannot be before start date."; $message_type = 'error';
    } else {
        $filter_details_header = "Period: " . date("M j, Y", strtotime($start_date)) . " to " . date("M j, Y", strtotime($end_date));
        $class_sections_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display, cs.academic_year FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
        $cs_result = mysqli_query($conn, $class_sections_sql);

        if ($cs_result) {
            while ($cs_row = mysqli_fetch_assoc($cs_result)) {
                $cs_id = $cs_row['id'];
                $section_summary_sql = "SELECT COUNT(ar.id) as marked_days, SUM(CASE WHEN ar.is_present = 1 THEN 1 ELSE 0 END) as present_days FROM attendance_records ar JOIN students s ON ar.student_id = s.id WHERE s.class_section_id = $cs_id AND ar.attendance_date BETWEEN '$start_date' AND '$end_date'";
                $summary_res = mysqli_query($conn, $section_summary_sql);
                if ($summary_res && mysqli_num_rows($summary_res) > 0) {
                    $summary_row = mysqli_fetch_assoc($summary_res);
                    $marked_days = (int)$summary_row['marked_days'];
                    $present_days = (int)$summary_row['present_days'];
                    if ($marked_days > 0) {
                        $absent_days = $marked_days - $present_days;
                        $percentage = round(($present_days / $marked_days) * 100, 2);
                        $report_data[] = ['class_section_display' => $cs_row['class_section_display'], 'academic_year' => $cs_row['academic_year'], 'marked_days' => $marked_days, 'present_days' => $present_days, 'absent_days' => $absent_days, 'percentage' => $percentage];
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

            // Generate HTML for report content
            ob_start();
            if(!empty($report_data)){
            ?>
                <h2 class="mt-4">Summary for: <?php echo htmlspecialchars($filter_details_header); ?></h2>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover report-table">
                        <thead class="table-light"><tr><th>Academic Year</th><th>Class Section</th><th class="text-end">Marked Days</th><th class="text-end">Present Days</th><th class="text-end">Absent Days</th><th class="text-end">Attendance %</th></tr></thead>
                        <tbody>
                        <?php
                        $current_ay_for_total_html = null; $ay_subtotal_html_marked = 0; $ay_subtotal_html_present = 0; $ay_subtotal_html_absent = 0;
                        foreach ($report_data as $index_html => $row):
                            if ($current_ay_for_total_html !== null && $row['academic_year'] !== $current_ay_for_total_html) {
                                $ay_perc = ($ay_subtotal_html_marked > 0) ? round(($ay_subtotal_html_present / $ay_subtotal_html_marked) * 100, 2) : 0;
                                echo "<tr class='table-group-divider fw-semibold bg-light-subtle'><td colspan='2' class='text-end'>Subtotal for " . htmlspecialchars($current_ay_for_total_html) . ":</td><td class='text-end'>".$ay_subtotal_html_marked."</td><td class='text-end'>".$ay_subtotal_html_present."</td><td class='text-end'>".$ay_subtotal_html_absent."</td><td class='text-end'>".$ay_perc."%</td></tr>";
                                $ay_subtotal_html_marked = 0; $ay_subtotal_html_present = 0; $ay_subtotal_html_absent = 0;
                            }
                            $current_ay_for_total_html = $row['academic_year'];
                            $ay_subtotal_html_marked += $row['marked_days']; $ay_subtotal_html_present += $row['present_days']; $ay_subtotal_html_absent += $row['absent_days'];
                        ?>
                            <tr><td><?php echo htmlspecialchars($row['academic_year']); ?></td><td><?php echo htmlspecialchars($row['class_section_display']); ?></td><td class="text-end"><?php echo $row['marked_days']; ?></td><td class="text-end"><?php echo $row['present_days']; ?></td><td class="text-end"><?php echo $row['absent_days']; ?></td><td class="text-end"><?php echo $row['percentage']; ?>%</td></tr>
                        <?php
                            if ($index_html === count($report_data) - 1) {
                                $ay_perc = ($ay_subtotal_html_marked > 0) ? round(($ay_subtotal_html_present / $ay_subtotal_html_marked) * 100, 2) : 0;
                                echo "<tr class='table-group-divider fw-semibold bg-light-subtle'><td colspan='2' class='text-end'>Subtotal for " . htmlspecialchars($current_ay_for_total_html) . ":</td><td class='text-end'>".$ay_subtotal_html_marked."</td><td class='text-end'>".$ay_subtotal_html_present."</td><td class='text-end'>".$ay_subtotal_html_absent."</td><td class='text-end'>".$ay_perc."%</td></tr>";
                            }
                        endforeach;
                        ?>
                        </tbody>
                        <tfoot class="table-dark"><tr><th colspan="2" class="text-end">Overall Total:</th><th class="text-end"><?php echo $overall_summary['total_marked_days']; ?></th><th class="text-end"><?php echo $overall_summary['total_present_days']; ?></th><th class="text-end"><?php echo $overall_summary['total_absent_days']; ?></th><th class="text-end"><?php echo $overall_summary['overall_percentage']; ?>%</th></tr></tfoot>
                    </table>
                </div>
            <?php
            } elseif(isset($_GET['view_report'])) {
                 echo '<p class="alert alert-info mt-3">No attendance data found to summarize for the selected criteria.</p>';
            }
            $report_html_content = ob_get_clean();

        } else { if(empty($message)) {$message = "Error fetching class sections: " . mysqli_error($conn); $message_type = 'error';} }
    }
}

if ($output_format == 'pdf' && empty($message_type == 'error') && !empty($report_data) ) {
    $pdf_title = "<h1>Overall Institution Attendance Summary</h1>";
    $pdf_title .= "<h2>" . htmlspecialchars($filter_details_header) . "</h2>";
    $pdf_css = "<style> body { font-family: Arial, sans-serif; font-size: 9pt; } table { width: 100%; border-collapse: collapse; margin-top: 10px; } th, td { border: 1px solid #ccc; padding: 4px; text-align: left; } th { background-color: #f0f0f0; font-weight: bold; } td.num, th.num {text-align:right;} h1,h2 {text-align:center; margin-bottom:5px;} tfoot th {background-color: #333; color:white;} </style>";
    // Regenerate table HTML specifically for PDF to ensure subtotals are included correctly if needed
    $pdf_table_html = "<table><thead><tr><th>Academic Year</th><th>Class Section</th><th class='num'>Marked Days</th><th class='num'>Present Days</th><th class='num'>Absent Days</th><th class='num'>Attendance %</th></tr></thead><tbody>";
    $loop_current_ay_pdf = null; $loop_ay_subtotal_pdf_marked = 0; $loop_ay_subtotal_pdf_present = 0; $loop_ay_subtotal_pdf_absent = 0;
    foreach ($report_data as $idx_pdf => $row_pdf) {
        if ($loop_current_ay_pdf !== null && $row_pdf['academic_year'] !== $loop_current_ay_pdf) {
            $ay_perc_pdf = ($loop_ay_subtotal_pdf_marked > 0) ? round(($loop_ay_subtotal_pdf_present / $loop_ay_subtotal_pdf_marked) * 100, 2) : 0;
            $pdf_table_html .= "<tr style='font-weight:bold; background-color:#f9f9f9;'><td colspan='2' style='text-align:right;'>Subtotal for " . htmlspecialchars($loop_current_ay_pdf) . ":</td><td class='num'>".$loop_ay_subtotal_pdf_marked."</td><td class='num'>".$loop_ay_subtotal_pdf_present."</td><td class='num'>".$loop_ay_subtotal_pdf_absent."</td><td class='num'>".$ay_perc_pdf."%</td></tr>";
            $loop_ay_subtotal_pdf_marked = 0; $loop_ay_subtotal_pdf_present = 0; $loop_ay_subtotal_pdf_absent = 0;
        }
        $loop_current_ay_pdf = $row_pdf['academic_year'];
        $loop_ay_subtotal_pdf_marked += $row_pdf['marked_days']; $loop_ay_subtotal_pdf_present += $row_pdf['present_days']; $loop_ay_subtotal_pdf_absent += $row_pdf['absent_days'];
        $pdf_table_html .= "<tr><td>" . htmlspecialchars($row_pdf['academic_year']) . "</td><td>" . htmlspecialchars($row_pdf['class_section_display']) . "</td><td class='num'>" . $row_pdf['marked_days'] . "</td><td class='num'>" . $row_pdf['present_days'] . "</td><td class='num'>" . $row_pdf['absent_days'] . "</td><td class='num'>" . $row_pdf['percentage'] . "%</td></tr>";
        if ($idx_pdf === count($report_data) - 1) {
            $ay_perc_pdf = ($loop_ay_subtotal_pdf_marked > 0) ? round(($loop_ay_subtotal_pdf_present / $loop_ay_subtotal_pdf_marked) * 100, 2) : 0;
            $pdf_table_html .= "<tr style='font-weight:bold; background-color:#f9f9f9;'><td colspan='2' style='text-align:right;'>Subtotal for " . htmlspecialchars($loop_current_ay_pdf) . ":</td><td class='num'>".$loop_ay_subtotal_pdf_marked."</td><td class='num'>".$loop_ay_subtotal_pdf_present."</td><td class='num'>".$loop_ay_subtotal_pdf_absent."</td><td class='num'>".$ay_perc_pdf."%</td></tr>";
        }
    }
    $pdf_table_html .= "</tbody><tfoot><tr><th colspan='2' style='text-align:right;'>Overall Total:</th><th class='num'>" . $overall_summary['total_marked_days'] . "</th><th class='num'>" . $overall_summary['total_present_days'] . "</th><th class='num'>" . $overall_summary['total_absent_days'] . "</th><th class='num'>" . $overall_summary['overall_percentage'] . "%</th></tr></tfoot></table>";

    $full_html_for_pdf = "<html><head><meta charset='UTF-8'>{$pdf_css}</head><body>" . $pdf_title . $pdf_table_html . "</body></html>";
    generate_and_stream_pdf($full_html_for_pdf, "overall_attendance_summary", 'P');
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
                <div class="col-md-auto">
                    <button type="submit" name="view_report" value="html" class="btn btn-primary">View Report</button>
                </div>
                 <div class="col-md-auto">
                    <button type="submit" name="output" value="pdf" formaction="reports_overall_attendance_summary.php?<?php echo http_build_query(array_merge($_GET, ['output'=>'pdf', 'view_report'=>'pdf']));?>" class="btn btn-secondary" <?php if(empty($report_data)) echo "disabled";?>>Download PDF</button>
                </div>
                <div class="col-md-auto">
                    <button type="button" onclick="window.print();" class="btn btn-info" <?php if(empty($report_data)) echo "disabled";?>>Print HTML</button>
                </div>
            </div>
        </form>
    </div>

    <?php
    if ($output_format == 'html' && isset($_GET['view_report']) && empty($message_type == 'error') ) {
        echo $report_html_content; // This variable now holds the generated table and summary
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
