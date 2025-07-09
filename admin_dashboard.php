<?php
include_once 'auth_check.php';
require_login(['admin']); // Ensure only admin can access

$page_title = "Admin Dashboard";

include 'config.php'; // For DB connection

// Fetch data for dashboard
$total_students = 0;
$total_teachers = 0;

// Get total students
$students_res = mysqli_query($conn, "SELECT COUNT(id) as count FROM students");
if ($students_res) {
    $total_students = mysqli_fetch_assoc($students_res)['count'];
}

// Get total teachers
$teachers_res = mysqli_query($conn, "SELECT COUNT(id) as count FROM users WHERE role = 'teacher'");
if ($teachers_res) {
    $total_teachers = mysqli_fetch_assoc($teachers_res)['count'];
}

// Start output buffering for page content
ob_start();
?>

<div class="container-fluid mt-3">
    <div class="row">
        <div class="col-12">
            <h1 class="mb-4"><?php echo htmlspecialchars($page_title); ?></h1>
            <p>Welcome, <?php echo htmlspecialchars(current_username()); ?>! Here's an overview of the system.</p>
        </div>
    </div>

    <div class="row g-3">
        <!-- Stat Cards -->
        <div class="col-md-6 col-lg-3">
            <div class="card text-white bg-primary mb-3">
                <div class="card-header">Total Students</div>
                <div class="card-body">
                    <h4 class="card-title"><?php echo $total_students; ?></h4>
                    <a href="students.php" class="text-white stretched-link">View Students &raquo;</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card text-white bg-success mb-3">
                <div class="card-header">Total Teachers</div>
                <div class="card-body">
                    <h4 class="card-title"><?php echo $total_teachers; ?></h4>
                    <a href="manage_users.php?role_filter=teacher" class="text-white stretched-link">Manage Teachers &raquo;</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card text-white bg-info mb-3">
                <div class="card-header">System Settings</div>
                <div class="card-body">
                    <h4 class="card-title"><i class="bi bi-gear-fill"></i></h4>
                    <a href="settings.php" class="text-white stretched-link">Configure Settings &raquo;</a>
                </div>
            </div>
        </div>
         <div class="col-md-6 col-lg-3">
            <div class="card text-white bg-warning mb-3">
                <div class="card-header">System Alerts</div>
                <div class="card-body">
                    <p class="card-text">No critical alerts at this time.</p>
                    <!-- In future, this could fetch actual alerts -->
                    <a href="#" class="text-white stretched-link">View Alerts &raquo;</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    Quick Actions
                </div>
                <div class="card-body">
                    <a href="manage_users.php" class="btn btn-lg btn-outline-secondary m-2"><i class="bi bi-people-fill"></i> Manage Users</a>
                    <a href="manage_class_sections.php" class="btn btn-lg btn-outline-secondary m-2"><i class="bi bi-grid-3x3-gap-fill"></i> Manage Class Sections</a>
                    <a href="attendance.php" class="btn btn-lg btn-outline-secondary m-2"><i class="bi bi-calendar-check"></i> Take/View Attendance</a>
                    <a href="reports_student_master.php" class="btn btn-lg btn-outline-secondary m-2"><i class="bi bi-file-earmark-text"></i> View Reports</a>
                    <a href="delegate_tasks.php" class="btn btn-lg btn-outline-secondary m-2"><i class="bi bi-person-check-fill"></i> Delegate Tasks</a>

                </div>
            </div>
        </div>
    </div>

    <!-- Placeholder for more dashboard widgets -->

</div>

<?php
$page_content_html = ob_get_clean(); // Get buffered content
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
