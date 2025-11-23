<?php
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] == 2;
}

function requireAdmin() {
    if (!isAdmin()) {
        header("Location: main.php");
        exit();
    }
}

function getUserRole() {
    return isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 1;
}
?>