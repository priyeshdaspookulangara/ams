<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']); // Admins and Teachers can view this report

include 'config.php';

$current_role = current_user_role();
$current_user_id = current_user_id();

$students_data = [];
$filter_class_section_id = isset($_GET['filter_cs_id']) ? (int)$_GET['filter_cs_id'] : 'all';
$filter_academic_year = isset($_GET['filter_ay']) ? mysqli_real_escape_string($conn, $_GET['filter_ay']) : 'all';


// Fetch class sections for filtering
$class_sections_for_filter = [];
$cs_filter_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                  FROM class_sections cs
                  JOIN grades g ON cs.grade_id = g.id
                  JOIN divisions d ON cs.division_id = d.id
                  ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
$cs_filter_res = mysqli_query($conn, $cs_filter_sql);
if ($cs_filter_res) while ($row = mysqli_fetch_assoc($cs_filter_res)) $class_sections_for_filter[] = $row;

// Fetch distinct academic years for filtering
$academic_years_for_filter = [];
$ay_filter_sql = "SELECT DISTINCT academic_year FROM class_sections ORDER BY academic_year DESC";
$ay_filter_res = mysqli_query($conn, $ay_filter_sql);
if ($ay_filter_res) while ($row = mysqli_fetch_assoc($ay_filter_res)) $academic_years_for_filter[] = $row['academic_year'];


// Base SQL to fetch student master list
$sql = "SELECT s.id as student_id, s.name as student_name, s.roll_number, s.date_of_birth,
               cs.academic_year,
               IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display
        FROM students s
        JOIN class_sections cs ON s.class_section_id = cs.id
        JOIN grades g ON cs.grade_id = g.id
        JOIN divisions d ON cs.division_id = d.id
        WHERE 1=1";

if ($filter_class_section_id !== 'all' && $filter_class_section_id > 0) {
    $sql .= " AND s.class_section_id = $filter_class_section_id";
}
if ($filter_academic_year !== 'all' && !empty($filter_academic_year)) {
    $sql .= " AND cs.academic_year = '$filter_academic_year'";
}

// Teacher role restriction: only see students from classes they manage or are delegated to manage students for.
// This is a simplified view for the report; a more complex report might need to list all students for general school directory.
// For now, teachers will see students based on their student management permissions.
if ($current_role == 'teacher') {
    $manageable_cs_ids = get_teacher_student_manageable_sections($current_user_id, $conn);
    if (!empty($manageable_cs_ids)) {
        $ids_str = implode(',', $manageable_cs_ids);
        $sql .= " AND s.class_section_id IN ($ids_str)";
    } else {
        $sql .= " AND 1=0"; // No manageable sections, show no students
    }
}

