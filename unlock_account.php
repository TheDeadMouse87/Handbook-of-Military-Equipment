<?php
session_start();
include 'connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $login = $_POST['login'];
    $stmt = $mysqli->prepare("
        UPDATE Users 
        SET Ban = 0, failed_login_attempts = 0, Date_of_change = NOW() 
        WHERE (Login = ? OR Email = ?) AND Ban = 1
    ");
    $stmt->bind_param("ss", $login, $login);
    $stmt->execute();
    $stmt->close();
}
?>