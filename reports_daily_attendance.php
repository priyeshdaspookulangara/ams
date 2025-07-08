<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

include 'config.php';
include 'functions.php'; // For get_teacher_attendance_accessible_sections if not already included by auth_check

$current_user_id = current_user_id();
$current_role = current_user_role();

$selected_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');
$selected_class_section_id = isset($_GET['class_section_id']) ? (int)$_GET['class_section_id'] : 0;

$report_data = [];
$class_section_name = '';
$total_students = 0;
$total_present = 0;
$total_absent = 0;
$message = '';
$message_type = '';

// Fetch class sections for the dropdown
$class_sections_for_dropdown = [];
if ($current_role == 'admin') {
    $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
               FROM class_sections cs
               JOIN grades g ON cs.grade_id = g.id
               JOIN divisions d ON cs.division_id = d.id
               ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
} else { // Teacher
    $accessible_section_ids = get_teacher_attendance_accessible_sections($current_user_id, $conn);
    if (!empty($accessible_section_ids)) {
        $ids_str = implode(',', array_map('intval', $accessible_section_ids));
        $cs_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                   FROM class_sections cs
                   JOIN grades g ON cs.grade_id = g.id
                   JOIN divisions d ON cs.division_id = d.id
                   WHERE cs.id IN ($ids_str)
                   ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
    } else {
        $cs_sql = ""; // No accessible sections
    }
}

if (!empty($cs_sql)) {
    $cs_res = mysqli_query($conn, $cs_sql);
    if ($cs_res) while ($row = mysqli_fetch_assoc($cs_res)) $class_sections_for_dropdown[] = $row;
}