$sql .= " ORDER BY cs.academic_year DESC, g.grade_name, d.division_name, s.roll_number, s.name";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($student_row = mysqli_fetch_assoc($result)) {
        // Fetch parent/guardian details for this student
        $pg_sql = "SELECT parent_name, phone_number, relationship
                   FROM parent_guardians
                   WHERE student_id = " . $student_row['student_id'] . " ORDER BY relationship";
        $pg_res = mysqli_query($conn, $pg_sql);
        $student_row['parents'] = [];
        if ($pg_res) {
            while ($parent_row = mysqli_fetch_assoc($pg_res)) {
                $student_row['parents'][] = $parent_row;
            }
        }
        $students_data[] = $student_row;
    }
} else {
    $message = "Error fetching student data: " . mysqli_error($conn);
    $message_type = 'error';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Master List</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding:0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 10px; text-decoration: none; font-weight: bold; }
        .top-nav .user-info { float: right; color: #ddd; font-size: 0.9em; margin-right: 20px; line-height: 2.5em;}
        .top-nav a:hover { text-decoration: underline; }
        .container { width: 95%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .filter-section { margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border-radius: 5px; }
        .filter-section label { font-weight: bold; margin-right: 5px; }
        .filter-section select, .filter-section input[type="submit"] { padding: 8px; margin-right: 10px; border-radius: 4px; border: 1px solid #ccc; }
        .filter-section input[type="submit"] { background-color: #007bff; color:white; cursor:pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 0.9em; vertical-align: top;}
        th { background-color: #f2f2f2; }
        .parent-info { list-style-type: none; padding-left: 0; margin:0;}
        .parent-info li { margin-bottom: 3px; }
        @media print {
            .top-nav, .filter-section, .action-links, .no-print { display: none !important; }
            .container { width: 100%; margin: 0; padding:0; box-shadow: none; border: none; }
            table, th, td { font-size: 10pt !important; } /* Adjust font size for print */
        }
    </style>
</head>
<body>
    <nav class="top-nav no-print">
        <a href="index.php">Home</a>
        <?php if (in_array($current_role, ['admin', 'teacher'])): ?>
            <a href="students.php">Manage Students</a>
            <a href="attendance.php">Take/View Attendance</a>
            <div style="display:inline-block; position:relative;">
                <a href="#">Reports &#9662;</a>
                <div style="position:absolute; background-color:#333; display:none; min-width:160px; box-shadow:0px 8px 16px 0px rgba(0,0,0,0.2); z-index:1;" class="dropdown-content">
                    <a href="reports_student_master.php" style="display:block; padding:8px 10px; text-align:left;">Student Master List</a>
                    <a href="reports_enrollment_summary.php" style="display:block; padding:8px 10px; text-align:left;">Enrollment Summary</a>
                    <!-- More reports here -->
                </div>
            </div>
        <?php endif; ?>
        <?php if ($current_role == 'admin'): ?>
            <a href="settings.php">Settings</a>
            <a href="manage_users.php">Manage Users</a>
            <a href="manage_grades.php">Manage Grades</a>
            <a href="manage_divisions.php">Manage Divisions</a>
            <a href="manage_class_sections.php">Manage Class Sections</a>
            <a href="delegate_tasks.php">Delegate Tasks</a>
        <?php elseif ($current_role == 'teacher'): ?>
             <a href="delegate_tasks.php">Delegate Tasks</a>
        <?php endif; ?>
        <span class="user-info">Logged in as: <?php echo htmlspecialchars(current_username()); ?> (<?php echo htmlspecialchars($current_role); ?>)</span>
        <a href="logout.php" style="float:right;">Logout</a>
    </nav>
    <script> // Simple dropdown for nav
        document.querySelectorAll('.top-nav > div').forEach(item => {
            item.addEventListener('mouseover', () => { item.querySelector('.dropdown-content').style.display = 'block'; });
            item.addEventListener('mouseout', () => { item.querySelector('.dropdown-content').style.display = 'none'; });
        });
    </script>

    <div class="container">
        <h1>Student Master List / Directory</h1>
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="filter-section no-print">
            <form action="reports_student_master.php" method="GET">
                <label for="filter_ay">Academic Year:</label>
                <select name="filter_ay" id="filter_ay">
                    <option value="all">All Years</option>
                    <?php foreach ($academic_years_for_filter as $ay): ?>
                        <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo ($filter_academic_year == $ay) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ay); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="filter_cs_id">Class Section:</label>
                <select name="filter_cs_id" id="filter_cs_id">
                    <option value="all">All Sections</option>
                    <?php foreach ($class_sections_for_filter as $cs): // All sections for admin, teacher's might be pre-filtered in SQL ?>
                        <option value="<?php echo $cs['id']; ?>" <?php echo ($filter_class_section_id == $cs['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cs['display_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" value="Filter">
                <button type="button" onclick="window.print();" style="margin-left: 10px;">Print Report</button>
            </form>
        </div>

        <?php if (!empty($students_data)): ?>
            <p>Total Students Found: <?php echo count($students_data); ?></p>
            <table>
                <thead>
                    <tr>
                        <th>Std. ID</th>
                        <th>Name</th>
                        <th>Roll No.</th>
                        <th>Date of Birth</th>
                        <th>Class Section</th>
                        <th>Academic Year</th>
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
                                    <ul class="parent-info">
                                    <?php foreach ($student['parents'] as $parent): ?>
                                        <li>
                                            <strong><?php echo htmlspecialchars($parent['parent_name']); ?></strong>
                                            (<?php echo htmlspecialchars($parent['relationship']); ?>)
                                            <br>Ph: <?php echo htmlspecialchars($parent['phone_number']); ?>
                                        </li>
                                    <?php endforeach; ?>
                                    </ul>
                                <?php else: echo 'No contact info.'; endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No students found matching the criteria.</p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php if(isset($conn)) mysqli_close($conn); ?>
