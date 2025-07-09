<?php
include_once 'auth_check.php';
if (!is_logged_in()) {
    header("Location: login.php");
    exit;
}

$page_title = "Manage Students";

include 'config.php';
// functions.php is included by auth_check.php if get_teacher_student_manageable_sections is there,
// otherwise, ensure it's included if needed. It is needed for get_teacher_student_manageable_sections.
if (!function_exists('get_teacher_student_manageable_sections')) { // Simple check
    include_once 'functions.php';
}


$message = '';
$message_type = '';
$current_user_id = current_user_id();
$current_role = current_user_role();

$class_sections_options_for_form = [];
$manageable_cs_ids_for_teacher = [];

if ($current_role == 'admin') {
    $cs_q_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                 FROM class_sections cs
                 JOIN grades g ON cs.grade_id = g.id
                 JOIN divisions d ON cs.division_id = d.id
                 ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
    $cs_res = mysqli_query($conn, $cs_q_sql);
    if ($cs_res) while ($row = mysqli_fetch_assoc($cs_res)) $class_sections_options_for_form[] = $row;
    else { $message = "DB Error (cs_form_admin): " . mysqli_error($conn); $message_type = 'error';}

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
        else { $message = "DB Error (cs_form_teacher): " . mysqli_error($conn); $message_type = 'error'; }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_student'])) {
    require_login(['admin', 'teacher']); // Double check role for POST action
    $name = mysqli_real_escape_string($conn, trim($_POST['name']));
    $roll_number = mysqli_real_escape_string($conn, trim($_POST['roll_number']));
    $class_section_id_posted = (int)$_POST['class_section_id'];
    $date_of_birth = !empty($_POST['date_of_birth']) ? mysqli_real_escape_string($conn, $_POST['date_of_birth']) : NULL;

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
        $message = "You do not have permission to add students to this selected class section."; $message_type = 'error';
    } elseif (empty($name) || empty($roll_number) || $class_section_id_posted == 0) {
        $message = "Student name, roll number, and class section are required."; $message_type = 'error';
    } else {
        $check_sql = "SELECT id FROM students WHERE roll_number = '$roll_number' AND class_section_id = $class_section_id_posted";
        $check_result = mysqli_query($conn, $check_sql);
        if (mysqli_num_rows($check_result) > 0) {
            $message = "Error: Roll number '$roll_number' already exists in this class section."; $message_type = 'error';
        } else {
            $dob_sql_val = $date_of_birth ? "'$date_of_birth'" : "NULL";
            $insert_sql = "INSERT INTO students (name, roll_number, class_section_id, date_of_birth) VALUES ('$name', '$roll_number', $class_section_id_posted, $dob_sql_val)";
            if (mysqli_query($conn, $insert_sql)) {
                $message = "Student '$name' added successfully."; $message_type = 'success';
            } else { $message = "Error adding student: " . mysqli_error($conn); $message_type = 'error';}
        }
    }
}

$students_list = [];
$filter_class_section_id = isset($_GET['filter_cs_id']) ? $_GET['filter_cs_id'] : 'all';
$filter_details_header = "All Accessible Students";

$students_query_base = "SELECT s.*,
                               IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as class_section_display
                        FROM students s
                        LEFT JOIN class_sections cs ON s.class_section_id = cs.id
                        LEFT JOIN grades g ON cs.grade_id = g.id
                        LEFT JOIN divisions d ON cs.division_id = d.id";
$students_query_conditions = " WHERE 1=1 ";
$students_query_order = " ORDER BY cs.academic_year DESC, g.grade_name, d.division_name, s.roll_number";

if ($current_role == 'admin') {
    if ($filter_class_section_id !== 'all' && $filter_class_section_id > 0) {
        $students_query_conditions .= " AND s.class_section_id = " . (int)$filter_class_section_id;
        // Get name for header
        $cs_name_q = mysqli_query($conn, "SELECT IFNULL(csn.section_name, CONCAT(gr.grade_name, ' - ', div.division_name, ' (', csn.academic_year, ')')) as name FROM class_sections csn JOIN grades gr ON csn.grade_id = gr.id JOIN divisions div ON csn.division_id = div.id WHERE csn.id = ".(int)$filter_class_section_id);
        $filter_details_header = ($cs_name_q && mysqli_num_rows($cs_name_q)>0) ? mysqli_fetch_assoc($cs_name_q)['name'] : "Selected Section";
    }
} elseif ($current_role == 'teacher') {
    // $manageable_cs_ids_for_teacher was fetched for form dropdown
    if (!empty($manageable_cs_ids_for_teacher)) {
        $ids_string = implode(',', $manageable_cs_ids_for_teacher);
        if ($filter_class_section_id !== 'all' && in_array($filter_class_section_id, $manageable_cs_ids_for_teacher)) {
             $students_query_conditions .= " AND s.class_section_id = " . (int)$filter_class_section_id;
             $cs_name_q = mysqli_query($conn, "SELECT IFNULL(csn.section_name, CONCAT(gr.grade_name, ' - ', div.division_name, ' (', csn.academic_year, ')')) as name FROM class_sections csn JOIN grades gr ON csn.grade_id = gr.id JOIN divisions div ON csn.division_id = div.id WHERE csn.id = ".(int)$filter_class_section_id);
             $filter_details_header = ($cs_name_q && mysqli_num_rows($cs_name_q)>0) ? mysqli_fetch_assoc($cs_name_q)['name'] : "Selected Section";
        } else {
            $students_query_conditions .= " AND s.class_section_id IN ($ids_string)";
            $filter_details_header = "Your Manageable Sections";
        }
    } else { $students_query_conditions .= " AND 1=0";  $filter_details_header = "No sections assigned/delegated.";}
} else {  $students_query_conditions .= " AND 1=0"; $filter_details_header = "Access Denied.";}

