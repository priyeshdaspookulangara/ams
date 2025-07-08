<?php
include_once 'auth_check.php';
require_login(['admin']); // Only admins can manage users

include 'config.php';
include 'functions.php'; // For get_settings or other helpers if needed

$message = '';
$message_type = '';

// Handle User Creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    $username = mysqli_real_escape_string($conn, trim($_POST['username']));
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = mysqli_real_escape_string($conn, $_POST['role']);
    $entity_id = isset($_POST['entity_id']) && !empty($_POST['entity_id']) ? (int)$_POST['entity_id'] : null; // Optional direct entity_id

    if (empty($username) || empty($password) || empty($role)) {
        $message = "Username, password, and role are required.";
        $message_type = 'error';
    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match.";
        $message_type = 'error';
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters long.";
        $message_type = 'error';
    } else {
        $sql_check = "SELECT id FROM users WHERE username = '$username'";
        $res_check = mysqli_query($conn, $sql_check);
        if (mysqli_num_rows($res_check) > 0) {
            $message = "Username '$username' already exists.";
            $message_type = 'error';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $entity_id_sql = $entity_id ? $entity_id : "NULL";

            $sql_insert = "INSERT INTO users (username, password, role, entity_id) VALUES ('$username', '$hashed_password', '$role', $entity_id_sql)";
            if (mysqli_query($conn, $sql_insert)) {
                $new_user_id = mysqli_insert_id($conn);
                $message = "User '$username' created successfully (ID: $new_user_id).";
                $message_type = 'success';

                // If parent role and students are selected, link them
                if ($role == 'parent' && isset($_POST['linked_students']) && is_array($_POST['linked_students'])) {
                    $link_count = 0;
                    foreach ($_POST['linked_students'] as $student_id) {
                        $student_id_clean = (int)$student_id;
                        $link_sql = "INSERT INTO user_student_links (user_id, student_id) VALUES ($new_user_id, $student_id_clean)";
                        // Add error checking for duplicate links if necessary (UNIQUE KEY handles it in DB)
                        if(mysqli_query($conn, $link_sql)) {
                            $link_count++;
                        } else {
                             $message .= " Error linking student ID $student_id_clean: " . mysqli_error($conn);
                             $message_type = 'error'; // Downgrade overall message if linking fails
                        }
                    }
                    if ($link_count > 0) {
                        $message .= " Linked to $link_count student(s).";
                    }
                }
            } else {
                $message = "Error creating user: " . mysqli_error($conn);
                $message_type = 'error';
            }
        }
    }
}

// Handle Linking Parent to Student (if a separate form or action was used)
// For now, integrated into user creation. Can be expanded to edit existing users.


