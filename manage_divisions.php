<?php
include_once 'auth_check.php';
require_login(['admin']);

$page_title = "Manage Divisions";

include 'config.php';

$message = '';
$message_type = '';

// Handle Create/Update Division
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['save_division'])) {
        $division_name = mysqli_real_escape_string($conn, trim($_POST['division_name']));
        $division_id = isset($_POST['division_id']) ? (int)$_POST['division_id'] : 0;

        if (empty($division_name)) {
            $message = "Division name cannot be empty."; $message_type = 'error';
        } else {
            $check_sql = "SELECT id FROM divisions WHERE division_name = '$division_name'" . ($division_id > 0 ? " AND id != $division_id" : "");
            $check_res = mysqli_query($conn, $check_sql);

            if (mysqli_num_rows($check_res) > 0) {
                $message = "Division name '$division_name' already exists."; $message_type = 'error';
            } else {
                if ($division_id > 0) {
                    $sql = "UPDATE divisions SET division_name = '$division_name' WHERE id = $division_id";
                    if (mysqli_query($conn, $sql)) { $message = "Division updated successfully."; $message_type = 'success'; }
                    else { $message = "Error updating division: " . mysqli_error($conn); $message_type = 'error'; }
                } else {
                    $sql = "INSERT INTO divisions (division_name) VALUES ('$division_name')";
                    if (mysqli_query($conn, $sql)) { $message = "Division '$division_name' created successfully."; $message_type = 'success'; }
                    else { $message = "Error creating division: " . mysqli_error($conn); $message_type = 'error'; }
                }
            }
        }
    } elseif (isset($_POST['delete_division'])) {
        $division_id = (int)$_POST['division_id'];
        $check_usage_sql = "SELECT id FROM class_sections WHERE division_id = $division_id LIMIT 1";
        $usage_res = mysqli_query($conn, $check_usage_sql);
        if (mysqli_num_rows($usage_res) > 0) {
            $message = "Cannot delete division: It is used in class sections. Remove assignments first."; $message_type = 'error';
        } else {
            $sql = "DELETE FROM divisions WHERE id = $division_id";
            if (mysqli_query($conn, $sql)) { $message = "Division deleted successfully."; $message_type = 'success'; }
            else { $message = "Error deleting division: " . mysqli_error($conn); $message_type = 'error'; }
        }
    }
}

$divisions_result = mysqli_query($conn, "SELECT * FROM divisions ORDER BY division_name");
$edit_division = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $edit_sql = "SELECT * FROM divisions WHERE id = $edit_id";
    $edit_res = mysqli_query($conn, $edit_sql);
    if ($edit_res && mysqli_num_rows($edit_res) > 0) $edit_division = mysqli_fetch_assoc($edit_res);
    else { $message = "Division not found for editing."; $message_type = 'error';}
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
        <h2 class="h4"><?php echo $edit_division ? 'Edit Division' : 'Create New Division'; ?></h2>
        <form action="manage_divisions.php<?php echo $edit_division ? '?edit_id=' . $edit_division['id'] : ''; ?>" method="POST">
            <?php if ($edit_division): ?>
                <input type="hidden" name="division_id" value="<?php echo $edit_division['id']; ?>">
            <?php endif; ?>
            <div class="mb-3">
                <label for="division_name" class="form-label">Division Name (e.g., "A", "Section Alpha", "None"):</label>
                <input type="text" name="division_name" id="division_name" class="form-control" value="<?php echo $edit_division ? htmlspecialchars($edit_division['division_name']) : ''; ?>" required>
            </div>
            <button type="submit" name="save_division" class="btn btn-primary"><?php echo $edit_division ? 'Update Division' : 'Create Division'; ?></button>
            <?php if ($edit_division): ?>
                <a href="manage_divisions.php" class="btn btn-secondary ms-2">Cancel Edit</a>
            <?php endif; ?>
        </form>
    </div>

    <h2 class="mt-4">Existing Divisions</h2>
    <?php if ($divisions_result && mysqli_num_rows($divisions_result) > 0): ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm">
                <thead class="table-light">
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
                            <td>
                                <a href="manage_divisions.php?edit_id=<?php echo $division['id']; ?>" class="btn btn-sm btn-outline-primary py-0">Edit</a>
                                <form action="manage_divisions.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this division?');">
                                    <input type="hidden" name="division_id" value="<?php echo $division['id']; ?>">
                                    <button type="submit" name="delete_division" class="btn btn-sm btn-outline-danger py-0">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="alert alert-info">No divisions found. Seed data might have added default divisions.</p>
    <?php endif; ?>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
