<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

include 'config.php';

$message = '';
$message_type = '';
$current_user_id = current_user_id();
$current_role = current_user_role();

$selected_class_section_id = isset($_REQUEST['class_section_id']) ? (int)$_REQUEST['class_section_id'] : 0;

// Fetch class sections that the current user can manage delegations for
$manageable_class_sections = [];
$cs_query_sql = "";
if ($current_role == 'admin') {
    $cs_query_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                     FROM class_sections cs
                     JOIN grades g ON cs.grade_id = g.id
                     JOIN divisions d ON cs.division_id = d.id
                     ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
} elseif ($current_role == 'teacher') {
    $cs_query_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                     FROM class_sections cs
                     JOIN grades g ON cs.grade_id = g.id
                     JOIN divisions d ON cs.division_id = d.id
                     WHERE cs.class_teacher_user_id = $current_user_id
                     ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
}
if (!empty($cs_query_sql)) {
    $cs_res = mysqli_query($conn, $cs_query_sql);
    if ($cs_res) while ($row = mysqli_fetch_assoc($cs_res)) $manageable_class_sections[] = $row;
}

// Fetch all teachers for delegation dropdown (excluding the class teacher of the selected section, if known)
$teachers_for_delegation = [];
// This query will be refined once a class section is selected to exclude its class teacher.
$all_teachers_sql = "SELECT id, username FROM users WHERE role = 'teacher' AND id != $current_user_id ORDER BY username"; // Basic exclusion of self
$teachers_res = mysqli_query($conn, $all_teachers_sql);
if ($teachers_res) while ($row = mysqli_fetch_assoc($teachers_res)) $teachers_for_delegation[] = $row;


// Handle Form Submission (Add/Update Delegation)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_delegation'])) {
    $delegated_to_user_id = (int)$_POST['delegated_to_user_id'];
    $form_class_section_id = (int)$_POST['form_class_section_id']; // Class section from the form
    $can_take_attendance = isset($_POST['can_take_attendance']) ? 1 : 0;
    $can_manage_students = isset($_POST['can_manage_students']) ? 1 : 0;

    // Permission check: Can current user manage this section?
    $can_manage_this_section = false;
    if ($current_role == 'admin') $can_manage_this_section = true;
    else { // Teacher managing their own class
        foreach($manageable_class_sections as $mcs) {
            if ($mcs['id'] == $form_class_section_id) {
                $can_manage_this_section = true;
                break;
            }
        }
    }
    // Check if trying to delegate to the class teacher of the section
    $class_teacher_of_section_q = mysqli_query($conn, "SELECT class_teacher_user_id FROM class_sections WHERE id = $form_class_section_id");
    $class_teacher_of_section_id = null;
    if($class_teacher_of_section_q && mysqli_num_rows($class_teacher_of_section_q)>0){
        $class_teacher_of_section_id = mysqli_fetch_assoc($class_teacher_of_section_q)['class_teacher_user_id'];
    }


    if (!$can_manage_this_section) {
        $message = "You do not have permission to delegate tasks for this class section.";
        $message_type = 'error';
    } elseif ($delegated_to_user_id == 0) {
        $message = "Please select a teacher to delegate to.";
        $message_type = 'error';
    } elseif ($delegated_to_user_id == $class_teacher_of_section_id) {
        $message = "You cannot delegate tasks to the primary class teacher of the section.";
        $message_type = 'error';
    } else {
        // Check if delegation already exists for this teacher and section
        $check_sql = "SELECT id FROM teacher_delegations WHERE class_section_id = $form_class_section_id AND delegated_to_user_id = $delegated_to_user_id";
        $check_res = mysqli_query($conn, $check_sql);

        if (mysqli_num_rows($check_res) > 0) { // Update existing delegation
            $existing_delegation = mysqli_fetch_assoc($check_res);
            $delegation_id = $existing_delegation['id'];
            $sql = "UPDATE teacher_delegations SET
                        can_take_attendance = $can_take_attendance,
                        can_manage_students = $can_manage_students,
                        delegated_by_user_id = $current_user_id -- record who made the last update
                    WHERE id = $delegation_id";
            if (mysqli_query($conn, $sql)) {
                $message = "Delegation updated successfully.";
                $message_type = 'success';
            } else {
                $message = "Error updating delegation: " . mysqli_error($conn);
                $message_type = 'error';
            }
        } else { // Create new delegation
            $sql = "INSERT INTO teacher_delegations (class_section_id, delegated_to_user_id, can_take_attendance, can_manage_students, delegated_by_user_id)
                    VALUES ($form_class_section_id, $delegated_to_user_id, $can_take_attendance, $can_manage_students, $current_user_id)";
            if (mysqli_query($conn, $sql)) {
                $message = "Delegation created successfully.";
                $message_type = 'success';
            } else {
                $message = "Error creating delegation: " . mysqli_error($conn);
                $message_type = 'error';
            }
        }
        $selected_class_section_id = $form_class_section_id; // Keep section selected
    }
}

