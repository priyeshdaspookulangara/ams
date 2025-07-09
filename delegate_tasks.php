<?php
include_once 'auth_check.php';
require_login(['admin', 'teacher']);

$page_title = "Delegate Tasks";

include 'config.php';
// functions.php is included via auth_check.php or should be if needed for get_teacher_... functions

$message = '';
$message_type = '';
$current_user_id = current_user_id();
$current_role = current_user_role();

$selected_class_section_id = isset($_REQUEST['class_section_id']) ? (int)$_REQUEST['class_section_id'] : 0;

$manageable_class_sections = [];
$cs_query_sql = "";
if ($current_role == 'admin') {
    $cs_query_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                     FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id
                     ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
} elseif ($current_role == 'teacher') {
    $cs_query_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name
                     FROM class_sections cs JOIN grades g ON cs.grade_id = g.id JOIN divisions d ON cs.division_id = d.id
                     WHERE cs.class_teacher_user_id = $current_user_id
                     ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
}
if (!empty($cs_query_sql)) {
    $cs_res = mysqli_query($conn, $cs_query_sql);
    if ($cs_res) while ($row = mysqli_fetch_assoc($cs_res)) $manageable_class_sections[] = $row;
    else { $message = "DB Error (manageable_cs): " . mysqli_error($conn); $message_type = 'error';}
}

$teachers_for_delegation = [];
$all_teachers_sql = "SELECT id, username FROM users WHERE role = 'teacher' ORDER BY username"; // Initial list
$teachers_res_initial = mysqli_query($conn, $all_teachers_sql);
if ($teachers_res_initial) while ($row = mysqli_fetch_assoc($teachers_res_initial)) $teachers_for_delegation[] = $row;


if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_delegation'])) {
    $delegated_to_user_id = (int)$_POST['delegated_to_user_id'];
    $form_class_section_id = (int)$_POST['form_class_section_id'];
    $can_take_attendance = isset($_POST['can_take_attendance']) ? 1 : 0;
    $can_manage_students = isset($_POST['can_manage_students']) ? 1 : 0;

    $can_manage_this_section = false;
    if ($current_role == 'admin') $can_manage_this_section = true;
    else {
        foreach($manageable_class_sections as $mcs) { if ($mcs['id'] == $form_class_section_id) {$can_manage_this_section = true; break;} }
    }

    $class_teacher_of_section_id = null;
    if($form_class_section_id > 0){
        $class_teacher_q = mysqli_query($conn, "SELECT class_teacher_user_id FROM class_sections WHERE id = $form_class_section_id");
        if($class_teacher_q && mysqli_num_rows($class_teacher_q)>0) $class_teacher_of_section_id = mysqli_fetch_assoc($class_teacher_q)['class_teacher_user_id'];
    }


    if (!$can_manage_this_section) {
        $message = "You do not have permission to delegate tasks for this class section."; $message_type = 'error';
    } elseif ($delegated_to_user_id == 0) {
        $message = "Please select a teacher to delegate to."; $message_type = 'error';
    } elseif ($delegated_to_user_id == $class_teacher_of_section_id) {
        $message = "You cannot delegate tasks to the primary class teacher of the section."; $message_type = 'error';
    } elseif ($current_role == 'teacher' && $delegated_to_user_id == $current_user_id) {
        $message = "You cannot delegate tasks to yourself."; $message_type = 'error'; // Prevent self-delegation by class teacher
    }
    else {
        $check_sql = "SELECT id FROM teacher_delegations WHERE class_section_id = $form_class_section_id AND delegated_to_user_id = $delegated_to_user_id";
        $check_res = mysqli_query($conn, $check_sql);
        if (mysqli_num_rows($check_res) > 0) {
            $existing_delegation = mysqli_fetch_assoc($check_res); $delegation_id = $existing_delegation['id'];
            $sql = "UPDATE teacher_delegations SET can_take_attendance = $can_take_attendance, can_manage_students = $can_manage_students, delegated_by_user_id = $current_user_id WHERE id = $delegation_id";
            if (mysqli_query($conn, $sql)) { $message = "Delegation updated successfully."; $message_type = 'success'; }
            else { $message = "Error updating delegation: " . mysqli_error($conn); $message_type = 'error'; }
        } else {
            $sql = "INSERT INTO teacher_delegations (class_section_id, delegated_to_user_id, can_take_attendance, can_manage_students, delegated_by_user_id) VALUES ($form_class_section_id, $delegated_to_user_id, $can_take_attendance, $can_manage_students, $current_user_id)";
            if (mysqli_query($conn, $sql)) { $message = "Delegation created successfully."; $message_type = 'success'; }
            else { $message = "Error creating delegation: " . mysqli_error($conn); $message_type = 'error'; }
        }
        $selected_class_section_id = $form_class_section_id;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['remove_delegation'])) {
    $delegation_id_to_remove = (int)$_POST['delegation_id'];
    $form_class_section_id = (int)$_POST['form_class_section_id_hidden'];

    $sql_get_delegation_details = "SELECT td.class_section_id, cs.class_teacher_user_id FROM teacher_delegations td JOIN class_sections cs ON td.class_section_id = cs.id WHERE td.id = $delegation_id_to_remove";
    $del_details_res = mysqli_query($conn, $sql_get_delegation_details);

    if ($del_details_res && mysqli_num_rows($del_details_res) > 0) {
        $del_info = mysqli_fetch_assoc($del_details_res);
        if ($current_role == 'admin' || ($current_role == 'teacher' && $del_info['class_teacher_user_id'] == $current_user_id)) {
            $sql_delete = "DELETE FROM teacher_delegations WHERE id = $delegation_id_to_remove";
            if (mysqli_query($conn, $sql_delete)) { $message = "Delegation removed successfully."; $message_type = 'success'; }
            else { $message = "Error removing delegation: " . mysqli_error($conn); $message_type = 'error'; }
        } else { $message = "You do not have permission to remove this delegation."; $message_type = 'error'; }
    } else { $message = "Delegation not found for removal."; $message_type = 'error'; }
    $selected_class_section_id = $form_class_section_id;
}

