<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

include 'config.php';
include 'functions.php';

$current_user_id = current_user_id();
$current_role = current_user_role();

$selected_class_section_id = isset($_GET['class_section_id']) ? (int)$_GET['class_section_id'] : 0;
$selected_student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

$report_data = [];
$student_info = null;
$summary_stats = ['total_school_days' => 0, 'total_present' => 0, 'total_absent' => 0, 'attendance_percentage' => 0];
$message = '';
$message_type = '';

// Fetch class sections for the first dropdown
$class_sections_for_dropdown = [];
$cs_sql = "";
if ($current_role == 'admin') {
    $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
               FROM class_sections cs
               JOIN grades g ON cs.grade_id = g.id
               JOIN divisions d ON cs.division_id = d.id
               ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
} else { // Teacher
    $accessible_section_ids = get_teacher_attendance_accessible_sections($current_user_id, $conn); // Use attendance accessible for this report
    if (!empty($accessible_section_ids)) {
        $ids_str = implode(',', array_map('intval', $accessible_section_ids));
        $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                   FROM class_sections cs
                   JOIN grades g ON cs.grade_id = g.id
                   JOIN divisions d ON cs.division_id = d.id
                   WHERE cs.id IN ($ids_str)
                   ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
    }
}
if (!empty($cs_sql)) {
    $cs_res = mysqli_query($conn, $cs_sql);
    if ($cs_res) while ($row = mysqli_fetch_assoc($cs_res)) $class_sections_for_dropdown[] = $row;
}

// Fetch students for the selected class section (for the second dropdown)
$students_for_dropdown = [];
if ($selected_class_section_id > 0) {
    // Permission check for selected class section
    $can_access_selected_section = false;
    if($current_role == 'admin') $can_access_selected_section = true;
    else { // Teacher
        foreach($class_sections_for_dropdown as $cs_option){
            if($cs_option['id'] == $selected_class_section_id) {
                $can_access_selected_section = true;
                break;
            }
        }
    }

    if($can_access_selected_section){
        $student_sql = "SELECT id, name, roll_number FROM students WHERE class_section_id = $selected_class_section_id ORDER BY name";
        $student_res = mysqli_query($conn, $student_sql);
        if ($student_res) while ($row = mysqli_fetch_assoc($student_res)) $students_for_dropdown[] = $row;
    } else {
        // Should not happen if dropdown is correctly populated, but handles direct URL manipulation
        $message = "You do not have permission to access students from the selected class section.";
        $message_type = "error";
        $selected_class_section_id = 0; // Reset to prevent further processing
    }
}