$students_final_sql = $students_query_base . $students_query_conditions . $students_query_order;
if (!($current_role == 'parent' && $students_query_conditions == " WHERE 1=1 AND 1=0")) { // Avoid query if no access
    $students_result = mysqli_query($conn, $students_final_sql);
    if($students_result){
        while($row = mysqli_fetch_assoc($students_result)) $students_list[] = $row;
    } else { $message = "DB Error (student_list): " . mysqli_error($conn); $message_type = 'error';}
}


ob_start();
?>
<div class="container-fluid mt-3">
    <h1><?php echo htmlspecialchars($page_title); ?></h1>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : ($message_type == 'success' ? 'success' : 'info'); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (in_array($current_role, ['admin', 'teacher']) && !empty($class_sections_options_for_form)): ?>
    <div class="card p-3 mb-4">
        <h2 class="h4">Add New Student</h2>
        <form action="students.php" method="POST">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Student Name:</label>
                    <input type="text" name="name" id="name" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label for="roll_number" class="form-label">Roll Number:</label>
                    <input type="text" name="roll_number" id="roll_number" class="form-control" required>
                </div>
                 <div class="col-md-3">
                    <label for="date_of_birth" class="form-label">Date of Birth (Optional):</label>
                    <input type="date" name="date_of_birth" id="date_of_birth" class="form-control">
                </div>
                <div class="col-md-12">
                    <label for="class_section_id" class="form-label">Class Section:</label>
                    <select name="class_section_id" id="class_section_id" class="form-select" required>
                        <option value="">-- Select Class Section --</option>
                        <?php foreach ($class_sections_options_for_form as $cs): ?>
                            <option value="<?php echo $cs['id']; ?>">
                                <?php echo htmlspecialchars($cs['display_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" name="add_student" class="btn btn-primary">Add Student</button>
                </div>
            </div>
        </form>
    </div>
    <?php elseif (in_array($current_role, ['admin', 'teacher']) && empty($class_sections_options_for_form)): ?>
         <p class="alert alert-info">
            <?php echo $current_role == 'admin' ? 'Please create Class Sections first in "Manage Class Sections" before adding students.' : 'You are not currently assigned to manage students for any class sections.'; ?>
        </p>
    <?php endif; ?>


    <h2 class="mt-4">Existing Students <small class="text-muted fs-6">(Showing: <?php echo htmlspecialchars($filter_details_header); ?>)</small></h2>
    <div class="card p-3 mb-3 bg-light">
        <form action="students.php" method="GET">
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label for="filter_cs_id" class="form-label">Filter by Class Section:</label>
                    <select name="filter_cs_id" id="filter_cs_id" class="form-select" onchange="this.form.submit()">
                        <option value="all">-- Show All My Accessible Classes --</option>
                        <?php
                        $filter_dropdown_options = ($current_role == 'admin') ? [] : $class_sections_options_for_form; // For teacher, use already filtered list
                        if ($current_role == 'admin') {
                            $all_cs_q_filter = mysqli_query($conn, "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id ORDER BY display_name");
                            if($all_cs_q_filter) while($r = mysqli_fetch_assoc($all_cs_q_filter)) $filter_dropdown_options[] = $r;
                        }
                        foreach ($filter_dropdown_options as $cs_filter_opt): ?>
                            <option value="<?php echo $cs_filter_opt['id']; ?>" <?php echo ($filter_class_section_id == $cs_filter_opt['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cs_filter_opt['display_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-auto">
                     <?php if ($filter_class_section_id !== 'all'): ?>
                        <a href="students.php" class="btn btn-outline-secondary">Show All</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <?php if (!empty($students_list)): ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Roll No.</th>
                        <th>DOB</th>
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
                            <td><?php echo $student['date_of_birth'] ? date("M j, Y", strtotime($student['date_of_birth'])) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($student['class_section_display']); ?></td>
                            <td>
                                <?php
                                $parent_sql = "SELECT parent_name, phone_number, email, relationship FROM parent_guardians WHERE student_id = " . $student['id'] . " ORDER BY relationship";
                                $parents_result = mysqli_query($conn, $parent_sql);
                                if ($parents_result && mysqli_num_rows($parents_result) > 0) {
                                    echo '<ul class="list-unstyled mb-0 small">';
                                    while ($parent = mysqli_fetch_assoc($parents_result)) {
                                        $email_info = $parent['email'] ? ' / ' . htmlspecialchars($parent['email']) : '';
                                        echo '<li>' . htmlspecialchars($parent['parent_name']) . ' (' . htmlspecialchars($parent['relationship']) . ')<br>Ph: ' . htmlspecialchars($parent['phone_number']) . $email_info . '</li>';
                                    }
                                    echo '</ul>';
                                } else { echo '<small class="text-muted">No parents listed.</small>'; }
                                ?>
                            </td>
                            <td class="action-links">
                                <a href="parents.php?student_id=<?php echo $student['id']; ?>" class="btn btn-sm btn-outline-primary py-0">Parents</a>
                                <!-- TODO: Edit/Delete Student (with permissions) -->
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif (in_array($current_role, ['admin', 'teacher'])): ?>
        <p class="alert alert-info">No students found matching your criteria or in sections you manage.</p>
    <?php else: ?>
         <p class="alert alert-warning">You do not have permission to view student lists.</p>
    <?php endif; ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
