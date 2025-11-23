<?php
session_start();

// ОТЛАДКА - удалите этот блок после исправления
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST данные: " . print_r($_POST, true));
    error_log("GET данные: " . print_r($_GET, true));
    error_log("Капча answer: " . ($_POST['captcha_answer'] ?? 'NOT SET'));
}
// Конец отладочного блока

include 'connect.php';
include 'Logger.php';

$logger = new Logger($mysqli);

// Обработка выхода из аккаунта
if (isset($_GET['logout'])) {
    if (isset($_SESSION['user_id'])) {
        $logger->logLogout($_SESSION['user_id']);
    }
    session_destroy();
    header("Location: auth.php");
    exit();
}

if (isset($_SESSION['user_id'])) {
    header("Location: account.php");
    exit();
}

$captcha_images = ['1.png', '2.png', '3.png', '4.png'];
$_SESSION['captcha_order'] = $captcha_images;

$show_lockout   = false;
$lock_remaining = 0;
$lock_login     = $_GET['login'] ?? '';

if (isset($_GET['error']) && $_GET['error'] === 'account_locked' && $lock_login) {
    $stmt = $mysqli->prepare("SELECT Ban, Date_of_change FROM Users WHERE Login = ? OR Email = ?");
    $stmt->bind_param("ss", $lock_login, $lock_login);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 1) {
        $u = $res->fetch_assoc();
        if ($u['Ban'] == 1 && $u['Date_of_change']) {
            $elapsed        = time() - strtotime($u['Date_of_change']);
            $lock_remaining = max(0, 10800 - $elapsed);
            if ($lock_remaining > 0) $show_lockout = true;
        }
    }
    $stmt->close();
}

