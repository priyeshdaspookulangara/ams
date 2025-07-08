<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']); // Admins and Teachers can view this report

include 'config.php';

$current_role = current_user_role();
$current_user_id = current_user_id();

$enrollment_data = [];
$filter_academic_year = isset($_GET['filter_ay']) ? mysqli_real_escape_string($conn, $_GET['filter_ay']) : 'all';

// Fetch distinct academic years for filtering
$academic_years_for_filter = [];
$ay_filter_sql = "SELECT DISTINCT academic_year FROM class_sections ORDER BY academic_year DESC";
$ay_filter_res = mysqli_query($conn, $ay_filter_sql);
if ($ay_filter_res) while ($row = mysqli_fetch_assoc($ay_filter_res)) $academic_years_for_filter[] = $row['academic_year'];

// Base SQL to fetch enrollment summary
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

// Teacher role restriction:
// If a teacher views this, they should ideally see summaries for sections they have access to.
// However, an enrollment summary is often a school-wide view.
// For simplicity in this version, teachers will see the same summary as admins.
// A more granular report might list only sections they are involved with.
// No specific class_section_id filter here, as it's a summary *by* class_section.

$sql .= " GROUP BY cs.id, cs.academic_year, class_section_display
          ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $enrollment_data[] = $row;
    }
} else {
    $message = "Error fetching enrollment data: " . mysqli_error($conn);
    $message_type = 'error';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Enrollment Summary</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding:0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 10px; text-decoration: none; font-weight: bold; }
        .top-nav .user-info { float: right; color: #ddd; font-size: 0.9em; margin-right: 20px; line-height: 2.5em;}
        .top-nav a:hover { text-decoration: underline; }
        .container { width: 80%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .filter-section { margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border-radius: 5px; }
        .filter-section label { font-weight: bold; margin-right: 5px; }
        .filter-section select, .filter-section input[type="submit"] { padding: 8px; margin-right: 10px; border-radius: 4px; border: 1px solid #ccc; }
        .filter-section input[type="submit"] { background-color: #007bff; color:white; cursor:pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        td.count { text-align: center; font-weight: bold; }
         @media print {
            .top-nav, .filter-section, .action-links, .no-print { display: none !important; }
            .container { width: 100%; margin: 0; padding:0; box-shadow: none; border: none; }
            table, th, td { font-size: 10pt !important; }
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
        <h1>Student Enrollment Summary</h1>
        <?php if (isset($message) && $message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="filter-section no-print">
            <form action="reports_enrollment_summary.php" method="GET">
                <label for="filter_ay">Filter by Academic Year:</label>
                <select name="filter_ay" id="filter_ay" onchange="this.form.submit()">
                    <option value="all">All Academic Years</option>
                    <?php foreach ($academic_years_for_filter as $ay): ?>
                        <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo ($filter_academic_year == $ay) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ay); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><input type="submit" value="Filter"></noscript>
                 <button type="button" onclick="window.print();" style="margin-left: 10px;">Print Report</button>
            </form>
        </div>

        <?php if (!empty($enrollment_data)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Academic Year</th>
                        <th>Class Section</th>
                        <th>Number of Students</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $grand_total_students = 0;
                    $current_ay_for_total = null;
                    $ay_subtotal = 0;
                    foreach ($enrollment_data as $index => $row):
                        if ($current_ay_for_total !== null && $row['academic_year'] !== $current_ay_for_total) {
                            echo "<tr><td colspan='2' style='text-align:right; font-weight:bold;'>Subtotal for " . htmlspecialchars($current_ay_for_total) . ":</td><td class='count'>" . $ay_subtotal . "</td></tr>";
                            $ay_subtotal = 0;
                        }
                        $current_ay_for_total = $row['academic_year'];
                        $ay_subtotal += $row['student_count'];
                        $grand_total_students += $row['student_count'];
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['academic_year']); ?></td>
                            <td><?php echo htmlspecialchars($row['class_section_display']); ?></td>
                            <td class="count"><?php echo $row['student_count']; ?></td>
                        </tr>
                    <?php
                        // After the loop, print the last subtotal if data exists
                        if ($index === count($enrollment_data) - 1) {
                             echo "<tr><td colspan='2' style='text-align:right; font-weight:bold;'>Subtotal for " . htmlspecialchars($current_ay_for_total) . ":</td><td class='count'>" . $ay_subtotal . "</td></tr>";
                        }
                    endforeach;
                    ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" style="text-align:right;">Grand Total Students:</th>
                        <th class="count"><?php echo $grand_total_students; ?></th>
                    </tr>
                </tfoot>
            </table>
        <?php else: ?>
            <p>No enrollment data found matching the criteria.</p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php if(isset($conn)) mysqli_close($conn); ?>
