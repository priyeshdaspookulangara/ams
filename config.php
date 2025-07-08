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


// --- Notification Gateway Configuration ---
// Replace with your actual Twilio credentials and numbers.
// These are placeholders.
// For production, consider using environment variables or a more secure config management.

// Twilio SMS Configuration
define('TWILIO_ACCOUNT_SID', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'); // Replace with your Twilio Account SID
define('TWILIO_AUTH_TOKEN', 'your_auth_token_xxxxxxxxxxxxxxx');   // Replace with your Twilio Auth Token
define('TWILIO_PHONE_NUMBER', '+1234567890'); // Replace with your Twilio phone number (for sending SMS)

// Twilio WhatsApp Configuration
// If using a Twilio number for WhatsApp, TWILIO_PHONE_NUMBER might be the same.
// If using the Twilio Sandbox for WhatsApp, the sender is often a specific sandbox number.
define('TWILIO_WHATSAPP_SENDER', 'whatsapp:+14155238886'); // Example: Twilio Sandbox number. Replace with your configured WhatsApp sender.

// You might add other provider configs here if you choose others.
// define('OTHER_SMS_API_KEY', 'your_other_provider_api_key');


// --- End Notification Gateway Configuration ---

?>
