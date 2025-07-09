<?php
include_once 'auth_check.php';
require_login(['admin']);

$page_title = "Manage Class Sections";

include 'config.php';

$message = '';
$message_type = '';

// Fetch data for dropdowns
$grades_res = mysqli_query($conn, "SELECT id, grade_name FROM grades ORDER BY grade_name");
$divisions_res = mysqli_query($conn, "SELECT id, division_name FROM divisions ORDER BY division_name");
$teachers_res = mysqli_query($conn, "SELECT id, username FROM users WHERE role = 'teacher' ORDER BY username");

$grades_options = [];
if($grades_res) while ($row = mysqli_fetch_assoc($grades_res)) $grades_options[] = $row;
$divisions_options = [];
if($divisions_res) while ($row = mysqli_fetch_assoc($divisions_res)) $divisions_options[] = $row;
$teachers_options = [];
if($teachers_res) while ($row = mysqli_fetch_assoc($teachers_res)) $teachers_options[] = $row;


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['save_class_section'])) {
        $grade_id = (int)$_POST['grade_id'];
        $division_id = (int)$_POST['division_id'];
        $class_teacher_user_id_val = !empty($_POST['class_teacher_user_id']) ? (int)$_POST['class_teacher_user_id'] : "NULL"; // Keep as string "NULL" for SQL
        $academic_year = mysqli_real_escape_string($conn, trim($_POST['academic_year']));
        $section_name_override = isset($_POST['section_name']) ? mysqli_real_escape_string($conn, trim($_POST['section_name'])) : null;
        $class_section_id = isset($_POST['class_section_id']) ? (int)$_POST['class_section_id'] : 0;

        if ($grade_id == 0 || $division_id == 0 || empty($academic_year)) {
            $message = "Grade, Division, and Academic Year are required."; $message_type = 'error';
        } else {
            $auto_section_name = '';
            $grade_name_q_res = mysqli_query($conn, "SELECT grade_name FROM grades WHERE id=$grade_id");
            $div_name_q_res = mysqli_query($conn, "SELECT division_name FROM divisions WHERE id=$division_id");
            if($grade_name_q_res && $div_name_q_res && mysqli_num_rows($grade_name_q_res)>0 && mysqli_num_rows($div_name_q_res)>0){
                $g_name = mysqli_fetch_assoc($grade_name_q_res)['grade_name'];
                $d_name = mysqli_fetch_assoc($div_name_q_res)['division_name'];
                $auto_section_name = "$g_name - $d_name ($academic_year)";
            } else { $auto_section_name = "Section ($academic_year)";} // Fallback
            $final_section_name = !empty($section_name_override) ? $section_name_override : $auto_section_name;

            $check_sql = "SELECT id FROM class_sections WHERE grade_id = $grade_id AND division_id = $division_id AND academic_year = '$academic_year'" . ($class_section_id > 0 ? " AND id != $class_section_id" : "");
            $check_res = mysqli_query($conn, $check_sql);

            if (mysqli_num_rows($check_res) > 0) {
                $message = "This Class Section (Grade-Division for academic year) already exists."; $message_type = 'error';
            } else {
                if ($class_section_id > 0) {
                    $sql = "UPDATE class_sections SET grade_id = $grade_id, division_id = $division_id, class_teacher_user_id = $class_teacher_user_id_val, academic_year = '$academic_year', section_name = '$final_section_name' WHERE id = $class_section_id";
                    if (mysqli_query($conn, $sql)) { $message = "Class Section updated successfully."; $message_type = 'success'; }
                    else { $message = "Error updating Class Section: " . mysqli_error($conn); $message_type = 'error'; }
                } else {
                    $sql = "INSERT INTO class_sections (grade_id, division_id, class_teacher_user_id, academic_year, section_name) VALUES ($grade_id, $division_id, $class_teacher_user_id_val, '$academic_year', '$final_section_name')";
                    if (mysqli_query($conn, $sql)) { $message = "Class Section created successfully."; $message_type = 'success'; }
                    else { $message = "Error creating Class Section: " . mysqli_error($conn); $message_type = 'error'; }
                }
            }
        }
    } elseif (isset($_POST['delete_class_section'])) {
        $class_section_id = (int)$_POST['class_section_id'];
        $check_students_sql = "SELECT id FROM students WHERE class_section_id = $class_section_id LIMIT 1";
        $students_res = mysqli_query($conn, $check_students_sql);
        if (mysqli_num_rows($students_res) > 0) {
            $message = "Cannot delete: Students are assigned to this section. Reassign them first."; $message_type = 'error';
        } else {
            $check_delegations_sql = "SELECT id FROM teacher_delegations WHERE class_section_id = $class_section_id LIMIT 1";
            $delegations_res = mysqli_query($conn, $check_delegations_sql);
            if (mysqli_num_rows($delegations_res) > 0) {
                 $message = "Cannot delete: Teacher delegations exist for this section. Remove them first."; $message_type = 'error';
            } else {
                $sql = "DELETE FROM class_sections WHERE id = $class_section_id";
                if (mysqli_query($conn, $sql)) { $message = "Class Section deleted successfully."; $message_type = 'success'; }
                else { $message = "Error deleting Class Section: " . mysqli_error($conn); $message_type = 'error'; }
            }
        }
    }
}