// Fetch all users
$users_result = mysqli_query($conn, "SELECT u.id, u.username, u.role, u.entity_id, GROUP_CONCAT(s.name SEPARATOR ', ') as linked_students_names
                                    FROM users u
                                    LEFT JOIN user_student_links usl ON u.id = usl.user_id
                                    LEFT JOIN students s ON usl.student_id = s.id
                                    GROUP BY u.id
                                    ORDER BY u.role, u.username");

// Fetch all students for linking dropdown
$all_students_result = mysqli_query($conn, "SELECT id, name, roll_number, grade FROM students ORDER BY grade, name");
$students_for_linking = [];
if($all_students_result){
    while($row = mysqli_fetch_assoc($all_students_result)){
        $students_for_linking[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users</title>
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
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .form-section { margin-bottom: 30px; padding: 20px; background-color: #f9f9f9; border-radius: 5px; }
        form label { display: block; margin-top: 10px; font-weight: bold; }
        form input[type="text"], form input[type="password"], form select, form input[type="number"] {
            width: calc(100% - 22px); padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;
        }
        form input[type="submit"] { background-color: #007bff; color: white; cursor: pointer; width: auto; padding: 10px 20px; margin-top:15px;}
        form input[type="submit"]:hover { background-color: #0056b3; }
        .student-link-options { max-height: 150px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; margin-top: 5px; background-color: white;}
        .student-link-options label { font-weight: normal; display: block; }
    </style>
    <script>
        function toggleStudentLink(roleSelect) {
            var studentLinkDiv = document.getElementById('studentLinkSection');
            if (roleSelect.value === 'parent') {
                studentLinkDiv.style.display = 'block';
            } else {
                studentLinkDiv.style.display = 'none';
            }
        }
    </script>
</head>
<body>
    <nav class="top-nav">
        <a href="index.php">Home</a>
        <?php if (is_logged_in()): ?>
            <?php if (in_array(current_user_role(), ['admin', 'teacher'])): ?>
                <a href="students.php">Manage Students</a>
                <a href="attendance.php">Take/View Attendance</a>
            <?php endif; ?>
            <?php if (current_user_role() == 'admin'): ?>
                <a href="settings.php">Settings</a>
                <a href="manage_users.php">Manage Users</a>
            <?php endif; ?>
            <?php if (current_user_role() == 'parent'): ?>
                <a href="parent_dashboard.php">Parent Dashboard</a>
            <?php endif; ?>
            <span class="user-info">Logged in as: <?php echo htmlspecialchars(current_username()); ?> (<?php echo htmlspecialchars(current_user_role()); ?>)</span>
            <a href="logout.php" style="float:right;">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="register.php">Register (Teacher)</a>
        <?php endif; ?>
    </nav>

    <div class="container">
        <h1>Manage Users</h1>
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; /* May contain HTML */ ?></div>
        <?php endif; ?>

        <div class="form-section">
            <h2>Create New User</h2>
            <form action="manage_users.php" method="POST">
                <label for="username">Username:</label>
                <input type="text" name="username" id="username" required>

                <label for="password">Password (min 6 chars):</label>
                <input type="password" name="password" id="password" required>

                <label for="confirm_password">Confirm Password:</label>
                <input type="password" name="confirm_password" id="confirm_password" required>

                <label for="role">Role:</label>
                <select name="role" id="role" required onchange="toggleStudentLink(this)">
                    <option value="teacher">Teacher</option>
                    <option value="admin">Admin</option>
                    <option value="parent">Parent</option>
                </select>

                <!-- Optional: Direct entity_id for simple links, less used if multi-link is primary -->
                <!-- <label for="entity_id">Entity ID (Optional - e.g., a Teacher ID if applicable):</label>
                <input type="number" name="entity_id" id="entity_id"> -->

                <div id="studentLinkSection" style="display:none;">
                    <label>Link Parent to Student(s):</label>
                    <div class="student-link-options">
                        <?php if (!empty($students_for_linking)): ?>
                            <?php foreach($students_for_linking as $student): ?>
                                <label>
                                    <input type="checkbox" name="linked_students[]" value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['name']) . " (Roll: " . htmlspecialchars($student['roll_number']) . ", Grade: " . htmlspecialchars($student['grade']) . ")"; ?>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No students available to link. Please add students first.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <input type="submit" name="create_user" value="Create User">
            </form>
        </div>

        <h2>Existing Users</h2>
        <?php if ($users_result && mysqli_num_rows($users_result) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Linked Students (Parents)</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($user = mysqli_fetch_assoc($users_result)): ?>
                        <tr>
                            <td><?php echo $user['id']; ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                            <td>
                                <?php
                                if ($user['role'] == 'parent' && !empty($user['linked_students_names'])) {
                                    echo htmlspecialchars($user['linked_students_names']);
                                } elseif ($user['role'] == 'parent') {
                                    echo '<em>No students linked</em>';
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </td>
                            <td>
                                <!-- Add Edit/Delete User links here later -->
                                <?php if ($user['role'] == 'parent'): ?>
                                    <a href="manage_user_links.php?user_id=<?php echo $user['id']; ?>">Manage Links</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No users found (except potentially the initial admin created by setup_db.php if not listed here due to no grouping matches).</p>
        <?php endif; ?>
    </div>
    <script>
        // Initial check in case the form is reloaded with 'parent' selected
        var roleSelect = document.getElementById('role');
        if (roleSelect) {
            toggleStudentLink(roleSelect);
        }
    </script>
</body>
</html>
<?php if(isset($conn)) mysqli_close($conn); ?>