if (isset($_GET['view_report']) && $selected_student_id > 0 && !empty($start_date) && !empty($end_date)) {
    // Final permission check for the specific student in the specific section
    $can_view_student_report = false;
    if ($current_role == 'admin') {
        $can_view_student_report = true;
    } elseif ($current_role == 'teacher') {
        // Verify student belongs to an accessible section for this teacher
        $student_section_q = mysqli_query($conn, "SELECT class_section_id FROM students WHERE id = $selected_student_id");
        if ($student_section_q && mysqli_num_rows($student_section_q) > 0) {
            $student_actual_cs_id = mysqli_fetch_assoc($student_section_q)['class_section_id'];
            if ($student_actual_cs_id == $selected_class_section_id) { // Student is in the section selected in filter
                 if (is_class_teacher_of_section($current_user_id, $selected_class_section_id, $conn) ||
                    has_delegated_permission($current_user_id, $selected_class_section_id, 'can_take_attendance', $conn)) { // Using attendance permission for this report
                    $can_view_student_report = true;
                }
            }
        }
    }

    if ($can_view_student_report) {
        $student_info_sql = "SELECT s.name as student_name, s.roll_number, s.date_of_birth,
                                    IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display
                             FROM students s
                             JOIN class_sections cs ON s.class_section_id = cs.id
                             JOIN grades g ON cs.grade_id = g.id
                             JOIN divisions d ON cs.division_id = d.id
                             WHERE s.id = $selected_student_id";
        $student_info_res = mysqli_query($conn, $student_info_sql);
        if ($student_info_res && mysqli_num_rows($student_info_res) > 0) {
            $student_info = mysqli_fetch_assoc($student_info_res);

            // Iterate through date range to build the report, fetching attendance for each day
            $current_loop_date = new DateTime($start_date);
            $end_loop_date = new DateTime($end_date);
            $school_days_in_period = 0;

            while ($current_loop_date <= $end_loop_date) {
                $date_str = $current_loop_date->format('Y-m-d');
                $day_of_week = $current_loop_date->format('N'); // 1 (Mon) to 7 (Sun)

                // Optional: Skip weekends if not considered school days for percentage
                // if ($day_of_week >= 6) { // Saturday or Sunday
                //    $current_loop_date->modify('+1 day');
                //    continue;
                // }
                $school_days_in_period++; // Count this day

                $att_sql = "SELECT ar.is_present, ar.notes as teacher_notes,
                                   abr.reason_text as parent_reason, abr.status as reason_status, u.username as reason_submitter
                            FROM attendance_records ar
                            LEFT JOIN absence_reasons abr ON ar.id = abr.attendance_record_id
                            LEFT JOIN users u ON abr.submitted_by_user_id = u.id
                            WHERE ar.student_id = $selected_student_id AND ar.attendance_date = '$date_str'";
                $att_res = mysqli_query($conn, $att_sql);
                $day_data = [
                    'date' => $date_str,
                    'day_of_week' => $current_loop_date->format('l'),
                    'status' => 'Not Marked', // Default
                    'is_present' => null,
                    'teacher_notes' => '',
                    'parent_reason' => '',
                    'reason_status' => '',
                    'reason_submitter' => ''
                ];

                if ($att_res && mysqli_num_rows($att_res) > 0) {
                    $att_row = mysqli_fetch_assoc($att_res);
                    $day_data['is_present'] = $att_row['is_present'];
                    $day_data['status'] = $att_row['is_present'] ? 'Present' : 'Absent';
                    $day_data['teacher_notes'] = $att_row['teacher_notes'];
                    $day_data['parent_reason'] = $att_row['parent_reason'];
                    $day_data['reason_status'] = $att_row['reason_status'];
                    $day_data['reason_submitter'] = $att_row['reason_submitter'];
                }

                $report_data[] = $day_data;

                if ($day_data['is_present'] === 1) {
                    $summary_stats['total_present']++;
                } elseif ($day_data['is_present'] === 0) { // Explicitly absent
                    $summary_stats['total_absent']++;
                } else { // Not marked, also counts towards non-present for percentage
                    $summary_stats['total_absent']++;
                }
                $current_loop_date->modify('+1 day');
            }
            $summary_stats['total_school_days'] = $school_days_in_period;
            if ($summary_stats['total_school_days'] > 0) {
                $summary_stats['attendance_percentage'] = round(($summary_stats['total_present'] / $summary_stats['total_school_days']) * 100, 2);
            }

        } else {
            $message = "Student details not found."; $message_type = 'error';
        }
    } else {
        $message = "You do not have permission to view this student's report."; $message_type = 'error';
    }

} elseif (isset($_GET['view_report'])) {
    $message = "Please select a class section, student, start date, and end date."; $message_type = 'error';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Individual Attendance Record</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding:0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 10px; text-decoration: none; font-weight: bold; }
        .top-nav .user-info { float: right; color: #ddd; font-size: 0.9em; margin-right: 20px; line-height: 2.5em;}
        .top-nav a:hover { text-decoration: underline; }
        .container { width: 95%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2, h3 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .filter-section { margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border-radius: 5px; display: flex; flex-wrap: wrap; align-items: flex-end; gap:10px;}
        .filter-section div { margin-right: 10px; margin-bottom:10px;}
        .filter-section label { font-weight: bold; display:block; margin-bottom:3px;}
        .filter-section select, .filter-section input[type="date"], .filter-section input[type="submit"], .filter-section button {
            padding: 8px; border-radius: 4px; border: 1px solid #ccc;
        }
        .filter-section input[type="submit"], .filter-section button { background-color: #007bff; color:white; cursor:pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 0.9em; vertical-align: top;}
        th { background-color: #f2f2f2; }
        .status-Present { color: green; } .status-Absent { color: red; font-weight:bold; } .status-Not.Marked {color: orange;}
        .parent-reason-report { font-size: 0.9em; color: #444; margin-top:3px; }
        .report-header, .report-summary-stats { margin-bottom: 15px; padding-bottom:10px; border-bottom: 1px solid #eee;}
        .report-summary-stats strong { margin-right:15px; }
         @media print {
            .top-nav, .filter-section, .no-print { display: none !important; }
            .container { width: 100%; margin: 0; padding:0; box-shadow: none; border: none; }
            body { background-color: #fff; }
            table, th, td { font-size: 9pt !important; }
            h1, h2, h3 { margin-top: 5px; padding-top: 0; page-break-after: avoid; }
            .report-summary-stats { page-break-before: auto; page-break-inside: avoid; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
        }
    </style>
    <script>
        function updateStudentDropdown() {
            const classSectionId = document.getElementById('class_section_id').value;
            const studentDropdown = document.getElementById('student_id');
            const currentStudentId = '<?php echo $selected_student_id; ?>'; // Preserve selected student if possible

            // Clear existing student options
            studentDropdown.innerHTML = '<option value=\"\">-- Select Student --</option>';

            if (classSectionId) {
                // Make an AJAX call to fetch students for this class_section_id
                // For simplicity here, we'll just submit the form to reload with students.
                // Or, if students are pre-loaded in JS (not scalable for many students):
                // This example will just enable the student dropdown.
                // A real implementation would populate it via AJAX or have PHP repopulate on form submit.
                <?php
                // This PHP block is illustrative of how JS might get student data,
                // but a proper AJAX solution is better.
                // For now, selecting a class section will require a page reload via form submit if student dropdown needs dynamic update.
                // The current PHP logic re-populates $students_for_dropdown based on $selected_class_section_id on page load.
                ?>
            }
        }
    </script>
</head>
<body>
    <nav class="top-nav no-print">
        <a href="index.php">Home</a>
        <?php if (in_array($current_role, ['admin', 'teacher'])): ?>
            <a href="students.php">Manage Students</a> <a href="attendance.php">Take/View Attendance</a>
            <div style="display:inline-block; position:relative;" class="nav-dropdown-container">
                <a href="#">Reports &#9662;</a>
                <div style="position:absolute; background-color:#333; display:none; min-width:180px; box-shadow:0px 8px 16px 0px rgba(0,0,0,0.2); z-index:1;" class="dropdown-content">
                    <a href="reports_student_master.php" style="display:block; padding:8px 10px; text-align:left;">Student Master List</a>
                    <a href="reports_enrollment_summary.php" style="display:block; padding:8px 10px; text-align:left;">Enrollment Summary</a>
                    <a href="reports_daily_attendance.php" style="display:block; padding:8px 10px; text-align:left;">Daily Attendance</a>
                    <a href="reports_student_individual_attendance.php" style="display:block; padding:8px 10px; text-align:left;">Student Individual Record</a>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($current_role == 'admin'): ?>
            <a href="settings.php">Settings</a> <a href="manage_users.php">Manage Users</a>
            <a href="manage_grades.php">Manage Grades</a> <a href="manage_divisions.php">Manage Divisions</a>
            <a href="manage_class_sections.php">Manage Class Sections</a> <a href="delegate_tasks.php">Delegate Tasks</a>
        <?php elseif ($current_role == 'teacher'): ?> <a href="delegate_tasks.php">Delegate Tasks</a> <?php endif; ?>
        <span class="user-info">Logged in as: <?php echo htmlspecialchars(current_username()); ?> (<?php echo htmlspecialchars($current_role); ?>)</span>
        <a href="logout.php" style="float:right;">Logout</a>
    </nav>
     <script>
        document.querySelectorAll('.nav-dropdown-container').forEach(item => {
            item.addEventListener('mouseover', () => { item.querySelector('.dropdown-content').style.display = 'block'; });
            item.addEventListener('mouseout', () => { item.querySelector('.dropdown-content').style.display = 'none'; });
        });
    </script>

    <div class="container">
        <h1>Student Individual Attendance Record</h1>
        <?php if ($message): ?> <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div> <?php endif; ?>

        <div class="filter-section no-print">
            <form action="reports_student_individual_attendance.php" method="GET">
                <div>
                    <label for="class_section_id">Class Section:</label>
                    <select name="class_section_id" id="class_section_id" required onchange="this.form.submit()"> <!-- Submit form on change to populate students -->
                        <option value="">-- Select Class Section --</option>
                        <?php foreach ($class_sections_for_dropdown as $cs): ?>
                            <option value="<?php echo $cs['id']; ?>" <?php echo ($selected_class_section_id == $cs['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cs['display_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="student_id">Student:</label>
                    <select name="student_id" id="student_id" required <?php if(empty($students_for_dropdown)) echo "disabled";?>>
                        <option value="">-- Select Student --</option>
                        <?php foreach ($students_for_dropdown as $student): ?>
                            <option value="<?php echo $student['id']; ?>" <?php echo ($selected_student_id == $student['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($student['name']) . " (Roll: " . htmlspecialchars($student['roll_number']) . ")"; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="start_date">Start Date:</label>
                    <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>" required>
                </div>
                <div>
                    <label for="end_date">End Date:</label>
                    <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>" required>
                </div>
                <div>
                    <input type="submit" name="view_report" value="View Report">
                </div>
                <div>
                     <button type="button" onclick="window.print();">Print Report</button>
                </div>
            </form>
        </div>

        <?php if (isset($_GET['view_report']) && $student_info && empty($message_type == 'error')): ?>
            <div class="report-header">
                <h3>Attendance Record For:</h3>
                <p><strong>Student:</strong> <?php echo htmlspecialchars($student_info['student_name']); ?></p>
                <p><strong>Roll Number:</strong> <?php echo htmlspecialchars($student_info['roll_number']); ?></p>
                <p><strong>Class Section:</strong> <?php echo htmlspecialchars($student_info['class_section_display']); ?></p>
                <p><strong>Date of Birth:</strong> <?php echo $student_info['date_of_birth'] ? date("M j, Y", strtotime($student_info['date_of_birth'])) : '-'; ?></p>
                <p><strong>Report Period:</strong> <?php echo date("M j, Y", strtotime($start_date)); ?> to <?php echo date("M j, Y", strtotime($end_date)); ?></p>
            </div>

            <div class="report-summary-stats">
                <strong>Total School Days in Period:</strong> <?php echo $summary_stats['total_school_days']; ?> |
                <strong>Present:</strong> <?php echo $summary_stats['total_present']; ?> |
                <strong>Absent / Not Marked:</strong> <?php echo $summary_stats['total_absent']; ?> |
                <strong>Attendance Percentage:</strong> <?php echo $summary_stats['attendance_percentage']; ?>%
            </div>

            <?php if (!empty($report_data)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Status</th>
                            <th>Teacher Notes</th>
                            <th>Parent Submitted Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $record): ?>
                            <tr>
                                <td><?php echo date("M j, Y", strtotime($record['date'])); ?></td>
                                <td><?php echo htmlspecialchars($record['day_of_week']); ?></td>
                                <td class="status-<?php echo str_replace(' ','',htmlspecialchars($record['status'])); ?>">
                                    <?php echo htmlspecialchars($record['status']); ?>
                                </td>
                                <td><?php echo !empty($record['teacher_notes']) ? nl2br(htmlspecialchars($record['teacher_notes'])) : '-'; ?></td>
                                <td>
                                    <?php if ($record['is_present'] === 0 && !empty($record['parent_reason'])): ?>
                                        <div class="parent-reason-report">
                                            <strong><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$record['reason_status']))); ?>:</strong>
                                            <?php echo nl2br(htmlspecialchars($record['parent_reason'])); ?>
                                            <br><small>(By: <?php echo htmlspecialchars($record['reason_submitter'] ?: 'Parent'); ?>)</small>
                                        </div>
                                    <?php elseif ($record['is_present'] === 0): ?> <small>-</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="info">No attendance records found for this student in the selected period.</p>
            <?php endif; ?>
        <?php elseif (isset($_GET['view_report']) && empty($message_type == 'error') ): ?>
             <p class="info">Please select a student and date range to generate the report.</p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php if(isset($conn)) mysqli_close($conn); ?>
