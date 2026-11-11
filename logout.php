<?php
// logout.php - Terminate user session and return to portal gate
session_start();

// 1. Clear all session variables in memory
$_SESSION = array();

// 2. Invalidate the session cookie in the user's browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// 3. Destroy the session on the server
session_destroy();

// 4. Redirect back to login gate
header("Location: index.php");
exit;
?>