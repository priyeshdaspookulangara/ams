<?php
include_once 'auth_check.php';
require_login(['admin']);

include 'config.php';

$message = '';
$message_type = '';

// Fetch data for dropdowns
$grades_res = mysqli_query($conn, "SELECT id, grade_name FROM grades ORDER BY grade_name");
$divisions_res = mysqli_query($conn, "SELECT id, division_name FROM divisions ORDER BY division_name");
$teachers_res = mysqli_query($conn, "SELECT id, username FROM users WHERE role = 'teacher' ORDER BY username");

$grades_options = [];
while ($row = mysqli_fetch_assoc($grades_res)) $grades_options[] = $row;
$divisions_options = [];
while ($row = mysqli_fetch_assoc($divisions_res)) $divisions_options[] = $row;
$teachers_options = [];
while ($row = mysqli_fetch_assoc($teachers_res)) $teachers_options[] = $row;


// Handle Create/Update Class Section
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['save_class_section'])) {
        $grade_id = (int)$_POST['grade_id'];
        $division_id = (int)$_POST['division_id'];
        $class_teacher_user_id = !empty($_POST['class_teacher_user_id']) ? (int)$_POST['class_teacher_user_id'] : "NULL";
        $academic_year = mysqli_real_escape_string($conn, trim($_POST['academic_year']));
        $section_name_override = isset($_POST['section_name']) ? mysqli_real_escape_string($conn, trim($_POST['section_name'])) : null; // Optional override
        $class_section_id = isset($_POST['class_section_id']) ? (int)$_POST['class_section_id'] : 0;

        if ($grade_id == 0 || $division_id == 0 || empty($academic_year)) {
            $message = "Grade, Division, and Academic Year are required.";
            $message_type = 'error';
        } else {
            // Auto-generate section_name if not overridden
            $auto_section_name = '';
            $grade_name_q = mysqli_query($conn, "SELECT grade_name FROM grades WHERE id=$grade_id");
            $div_name_q = mysqli_query($conn, "SELECT division_name FROM divisions WHERE id=$division_id");
            if($grade_name_q && $div_name_q){
                $g_name = mysqli_fetch_assoc($grade_name_q)['grade_name'];
                $d_name = mysqli_fetch_assoc($div_name_q)['division_name'];
                $auto_section_name = "$g_name - $d_name ($academic_year)";
            }
            $final_section_name = !empty($section_name_override) ? $section_name_override : $auto_section_name;


            $check_sql = "SELECT id FROM class_sections WHERE grade_id = $grade_id AND division_id = $division_id AND academic_year = '$academic_year'" . ($class_section_id > 0 ? " AND id != $class_section_id" : "");
            $check_res = mysqli_query($conn, $check_sql);

            if (mysqli_num_rows($check_res) > 0) {
                $message = "This Class Section (Grade-Division combination for the academic year) already exists.";
                $message_type = 'error';
            } else {
                if ($class_section_id > 0) { // Update
                    $sql = "UPDATE class_sections SET
                                grade_id = $grade_id,
                                division_id = $division_id,
                                class_teacher_user_id = $class_teacher_user_id,
                                academic_year = '$academic_year',
                                section_name = '$final_section_name'
                            WHERE id = $class_section_id";
                    if (mysqli_query($conn, $sql)) {
                        $message = "Class Section updated successfully.";
                        $message_type = 'success';
                    } else {
                        $message = "Error updating Class Section: " . mysqli_error($conn);
                        $message_type = 'error';
                    }
                } else { // Create
                    $sql = "INSERT INTO class_sections (grade_id, division_id, class_teacher_user_id, academic_year, section_name)
                            VALUES ($grade_id, $division_id, $class_teacher_user_id, '$academic_year', '$final_section_name')";
                    if (mysqli_query($conn, $sql)) {
                        $message = "Class Section created successfully.";
                        $message_type = 'success';
                    } else {
                        $message = "Error creating Class Section: " . mysqli_error($conn);
                        $message_type = 'error';
                    }
                }
            }
        }
    } elseif (isset($_POST['delete_class_section'])) {
        $class_section_id = (int)$_POST['class_section_id'];
        // Check if class section has students assigned
        $check_students_sql = "SELECT id FROM students WHERE class_section_id = $class_section_id LIMIT 1";
        $students_res = mysqli_query($conn, $check_students_sql);
        if (mysqli_num_rows($students_res) > 0) {
            $message = "Cannot delete Class Section. Students are currently assigned to it. Please reassign students first.";
            $message_type = 'error';
        } else {
            // Also check teacher_delegations
            $check_delegations_sql = "SELECT id FROM teacher_delegations WHERE class_section_id = $class_section_id LIMIT 1";
            $delegations_res = mysqli_query($conn, $check_delegations_sql);
            if (mysqli_num_rows($delegations_res) > 0) {
                 $message = "Cannot delete Class Section. Teacher delegations exist for it. Please remove delegations first.";
                 $message_type = 'error';
            } else {
                $sql = "DELETE FROM class_sections WHERE id = $class_section_id";
                if (mysqli_query($conn, $sql)) {
                    $message = "Class Section deleted successfully.";
                    $message_type = 'success';
                } else {
                    $message = "Error deleting Class Section: " . mysqli_error($conn);
                    $message_type = 'error';
                }
            }
        }
    }
}

