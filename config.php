<?php
define('DB_HOST', 'localhost'); // Or your database host
define('DB_USER', 'root');      // Your database username
define('DB_PASS', '');          // Your database password
define('DB_NAME', 'school_attendance'); // Your database name

// Create connection
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Create database if it doesn't exist
$sql_create_db = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
if (!mysqli_query($conn, $sql_create_db)) {
    die("Error creating database: " . mysqli_error($conn));
}

// Select the database
mysqli_select_db($conn, DB_NAME);

?>