$cs_sql = "SELECT cs.*, g.grade_name, d.division_name, u.username as class_teacher_name
           FROM class_sections cs
           JOIN grades g ON cs.grade_id = g.id
           JOIN divisions d ON cs.division_id = d.id
           LEFT JOIN users u ON cs.class_teacher_user_id = u.id
           ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
$class_sections_result = mysqli_query($conn, $cs_sql);

$edit_section = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $edit_sql_q = "SELECT * FROM class_sections WHERE id = $edit_id";
    $edit_res_q = mysqli_query($conn, $edit_sql_q);
    if ($edit_res_q && mysqli_num_rows($edit_res_q) > 0) $edit_section = mysqli_fetch_assoc($edit_res_q);
    else { $message = "Class Section not found for editing."; $message_type = 'error';}
}
$current_academic_year = date("Y") . "-" . (date("Y") + 1);

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
        <h2 class="h4"><?php echo $edit_section ? 'Edit Class Section' : 'Create New Class Section'; ?></h2>
        <form action="manage_class_sections.php<?php echo $edit_section ? '?edit_id=' . $edit_section['id'] : ''; ?>" method="POST">
            <?php if ($edit_section): ?>
                <input type="hidden" name="class_section_id" value="<?php echo $edit_section['id']; ?>">
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="grade_id" class="form-label">Grade:</label>
                    <select name="grade_id" id="grade_id" class="form-select" required>
                        <option value="">-- Select Grade --</option>
                        <?php foreach ($grades_options as $grade): ?>
                            <option value="<?php echo $grade['id']; ?>" <?php echo ($edit_section && $edit_section['grade_id'] == $grade['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($grade['grade_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="division_id" class="form-label">Division:</label>
                    <select name="division_id" id="division_id" class="form-select" required>
                        <option value="">-- Select Division --</option>
                        <?php foreach ($divisions_options as $division): ?>
                            <option value="<?php echo $division['id']; ?>" <?php echo ($edit_section && $edit_section['division_id'] == $division['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($division['division_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="academic_year" class="form-label">Academic Year (e.g., YYYY-YYYY):</label>
                    <input type="text" name="academic_year" id="academic_year" class="form-control" value="<?php echo $edit_section ? htmlspecialchars($edit_section['academic_year']) : $current_academic_year; ?>" required>
                </div>
                <div class="col-md-6">
                    <label for="class_teacher_user_id" class="form-label">Class Teacher (Optional):</label>
                    <select name="class_teacher_user_id" id="class_teacher_user_id" class="form-select">
                        <option value="">-- None --</option>
                        <?php foreach ($teachers_options as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>" <?php echo ($edit_section && $edit_section['class_teacher_user_id'] == $teacher['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($teacher['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="section_name" class="form-label">Section Display Name (Optional):</label>
                    <input type="text" name="section_name" id="section_name" class="form-control" value="<?php echo $edit_section ? htmlspecialchars($edit_section['section_name']) : ''; ?>" placeholder="Auto-generated if blank">
                </div>
                <div class="col-12">
                    <button type="submit" name="save_class_section" class="btn btn-primary"><?php echo $edit_section ? 'Update Section' : 'Create Section'; ?></button>
                    <?php if ($edit_section): ?>
                        <a href="manage_class_sections.php" class="btn btn-secondary ms-2">Cancel Edit</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <h2 class="mt-4">Existing Class Sections</h2>
    <?php if ($class_sections_result && mysqli_num_rows($class_sections_result) > 0): ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm">
                <thead class="table-light">
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
                            <td><?php echo $section['class_teacher_name'] ? htmlspecialchars($section['class_teacher_name']) : '<em class="text-muted">Not Assigned</em>'; ?></td>
                            <td>
                                <a href="manage_class_sections.php?edit_id=<?php echo $section['id']; ?>" class="btn btn-sm btn-outline-primary py-0">Edit</a>
                                <form action="manage_class_sections.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this class section?');">
                                    <input type="hidden" name="class_section_id" value="<?php echo $section['id']; ?>">
                                    <button type="submit" name="delete_class_section" class="btn btn-sm btn-outline-danger py-0">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="alert alert-info">No class sections found. Create grades and divisions first, then define class sections here.</p>
    <?php endif; ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
