<?php
session_start(); // Useful for potential future login features or messages
include 'config.php'; // For DB connection, if needed on the homepage

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Attendance Management</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; color: #333; }
        .container { background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        nav ul { list-style-type: none; padding: 0; }
        nav ul li { display: inline; margin-right: 15px; }
        nav ul li a { text-decoration: none; color: #007bff; font-weight: bold; }
        nav ul li a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>School Attendance Management System</h1>
        <nav>
            <ul>
                <li><a href="index.php">Home</a></li>
                <li><a href="students.php">Manage Students</a></li>
                <li><a href="attendance.php">Take/View Attendance</a></li>
                <li><a href="settings.php">Settings</a></li>
            </ul>
        </nav>
        <p>Welcome to the School Attendance Management System. Use the navigation links above to manage different aspects of the system.</p>
        <p><strong>Important:</strong> Before using the system for the first time, please ensure you have run the <a href="setup_db.php">setup_db.php</a> script to create the necessary database tables.</p>
    </div>
</body>
</html>
<?php
mysqli_close($conn);
?>
