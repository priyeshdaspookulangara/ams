<?php
session_start();
include 'config.php'; // Establishes $conn

$message = '';
$message_type = ''; // 'success' or 'error'
$student_id = null;
$student_name = '';

if (isset($_GET['student_id'])) {
    $student_id = (int)$_GET['student_id'];
    // Fetch student details to display name
    $student_query = "SELECT name FROM students WHERE id = $student_id";
    $student_result = mysqli_query($conn, $student_query);
    if ($student_result && mysqli_num_rows($student_result) > 0) {
        $student = mysqli_fetch_assoc($student_result);
        $student_name = $student['name'];
    } else {
        $message = "Error: Student not found.";
        $message_type = 'error';
        $student_id = null; // Invalidate student_id if not found
    }
} else {
    $message = "Error: No student ID provided.";
    $message_type = 'error';
}

// Handle Add Parent/Guardian form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_parent_guardian']) && $student_id) {
    $parent_name = mysqli_real_escape_string($conn, $_POST['parent_name']);
    $phone_number = mysqli_real_escape_string($conn, $_POST['phone_number']);
    $relationship = mysqli_real_escape_string($conn, $_POST['relationship']);

    if (empty($parent_name) || empty($phone_number) || empty($relationship)) {
        $message = "All parent/guardian fields are required.";
        $message_type = 'error';
    } else {
        // Check if this parent (name and phone) already exists for this student to avoid duplicates
        $check_sql = "SELECT id FROM parent_guardians WHERE student_id = $student_id AND parent_name = '$parent_name' AND phone_number = '$phone_number'";
        $check_result = mysqli_query($conn, $check_sql);

        if ($check_result && mysqli_num_rows($check_result) > 0) {
            $message = "Error: This parent/guardian is already listed for this student.";
            $message_type = 'error';
        } else {
            $insert_sql = "INSERT INTO parent_guardians (student_id, parent_name, phone_number, relationship)
                           VALUES ($student_id, '$parent_name', '$phone_number', '$relationship')";
            if (mysqli_query($conn, $insert_sql)) {
                $message = "Parent/Guardian '$parent_name' added successfully for $student_name.";
                $message_type = 'success';
            } else {
                $message = "Error adding parent/guardian: " . mysqli_error($conn);
                $message_type = 'error';
            }
        }
    }
}

// Fetch existing parents for this student
$parents_list = [];
if ($student_id) {
    $sql_get_parents = "SELECT * FROM parent_guardians WHERE student_id = $student_id ORDER BY parent_name";
    $parents_result = mysqli_query($conn, $sql_get_parents);
    if ($parents_result) {
        while ($row = mysqli_fetch_assoc($parents_result)) {
            $parents_list[] = $row;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Parents/Guardians</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding:0; background-color: #f4f4f4; color: #333; }
        .top-nav { background-color: #333; color: white; padding: 10px 20px; text-align: center; }
        .top-nav a { color: white; margin: 0 15px; text-decoration: none; font-weight: bold; }
        .top-nav a:hover { text-decoration: underline; }
        .container { width: 70%; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
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
            background-color: #007bff;
            color: white;
            cursor: pointer;
            width: auto; /* Auto width for submit */
            padding: 10px 20px;
        }
        form input[type="submit"]:hover { background-color: #0056b3; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #007bff; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        .no-results { text-align: center; padding: 15px; color: #777;}
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
        <a href="students.php" class="back-link">&laquo; Back to Students List</a>
        <h1>Manage Parents/Guardians for <?php echo htmlspecialchars($student_name ? $student_name : 'Student'); ?></h1>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($student_id): // Only show form if student_id is valid ?>
        <div class="form-section">
            <h2>Add New Parent/Guardian</h2>
            <form action="parents.php?student_id=<?php echo $student_id; ?>" method="POST">
                <label for="parent_name">Parent/Guardian Name:</label>
                <input type="text" name="parent_name" id="parent_name" required>

                <label for="phone_number">Phone Number:</label>
                <input type="text" name="phone_number" id="phone_number" required>
                <small>Include country code if applicable, e.g., +11234567890</small>

                <label for="relationship">Relationship to Student:</label>
                <input type="text" name="relationship" id="relationship" placeholder="e.g., Father, Mother, Guardian" required>

                <input type="submit" name="add_parent_guardian" value="Add Parent/Guardian">
            </form>
        </div>

        <h2>Existing Parents/Guardians for <?php echo htmlspecialchars($student_name); ?></h2>
        <?php if (!empty($parents_list)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Phone Number</th>
                        <th>Relationship</th>
                        <!-- Add actions like Edit/Delete later if needed -->
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parents_list as $parent): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($parent['parent_name']); ?></td>
                            <td><?php echo htmlspecialchars($parent['phone_number']); ?></td>
                            <td><?php echo htmlspecialchars($parent['relationship']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="no-results">No parents or guardians listed for this student yet.</p>
        <?php endif; ?>

        <?php endif; // End if($student_id) ?>

    </div>
</body>
</html>
<?php
mysqli_close($conn);
?>
