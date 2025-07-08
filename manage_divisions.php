<?php
include_once 'auth_check.php';
require_login(['admin']);

include 'config.php';

$message = '';
$message_type = '';

// Handle Create/Update Division
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['save_division'])) {
        $division_name = mysqli_real_escape_string($conn, trim($_POST['division_name']));
        $division_id = isset($_POST['division_id']) ? (int)$_POST['division_id'] : 0;

        if (empty($division_name)) {
            $message = "Division name cannot be empty.";
            $message_type = 'error';
        } else {
            $check_sql = "SELECT id FROM divisions WHERE division_name = '$division_name'" . ($division_id > 0 ? " AND id != $division_id" : "");
            $check_res = mysqli_query($conn, $check_sql);

            if (mysqli_num_rows($check_res) > 0) {
                $message = "Division name '$division_name' already exists.";
                $message_type = 'error';
            } else {
                if ($division_id > 0) { // Update
                    $sql = "UPDATE divisions SET division_name = '$division_name' WHERE id = $division_id";
                    if (mysqli_query($conn, $sql)) {
                        $message = "Division updated successfully.";
                        $message_type = 'success';
                    } else {
                        $message = "Error updating division: " . mysqli_error($conn);
                        $message_type = 'error';
                    }
                } else { // Create
                    $sql = "INSERT INTO divisions (division_name) VALUES ('$division_name')";
                    if (mysqli_query($conn, $sql)) {
                        $message = "Division '$division_name' created successfully.";
                        $message_type = 'success';
                    } else {
                        $message = "Error creating division: " . mysqli_error($conn);
                        $message_type = 'error';
                    }
                }
            }
        }
    } elseif (isset($_POST['delete_division'])) { // Handle Delete Division
        $division_id = (int)$_POST['division_id'];
        // Check if division is used in class_sections
        $check_usage_sql = "SELECT id FROM class_sections WHERE division_id = $division_id LIMIT 1";
        $usage_res = mysqli_query($conn, $check_usage_sql);
        if (mysqli_num_rows($usage_res) > 0) {
            $message = "Cannot delete division. It is currently assigned to one or more class sections. Please remove assignments first.";
            $message_type = 'error';
        } else {
            $sql = "DELETE FROM divisions WHERE id = $division_id";
            if (mysqli_query($conn, $sql)) {
                $message = "Division deleted successfully.";
                $message_type = 'success';
            } else {
                $message = "Error deleting division: " . mysqli_error($conn);
                $message_type = 'error';
            }
        }
    }
}

// Fetch all divisions
$divisions_result = mysqli_query($conn, "SELECT * FROM divisions ORDER BY division_name");

// For editing, fetch division details
$edit_division = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $edit_sql = "SELECT * FROM divisions WHERE id = $edit_id";
    $edit_res = mysqli_query($conn, $edit_sql);
    if ($edit_res && mysqli_num_rows($edit_res) > 0) {
        $edit_division = mysqli_fetch_assoc($edit_res);
    } else {
        $message = "Division not found for editing.";
        $message_type = 'error';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Divisions</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding:0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 10px; text-decoration: none; font-weight: bold; }
        .top-nav .user-info { float: right; color: #ddd; font-size: 0.9em; margin-right: 20px; line-height: 2.5em;}
        .top-nav a:hover { text-decoration: underline; }
        .container { width: 70%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        .form-section { margin-bottom: 30px; padding: 20px; background-color: #f9f9f9; border-radius: 5px; }
        form label { display: block; margin-top: 10px; font-weight: bold; }
        form input[type="text"] { width: calc(100% - 22px); padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
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
        <h1>Manage Divisions</h1>
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="form-section">
            <h2><?php echo $edit_division ? 'Edit Division' : 'Create New Division'; ?></h2>
            <form action="manage_divisions.php<?php echo $edit_division ? '?edit_id=' . $edit_division['id'] : ''; ?>" method="POST">
                <?php if ($edit_division): ?>
                    <input type="hidden" name="division_id" value="<?php echo $edit_division['id']; ?>">
                <?php endif; ?>
                <label for="division_name">Division Name (e.g., "A", "Section Alpha", "None"):</label>
                <input type="text" name="division_name" id="division_name" value="<?php echo $edit_division ? htmlspecialchars($edit_division['division_name']) : ''; ?>" required>
                <input type="submit" name="save_division" value="<?php echo $edit_division ? 'Update Division' : 'Create Division'; ?>">
                 <?php if ($edit_division): ?>
                    <a href="manage_divisions.php" style="margin-left:10px; text-decoration:none; padding:10px 15px; background-color:#6c757d; color:white; border-radius:4px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <h2>Existing Divisions</h2>
        <?php if ($divisions_result && mysqli_num_rows($divisions_result) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Division Name</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($division = mysqli_fetch_assoc($divisions_result)): ?>
                        <tr>
                            <td><?php echo $division['id']; ?></td>
                            <td><?php echo htmlspecialchars($division['division_name']); ?></td>
                            <td class="action-links">
                                <a href="manage_divisions.php?edit_id=<?php echo $division['id']; ?>" class="button">Edit</a>
                                <form action="manage_divisions.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this division? This cannot be undone if it is not in use.');">
                                    <input type="hidden" name="division_id" value="<?php echo $division['id']; ?>">
                                    <button type="submit" name="delete_division" class="delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No divisions found. You can create some using the form above. Default divisions (like 'None', 'A', 'B', 'C') might have been seeded during setup.</p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php if(isset($conn)) mysqli_close($conn); ?>
