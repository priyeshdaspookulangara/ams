<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

$page_title = "Manage Parents/Guardians";

include 'config.php';

$message = '';
$message_type = '';
$student_id = null;
$student_name = '';
$student_class_section_info = '';


if (isset($_GET['student_id'])) {
    $student_id = (int)$_GET['student_id'];
    // Fetch student details to display name and verify access
    $student_query_sql = "SELECT s.name, s.class_section_id,
                                 IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display
                          FROM students s
                          JOIN class_sections cs ON s.class_section_id = cs.id
                          JOIN grades g ON cs.grade_id = g.id
                          JOIN divisions d ON cs.division_id = d.id
                          WHERE s.id = $student_id";
    $student_result = mysqli_query($conn, $student_query_sql);

    if ($student_result && mysqli_num_rows($student_result) > 0) {
        $student_data = mysqli_fetch_assoc($student_result);
        $student_name = $student_data['name'];
        $student_class_section_info = $student_data['class_section_display'];
        $student_actual_cs_id = $student_data['class_section_id'];

        // Permission check: Can current user manage this student?
        $can_manage_this_student = false;
        if(current_user_role() == 'admin'){
            $can_manage_this_student = true;
        } elseif (current_user_role() == 'teacher'){
            if(is_class_teacher_of_section(current_user_id(), $student_actual_cs_id, $conn) ||
               has_delegated_permission(current_user_id(), $student_actual_cs_id, 'can_manage_students', $conn)){
                $can_manage_this_student = true;
            }
        }
        if(!$can_manage_this_student){
            $_SESSION['error_message'] = "You do not have permission to manage parents for this student.";
            header("Location: students.php"); // Redirect if no permission
            exit;
        }

    } else {
        $message = "Error: Student not found."; $message_type = 'error';
        $student_id = null;
    }
} else {
    // If no student_id, redirect or show error, as this page is context-dependent
    $_SESSION['error_message'] = "No student ID provided to manage parents.";
    header("Location: students.php");
    exit;
}

// Handle Add Parent/Guardian form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_parent_guardian']) && $student_id) {
    // Permission already checked when loading student details
    $parent_name = mysqli_real_escape_string($conn, trim($_POST['parent_name']));
    $phone_number = mysqli_real_escape_string($conn, trim($_POST['phone_number']));
    $relationship = mysqli_real_escape_string($conn, trim($_POST['relationship']));
    $email = isset($_POST['email']) ? mysqli_real_escape_string($conn, trim($_POST['email'])) : null;

    if (empty($parent_name) || empty($phone_number) || empty($relationship)) {
        $message = "Parent name, phone number, and relationship are required."; $message_type = 'error';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email address format provided."; $message_type = 'error';
    } else {
        $check_sql = "SELECT id FROM parent_guardians WHERE student_id = $student_id AND parent_name = '$parent_name' AND phone_number = '$phone_number'";
        $check_result = mysqli_query($conn, $check_sql);
        if ($check_result && mysqli_num_rows($check_result) > 0) {
            $message = "Error: This parent/guardian (based on name and phone) is already listed for this student."; $message_type = 'error';
        } else {
            $email_sql_val = $email ? "'$email'" : "NULL";
            $insert_sql = "INSERT INTO parent_guardians (student_id, parent_name, phone_number, email, relationship)
                           VALUES ($student_id, '$parent_name', '$phone_number', $email_sql_val, '$relationship')";
            if (mysqli_query($conn, $insert_sql)) {
                $message = "Parent/Guardian '$parent_name' added successfully for $student_name."; $message_type = 'success';
            } else { $message = "Error adding parent/guardian: " . mysqli_error($conn); $message_type = 'error'; }
        }
    }
}
// TODO: Add logic for Deleting a parent/guardian record.

// Fetch existing parents for this student
$parents_list = [];
if ($student_id) {
    $sql_get_parents = "SELECT * FROM parent_guardians WHERE student_id = $student_id ORDER BY parent_name";
    $parents_result = mysqli_query($conn, $sql_get_parents);
    if ($parents_result) while ($row = mysqli_fetch_assoc($parents_result)) $parents_list[] = $row;
}

ob_start();
?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1><?php echo htmlspecialchars($page_title); ?> for <?php echo htmlspecialchars($student_name); ?></h1>
        <a href="students.php?filter_cs_id=<?php echo $student_actual_cs_id ?? 'all'; ?>" class="btn btn-outline-secondary">&laquo; Back to Student List</a>
    </div>
    <p class="text-muted">Student: <?php echo htmlspecialchars($student_name); ?> (<?php echo htmlspecialchars($student_class_section_info); ?>)</p>


    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($student_id): ?>
    <div class="card p-3 mb-4">
        <h2 class="h4">Add New Parent/Guardian</h2>
        <form action="parents.php?student_id=<?php echo $student_id; ?>" method="POST">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="parent_name" class="form-label">Parent/Guardian Name:</label>
                    <input type="text" name="parent_name" id="parent_name" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label for="relationship" class="form-label">Relationship to Student:</label>
                    <input type="text" name="relationship" id="relationship" class="form-control" placeholder="e.g., Father, Mother, Guardian" required>
                </div>
                <div class="col-md-6">
                    <label for="phone_number" class="form-label">Phone Number:</label>
                    <input type="text" name="phone_number" id="phone_number" class="form-control" required>
                    <div class="form-text">Include country code if applicable, e.g., +11234567890</div>
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">Email Address (Optional):</label>
                    <input type="email" name="email" id="email" class="form-control" placeholder="e.g., parent@example.com">
                </div>
                <div class="col-12">
                    <button type="submit" name="add_parent_guardian" class="btn btn-primary">Add Parent/Guardian</button>
                </div>
            </div>
        </form>
    </div>

    <h2 class="mt-4">Existing Parents/Guardians for <?php echo htmlspecialchars($student_name); ?></h2>
    <?php if (!empty($parents_list)): ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Relationship</th>
                        <th>Phone Number</th>
                        <th>Email</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parents_list as $parent): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($parent['parent_name']); ?></td>
                            <td><?php echo htmlspecialchars($parent['relationship']); ?></td>
                            <td><?php echo htmlspecialchars($parent['phone_number']); ?></td>
                            <td><?php echo $parent['email'] ? htmlspecialchars($parent['email']) : '<em class="text-muted">Not set</em>'; ?></td>
                            <td>
                                <!-- TODO: Add Edit/Delete Parent links -->
                                <button class="btn btn-sm btn-outline-danger py-0 disabled">Delete (future)</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="alert alert-info">No parents or guardians listed for this student yet.</p>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