// Обработка POST запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Определяем тип формы (регистрация или авторизация)
    $is_registration = isset($_POST['register']);
    
    // Общие данные
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $captcha_answer = $_POST['captcha_answer'] ?? '';
    
    // Отладочная информация
    error_log("is_registration: " . ($is_registration ? 'true' : 'false'));
    error_log("login: " . $login);
    error_log("captcha_answer: " . $captcha_answer);
    
    if ($is_registration) {
        // ДАННЫЕ РЕГИСТРАЦИИ
        $email = trim($_POST['email'] ?? '');
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Проверка обязательных полей для регистрации
        if (empty($login) || empty($email) || empty($password) || empty($confirm_password) || empty($captcha_answer)) {
            error_log("Empty fields detected in registration");
            header("Location: auth.php?register=true&error=empty_fields&login=" . urlencode($login) . "&email=" . urlencode($email));
            exit();
        }
    } else {
        // ДАННЫЕ АВТОРИЗАЦИИ
        // Проверка обязательных полей для авторизации
        if (empty($login) || empty($password) || empty($captcha_answer)) {
            error_log("Empty fields detected in login");
            header("Location: auth.php?error=empty_fields&login=" . urlencode($login));
            exit();
        }
    }
    
    // ПРОВЕРКА КАПЧИ (общая для обеих форм)
    $captcha_parts = explode(',', $captcha_answer);
    $correct_order = ['1.png', '2.png', '3.png', '4.png'];
    $is_captcha_valid = true;
    
    error_log("Captcha parts: " . print_r($captcha_parts, true));
    error_log("Correct order: " . print_r($correct_order, true));
    
    for ($i = 0; $i < 4; $i++) {
        if (!isset($captcha_parts[$i]) || $captcha_parts[$i] !== $correct_order[$i]) {
            $is_captcha_valid = false;
            error_log("Captcha invalid at position $i: expected {$correct_order[$i]}, got {$captcha_parts[$i]}");
            break;
        }
    }
    
    if (!$is_captcha_valid) {
        error_log("Captcha validation failed");
        if ($is_registration) {
            header("Location: auth.php?register=true&error=captcha_invalid&login=" . urlencode($login) . "&email=" . urlencode($email));
        } else {
            header("Location: auth.php?error=captcha_invalid&login=" . urlencode($login));
        }
        exit();
    }
    
    error_log("Captcha validation passed");
    
    if ($is_registration) {
        // ОБРАБОТКА РЕГИСТРАЦИИ
        // Проверка email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header("Location: auth.php?register=true&error=invalid_email&login=" . urlencode($login) . "&email=" . urlencode($email));
            exit();
        }
        
        // Проверка пароля
        if (strlen($password) < 6) {
            header("Location: auth.php?register=true&error=short_password&login=" . urlencode($login) . "&email=" . urlencode($email));
            exit();
        }
        
        if ($password !== $confirm_password) {
            header("Location: auth.php?register=true&error=password_mismatch&login=" . urlencode($login) . "&email=" . urlencode($email));
            exit();
        }
        
        // Проверка уникальности логина и email
        $check_stmt = $mysqli->prepare("SELECT ID, Login, Email FROM Users WHERE Login = ? OR Email = ?");
        $check_stmt->bind_param("ss", $login, $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $existing_users = [];
            while ($row = $check_result->fetch_assoc()) {
                $existing_users[] = $row;
            }
            
            // Проверяем что именно совпало
            $login_exists = false;
            $email_exists = false;
            foreach ($existing_users as $user) {
                if ($user['Login'] === $login) $login_exists = true;
                if ($user['Email'] === $email) $email_exists = true;
            }
            
            if ($login_exists) {
                header("Location: auth.php?register=true&error=user_exists&login=" . urlencode($login) . "&email=" . urlencode($email));
            } else {
                header("Location: auth.php?register=true&error=email_exists&login=" . urlencode($login) . "&email=" . urlencode($email));
            }
            exit();
        }
        
        // Хеширование пароля
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $role_id = 1; // Обычный пользователь
        $ban = 0;
        $reg_date = date('Y-m-d H:i:s');
        
        // Выбираем случайный стандартный аватар
        $standard_avatars = ['1.jpg', '2.jpg', '3.jpg', '4.jpg', '5.jpg', '6.jpg', '7.jpg'];
        $random_avatar = $standard_avatars[array_rand($standard_avatars)];
        $avatar_path = 'standart_avatar/' . $random_avatar;

        // Генерация кода подтверждения для регистрации
        $verification_code = sprintf("%06d", mt_rand(1, 999999));
        $code_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // Создание пользователя
        $insert_stmt = $mysqli->prepare("
            INSERT INTO Users (Login, Email, Password, Role_ID, Ban, Date_of_reg, avatar_path, Name, verification_code, code_expires) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert_stmt->bind_param("sssiisssss", $login, $email, $hashed_password, $role_id, $ban, $reg_date, $avatar_path, $login, $verification_code, $code_expires);
        
        if ($insert_stmt->execute()) {
            $new_user_id = $insert_stmt->insert_id;
            $logger->logCreate('Users', $new_user_id, "Зарегистрирован новый пользователь: $login");
            
            // Отправляем код на почту
            require_once 'mailer.php';
            if (sendVerificationUniversal($email, $verification_code)) {
                // Сохраняем данные пользователя в сессии как "ожидающие верификации"
                $_SESSION['pending_user_id'] = $new_user_id;
                $_SESSION['pending_user_login'] = $login;
                $_SESSION['pending_user_email'] = $email;
                $_SESSION['pending_user_role'] = $role_id;
                $_SESSION['pending_user_reg_date'] = $reg_date;
                $_SESSION['pending_user_name'] = $login;
                
                // Указываем, что это регистрация
                $_SESSION['is_registration'] = true;

                $logger->logLogin($new_user_id, true);

                // Перенаправляем на страницу верификации
                header("Location: verify_code.php");
                exit();
            } else {
                // Если отправка email не удалась, удаляем пользователя
                $delete_stmt = $mysqli->prepare("DELETE FROM Users WHERE ID = ?");
                $delete_stmt->bind_param("i", $new_user_id);
                $delete_stmt->execute();
                $delete_stmt->close();
                
                header("Location: auth.php?register=true&error=email_error&login=" . urlencode($login) . "&email=" . urlencode($email));
                exit();
            }
        } else {
            header("Location: auth.php?register=true&error=db_error&login=" . urlencode($login) . "&email=" . urlencode($email));
            exit();
        }
        
    } else {
        // ОБРАБОТКА АВТОРИЗАЦИИ
        // Проверка пользователя
        $stmt = $mysqli->prepare("SELECT ID, Login, Password, Ban, Role_ID, Failed_Attempts, Email FROM Users WHERE Login = ? OR Email = ?");
        $stmt->bind_param("ss", $login, $login);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Проверка бана
            if ($user['Ban'] == 1) {
                $logger->logLogin($user['ID'], false);
                header("Location: auth.php?error=banned&login=" . urlencode($login));
                exit();
            }
            
            // Проверка пароля
            if (password_verify($password, $user['Password'])) {
                // Успешная авторизация - отправляем код верификации
                $verification_code = sprintf("%06d", mt_rand(1, 999999));
                $code_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                // Сохраняем код в базу
                $code_stmt = $mysqli->prepare("UPDATE Users SET verification_code = ?, code_expires = ? WHERE ID = ?");
                $code_stmt->bind_param("ssi", $verification_code, $code_expires, $user['ID']);
                $code_stmt->execute();
                $code_stmt->close();

                // Отправляем код на почту
                require_once 'mailer.php';
                sendVerificationUniversal($user['Email'], $verification_code);

                // Сохраняем данные пользователя в сессии как "ожидающие верификации"
                $_SESSION['pending_user_id'] = $user['ID'];
                $_SESSION['pending_user_login'] = $user['Login'];
                $_SESSION['pending_user_email'] = $user['Email'];
                $_SESSION['pending_user_role'] = $user['Role_ID'];
                
                // Указываем, что это вход (не регистрация)
                $_SESSION['is_registration'] = false;

                // Сбрасываем счетчик неудачных попыток
                $reset_stmt = $mysqli->prepare("UPDATE Users SET Failed_Attempts = 0 WHERE ID = ?");
                $reset_stmt->bind_param("i", $user['ID']);
                $reset_stmt->execute();
                $reset_stmt->close();

                $logger->logLogin($user['ID'], true);

                // Перенаправляем на страницу верификации
                header("Location: verify_code.php");
                exit();
            } else {
                // Неверный пароль
                $failed_attempts = $user['Failed_Attempts'] + 1;
                $update_stmt = $mysqli->prepare("UPDATE Users SET Failed_Attempts = ? WHERE ID = ?");
                $update_stmt->bind_param("ii", $failed_attempts, $user['ID']);
                $update_stmt->execute();
                $update_stmt->close();
                
                $logger->logLogin($user['ID'], false);
                
                if ($failed_attempts >= 3) {
                    // Блокируем аккаунт на 3 часа
                    $ban_stmt = $mysqli->prepare("UPDATE Users SET Ban = 1, Date_of_change = NOW() WHERE ID = ?");
                    $ban_stmt->bind_param("i", $user['ID']);
                    $ban_stmt->execute();
                    $ban_stmt->close();
                    
                    header("Location: auth.php?error=account_locked&login=" . urlencode($login));
                } else {
                    header("Location: auth.php?error=invalid_credentials&attempts=" . $failed_attempts . "&login=" . urlencode($login));
                }
                exit();
            }
        } else {
            header("Location: auth.php?error=invalid_credentials&login=" . urlencode($login));
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Справочник военной техники - <?php echo isset($_GET['register']) ? 'Регистрация' : 'Вход'; ?></title>
    <link rel="stylesheet" href="auth_register.css">
</head>
<body>
    <div class="page-wrapper">
        <div class="top-bar">
            <nav class="nav-menu">
                <div class="nav-item">
                    <a href="main.php" class="nav-link">Главная</a>
                </div>
            </nav>
            <h1 class="page-title-top"><?php echo isset($_GET['register']) ? 'Регистрация' : 'Авторизация'; ?></h1>
            <div style="width:100px;"></div>
        </div>

        <div class="background-layer"></div>

        <div class="notification-container" id="notificationContainer">
            <?php if (isset($_GET['error'])): ?>
                <div class="notification notification-error">
                    <span class="notification-close" onclick="this.parentElement.remove()">×</span>
                    <?php
                    $errors = [
                        'empty_fields' => 'Заполните все поля',
                        'password_mismatch' => 'Пароли не совпадают',
                        'short_password' => 'Пароль не менее 6 символов',
                        'user_exists' => 'Пользователь с таким логином уже существует',
                        'email_exists' => 'Пользователь с таким email уже существует',
                        'invalid_email' => 'Неверный формат email',
                        'db_error' => 'Ошибка базы данных',
                        'email_error' => 'Ошибка отправки email',
                        'invalid_credentials' => 'Неверный логин или пароль',
                        'banned' => 'Аккаунт заблокирован',
                        'captcha_required' => 'Необходимо пройти капчу',
                        'captcha_invalid' => 'Неправильно собрана капча',
                        'account_locked' => 'Ваш аккаунт был заблокирован на 3 часа за 3 неверные попытки ввода пароля'
                    ];
                    $msg = $errors[$_GET['error']] ?? 'Ошибка';
                    if ($_GET['error'] === 'invalid_credentials' && isset($_GET['attempts'])) {
                        $rem = 3 - (int)$_GET['attempts'];
                        $msg .= $rem > 0 ? " (осталось попыток: $rem)" : '';
                    }
                    echo htmlspecialchars($msg);
                    ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['success'])): ?>
                <div class="notification notification-success">
                    <span class="notification-close" onclick="this.parentElement.remove()">×</span>
                    <?php 
                    $successMessages = [
                        'registered' => 'Регистрация прошла успешно!',
                        'logged_in' => 'Вход выполнен успешно!'
                    ];
                    echo htmlspecialchars($successMessages[$_GET['success']] ?? 'Успешно');
                    ?>
                </div>
            <?php endif; ?>
            
            <?php if ($show_lockout && $lock_remaining > 0): ?>
                <div class="notification notification-error" id="lockoutNotification">
                    <span class="notification-close" onclick="this.parentElement.remove()">×</span>
                    Ваш аккаунт заблокирован. До разблокировки осталось: <span id="lockoutTimer">--:--</span>
                </div>
                <script>
                    let lockTimeLeft = <?php echo $lock_remaining; ?>;
                    const timerEl = document.getElementById('lockoutTimer');
                    setInterval(() => {
                        if (lockTimeLeft <= 0) location.reload();
                        const m = String(Math.floor(lockTimeLeft / 60)).padStart(2, '0');
                        const s = String(lockTimeLeft % 60).padStart(2, '0');
                        timerEl.textContent = `${m}:${s}`;
                        lockTimeLeft--;
                    }, 1000);
                </script>
            <?php endif; ?>
        </div>

        <div class="captcha-modal" id="captchaModal">
            <div class="captcha-modal-content">
                <button class="captcha-modal-close" onclick="closeCaptchaModal()">×</button>
                <div class="captcha-title">Соберите изображение девочки</div>
                <div class="captcha-instructions">Перетащите части из левой области в правые ячейки</div>
                <div class="captcha-area">
                    <div class="captcha-pieces-area">
                        <div class="captcha-pieces-container" id="captchaPieces"></div>
                    </div>
                    <div class="captcha-target-area">
                        <div class="captcha-target" id="captchaTarget">
                            <div class="captcha-slot" data-slot="1"><div class="slot-number">1</div></div>
                            <div class="captcha-slot" data-slot="2"><div class="slot-number">2</div></div>
                            <div class="captcha-slot" data-slot="3"><div class="slot-number">3</div></div>
                            <div class="captcha-slot" data-slot="4"><div class="slot-number">4</div></div>
                        </div>
                    </div>
                </div>
                <div class="captcha-controls">
                    <button type="button" class="captcha-check" onclick="checkCaptcha()">Проверить сборку</button>
                    <button type="button" class="captcha-reset" onclick="resetCaptcha()">Сбросить</button>
                </div>
                <div id="captchaMsg" class="captcha-error"></div>
            </div>
        </div>

        <div class="military-container">
            <div class="military-header">
                <h1>СПРАВОЧНИК ВОЕННОЙ ТЕХНИКИ</h1>
            </div>

            <?php if (isset($_GET['register'])): ?>
                <!-- Форма регистрации -->
                <form class="military-form" method="POST" action="auth.php" id="registerForm">
                    <input type="hidden" name="register" value="true">
                    <div class="form-group">
                        <label for="login">Логин:</label>
                        <input type="text" id="login" name="login" required 
                               value="<?php echo htmlspecialchars($_GET['login'] ?? ($_POST['login'] ?? '')); ?>" 
                               placeholder="Введите логин">
                    </div>
                    <div class="form-group">
                        <label for="email">Email:</label>
                        <input type="email" id="email" name="email" required 
                               value="<?php echo htmlspecialchars($_GET['email'] ?? ($_POST['email'] ?? '')); ?>" 
                               placeholder="Введите email">
                    </div>
                    <div class="form-group">
                        <label for="password">Пароль:</label>
                        <input type="password" id="password" name="password" required 
                               placeholder="Не менее 6 символов">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Подтвердите пароль:</label>
                        <input type="password" id="confirm_password" name="confirm_password" required 
                               placeholder="Повторите пароль">
                    </div>
                    <div class="captcha-container">
                        <div class="captcha-status pending" id="captchaStatus">Капча не пройдена</div>
                        <button type="button" class="captcha-trigger-btn" id="captchaTriggerBtn" onclick="openCaptchaModal()">Пройти капчу</button>
                        <input type="hidden" name="captcha_answer" id="captchaAnswer" value="">
                    </div>
                    <button type="submit" class="military-btn">Зарегистрироваться</button>
                </form>
                <div class="form-switch">
                    <span>Уже есть аккаунт?</span> 
                    <a href="auth.php">Войти</a>
                </div>
            <?php else: ?>
                <!-- Форма авторизации -->
                <form class="military-form" method="POST" action="auth.php" id="loginForm">
                    <div class="form-group">
                        <label for="login">Логин или Email:</label>
                        <input type="text" id="login" name="login" required 
                               value="<?php echo htmlspecialchars($lock_login ?: ($_GET['login'] ?? ($_POST['login'] ?? ''))); ?>" 
                               placeholder="Введите логин или email">
                    </div>
                    <div class="form-group">
                        <label for="password">Пароль:</label>
                        <input type="password" id="password" name="password" required 
                               placeholder="Введите пароль">
                    </div>
                    <div class="captcha-container">
                        <div class="captcha-status pending" id="captchaStatus">Капча не пройдена</div>
                        <button type="button" class="captcha-trigger-btn" id="captchaTriggerBtn" onclick="openCaptchaModal()">Пройти капчу</button>
                        <input type="hidden" name="captcha_answer" id="captchaAnswer" value="">
                    </div>
                    <button type="submit" class="military-btn">Войти</button>
                </form>
                <div class="form-switch">
                    <span>Нет аккаунта?</span> 
                    <a href="auth.php?register=true">Регистрация</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        let draggedPiece = null;
        const correctOrder = ['1.png', '2.png', '3.png', '4.png'];
        let isCaptchaCompleted = false;

        function openCaptchaModal() {
            console.log('Opening captcha modal');
            const modal = document.getElementById('captchaModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            document.getElementById('captchaMsg').textContent = '';
            document.getElementById('captchaMsg').className = 'captcha-error';
            initializeCaptcha();
        }

        function closeCaptchaModal() {
            console.log('Closing captcha modal');
            const modal = document.getElementById('captchaModal');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }

        function shuffleArray(arr) {
            const a = [...arr];
            for (let i = a.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [a[i], a[j]] = [a[j], a[i]];
            }
            return a;
        }

        function generateSimplePositions() {
            return [
                { x: 10, y: 10 }, { x: 140, y: 10 },
                { x: 10, y: 140 }, { x: 140, y: 140 }
            ];
        }

        function initializeCaptcha() {
            console.log('Initializing captcha');
            const pieces = document.getElementById('captchaPieces');
            pieces.innerHTML = '';

            const imgs = shuffleArray(['1.png', '2.png', '3.png', '4.png']);
            const pos = generateSimplePositions();

            imgs.forEach((img, i) => {
                const div = document.createElement('div');
                div.className = 'captcha-piece';
                div.dataset.image = img;
                div.style.position = 'absolute';
                div.style.left = pos[i].x + 'px';
                div.style.top = pos[i].y + 'px';
                div.innerHTML = `<img src="captcha/${img}" class="captcha-piece-image">`;
                div.draggable = true;
                div.addEventListener('dragstart', dragStart);
                pieces.appendChild(div);
            });

            document.querySelectorAll('.captcha-slot').forEach(slot => {
                slot.className = 'captcha-slot';
                if (slot.querySelector('.captcha-piece')) slot.querySelector('.captcha-piece').remove();
                slot.innerHTML = '<div class="slot-number">' + slot.dataset.slot + '</div>';
            });

            document.getElementById('captchaMsg').textContent = '';
            document.getElementById('captchaMsg').className = 'captcha-error';
        }

        function dragStart(e) {
            draggedPiece = e.target;
            if (draggedPiece.className === 'captcha-piece-image') {
                draggedPiece = draggedPiece.parentNode;
            }
            e.dataTransfer.setData('text/plain', draggedPiece.dataset.image);
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setDragImage(draggedPiece, 60, 60);
        }

        function updateCaptchaAnswer() {
            const answer = [];
            const slots = document.querySelectorAll('.captcha-slot');
            
            slots.forEach(slot => {
                const slotIndex = parseInt(slot.dataset.slot) - 1;
                if (slot.classList.contains('filled')) {
                    const img = slot.querySelector('img').src.split('/').pop();
                    answer[slotIndex] = img;
                } else {
                    answer[slotIndex] = '';
                }
            });
            
            const finalAnswer = answer.join(',');
            document.getElementById('captchaAnswer').value = finalAnswer;
            
            console.log('Captcha answer updated:', finalAnswer);
            return finalAnswer;
        }

        function checkCaptcha() {
            console.log('Checking captcha...');
            const answer = updateCaptchaAnswer();
            const answerParts = answer.split(',');
            
            // Проверяем, все ли слоты заполнены
            const allFilled = answerParts.every(part => part !== '');
            
            if (!allFilled) {
                document.getElementById('captchaMsg').textContent = '❌ Заполните все ячейки';
                document.getElementById('captchaMsg').className = 'captcha-error';
                return false;
            }
            
            // Проверяем правильность порядка
            const isCorrect = answerParts[0] === '1.png' && 
                             answerParts[1] === '2.png' && 
                             answerParts[2] === '3.png' && 
                             answerParts[3] === '4.png';
            
            if (isCorrect) {
                // УСПЕШНОЕ ЗАВЕРШЕНИЕ КАПЧИ
                isCaptchaCompleted = true;
                
                document.getElementById('captchaMsg').textContent = '✅ Капча пройдена!';
                document.getElementById('captchaMsg').className = 'captcha-success';
                
                // Обновляем статус
                updateCaptchaStatus(true);
                
                // Закрываем модальное окно с задержкой
                setTimeout(() => {
                    document.getElementById('captchaModal').style.display = 'none';
                    document.body.style.overflow = '';
                }, 1000);
                
                console.log('CAPTCHA PASSED AND CLOSED');
                return true;
            } else {
                document.getElementById('captchaMsg').textContent = '❌ Неверно, соберите изображение правильно';
                document.getElementById('captchaMsg').className = 'captcha-error';
                isCaptchaCompleted = false;
                updateCaptchaStatus(false);
                return false;
            }
        }

        function updateCaptchaStatus(completed) {
            const status = document.getElementById('captchaStatus');
            const btn = document.getElementById('captchaTriggerBtn');
            if (completed) {
                status.textContent = 'Капча пройдена';
                status.className = 'captcha-status completed';
                btn.textContent = 'Готово ✓';
                btn.className = 'captcha-trigger-btn completed';
            } else {
                status.textContent = 'Капча не пройдена';
                status.className = 'captcha-status pending';
                btn.textContent = 'Пройти капчу';
                btn.className = 'captcha-trigger-btn';
            }
        }

        function resetCaptcha() {
            console.log('Resetting captcha');
            isCaptchaCompleted = false;
            
            document.querySelectorAll('.captcha-slot').forEach(slot => {
                slot.className = 'captcha-slot';
                if (slot.querySelector('.captcha-piece')) slot.querySelector('.captcha-piece').remove();
                slot.innerHTML = '<div class="slot-number">' + slot.dataset.slot + '</div>';
            });
            
            document.getElementById('captchaMsg').textContent = '';
            document.getElementById('captchaMsg').className = 'captcha-error';
            
            initializeCaptcha();
            updateCaptchaStatus(false);
            
            // Очищаем скрытое поле
            document.getElementById('captchaAnswer').value = '';
        }

        function showNotification(message, type) {
            const notificationContainer = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            
            if (message.toLowerCase().includes('капч') || message.toLowerCase().includes('captcha')) {
                notification.className = 'notification captcha-notification';
                notification.innerHTML = `
                    <div class="notification-content">
                        <span class="notification-icon">⚠️</span>
                        <span>${message}</span>
                    </div>
                `;
            } else {
                notification.className = `notification notification-${type}`;
                notification.innerHTML = `<span class="notification-close" onclick="this.parentElement.remove()">×</span>${message}`;
            }
            
            notificationContainer.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            setTimeout(() => {
                notification.classList.remove('show');
                notification.classList.add('hide');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 400);
            }, 5000);
        }

        // Закрытие модального окна при клике вне его области
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('captchaModal');
            if (e.target === modal) {
                closeCaptchaModal();
            }
        });

        // Закрытие модального окна по клавише Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('captchaModal');
                if (modal.style.display === 'flex') {
                    closeCaptchaModal();
                }
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded - initializing event handlers');

            const bg = document.querySelector('.background-layer');
            document.addEventListener('mousemove', e => {
                const sens = 0.03;
                const x = (e.clientX - window.innerWidth / 2) * sens;
                const y = (e.clientY - window.innerHeight / 2) * sens;
                bg.style.setProperty('--mouse-x', x + 'px');
                bg.style.setProperty('--mouse-y', y + 'px');
            });

            // Обработчики для drag and drop
            const captchaSlots = document.querySelectorAll('.captcha-slot');
            
            captchaSlots.forEach(slot => {
                slot.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    if (!this.querySelector('.captcha-piece')) {
                        this.classList.add('hover');
                    }
                });

                slot.addEventListener('dragleave', function() {
                    this.classList.remove('hover');
                });

                slot.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.classList.remove('hover');
                    
                    if (this.querySelector('.captcha-piece') || !draggedPiece) {
                        draggedPiece = null;
                        return;
                    }

                    const imgName = e.dataTransfer.getData('text/plain');
                    if (!imgName) {
                        draggedPiece = null;
                        return;
                    }

                    const piece = draggedPiece.cloneNode(true);
                    piece.dataset.image = imgName;
                    piece.querySelector('img').className = 'captcha-piece-image';
                    piece.className = 'captcha-piece in-slot';
                    piece.style.position = 'static';
                    piece.style.width = '100%';
                    piece.style.height = '100%';
                    piece.draggable = false;
                    piece.removeEventListener('dragstart', dragStart);

                    this.appendChild(piece);
                    this.className = 'captcha-slot filled';
                    draggedPiece.parentNode.removeChild(draggedPiece);
                    draggedPiece = null;

                    // Обновляем ответ капчи
                    updateCaptchaAnswer();
                    
                    // Автоматическая проверка при заполнении всех слотов
                    const allSlots = document.querySelectorAll('.captcha-slot');
                    const allFilled = Array.from(allSlots).every(slot => slot.classList.contains('filled'));
                    if (allFilled) {
                        console.log('All slots filled, auto-checking captcha...');
                        setTimeout(() => {
                            checkCaptcha();
                        }, 500);
                    }
                });
            });

            // Инициализация обработчиков для drop зон
            const captchaPiecesContainer = document.getElementById('captchaPieces');
            if (captchaPiecesContainer) {
                captchaPiecesContainer.addEventListener('dragover', function(e) {
                    e.preventDefault();
                });

                captchaPiecesContainer.addEventListener('drop', function(e) {
                    e.preventDefault();
                    const imgName = e.dataTransfer.getData('text/plain');
                    if (!imgName) return;

                    const slot = document.querySelector(`.captcha-slot.filled img[src*="${imgName}"]`);
                    if (slot) {
                        const pieceToRemove = slot.parentNode;
                        const slotElement = pieceToRemove.parentNode;
                        
                        slotElement.className = 'captcha-slot';
                        slotElement.innerHTML = '<div class="slot-number">' + slotElement.dataset.slot + '</div>';
                        
                        const originalPiece = document.createElement('div');
                        originalPiece.className = 'captcha-piece';
                        originalPiece.dataset.image = imgName;
                        originalPiece.style.position = 'absolute';
                        originalPiece.style.left = (Math.random() * 200) + 'px';
                        originalPiece.style.top = (Math.random() * 200) + 'px';
                        originalPiece.innerHTML = `<img src="captcha/${imgName}" class="captcha-piece-image">`;
                        originalPiece.draggable = true;
                        originalPiece.addEventListener('dragstart', dragStart);
                        
                        captchaPiecesContainer.appendChild(originalPiece);
                        
                        updateCaptchaAnswer();
                    }
                });
            }

            // Анимация для существующих уведомлений при загрузке
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
                }, 15000);
            });

            document.querySelectorAll('.notification-close').forEach(b => {
                b.addEventListener('click', function() {
                    if (this.parentElement) {
                        this.parentElement.remove();
                    }
                });
            });

            // Обработчик отправки формы
            document.querySelectorAll('.military-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const captchaField = document.getElementById('captchaAnswer');
                    
                    console.log('Form submit - captcha completed:', isCaptchaCompleted);
                    console.log('Captcha field value:', captchaField ? captchaField.value : 'NOT FOUND');
                    
                    if (!isCaptchaCompleted) {
                        e.preventDefault();
                        showNotification('Для продолжения необходимо пройти капчу', 'error');
                        return;
                    }
                    
                    if (!captchaField || !captchaField.value) {
                        e.preventDefault();
                        showNotification('Ошибка капчи. Пройдите капчу заново.', 'error');
                        return;
                    }
                    
                    const captchaParts = captchaField.value.split(',');
                    console.log('Captcha parts:', captchaParts);
                    
                    if (captchaParts.length !== 4 || captchaParts.some(part => !part)) {
                        e.preventDefault();
                        showNotification('Капча заполнена некорректно. Пройдите капчу заново.', 'error');
                        return;
                    }
                    
                    const isCorrect = captchaParts[0] === '1.png' && 
                                     captchaParts[1] === '2.png' && 
                                     captchaParts[2] === '3.png' && 
                                     captchaParts[3] === '4.png';
                    
                    console.log('Captcha validation result:', isCorrect);
                    
                    if (!isCorrect) {
                        e.preventDefault();
                        showNotification('Капча собрана неправильно. Проверьте порядок сборки.', 'error');
                        return;
                    }
                    
                    console.log('Form submission allowed - captcha is correct');
                });
            });

            initializeCaptcha();
            console.log('Initialization complete');
        });
    </script>
</body>
</html>