<?php
include_once 'auth_check.php';
if (!is_logged_in()) {
    header("Location: login.php");
    exit;
}
// Specific role/permission checks will be done contextually below

include 'config.php';
$message = '';
$message_type = '';
$current_user_id = current_user_id();
$current_role = current_user_role();

// Fetch class sections that the current user can manage students for
$class_sections_options_for_form = []; // For the "Add Student" form dropdown
$manageable_cs_ids_for_teacher = []; // For filtering and permission checks

if ($current_role == 'admin') {
    $cs_q_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                 FROM class_sections cs
                 JOIN grades g ON cs.grade_id = g.id
                 JOIN divisions d ON cs.division_id = d.id
                 ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
    $cs_res = mysqli_query($conn, $cs_q_sql);
    if ($cs_res) while ($row = mysqli_fetch_assoc($cs_res)) $class_sections_options_for_form[] = $row;

} elseif ($current_role == 'teacher') {
    $manageable_cs_ids_for_teacher = get_teacher_student_manageable_sections($current_user_id, $conn);
    if (!empty($manageable_cs_ids_for_teacher)) {
        $ids_str = implode(',', $manageable_cs_ids_for_teacher);
        $cs_q_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                     FROM class_sections cs
                     JOIN grades g ON cs.grade_id = g.id
                     JOIN divisions d ON cs.division_id = d.id
                     WHERE cs.id IN ($ids_str)
                     ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
        $cs_res = mysqli_query($conn, $cs_q_sql);
        if ($cs_res) while ($row = mysqli_fetch_assoc($cs_res)) $class_sections_options_for_form[] = $row;
    }
}


// Handle Add Student form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_student'])) {
    if (!in_array($current_role, ['admin', 'teacher'])) {
        $message = "You do not have permission to add students.";
        $message_type = 'error';
    } else {
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $roll_number = mysqli_real_escape_string($conn, $_POST['roll_number']);
        $class_section_id_posted = (int)$_POST['class_section_id'];

        $can_add_to_section = false;
        if ($current_role == 'admin') {
            $can_add_to_section = true;
        } elseif ($current_role == 'teacher') {
            if (is_class_teacher_of_section($current_user_id, $class_section_id_posted, $conn) ||
                has_delegated_permission($current_user_id, $class_section_id_posted, 'can_manage_students', $conn)) {
                $can_add_to_section = true;
            }
        }

        if (!$can_add_to_section) {
            $message = "You do not have permission to add students to this selected class section.";
            $message_type = 'error';
        } elseif (empty($name) || empty($roll_number) || $class_section_id_posted == 0) {
            $message = "Student name, roll number, and class section are required.";
            $message_type = 'error';
        } else {
            $check_sql = "SELECT id FROM students WHERE roll_number = '$roll_number' AND class_section_id = $class_section_id_posted";
            $check_result = mysqli_query($conn, $check_sql);
            if (mysqli_num_rows($check_result) > 0) {
                $message = "Error: Roll number '$roll_number' already exists in this class section.";
                $message_type = 'error';
            } else {
                $insert_sql = "INSERT INTO students (name, roll_number, class_section_id) VALUES ('$name', '$roll_number', $class_section_id_posted)";
                if (mysqli_query($conn, $insert_sql)) {
                    $message = "Student '$name' added successfully.";
                    $message_type = 'success';
                } else {
                    $message = "Error adding student: " . mysqli_error($conn);
                    $message_type = 'error';
                }
            }
        }
    }
}

// Fetch students to display based on role and selected filter
$students_list = [];
$filter_class_section_id = isset($_GET['filter_cs_id']) ? (int)$_GET['filter_cs_id'] : 'all';

$students_query_base = "SELECT s.*,
                               IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display_name
                        FROM students s
                        JOIN class_sections cs ON s.class_section_id = cs.id
                        JOIN grades g ON cs.grade_id = g.id
                        JOIN divisions d ON cs.division_id = d.id";
$students_query_conditions = " WHERE 1=1 ";
$students_query_order = " ORDER BY cs.academic_year DESC, g.grade_name, d.division_name, s.roll_number";

if ($current_role == 'admin') {
    if ($filter_class_section_id !== 'all' && $filter_class_section_id > 0) {
        $students_query_conditions .= " AND s.class_section_id = $filter_class_section_id";
    }
} elseif ($current_role == 'teacher') {
    // $manageable_cs_ids_for_teacher was fetched earlier
    if (!empty($manageable_cs_ids_for_teacher)) {
        $ids_string = implode(',', $manageable_cs_ids_for_teacher);
        if ($filter_class_section_id !== 'all' && in_array($filter_class_section_id, $manageable_cs_ids_for_teacher)) {
             $students_query_conditions .= " AND s.class_section_id = $filter_class_section_id";
        } else {
            $students_query_conditions .= " AND s.class_section_id IN ($ids_string)";
        }
    } else {
        $students_query_conditions .= " AND 1=0";
    }
} else {
     $students_query_conditions .= " AND 1=0";
}

