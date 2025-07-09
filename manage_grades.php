<?php
include_once 'auth_check.php';
require_login(['admin']);

$page_title = "Manage Grades";

include 'config.php';

$message = '';
$message_type = '';

// Handle Create/Update Grade
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['save_grade'])) {
        $grade_name = mysqli_real_escape_string($conn, trim($_POST['grade_name']));
        $grade_id = isset($_POST['grade_id']) ? (int)$_POST['grade_id'] : 0;

        if (empty($grade_name)) {
            $message = "Grade name cannot be empty."; $message_type = 'error';
        } else {
            $check_sql = "SELECT id FROM grades WHERE grade_name = '$grade_name'" . ($grade_id > 0 ? " AND id != $grade_id" : "");
            $check_res = mysqli_query($conn, $check_sql);

            if (mysqli_num_rows($check_res) > 0) {
                $message = "Grade name '$grade_name' already exists."; $message_type = 'error';
            } else {
                if ($grade_id > 0) {
                    $sql = "UPDATE grades SET grade_name = '$grade_name' WHERE id = $grade_id";
                    if (mysqli_query($conn, $sql)) { $message = "Grade updated successfully."; $message_type = 'success'; }
                    else { $message = "Error updating grade: " . mysqli_error($conn); $message_type = 'error'; }
                } else {
                    $sql = "INSERT INTO grades (grade_name) VALUES ('$grade_name')";
                    if (mysqli_query($conn, $sql)) { $message = "Grade '$grade_name' created successfully."; $message_type = 'success'; }
                    else { $message = "Error creating grade: " . mysqli_error($conn); $message_type = 'error'; }
                }
            }
        }
    } elseif (isset($_POST['delete_grade'])) {
        $grade_id = (int)$_POST['grade_id'];
        $check_usage_sql = "SELECT id FROM class_sections WHERE grade_id = $grade_id LIMIT 1";
        $usage_res = mysqli_query($conn, $check_usage_sql);
        if (mysqli_num_rows($usage_res) > 0) {
            $message = "Cannot delete grade: It is used in class sections. Remove assignments first."; $message_type = 'error';
        } else {
            $sql = "DELETE FROM grades WHERE id = $grade_id";
            if (mysqli_query($conn, $sql)) { $message = "Grade deleted successfully."; $message_type = 'success';}
            else { $message = "Error deleting grade: " . mysqli_error($conn); $message_type = 'error'; }
        }
    }
}

$grades_result = mysqli_query($conn, "SELECT * FROM grades ORDER BY grade_name");
$edit_grade = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $edit_sql = "SELECT * FROM grades WHERE id = $edit_id";
    $edit_res = mysqli_query($conn, $edit_sql);
    if ($edit_res && mysqli_num_rows($edit_res) > 0) $edit_grade = mysqli_fetch_assoc($edit_res);
    else { $message = "Grade not found for editing."; $message_type = 'error'; }
}

ob_start();
?>

<div class="container-fluid mt-3">
    <h1><?php echo htmlspecialchars($page_title); ?></h1>
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card p-3 mb-4">
        <h2 class="h4"><?php echo $edit_grade ? 'Edit Grade' : 'Create New Grade'; ?></h2>
        <form action="manage_grades.php<?php echo $edit_grade ? '?edit_id=' . $edit_grade['id'] : ''; ?>" method="POST">
            <?php if ($edit_grade): ?>
                <input type="hidden" name="grade_id" value="<?php echo $edit_grade['id']; ?>">
            <?php endif; ?>
            <div class="mb-3">
                <label for="grade_name" class="form-label">Grade Name (e.g., "Grade 1", "Class X"):</label>
                <input type="text" name="grade_name" id="grade_name" class="form-control" value="<?php echo $edit_grade ? htmlspecialchars($edit_grade['grade_name']) : ''; ?>" required>
            </div>
            <button type="submit" name="save_grade" class="btn btn-primary"><?php echo $edit_grade ? 'Update Grade' : 'Create Grade'; ?></button>
            <?php if ($edit_grade): ?>
                <a href="manage_grades.php" class="btn btn-secondary ms-2">Cancel Edit</a>
            <?php endif; ?>
        </form>
    </div>

    <h2 class="mt-4">Existing Grades</h2>
    <?php if ($grades_result && mysqli_num_rows($grades_result) > 0): ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Grade Name</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($grade = mysqli_fetch_assoc($grades_result)): ?>
                        <tr>
                            <td><?php echo $grade['id']; ?></td>
                            <td><?php echo htmlspecialchars($grade['grade_name']); ?></td>
                            <td>
                                <a href="manage_grades.php?edit_id=<?php echo $grade['id']; ?>" class="btn btn-sm btn-outline-primary py-0">Edit</a>
                                <form action="manage_grades.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this grade?');">
                                    <input type="hidden" name="grade_id" value="<?php echo $grade['id']; ?>">
                                    <button type="submit" name="delete_grade" class="btn btn-sm btn-outline-danger py-0">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="alert alert-info">No grades found. Seed data might have added default grades.</p>
    <?php endif; ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
