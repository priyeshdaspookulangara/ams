<?php
if (session_status() == PHP_SESSION_NONE) { // Start session if not already started
    session_start();
}

function require_login($allowed_roles = []) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php?redirect_url=" . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    if (!empty($allowed_roles)) {
        if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
            // User is logged in but does not have the required role
            // Redirect to a generic page or show an error
            // For simplicity, redirect to index.php with an error message
            $_SESSION['error_message'] = "You do not have permission to access this page.";
            header("Location: index.php");
            exit;
        }
    }
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function current_user_role() {
    return isset($_SESSION['role']) ? $_SESSION['role'] : null;
}

function current_user_id() {
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
}

function current_username() {
    return isset($_SESSION['username']) ? $_SESSION['username'] : null;
}

function current_entity_id(){
    return isset($_SESSION['entity_id']) ? $_SESSION['entity_id'] : null;
}

?>