// Fetch all class sections with details
$cs_sql = "SELECT cs.*, g.grade_name, d.division_name, u.username as class_teacher_name
           FROM class_sections cs
           JOIN grades g ON cs.grade_id = g.id
           JOIN divisions d ON cs.division_id = d.id
           LEFT JOIN users u ON cs.class_teacher_user_id = u.id
           ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
$class_sections_result = mysqli_query($conn, $cs_sql);

// For editing
$edit_section = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $edit_sql_q = "SELECT * FROM class_sections WHERE id = $edit_id";
    $edit_res_q = mysqli_query($conn, $edit_sql_q);
    if ($edit_res_q && mysqli_num_rows($edit_res_q) > 0) {
        $edit_section = mysqli_fetch_assoc($edit_res_q);
    } else {
        $message = "Class Section not found for editing.";
        $message_type = 'error';
    }
}
$current_academic_year = date("Y") . "-" . (date("Y") + 1); // Default for new entries

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Class Sections</title>
    <style>
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
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 0.9em;}
        th { background-color: #f2f2f2; }
        .form-section { margin-bottom: 30px; padding: 20px; background-color: #f9f9f9; border-radius: 5px; }
        form label { display: block; margin-top: 10px; font-weight: bold; }
        form input[type="text"], form select { width: calc(100% - 22px); padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        form input[type="submit"], form button { background-color: #007bff; color: white; cursor: pointer; width: auto; padding: 10px 15px; margin-top:15px; border: none; border-radius: 4px; }
        form input[type="submit"]:hover, form button:hover { background-color: #0056b3; }
        form button.delete { background-color: #dc3545; }
        form button.delete:hover { background-color: #c82333; }
        .action-links a, .action-links button { margin-right: 5px; text-decoration: none; font-size:0.9em; padding: 5px 8px;}
    </style>
</head>
<body>
    <nav class="top-nav">
        <a href="index.php">Home</a>
        <?php if (in_array(current_user_role(), ['admin', 'teacher'])): ?>
            <a href="students.php">Manage Students</a>
            <a href="attendance.php">Take/View Attendance</a>
        <?php endif; ?>
        <?php if (current_user_role() == 'admin'): ?>
            <a href="settings.php">Settings</a>
            <a href="manage_users.php">Manage Users</a>
            <a href="manage_grades.php">Manage Grades</a>
            <a href="manage_divisions.php">Manage Divisions</a>
            <a href="manage_class_sections.php">Manage Class Sections</a>
        <?php endif; ?>
        <span class="user-info">Logged in as: <?php echo htmlspecialchars(current_username()); ?> (<?php echo htmlspecialchars(current_user_role()); ?>)</span>
        <a href="logout.php" style="float:right;">Logout</a>
    </nav>

    <div class="container">
        <h1>Manage Class Sections</h1>
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="form-section">
            <h2><?php echo $edit_section ? 'Edit Class Section' : 'Create New Class Section'; ?></h2>
            <form action="manage_class_sections.php<?php echo $edit_section ? '?edit_id=' . $edit_section['id'] : ''; ?>" method="POST">
                <?php if ($edit_section): ?>
                    <input type="hidden" name="class_section_id" value="<?php echo $edit_section['id']; ?>">
                <?php endif; ?>

                <label for="grade_id">Grade:</label>
                <select name="grade_id" id="grade_id" required>
                    <option value="">-- Select Grade --</option>
                    <?php foreach ($grades_options as $grade): ?>
                        <option value="<?php echo $grade['id']; ?>" <?php echo ($edit_section && $edit_section['grade_id'] == $grade['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($grade['grade_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="division_id">Division:</label>
                <select name="division_id" id="division_id" required>
                    <option value="">-- Select Division --</option>
                    <?php foreach ($divisions_options as $division): ?>
                        <option value="<?php echo $division['id']; ?>" <?php echo ($edit_section && $edit_section['division_id'] == $division['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($division['division_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="academic_year">Academic Year (e.g., YYYY-YYYY):</label>
                <input type="text" name="academic_year" id="academic_year" value="<?php echo $edit_section ? htmlspecialchars($edit_section['academic_year']) : $current_academic_year; ?>" required>

                <label for="class_teacher_user_id">Class Teacher (Optional):</label>
                <select name="class_teacher_user_id" id="class_teacher_user_id">
                    <option value="">-- None --</option>
                    <?php foreach ($teachers_options as $teacher): ?>
                        <option value="<?php echo $teacher['id']; ?>" <?php echo ($edit_section && $edit_section['class_teacher_user_id'] == $teacher['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($teacher['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="section_name">Section Display Name (Optional - auto-generated if blank):</label>
                <input type="text" name="section_name" id="section_name" value="<?php echo $edit_section ? htmlspecialchars($edit_section['section_name']) : ''; ?>" placeholder="e.g., Grade 10 - A (2023-2024)">

                <input type="submit" name="save_class_section" value="<?php echo $edit_section ? 'Update Section' : 'Create Section'; ?>">
                 <?php if ($edit_section): ?>
                    <a href="manage_class_sections.php" style="margin-left:10px; text-decoration:none; padding:10px 15px; background-color:#6c757d; color:white; border-radius:4px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <h2>Existing Class Sections</h2>
        <?php if ($class_sections_result && mysqli_num_rows($class_sections_result) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Section Name</th>
                        <th>Grade</th>
                        <th>Division</th>
                        <th>Academic Year</th>
                        <th>Class Teacher</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($section = mysqli_fetch_assoc($class_sections_result)): ?>
                        <tr>
                            <td><?php echo $section['id']; ?></td>
                            <td><?php echo htmlspecialchars($section['section_name'] ? $section['section_name'] : ($section['grade_name'] . ' - ' . $section['division_name'] . ' (' . $section['academic_year'] . ')')); ?></td>
                            <td><?php echo htmlspecialchars($section['grade_name']); ?></td>
                            <td><?php echo htmlspecialchars($section['division_name']); ?></td>
                            <td><?php echo htmlspecialchars($section['academic_year']); ?></td>
                            <td><?php echo $section['class_teacher_name'] ? htmlspecialchars($section['class_teacher_name']) : '<em>Not Assigned</em>'; ?></td>
                            <td class="action-links">
                                <a href="manage_class_sections.php?edit_id=<?php echo $section['id']; ?>" class="button">Edit</a>
                                <form action="manage_class_sections.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this class section? This cannot be undone if it is not in use by students or delegations.');">
                                    <input type="hidden" name="class_section_id" value="<?php echo $section['id']; ?>">
                                    <button type="submit" name="delete_class_section" class="delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No class sections found. Please create grades and divisions first, then define class sections here.</p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php if(isset($conn)) mysqli_close($conn); ?>
