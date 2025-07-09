<?php
include_once 'auth_check.php';
require_login(['admin']);

$page_title = "Manage Users";

include 'config.php';
// include 'functions.php'; // Not strictly needed unless get_settings or other shared funcs are used here.

$message = '';
$message_type = '';

// Handle User Creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    $username = mysqli_real_escape_string($conn, trim($_POST['username']));
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = mysqli_real_escape_string($conn, $_POST['role']);
    // $entity_id = isset($_POST['entity_id']) && !empty($_POST['entity_id']) ? (int)$_POST['entity_id'] : null; // entity_id is less relevant now with user_student_links

    if (empty($username) || empty($password) || empty($role)) {
        $message = "Username, password, and role are required."; $message_type = 'error';
    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match."; $message_type = 'error';
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters long."; $message_type = 'error';
    } else {
        $sql_check = "SELECT id FROM users WHERE username = '$username'";
        $res_check = mysqli_query($conn, $sql_check);
        if (mysqli_num_rows($res_check) > 0) {
            $message = "Username '$username' already exists."; $message_type = 'error';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            // $entity_id_sql = $entity_id ? $entity_id : "NULL"; // Not inserting entity_id directly for parents now

            $sql_insert = "INSERT INTO users (username, password, role) VALUES ('$username', '$hashed_password', '$role')";
            if (mysqli_query($conn, $sql_insert)) {
                $new_user_id = mysqli_insert_id($conn);
                $message = "User '$username' created successfully (ID: $new_user_id).";
                $message_type = 'success';

                if ($role == 'parent' && isset($_POST['linked_students']) && is_array($_POST['linked_students'])) {
                    $link_count = 0;
                    foreach ($_POST['linked_students'] as $student_id) {
                        $student_id_clean = (int)$student_id;
                        // Prevent duplicate links using INSERT IGNORE or by checking first. DB has UNIQUE KEY.
                        $link_sql = "INSERT IGNORE INTO user_student_links (user_id, student_id) VALUES ($new_user_id, $student_id_clean)";
                        if(mysqli_query($conn, $link_sql)) {
                            if(mysqli_affected_rows($conn) > 0) $link_count++;
                        } else {
                             $message .= " Error linking student ID $student_id_clean: " . mysqli_error($conn);
                             if($message_type == 'success') $message_type = 'warning'; // Downgrade if linking had issues
                        }
                    }
                    if ($link_count > 0) {
                        $message .= " Linked to $link_count student(s).";
                    }
                }
            } else {
                $message = "Error creating user: " . mysqli_error($conn); $message_type = 'error';
            }
        }
    }
}

// Fetch all users with their linked students (if parent)
$users_result = mysqli_query($conn, "SELECT u.id, u.username, u.role,
                                    GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') as linked_students_names
                                    FROM users u
                                    LEFT JOIN user_student_links usl ON u.id = usl.user_id AND u.role = 'parent'
                                    LEFT JOIN students s ON usl.student_id = s.id
                                    GROUP BY u.id, u.username, u.role
                                    ORDER BY u.role, u.username");

// Fetch all students for linking dropdown
$all_students_result = mysqli_query($conn, "SELECT id, name, roll_number,
                                            IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display
                                            FROM students s
                                            JOIN class_sections cs ON s.class_section_id = cs.id
                                            JOIN grades g ON cs.grade_id = g.id
                                            JOIN divisions d ON cs.division_id = d.id
                                            ORDER BY class_section_display, name");
$students_for_linking = [];
if($all_students_result){
    while($row = mysqli_fetch_assoc($all_students_result)){
        $students_for_linking[] = $row;
    }
}

ob_start();
?>

<div class="container-fluid mt-3">
    <h1><?php echo htmlspecialchars($page_title); ?></h1>
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : ($message_type == 'success' ? 'success' : 'warning'); ?> alert-dismissible fade show" role="alert">
            <?php echo $message; /* May contain HTML from mysqli_error */ ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card p-3 mb-4">
        <h2 class="h4">Create New User</h2>
        <form action="manage_users.php" method="POST">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="username" class="form-label">Username:</label>
                    <input type="text" name="username" id="username" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label for="password" class="form-label">Password (min 6 chars):</label>
                    <input type="password" name="password" id="password" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label for="confirm_password" class="form-label">Confirm Password:</label>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                </div>
                <div class="col-md-12">
                    <label for="role" class="form-label">Role:</label>
                    <select name="role" id="role" class="form-select" required onchange="toggleStudentLink(this)">
                        <option value="teacher">Teacher</option>
                        <option value="admin">Admin</option>
                        <option value="parent">Parent</option>
                    </select>
                </div>

                <div id="studentLinkSection" class="col-md-12" style="display:none;">
                    <label class="form-label">Link Parent to Student(s):</label>
                    <div class="student-link-options border rounded p-2 bg-white" style="max-height: 200px; overflow-y: auto;">
                        <?php if (!empty($students_for_linking)): ?>
                            <?php foreach($students_for_linking as $student): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="linked_students[]" value="<?php echo $student['id']; ?>" id="student_<?php echo $student['id']; ?>">
                                    <label class="form-check-label" for="student_<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['name']) . " (Roll: " . htmlspecialchars($student['roll_number']) . " - " . htmlspecialchars($student['class_section_display']) . ")"; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted">No students available to link. Please add students first.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" name="create_user" class="btn btn-primary">Create User</button>
                </div>
            </div>
        </form>
    </div>

    <h2 class="mt-4">Existing Users</h2>
    <?php if ($users_result && mysqli_num_rows($users_result) > 0): ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-light">
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
                            <td><?php echo htmlspecialchars(ucfirst($user['role'])); ?></td>
                            <td>
                                <?php
                                if ($user['role'] == 'parent' && !empty($user['linked_students_names'])) {
                                    echo htmlspecialchars($user['linked_students_names']);
                                } elseif ($user['role'] == 'parent') {
                                    echo '<em class="text-muted">No students linked</em>';
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($user['role'] == 'parent' || ($user['role'] == 'teacher' && $user['id'] != current_user_id() ) ): // Example condition for manage links ?>
                                    <a href="manage_user_links.php?user_id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-info py-0">Manage Links/Details</a>
                                <?php endif; ?>
                                <!-- TODO: Add Edit/Delete User functionality -->
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="alert alert-info">No users found (except the initial admin if not listed due to grouping). Use the form above to create users.</p>
    <?php endif; ?>
</div>
<script>
    function toggleStudentLink(roleSelect) {
        var studentLinkDiv = document.getElementById('studentLinkSection');
        if (roleSelect.value === 'parent') {
            studentLinkDiv.style.display = 'block';
        } else {
            studentLinkDiv.style.display = 'none';
        }
    }
    // Initial check on page load in case of form reload with 'parent' selected
    var roleSelect = document.getElementById('role');
    if (roleSelect) {
        toggleStudentLink(roleSelect);
    }
</script>

<?php
$page_content_html = ob_get_clean();
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
