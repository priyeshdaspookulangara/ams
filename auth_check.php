<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

function require_login($allowed_roles = []) {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI']; // Store intended URL
        header("Location: login.php");
        exit;
    }
    if (!empty($allowed_roles)) {
        if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
            $_SESSION['error_message'] = "You do not have permission to access this page with your current role.";
            header("Location: index.php"); // Or a dedicated 'unauthorized' page
            exit;
        }
    }
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function current_user_role() {
    return isset($_SESSION['role']) ? $_SESSION['role'] : null;
}

function current_user_id() {
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
}

function current_username() {
    return isset($_SESSION['username']) ? $_SESSION['username'] : null;
}

function current_entity_id(){ // Currently not heavily used with new structure
    return isset($_SESSION['entity_id']) ? $_SESSION['entity_id'] : null;
}

// --- New Authorization Helper Functions ---

/**
 * Checks if a user is the designated class teacher for a specific class section.
 * @param int $user_id The ID of the user (teacher).
 * @param int $class_section_id The ID of the class section.
 * @param mysqli $conn The database connection.
 * @return bool True if the user is the class teacher, false otherwise.
 */
function is_class_teacher_of_section($user_id, $class_section_id, $conn) {
    if (!$user_id || !$class_section_id || !$conn) return false;
    $user_id = (int)$user_id;
    $class_section_id = (int)$class_section_id;

    $sql = "SELECT id FROM class_sections WHERE id = $class_section_id AND class_teacher_user_id = $user_id";
    $result = mysqli_query($conn, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        return true;
    }
    return false;
}

/**
 * Checks if a teacher has been delegated a specific permission for a class section.
 * @param int $user_id The ID of the user (delegated teacher).
 * @param int $class_section_id The ID of the class section.
 * @param string $permission_type 'can_take_attendance' or 'can_manage_students'.
 * @param mysqli $conn The database connection.
 * @return bool True if the teacher has the delegated permission, false otherwise.
 */
function has_delegated_permission($user_id, $class_section_id, $permission_type, $conn) {
    if (!$user_id || !$class_section_id || empty($permission_type) || !$conn) return false;
    $user_id = (int)$user_id;
    $class_section_id = (int)$class_section_id;

    // Ensure $permission_type is a valid column name to prevent SQL injection issues if it were dynamic beyond these two.
    if (!in_array($permission_type, ['can_take_attendance', 'can_manage_students'])) {
        return false;
    }

    $sql = "SELECT id FROM teacher_delegations
            WHERE delegated_to_user_id = $user_id
            AND class_section_id = $class_section_id
            AND $permission_type = 1"; // Check if the specific permission column is true (1)

    $result = mysqli_query($conn, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        return true;
    }
    return false;
}

/**
 * Gets an array of class_section_ids for which a teacher has direct or delegated 'attendance' rights.
 * @param int $user_id The ID of the teacher.
 * @param mysqli $conn The database connection.
 * @return array Array of class_section_ids.
 */
function get_teacher_attendance_accessible_sections($user_id, $conn) {
    if (!$user_id || !$conn) return [];
    $user_id = (int)$user_id;
    $accessible_section_ids = [];

    // Sections where they are class teacher
    $sql_class_teacher = "SELECT id FROM class_sections WHERE class_teacher_user_id = $user_id";
    $res_ct = mysqli_query($conn, $sql_class_teacher);
    if ($res_ct) {
        while ($row = mysqli_fetch_assoc($res_ct)) {
            $accessible_section_ids[] = $row['id'];
        }
    }

    // Sections where they have delegated attendance permission
    $sql_delegated = "SELECT class_section_id FROM teacher_delegations
                      WHERE delegated_to_user_id = $user_id AND can_take_attendance = 1";
    $res_del = mysqli_query($conn, $sql_delegated);
    if ($res_del) {
        while ($row = mysqli_fetch_assoc($res_del)) {
            $accessible_section_ids[] = $row['class_section_id'];
        }
    }
    return array_unique($accessible_section_ids);
}


/**
 * Gets an array of class_section_ids for which a teacher has direct or delegated 'student management' rights.
 * @param int $user_id The ID of the teacher.
 * @param mysqli $conn The database connection.
 * @return array Array of class_section_ids.
 */
function get_teacher_student_manageable_sections($user_id, $conn) {
     if (!$user_id || !$conn) return [];
    $user_id = (int)$user_id;
    $manageable_section_ids = [];

    // Sections where they are class teacher
    $sql_class_teacher = "SELECT id FROM class_sections WHERE class_teacher_user_id = $user_id";
    $res_ct = mysqli_query($conn, $sql_class_teacher);
    if ($res_ct) {
        while ($row = mysqli_fetch_assoc($res_ct)) {
            $manageable_section_ids[] = $row['id'];
        }
    }

    // Sections where they have delegated student management permission
    $sql_delegated = "SELECT class_section_id FROM teacher_delegations
                      WHERE delegated_to_user_id = $user_id AND can_manage_students = 1";
    $res_del = mysqli_query($conn, $sql_delegated);
    if ($res_del) {
        while ($row = mysqli_fetch_assoc($res_del)) {
            $manageable_section_ids[] = $row['class_section_id'];
        }
    }
    return array_unique($manageable_section_ids);
}

?>
