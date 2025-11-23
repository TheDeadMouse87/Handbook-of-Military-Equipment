<?php
session_start();
include 'connect.php';
require_once 'mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: auth.php");
    exit();
}

$login = trim($_POST['login']);
$password = trim($_POST['password']);
$captcha_answer = $_POST['captcha_answer'] ?? '';

// === Проверка капчи ===
if (empty($captcha_answer)) {
    header("Location: auth.php?error=captcha_required&login=" . urlencode($login));
    exit();
}

$selected_order = array_filter(explode(',', $captcha_answer));
$correct_order = ['1.png', '2.png', '3.png', '4.png'];

if (count($selected_order) !== 4 || $selected_order !== $correct_order) {
    header("Location: auth.php?error=captcha_invalid&login=" . urlencode($login));
    exit();
}

if (empty($login) || empty($password)) {
    header("Location: auth.php?error=empty_fields");
    exit();
}

try {
    // === Поиск пользователя ===
    $stmt = $mysqli->prepare("
        SELECT ID, Login, Password, Ban, failed_login_attempts, Date_of_change,
               Date_of_reg, Role_ID, Name, Email
        FROM Users 
        WHERE Login = ? OR Email = ?
    ");
    if (!$stmt) throw new Exception("Prepare failed: " . $mysqli->error);
    $stmt->bind_param("ss", $login, $login);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        $stmt->close();
        header("Location: auth.php?error=invalid_credentials&login=" . urlencode($login));
        exit();
    }

    $user = $result->fetch_assoc();

    // === Проверка блокировки ===
    if ($user['Ban'] == 1 && $user['Date_of_change']) {
        $lock_time = strtotime($user['Date_of_change']);
        $elapsed = time() - $lock_time;

        if ($elapsed >= 10800) { // 3 часа = 10800 сек
            // Авто-разблокировка
            $unban_stmt = $mysqli->prepare("
                UPDATE Users 
                SET Ban = 0, failed_login_attempts = 0, Date_of_change = NOW() 
                WHERE ID = ?
            ");
            $unban_stmt->bind_param("i", $user['ID']);
            $unban_stmt->execute();
            $unban_stmt->close();

            $user['Ban'] = 0;
            $user['failed_login_attempts'] = 0;
        } else {
            $remaining_seconds = 10800 - $elapsed;
            $remaining_minutes = ceil($remaining_seconds / 60);
            $stmt->close();
            header("Location: auth.php?error=account_locked&minutes=$remaining_minutes&login=" . urlencode($login));
            exit();
        }
    }

    // === Проверка пароля ===
    if (password_verify($password, $user['Password'])) {
        // === УСПЕШНЫЙ ВХОД ===
        $reset_stmt = $mysqli->prepare("
            UPDATE Users 
            SET failed_login_attempts = 0, Date_of_change = NOW() 
            WHERE ID = ?
        ");
        $reset_stmt->bind_param("i", $user['ID']);
        $reset_stmt->execute();
        $reset_stmt->close();

        // === 2FA ===
        $verification_code = sprintf("%06d", mt_rand(1, 999999));
        $code_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        $code_stmt = $mysqli->prepare("
            UPDATE Users 
            SET verification_code = ?, code_expires = ? 
            WHERE ID = ?
        ");
        $code_stmt->bind_param("ssi", $verification_code, $code_expires, $user['ID']);
        $code_stmt->execute();
        $code_stmt->close();

        sendVerificationUniversal($user['Email'], $verification_code);

        // Сохраняем в сессию
        $_SESSION['pending_user_id'] = $user['ID'];
        $_SESSION['pending_user_login'] = $user['Login'];
        $_SESSION['pending_user_email'] = $user['Email'];
        $_SESSION['pending_user_reg_date'] = $user['Date_of_reg'];
        $_SESSION['pending_user_role'] = $user['Role_ID'];
        $_SESSION['pending_user_name'] = $user['Name'];

        $stmt->close();
        header("Location: verify_code.php");
        exit();

    } else {
        // === НЕВЕРНЫЙ ПАРОЛЬ ===
        $attempts = ($user['failed_login_attempts'] ?? 0) + 1;

        if ($attempts >= 3) {
            // Блокируем на 3 часа
            $block_stmt = $mysqli->prepare("
                UPDATE Users 
                SET failed_login_attempts = ?, Ban = 1, Date_of_change = NOW() 
                WHERE ID = ?
            ");
            $block_stmt->bind_param("ii", $attempts, $user['ID']);
            $block_stmt->execute();
            $block_stmt->close();

            $stmt->close();
            header("Location: auth.php?error=account_locked&minutes=180&login=" . urlencode($login));
            exit();
        } else {
            // Увеличиваем счётчик
            $update_stmt = $mysqli->prepare("
                UPDATE Users 
                SET failed_login_attempts = ?, Date_of_change = NOW() 
                WHERE ID = ?
            ");
            $update_stmt->bind_param("ii", $attempts, $user['ID']);
            $update_stmt->execute();
            $update_stmt->close();

            $stmt->close();
            header("Location: auth.php?error=invalid_credentials&attempts=$attempts&login=" . urlencode($login));
            exit();
        }
    }

} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    header("Location: auth.php?error=db_error");
    exit();
}
?>