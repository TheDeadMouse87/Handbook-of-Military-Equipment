<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['pending_user_id'])) {
    header("Location: auth.php");
    exit();
}

// Проверяем, откуда пришел пользователь (регистрация или вход)
$is_registration = isset($_SESSION['is_registration']) && $_SESSION['is_registration'] === true;

// Обработка повторной отправки кода
if (isset($_GET['resend'])) {
    require_once 'mailer.php';
    
    try {
        // Генерация нового кода
        $verification_code = sprintf("%06d", mt_rand(1, 999999));
        $code_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // Сохранение кода в базе
        $update_stmt = $mysqli->prepare("UPDATE Users SET verification_code = ?, code_expires = ? WHERE ID = ?");
        $update_stmt->bind_param("ssi", $verification_code, $code_expires, $_SESSION['pending_user_id']);
        $update_stmt->execute();
        $update_stmt->close();

        // Отправка кода на email
        if (sendVerificationUniversal($_SESSION['pending_user_email'], $verification_code)) {
            header("Location: verify_code.php?success=code_resent");
        } else {
            header("Location: verify_code.php?error=email_send_failed");
        }
        exit();
        
    } catch (Exception $e) {
        error_log("Ошибка повторной отправки кода: " . $e->getMessage());
        header("Location: verify_code.php?error=db_error");
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered_code = trim($_POST['verification_code']);
    
    if (empty($entered_code)) {
        header("Location: verify_code.php?error=empty_code");
        exit();
    }

    try {
        // Проверка кода
        $stmt = $mysqli->prepare("SELECT ID, verification_code, code_expires FROM Users WHERE ID = ?");
        if (!$stmt) {
            throw new Exception("Ошибка подготовки запроса: " . $mysqli->error);
        }
        
        $stmt->bind_param("i", $_SESSION['pending_user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        // Проверка срока действия кода
        if (strtotime($user['code_expires']) < time()) {
            header("Location: verify_code.php?error=code_expired");
            exit();
        }

        // Проверка кода
        if ($user['verification_code'] !== $entered_code) {
            header("Location: verify_code.php?error=invalid_code");
            exit();
        }

        // Код верный - очищаем код и завершаем процесс
        $update_stmt = $mysqli->prepare("UPDATE Users SET verification_code = NULL, code_expires = NULL WHERE ID = ?");
        $update_stmt->bind_param("i", $_SESSION['pending_user_id']);
        $update_stmt->execute();
        $update_stmt->close();

        // Устанавливаем сессию пользователя
        $_SESSION['user_id'] = $_SESSION['pending_user_id'];
        $_SESSION['user_login'] = $_SESSION['pending_user_login'];
        $_SESSION['user_email'] = $_SESSION['pending_user_email'];
        $_SESSION['user_reg_date'] = $_SESSION['pending_user_reg_date'];
        $_SESSION['user_role'] = $_SESSION['pending_user_role'];
        $_SESSION['user_name'] = $_SESSION['pending_user_name'];

        // Очищаем временные данные
        unset($_SESSION['pending_user_id']);
        unset($_SESSION['pending_user_login']);
        unset($_SESSION['pending_user_email']);
        unset($_SESSION['pending_user_reg_date']);
        unset($_SESSION['pending_user_role']);
        unset($_SESSION['pending_user_name']);
        unset($_SESSION['is_registration']);

        // Редирект в зависимости от типа операции
        if ($is_registration) {
            header("Location: auth.php?success=registered");
        } else {
            header("Location: account.php");
        }
        exit();

    } catch (Exception $e) {
        error_log("Ошибка проверки кода: " . $e->getMessage());
        header("Location: verify_code.php?error=db_error");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подтверждение <?php echo $is_registration ? 'регистрации' : 'входа'; ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="top-bar">
        <nav class="nav-menu">
            <div class="nav-item">
                <a href="main.php" class="nav-link">Главная</a>
            </div>
        </nav>
        <h1 class="page-title-top"><?php echo $is_registration ? 'Подтверждение регистрации' : 'Подтверждение входа'; ?></h1>
        <div style="width: 100px;"></div>
    </div>

    <div class="background-layer"></div>

    <div class="notification-container">
        <?php if (isset($_GET['error'])): ?>
            <div class="notification notification-error">
                <span class="notification-close" onclick="this.parentElement.remove()">×</span>
                <?php
                $errors = [
                    'empty_code' => 'Введите код подтверждения',
                    'invalid_code' => 'Неверный код подтверждения',
                    'code_expired' => 'Срок действия кода истек',
                    'db_error' => 'Ошибка базы данных',
                    'email_send_failed' => 'Ошибка отправки письма'
                ];
                echo $errors[$_GET['error']] ?? 'Произошла ошибка';
                ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="notification notification-success">
                <span class="notification-close" onclick="this.parentElement.remove()">×</span>
                <?php
                $success = [
                    'code_resent' => 'Новый код отправлен на вашу почту!'
                ];
                echo $success[$_GET['success']] ?? 'Операция выполнена успешно';
                ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="centered-container">
        <div class="centered-military-container">
            <div class="military-header">
                <h1><?php echo $is_registration ? 'ПОДТВЕРЖДЕНИЕ РЕГИСТРАЦИИ' : 'ПОДТВЕРЖДЕНИЕ ВХОДА'; ?></h1>
            </div>

            <div style="text-align: center; color: #8b966c; margin-bottom: 2rem;">
                <p>✅ Код подтверждения <?php echo $is_registration ? 'для регистрации' : 'для входа'; ?> отправлен на вашу почту</p>
                <p><strong><?php echo $_SESSION['pending_user_email']; ?></strong></p>
                <p style="font-size: 0.9rem; color: #6b705a; margin-top: 0.5rem;">
                    📧 Проверьте папку "Входящие" или "Спам"<br>
                    ⏰ Код действителен в течение 10 минут
                </p>
            </div>

            <form class="military-form" action="verify_code.php" method="POST">
                <div class="form-group">
                    <label for="verification_code">Код подтверждения:</label>
                    <input type="text" id="verification_code" name="verification_code" required 
                           placeholder="Введите 6-значный код из письма" maxlength="6" pattern="[0-9]{6}"
                           style="text-align: center; letter-spacing: 5px; font-size: 1.4rem;">
                </div>
                <button type="submit" class="military-btn" id="submit-btn"><?php echo $is_registration ? 'Завершить регистрацию' : 'Подтвердить вход'; ?></button>
            </form>

            <div class="form-switch">
                <span>Не получили код?</span>
                <a href="verify_code.php?resend=true">Отправить повторно</a>
            </div>

            <div style="text-align: center; margin-top: 1rem;">
                <a href="auth.php" style="color: #8b966c; text-decoration: none;">← Вернуться к <?php echo $is_registration ? 'регистрации' : 'авторизации'; ?></a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const codeInput = document.getElementById('verification_code');
            const submitBtn = document.getElementById('submit-btn');
            
            // Автофокус на поле ввода кода
            codeInput.focus();

            // Ограничение ввода только цифр
            codeInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '');
                
                // Включаем кнопку только когда введено 6 цифр
                if (this.value.length === 6) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.style.cursor = 'pointer';
                } else {
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.6';
                    submitBtn.style.cursor = 'not-allowed';
                }
            });

            // Предотвращаем отправку формы при нажатии Enter в поле ввода
            codeInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (this.value.length === 6) {
                        submitBtn.click();
                    }
                }
            });

            // Изначально делаем кнопку неактивной
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
            submitBtn.style.cursor = 'not-allowed';

            // Анимация для уведомлений
            document.querySelectorAll('.notification').forEach((n, i) => {
                setTimeout(() => n.classList.add('show'), i * 200);
                setTimeout(() => {
                    n.classList.remove('show');
                    n.classList.add('hide');
                    setTimeout(() => {
                        if (n.parentNode) {
                            n.remove();
                        }
                    }, 400);
                }, 5000);
            });

            document.querySelectorAll('.notification-close').forEach(b => {
                b.addEventListener('click', function() {
                    if (this.parentElement) {
                        this.parentElement.remove();
                    }
                });
            });
        });
    </script>
</body>
</html>