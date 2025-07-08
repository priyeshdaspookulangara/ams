<?php
session_start();
include 'config.php'; // Establishes $conn

$success_message = '';
$error_message = '';

// If already logged in, redirect
if (isset($_SESSION['user_id'])) {
    header("Location: index.php"); // Or role-based dashboard
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $username = mysqli_real_escape_string($conn, trim($_POST['username']));
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    // For now, only allow 'teacher' self-registration.
    // Admin is created by setup_db.php. Parents will be created by Admin.
    $role = 'teacher';

    // Basic Validations
    if (empty($username) || empty($password) || empty($confirm_password)) {
        $error_message = "All fields are required.";
    } elseif (strlen($password) < 6) {
        $error_message = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } else {
        // Check if username already exists
        $sql_check_username = "SELECT id FROM users WHERE username = '$username'";
        $result_check = mysqli_query($conn, $sql_check_username);
        if (mysqli_num_rows($result_check) > 0) {
            $error_message = "Username already taken. Please choose another.";
        } else {
            // Hash the password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $sql_insert_user = "INSERT INTO users (username, password, role) VALUES ('$username', '$hashed_password', '$role')";
            if (mysqli_query($conn, $sql_insert_user)) {
                $success_message = "Registration successful as a Teacher! You can now <a href='login.php'>login</a>.";
                // Optionally, log the user in directly after registration
                // $_SESSION['user_id'] = mysqli_insert_id($conn);
                // $_SESSION['username'] = $username;
                // $_SESSION['role'] = $role;
                // header("Location: attendance.php"); // Redirect to teacher dashboard
                // exit;
            } else {
                $error_message = "Registration failed. Please try again. Error: " . mysqli_error($conn);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .register-container { background-color: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 0 15px rgba(0,0,0,0.2); width: 350px; margin-top: 20px; margin-bottom:20px;}
        h1 { text-align: center; color: #333; margin-bottom: 20px; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; text-align: center; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input[type="text"], input[type="password"] {
            width: calc(100% - 20px); padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px;
        }
        input[type="submit"] {
            width: 100%; background-color: #28a745; color: white; padding: 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px;
        }
        input[type="submit"]:hover { background-color: #218838; }
        .form-footer { text-align: center; margin-top: 15px; font-size: 0.9em; }
        .form-footer a { color: #007bff; text-decoration: none; }
        .form-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="register-container">
        <h1>Register (Teacher Account)</h1>
        <?php if ($success_message): ?>
            <div class="message success"><?php echo $success_message; /* Contains HTML link */ ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="message error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if (!$success_message): // Hide form on success ?>
        <form action="register.php" method="POST">
            <div>
                <label for="username">Username:</label>
                <input type="text" name="username" id="username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required>
            </div>
            <div>
                <label for="password">Password (min 6 chars):</label>
                <input type="password" name="password" id="password" required>
            </div>
            <div>
                <label for="confirm_password">Confirm Password:</label>
                <input type="password" name="confirm_password" id="confirm_password" required>
            </div>
            <div>
                <input type="submit" name="register" value="Register as Teacher">
            </div>
        </form>
        <?php endif; ?>
        <div class="form-footer">
            <p>Already have an account? <a href="login.php">Login here</a></p>
            <p><a href="index.php">Back to Home</a></p>
        </div>
    </div>
</body>
</html>
<?php mysqli_close($conn); ?>