$students_final_sql = $students_query_base . $students_query_conditions . $students_query_order;
$students_result = mysqli_query($conn, $students_final_sql);
if($students_result){
    while($row = mysqli_fetch_assoc($students_result)){
        $students_list[] = $row;
    }
} else {
    $message = "Error fetching student list: " . mysqli_error($conn);
    $message_type = 'error';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students</title>
    <style>
        /* Styles remain largely the same as before */
        body { font-family: Arial, sans-serif; margin: 0; padding:0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 10px; text-decoration: none; font-weight: bold; }
        .top-nav .user-info { float: right; color: #ddd; font-size: 0.9em; margin-right: 20px; line-height: 2.5em;}
        .top-nav a:hover { text-decoration: underline; }
        .container { width: 90%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size:0.9em; }
        th { background-color: #f2f2f2; }
        .action-links a { margin-right: 10px; text-decoration: none; color: #007bff; }
        .action-links a:hover { text-decoration: underline; }
        .form-section, .filter-section { margin-bottom: 30px; padding: 20px; background-color: #f9f9f9; border-radius: 5px; }
        form label { display: block; margin-top: 10px; font-weight: bold; }
        form input[type="text"], form select {
            width: calc(100% - 22px); padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;
        }
        form input[type="submit"] { background-color: #28a745; color: white; cursor: pointer; width: auto; padding: 10px 20px; margin-top:15px;}
        form input[type="submit"]:hover { background-color: #218838; }
        .no-students { text-align: center; padding: 20px; color: #777; }
        .parents-list { list-style-type: none; padding-left: 0; font-size: 0.9em; }
        .parents-list li { color: #555; }
    </style>
</head>
<body>
    <nav class="top-nav">
        <a href="index.php">Home</a>
        <?php if (in_array($current_role, ['admin', 'teacher'])): ?>
            <a href="students.php">Manage Students</a>
            <a href="attendance.php">Take/View Attendance</a>
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

    <div class="container">
        <h1>Manage Students</h1>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if (in_array($current_role, ['admin', 'teacher']) && !empty($class_sections_options_for_form)): ?>
        <div class="form-section">
            <h2>Add New Student</h2>
            <form action="students.php" method="POST">
                <label for="name">Student Name:</label>
                <input type="text" name="name" id="name" required>

                <label for="roll_number">Roll Number:</label>
                <input type="text" name="roll_number" id="roll_number" required>

                <label for="class_section_id">Class Section:</label>
                <select name="class_section_id" id="class_section_id" required>
                    <option value="">-- Select Class Section --</option>
                    <?php foreach ($class_sections_options_for_form as $cs): ?>
                        <option value="<?php echo $cs['id']; ?>">
                            <?php echo htmlspecialchars($cs['display_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" name="add_student" value="Add Student">
            </form>
        </div>
        <?php elseif (in_array($current_role, ['admin', 'teacher']) && empty($class_sections_options_for_form)): ?>
             <p class="info">
                <?php echo $current_role == 'admin' ? 'Please create Class Sections first in "Manage Class Sections" before adding students.' : 'You are not currently assigned to manage students for any class sections.'; ?>
            </p>
        <?php endif; ?>


        <h2>Existing Students</h2>
        <div class="filter-section">
            <form action="students.php" method="GET">
                <label for="filter_cs_id">Filter by Class Section:</label>
                <select name="filter_cs_id" id="filter_cs_id" onchange="this.form.submit()">
                    <option value="all">-- Show All My Accessible Classes --</option>
                    <?php
                    // For filter dropdown, use $class_sections_options_for_form which is already role-filtered.
                    // If admin, they might want to see all sections even if they don't manage them for adding, so re-fetch for admin filter.
                    $filter_dropdown_options = $class_sections_options_for_form;
                    if ($current_role == 'admin') {
                        $filter_dropdown_options = []; // Re-fetch all for admin's filter
                        $all_cs_q_filter = mysqli_query($conn, "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id ORDER BY display_name");
                        if($all_cs_q_filter) while($r = mysqli_fetch_assoc($all_cs_q_filter)) $filter_dropdown_options[] = $r;
                    }


                    foreach ($filter_dropdown_options as $cs_filter_opt): ?>
                        <option value="<?php echo $cs_filter_opt['id']; ?>" <?php echo ($filter_class_section_id == $cs_filter_opt['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cs_filter_opt['display_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                 <?php if ($filter_class_section_id !== 'all'): ?>
                    <a href="students.php" style="margin-left: 10px;">Show All</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (!empty($students_list)): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Roll Number</th>
                        <th>Class Section</th>
                        <th>Parents/Guardians</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students_list as $student): ?>
                        <tr>
                            <td><?php echo $student['id']; ?></td>
                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                            <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                            <td><?php echo htmlspecialchars($student['class_section_display_name']); ?></td>
                            <td>
                                <?php
                                $parent_sql = "SELECT parent_name, relationship, phone_number FROM parent_guardians WHERE student_id = " . $student['id'];
                                $parents_result = mysqli_query($conn, $parent_sql);
                                if ($parents_result && mysqli_num_rows($parents_result) > 0) {
                                    echo '<ul class="parents-list">';
                                    while ($parent = mysqli_fetch_assoc($parents_result)) {
                                        echo '<li>' . htmlspecialchars($parent['parent_name']) . ' (' . htmlspecialchars($parent['relationship']) . ') - ' . htmlspecialchars($parent['phone_number']) . '</li>';
                                    }
                                    echo '</ul>';
                                } else { echo 'No parents listed.'; }
                                ?>
                            </td>
                            <td class="action-links">
                                <a href="parents.php?student_id=<?php echo $student['id']; ?>">Manage Parents</a>
                                <!-- TODO: Add Edit/Delete Student links, with permission checks -->
                                <!-- e.g., if(current_user_role() == 'admin' || is_class_teacher_of_section(current_user_id(), $student['class_section_id'], $conn) || has_delegated_permission(current_user_id(), $student['class_section_id'], 'can_manage_students', $conn) ) -->
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif (in_array($current_role, ['admin', 'teacher'])): ?>
            <p class="no-students">No students found matching your criteria or in sections you manage.</p>
        <?php else: ?>
             <p class="info">You do not have permission to view student lists.</p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php if(isset($conn)) mysqli_close($conn); ?>