// Handle Remove Delegation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['remove_delegation'])) {
    $delegation_id_to_remove = (int)$_POST['delegation_id'];
    $form_class_section_id = (int)$_POST['form_class_section_id_hidden']; // Get section from hidden field

    // Permission check (simplified: assume if they can see it, they can remove for now)
    // A more robust check would verify current_user_id is admin or class_teacher_of_section

    $sql_get_delegation_details = "SELECT td.class_section_id, cs.class_teacher_user_id FROM teacher_delegations td JOIN class_sections cs ON td.class_section_id = cs.id WHERE td.id = $delegation_id_to_remove";
    $del_details_res = mysqli_query($conn, $sql_get_delegation_details);

    if ($del_details_res && mysqli_num_rows($del_details_res) > 0) {
        $del_info = mysqli_fetch_assoc($del_details_res);
        if ($current_role == 'admin' || ($current_role == 'teacher' && $del_info['class_teacher_user_id'] == $current_user_id)) {
            $sql_delete = "DELETE FROM teacher_delegations WHERE id = $delegation_id_to_remove";
            if (mysqli_query($conn, $sql_delete)) {
                $message = "Delegation removed successfully.";
                $message_type = 'success';
            } else {
                $message = "Error removing delegation: " . mysqli_error($conn);
                $message_type = 'error';
            }
        } else {
            $message = "You do not have permission to remove this delegation.";
            $message_type = 'error';
        }
    } else {
        $message = "Delegation not found for removal.";
        $message_type = 'error';
    }
    $selected_class_section_id = $form_class_section_id; // Keep section selected
}


