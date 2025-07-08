<?php
session_start();
include 'config.php'; // Establishes $conn

$message = '';
$message_type = ''; // 'success' or 'error'

// Handle Add Student form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_student'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $roll_number = mysqli_real_escape_string($conn, $_POST['roll_number']);
    $grade = mysqli_real_escape_string($conn, $_POST['grade']);

    if (empty($name) || empty($roll_number) || empty($grade)) {
        $message = "All student fields are required.";
        $message_type = 'error';
    } else {
        // Check if roll number already exists
        $check_sql = "SELECT id FROM students WHERE roll_number = '$roll_number'";
        $check_result = mysqli_query($conn, $check_sql);
        if (mysqli_num_rows($check_result) > 0) {
            $message = "Error: Roll number '$roll_number' already exists.";
            $message_type = 'error';
        } else {
            $insert_sql = "INSERT INTO students (name, roll_number, grade) VALUES ('$name', '$roll_number', '$grade')";
            if (mysqli_query($conn, $insert_sql)) {
                $message = "Student '$name' added successfully.";
                $message_type = 'success';
            } else {
                $message = "Error adding student: " . mysqli_error($conn);
                $message_type = 'error';
            }
        }
    }
}

// Fetch all students to display
$students_result = mysqli_query($conn, "SELECT * FROM students ORDER BY grade, name");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding:0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 15px; text-decoration: none; font-weight: bold; }
        .top-nav a:hover { text-decoration: underline; }
        .container { width: 90%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .action-links a { margin-right: 10px; text-decoration: none; color: #007bff; }
        .action-links a:hover { text-decoration: underline; }
        .form-section { margin-bottom: 30px; padding: 20px; background-color: #f9f9f9; border-radius: 5px; }
        .form-section h2 { margin-top: 0; }
        form label { display: block; margin-top: 10px; font-weight: bold; }
        form input[type="text"], form input[type="submit"], form select {
            width: calc(100% - 22px); /* Full width minus padding and border */
            padding: 10px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        form input[type="submit"] {
            background-color: #28a745;
            color: white;
            cursor: pointer;
            width: auto; /* Auto width for submit */
            padding: 10px 20px;
        }
        form input[type="submit"]:hover { background-color: #218838; }
        .no-students { text-align: center; padding: 20px; color: #777; }
        .parents-list { list-style-type: none; padding-left: 0; }
        .parents-list li { font-size: 0.9em; color: #555; }
    </style>
</head>
<body>
    <nav class="top-nav">
        <a href="index.php">Home</a>
        <a href="students.php">Manage Students</a>
        <a href="attendance.php">Take/View Attendance</a>
        <a href="settings.php">Settings</a>
    </nav>

    <div class="container">
        <h1>Manage Students</h1>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="form-section">
            <h2>Add New Student</h2>
            <form action="students.php" method="POST">
                <label for="name">Student Name:</label>
                <input type="text" name="name" id="name" required>

                <label for="roll_number">Roll Number:</label>
                <input type="text" name="roll_number" id="roll_number" required>

                <label for="grade">Grade/Class:</label>
                <input type="text" name="grade" id="grade" required>

                <input type="submit" name="add_student" value="Add Student">
            </form>
        </div>

        <h2>Existing Students</h2>
        <?php if ($students_result && mysqli_num_rows($students_result) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Roll Number</th>
                        <th>Grade/Class</th>
                        <th>Parents/Guardians</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($student = mysqli_fetch_assoc($students_result)): ?>
                        <tr>
                            <td><?php echo $student['id']; ?></td>
                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                            <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                            <td><?php echo htmlspecialchars($student['grade']); ?></td>
                            <td>
                                <?php
                                $parent_sql = "SELECT * FROM parent_guardians WHERE student_id = " . $student['id'];
                                $parents_result = mysqli_query($conn, $parent_sql);
                                if ($parents_result && mysqli_num_rows($parents_result) > 0) {
                                    echo '<ul class="parents-list">';
                                    while ($parent = mysqli_fetch_assoc($parents_result)) {
                                        echo '<li>' . htmlspecialchars($parent['parent_name']) . ' (' . htmlspecialchars($parent['relationship']) . ') - ' . htmlspecialchars($parent['phone_number']) . '</li>';
                                    }
                                    echo '</ul>';
                                } else {
                                    echo 'No parents/guardians listed.';
                                }
                                ?>
                            </td>
                            <td class="action-links">
                                <a href="parents.php?student_id=<?php echo $student['id']; ?>">Add Parent/Guardian</a>
                                <!-- Add Edit/Delete Student links here later if needed -->
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="no-students">No students found. Add some students using the form above.</p>
        <?php endif; ?>

    </div>
</body>
</html>
<?php
mysqli_close($conn);
?>