$current_delegations = [];
if ($selected_class_section_id > 0) {
    // Check if current user can actually manage this selected section before showing details
    $can_actually_manage_selected = false;
    if($current_role == 'admin') $can_actually_manage_selected = true;
    else { foreach($manageable_class_sections as $mcs) { if($mcs['id'] == $selected_class_section_id) $can_actually_manage_selected = true; } }

    if($can_actually_manage_selected){
        $delegations_sql = "SELECT td.id, u.username as delegated_teacher_name, td.can_take_attendance, td.can_manage_students, ub.username as delegated_by_name
                            FROM teacher_delegations td
                            JOIN users u ON td.delegated_to_user_id = u.id
                            JOIN users ub ON td.delegated_by_user_id = ub.id
                            WHERE td.class_section_id = $selected_class_section_id
                            ORDER BY u.username";
        $delegations_res = mysqli_query($conn, $delegations_sql);
        if ($delegations_res) while ($row = mysqli_fetch_assoc($delegations_res)) $current_delegations[] = $row;

        // Refine teachers_for_delegation to exclude class teacher of *this selected section* and self if teacher
        $selected_section_details_q = mysqli_query($conn, "SELECT class_teacher_user_id FROM class_sections WHERE id = $selected_class_section_id");
        $ct_id_for_selected_section = null;
        if($selected_section_details_q && mysqli_num_rows($selected_section_details_q) > 0){
            $ct_id_for_selected_section = mysqli_fetch_assoc($selected_section_details_q)['class_teacher_user_id'];
        }

        $temp_teachers_list = [];
        $exclude_ids = [];
        if($ct_id_for_selected_section) $exclude_ids[] = $ct_id_for_selected_section;
        if($current_role == 'teacher') $exclude_ids[] = $current_user_id; // Teacher cannot delegate to self

        $all_teachers_sql_refined = "SELECT id, username FROM users WHERE role = 'teacher'";
        if(!empty($exclude_ids)){
            $exclude_ids_str = implode(',', array_unique(array_map('intval',$exclude_ids)));
            $all_teachers_sql_refined .= " AND id NOT IN ($exclude_ids_str)";
        }
        $all_teachers_sql_refined .= " ORDER BY username";

        $teachers_res_refined = mysqli_query($conn, $all_teachers_sql_refined);
        $teachers_for_delegation = []; // Reset and refill
        if ($teachers_res_refined) while ($row = mysqli_fetch_assoc($teachers_res_refined)) $teachers_for_delegation[] = $row;

    } else {
        if(empty($message)) { // Only show if no other error/message is more prominent
            $message = "You do not have permission to manage the selected class section's delegations.";
            $message_type = 'error';
        }
        $selected_class_section_id = 0; // Reset selection
        $current_delegations = []; // Clear delegations
    }
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

    <div class="card p-3 mb-3 bg-light">
        <form action="delegate_tasks.php" method="GET" id="selectSectionForm">
            <div class="mb-3">
                <label for="class_section_id" class="form-label">Select Class Section to Manage Delegations:</label>
                <select name="class_section_id" id="class_section_id" class="form-select" required onchange="document.getElementById('selectSectionForm').submit();">
                    <option value="">-- Select Section --</option>
                    <?php foreach ($manageable_class_sections as $cs): ?>
                        <option value="<?php echo $cs['id']; ?>" <?php echo ($selected_class_section_id == $cs['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cs['display_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button type="submit" class="btn btn-secondary btn-sm">Load Section</button></noscript>
        </form>
    </div>

    <?php if ($selected_class_section_id > 0 && (current_user_role() == 'admin' || in_array($selected_class_section_id, array_column($manageable_class_sections, 'id')) ) ): ?>
        <div class="card p-3 mb-4">
            <h2 class="h4">Delegate Tasks to Another Teacher</h2>
            <form action="delegate_tasks.php" method="POST">
                <input type="hidden" name="form_class_section_id" value="<?php echo $selected_class_section_id; ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="delegated_to_user_id" class="form-label">Select Teacher to Delegate To:</label>
                        <select name="delegated_to_user_id" id="delegated_to_user_id" class="form-select" required>
                            <option value="">-- Select Teacher --</option>
                            <?php foreach ($teachers_for_delegation as $teacher): ?>
                                <option value="<?php echo $teacher['id']; ?>">
                                    <?php echo htmlspecialchars($teacher['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 align-self-center">
                        <label class="form-label d-block mb-2">Permissions to Delegate:</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="can_take_attendance" id="can_take_attendance" value="1">
                            <label class="form-check-label" for="can_take_attendance">Can Take Attendance</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="can_manage_students" id="can_manage_students" value="1">
                            <label class="form-check-label" for="can_manage_students">Can Manage Students</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="save_delegation" class="btn btn-primary">Save/Update Delegation</button>
                    </div>
                </div>
            </form>
        </div>

        <h2 class="mt-4">Current Delegations for this Section</h2>
        <?php if (!empty($current_delegations)): ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm">
                    <thead class="table-light">
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
                                <td><span class="badge bg-<?php echo $delegation['can_take_attendance'] ? 'success' : 'secondary'; ?>"><?php echo $delegation['can_take_attendance'] ? 'Yes' : 'No'; ?></span></td>
                                <td><span class="badge bg-<?php echo $delegation['can_manage_students'] ? 'success' : 'secondary'; ?>"><?php echo $delegation['can_manage_students'] ? 'Yes' : 'No'; ?></span></td>
                                <td><?php echo htmlspecialchars($delegation['delegated_by_name']); ?></td>
                                <td>
                                    <form action="delegate_tasks.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove this delegation?');">
                                        <input type="hidden" name="delegation_id" value="<?php echo $delegation['id']; ?>">
                                        <input type="hidden" name="form_class_section_id_hidden" value="<?php echo $selected_class_section_id; ?>">
                                        <button type="submit" name="remove_delegation" class="btn btn-sm btn-outline-danger py-0">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="alert alert-info">No tasks have been delegated for this class section yet.</p>
        <?php endif; ?>
    <?php elseif (empty($message) && !empty($manageable_class_sections)): ?>
        <p class="alert alert-info">Please select a class section above to manage its delegations.</p>
    <?php elseif (empty($manageable_class_sections) && empty($message)): ?>
         <p class="alert alert-warning">You are not assigned as a class teacher to any sections, or no class sections have been created by the admin, so you cannot delegate tasks.</p>
    <?php endif; ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