// Fetch current delegations for the selected class section
$current_delegations = [];
if ($selected_class_section_id > 0) {
    $delegations_sql = "SELECT td.id, u.username as delegated_teacher_name, td.can_take_attendance, td.can_manage_students, ub.username as delegated_by_name
                        FROM teacher_delegations td
                        JOIN users u ON td.delegated_to_user_id = u.id
                        JOIN users ub ON td.delegated_by_user_id = ub.id
                        WHERE td.class_section_id = $selected_class_section_id
                        ORDER BY u.username";
    $delegations_res = mysqli_query($conn, $delegations_sql);
    if ($delegations_res) while ($row = mysqli_fetch_assoc($delegations_res)) $current_delegations[] = $row;

    // Refine teachers_for_delegation to exclude class teacher of *this selected section*
    $selected_section_details_q = mysqli_query($conn, "SELECT class_teacher_user_id FROM class_sections WHERE id = $selected_class_section_id");
    if($selected_section_details_q && mysqli_num_rows($selected_section_details_q) > 0){
        $ct_id = mysqli_fetch_assoc($selected_section_details_q)['class_teacher_user_id'];
        $teachers_for_delegation = []; // Re-fetch
        $all_teachers_sql = "SELECT id, username FROM users WHERE role = 'teacher' AND id != " . ($ct_id ? $ct_id : 0) . " ORDER BY username";
        $teachers_res_refined = mysqli_query($conn, $all_teachers_sql);
        if ($teachers_res_refined) while ($row = mysqli_fetch_assoc($teachers_res_refined)) $teachers_for_delegation[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delegate Tasks</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding:0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 10px; text-decoration: none; font-weight: bold; }
        .top-nav .user-info { float: right; color: #ddd; font-size: 0.9em; margin-right: 20px; line-height: 2.5em;}
        .top-nav a:hover { text-decoration: underline; }
        .container { width: 80%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 0.9em;}
        th { background-color: #f2f2f2; }
        .form-section, .filter-section { margin-bottom: 30px; padding: 20px; background-color: #f9f9f9; border-radius: 5px; }
        form label { display: block; margin-top: 10px; font-weight: bold; }
        form input[type="text"], form select, form input[type="checkbox"] { padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        form input[type="checkbox"] { width: auto; margin-right: 5px; vertical-align: middle;}
        form select { width: calc(100% - 22px); }
        form input[type="submit"], form button { background-color: #007bff; color: white; cursor: pointer; width: auto; padding: 10px 15px; margin-top:15px; border: none; border-radius: 4px; }
        form input[type="submit"]:hover, form button:hover { background-color: #0056b3; }
        form button.delete { background-color: #dc3545; margin-left: 5px;}
        form button.delete:hover { background-color: #c82333; }
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
        <?php endif; ?>
         <?php if ($current_role == 'teacher' || $current_role == 'admin'): // Class teacher specific links ?>
            <a href="delegate_tasks.php">Delegate Tasks</a>
        <?php endif; ?>
        <span class="user-info">Logged in as: <?php echo htmlspecialchars(current_username()); ?> (<?php echo htmlspecialchars($current_role); ?>)</span>
        <a href="logout.php" style="float:right;">Logout</a>
    </nav>

    <div class="container">
        <h1>Delegate Tasks for Class Section</h1>
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="filter-section">
            <form action="delegate_tasks.php" method="GET">
                <label for="class_section_id">Select Class Section:</label>
                <select name="class_section_id" id="class_section_id" required onchange="this.form.submit()">
                    <option value="">-- Select Section --</option>
                    <?php foreach ($manageable_class_sections as $cs): ?>
                        <option value="<?php echo $cs['id']; ?>" <?php echo ($selected_class_section_id == $cs['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cs['display_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($selected_class_section_id > 0): ?>
            <div class="form-section">
                <h2>Delegate Tasks to Another Teacher</h2>
                <form action="delegate_tasks.php" method="POST">
                    <input type="hidden" name="form_class_section_id" value="<?php echo $selected_class_section_id; ?>">

                    <label for="delegated_to_user_id">Select Teacher to Delegate To:</label>
                    <select name="delegated_to_user_id" id="delegated_to_user_id" required>
                        <option value="">-- Select Teacher --</option>
                        <?php foreach ($teachers_for_delegation as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>">
                                <?php echo htmlspecialchars($teacher['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label style="margin-top: 15px;">Permissions to Delegate:</label>
                    <div>
                        <input type="checkbox" name="can_take_attendance" id="can_take_attendance" value="1">
                        <label for="can_take_attendance" style="font-weight:normal; display:inline;">Can Take Attendance</label>
                    </div>
                    <div>
                        <input type="checkbox" name="can_manage_students" id="can_manage_students" value="1">
                        <label for="can_manage_students" style="font-weight:normal; display:inline;">Can Manage Students (View/Edit basic details)</label>
                    </div>
                    <input type="submit" name="save_delegation" value="Save/Update Delegation">
                </form>
            </div>

            <h2>Current Delegations for this Section</h2>
            <?php if (!empty($current_delegations)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Delegated Teacher</th>
                            <th>Can Take Attendance?</th>
                            <th>Can Manage Students?</th>
                            <th>Delegated By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($current_delegations as $delegation): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($delegation['delegated_teacher_name']); ?></td>
                                <td><?php echo $delegation['can_take_attendance'] ? 'Yes' : 'No'; ?></td>
                                <td><?php echo $delegation['can_manage_students'] ? 'Yes' : 'No'; ?></td>
                                <td><?php echo htmlspecialchars($delegation['delegated_by_name']); ?></td>
                                <td>
                                    <form action="delegate_tasks.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove this delegation?');">
                                        <input type="hidden" name="delegation_id" value="<?php echo $delegation['id']; ?>">
                                        <input type="hidden" name="form_class_section_id_hidden" value="<?php echo $selected_class_section_id; ?>">
                                        <button type="submit" name="remove_delegation" class="delete">Remove</button>
                                    </form>
                                    <!-- Edit could link back to the form pre-filled, or a separate edit page -->
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="info">No tasks have been delegated for this class section yet.</p>
            <?php endif; ?>
        <?php elseif (empty($message) && !empty($manageable_class_sections)): ?>
            <p class="info">Please select a class section above to manage its delegations.</p>
        <?php elseif (empty($manageable_class_sections)): ?>
             <p class="info">You are not assigned as a class teacher to any sections, or no class sections have been created by the admin.</p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php if(isset($conn)) mysqli_close($conn); ?>