if (isset($_GET['view_report']) && $selected_class_section_id > 0 && !empty($selected_date)) {
    // Permission check: Can current user view report for this section?
    $can_view_report = false;
    if ($current_role == 'admin') {
        $can_view_report = true;
    } elseif ($current_role == 'teacher') {
        if (is_class_teacher_of_section($current_user_id, $selected_class_section_id, $conn) ||
            has_delegated_permission($current_user_id, $selected_class_section_id, 'can_take_attendance', $conn)) { // Assuming 'can_take_attendance' implies can view daily report
            $can_view_report = true;
        }
    }

    if ($can_view_report) {
        $section_name_sql = "SELECT IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                             FROM class_sections cs
                             JOIN grades g ON cs.grade_id = g.id
                             JOIN divisions d ON cs.division_id = d.id
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
                if (isset($row['is_present'])) { // Attendance was taken
                    if ($row['is_present'] == 1) $total_present++;
                    else $total_absent++;
                } else { // Attendance not taken for this student on this day
                    $total_absent++; // Or consider them 'Not Marked' - for simplicity, count as absent for summary
                }
            }
            if($total_students == 0){
                 $message = "No students found in the selected class section.";
                 $message_type = 'info';
            }
        } else {
            $message = "Error generating report: " . mysqli_error($conn);
            $message_type = 'error';
        }
    } else {
        $message = "You do not have permission to view the report for this class section.";
        $message_type = 'error';
    }
} elseif (isset($_GET['view_report']) && ($selected_class_section_id == 0 || empty($selected_date))) {
    $message = "Please select a date and a class section to view the report.";
    $message_type = 'error';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Attendance Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding:0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 10px; text-decoration: none; font-weight: bold; }
        .top-nav .user-info { float: right; color: #ddd; font-size: 0.9em; margin-right: 20px; line-height: 2.5em;}
        .top-nav a:hover { text-decoration: underline; }
        .container { width: 95%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .filter-section { margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border-radius: 5px; }
        .filter-section label { font-weight: bold; margin-right: 5px; }
        .filter-section input[type="date"], .filter-section select, .filter-section input[type="submit"], .filter-section button {
            padding: 8px; margin-right: 10px; border-radius: 4px; border: 1px solid #ccc;
        }
        .filter-section input[type="submit"], .filter-section button { background-color: #007bff; color:white; cursor:pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 0.9em; vertical-align: top;}
        th { background-color: #f2f2f2; }
        .status-present { color: green; }
        .status-absent { color: red; font-weight: bold; }
        .status-notmarked { color: orange; }
        .parent-reason-report { font-size: 0.9em; color: #444; margin-top:3px; }
        .report-summary { margin-top: 15px; padding: 10px; background-color: #e9ecef; border-radius: 4px;}
        .report-summary strong { margin-right: 15px; }
         @media print {
            .top-nav, .filter-section, .no-print { display: none !important; }
            .container { width: 100%; margin: 0; padding:0; box-shadow: none; border: none; }
            body { background-color: #fff; }
            table, th, td { font-size: 9pt !important; }
            h1, h2 { margin-top: 5px; padding-top: 0; }
        }
    </style>
</head>
<body>
    <nav class="top-nav no-print">
        <a href="index.php">Home</a>
        <?php if (in_array($current_role, ['admin', 'teacher'])): ?>
            <a href="students.php">Manage Students</a>
            <a href="attendance.php">Take/View Attendance</a>
            <div style="display:inline-block; position:relative;" class="nav-dropdown-container">
                <a href="#">Reports &#9662;</a>
                <div style="position:absolute; background-color:#333; display:none; min-width:160px; box-shadow:0px 8px 16px 0px rgba(0,0,0,0.2); z-index:1;" class="dropdown-content">
                    <a href="reports_student_master.php" style="display:block; padding:8px 10px; text-align:left;">Student Master List</a>
                    <a href="reports_enrollment_summary.php" style="display:block; padding:8px 10px; text-align:left;">Enrollment Summary</a>
                    <a href="reports_daily_attendance.php" style="display:block; padding:8px 10px; text-align:left;">Daily Attendance</a>
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
        <h1>Daily Attendance Report</h1>
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="filter-section no-print">
            <form action="reports_daily_attendance.php" method="GET">
                <label for="report_date">Date:</label>
                <input type="date" name="report_date" id="report_date" value="<?php echo htmlspecialchars($selected_date); ?>" required>

                <label for="class_section_id">Class Section:</label>
                <select name="class_section_id" id="class_section_id" required>
                    <option value="">-- Select Class Section --</option>
                    <?php foreach ($class_sections_for_dropdown as $cs): ?>
                        <option value="<?php echo $cs['id']; ?>" <?php echo ($selected_class_section_id == $cs['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cs['display_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" name="view_report" value="View Report">
                <button type="button" onclick="window.print();" style="margin-left: 10px;">Print Report</button>
            </form>
        </div>

        <?php if (isset($_GET['view_report']) && !empty($report_data) && empty($message_type == 'error')): ?>
            <h2>Report for: <?php echo htmlspecialchars($class_section_name); ?> on <?php echo date("D, M j, Y", strtotime($selected_date)); ?></h2>

            <div class="report-summary">
                <strong>Total Students:</strong> <?php echo $total_students; ?> |
                <strong>Present:</strong> <?php echo $total_present; ?> |
                <strong>Absent / Not Marked:</strong> <?php echo $total_absent; ?>
            </div>

            <table>
                <thead>
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
                                <?php if (!$student['is_present'] && !empty($student['parent_reason'])): // Show only if absent and reason exists ?>
                                    <div class="parent-reason-report">
                                        <strong><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$student['reason_status']))); ?>:</strong>
                                        <?php echo nl2br(htmlspecialchars($student['parent_reason'])); ?>
                                        <br><small>(By: <?php echo htmlspecialchars($student['reason_submitter'] ?: 'Parent'); ?>)</small>
                                    </div>
                                <?php elseif (!$student['is_present'] && isset($student['is_present'])) : // Absent but no reason ?>
                                    <small>-</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif (isset($_GET['view_report']) && $selected_class_section_id > 0 && empty($message_type == 'error')): ?>
            <p class="info">No attendance data or students found for the selected criteria.</p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php if(isset($conn)) mysqli_close($conn); ?>
