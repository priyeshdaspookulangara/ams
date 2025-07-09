<?php
// session_start(); // session_start() will be called by auth_check.php
include_once 'auth_check.php'; // Includes session_start if not already active
include 'config.php';      // For DB connection, if needed on the homepage (though less likely now)

$page_message = '';
if(isset($_SESSION['error_message'])){
    $page_message = "<div class='message error'>" . htmlspecialchars($_SESSION['error_message']) . "</div>";
    unset($_SESSION['error_message']);
}
if(isset($_GET['logged_out'])){
    $page_message = "<div class='message success'>You have been successfully logged out.</div>";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Attendance Management</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding:0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 10px; text-decoration: none; font-weight: bold; }
        .top-nav a:hover { text-decoration: underline; }
        .top-nav .user-info { float: right; color: #ddd; font-size: 0.9em; margin-right: 20px;}

        .container { width: 80%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); text-align: center;}
        h1 { color: #333; }
        p { font-size: 1.1em; color: #555; }
        .message { padding: 10px; margin: 15px auto; border-radius: 4px; width: fit-content;}
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .quick-links { margin-top: 30px; }
        .quick-links a {
            display: inline-block;
            background-color: #007bff;
            color: white;
            padding: 12px 20px;
            margin: 5px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
        }
        .quick-links a:hover { background-color: #0056b3; }
        .quick-links a.secondary { background-color: #6c757d; }
        .quick-links a.secondary:hover { background-color: #5a6268; }
    </style>
</head>
<body>
    <nav class="top-nav">
        <a href="index.php">Home</a>
        <?php if (is_logged_in()): ?>
            <?php if (in_array(current_user_role(), ['admin', 'teacher'])): ?>
                <a href="students.php">Manage Students</a>
                <a href="attendance.php">Take/View Attendance</a>
            <div style="display:inline-block; position:relative;" class="nav-dropdown-container">
                <a href="#">Reports &#9662;</a>
                <div style="position:absolute; background-color:#333; display:none; min-width:200px; /* Adjusted min-width */ box-shadow:0px 8px 16px 0px rgba(0,0,0,0.2); z-index:1;" class="dropdown-content">
                    <a href="reports_student_master.php" style="display:block; padding:8px 10px; text-align:left;">Student Master List</a>
                    <a href="reports_enrollment_summary.php" style="display:block; padding:8px 10px; text-align:left;">Enrollment Summary</a>
                    <a href="reports_daily_attendance.php" style="display:block; padding:8px 10px; text-align:left;">Daily Attendance</a>
                    <a href="reports_student_individual_attendance.php" style="display:block; padding:8px 10px; text-align:left;">Student Individual Record</a>
                    <a href="reports_absentee_list.php" style="display:block; padding:8px 10px; text-align:left;">Absentee List (Daily)</a>
                    <a href="reports_excessive_absences.php" style="display:block; padding:8px 10px; text-align:left;">Excessive Absences</a>
                    <!-- More reports here -->
                </div>
            </div>
            <?php endif; ?>
            <?php if (current_user_role() == 'admin'): ?>
                <a href="settings.php">Settings</a>
                <a href="manage_users.php">Manage Users</a>
                <a href="manage_grades.php">Manage Grades</a>
                <a href="manage_divisions.php">Manage Divisions</a>
                <a href="manage_class_sections.php">Manage Class Sections</a>
                <a href="delegate_tasks.php">Delegate Tasks</a>
        <?php elseif (current_user_role() == 'teacher'): // Teachers who are not admins also get delegate tasks ?>
                <a href="delegate_tasks.php">Delegate Tasks</a>
            <?php endif; ?>
            <?php if (current_user_role() == 'parent'): ?>
                <a href="parent_dashboard.php">Parent Dashboard</a>
            <?php endif; ?>
            <span class="user-info">Logged in as: <?php echo htmlspecialchars(current_username()); ?> (<?php echo htmlspecialchars(current_user_role()); ?>)</span>
            <a href="logout.php" style="float:right;">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="register.php">Register (Teacher)</a>
        <?php endif; ?>
    </nav>

    <div class="container">
        <h1>School Attendance Management System</h1>
        <?php echo $page_message; ?>

        <?php if (is_logged_in()): ?>
            <p>Welcome back, <?php echo htmlspecialchars(current_username()); ?>!</p>
            <div class="quick-links">
                <?php if (current_user_role() == 'admin'): ?>
                    <a href="settings.php">Go to Settings</a>
                    <a href="attendance.php">Take Attendance</a>
                    <a href="manage_users.php" class="secondary">Manage Users</a>
                <?php elseif (current_user_role() == 'teacher'): ?>
                    <a href="attendance.php">Take Attendance</a>
                    <a href="students.php">Manage Students</a>
                <?php elseif (current_user_role() == 'parent'): ?>
                    <a href="parent_dashboard.php">View Your Child's Attendance</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p>Please login or register to access the system features.</p>
            <div class="quick-links">
                <a href="login.php">Login</a>
                <a href="register.php" class="secondary">Register as Teacher</a>
            </div>
            <p style="margin-top: 20px;"><strong>Note:</strong> The default admin credentials (if `setup_db.php` just ran for the first time) are username: `admin`, password: `admin123`. Please change this immediately after logging in if you are the administrator.</p>
        <?php endif; ?>

        <p style="margin-top:30px; font-size:0.9em; color: #777;">
            <?php if (!is_logged_in() && file_exists('setup_db.php')): ?>
                If this is the first time setting up the system, ensure you have run the <a href="setup_db.php">setup_db.php</a> script.
            <?php endif; ?>
        </p>
    </div>
</body>
</html>
<?php if(isset($conn)) mysqli_close($conn); ?>
