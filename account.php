<?php
session_start();

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php");
    exit();
}

// Подключаем базу данных
include 'connect.php';

// Обработка изменения пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $user_id = $_SESSION['user_id'];
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Проверяем, что все поля заполнены
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        header("Location: account.php?error=empty_password_fields");
        exit();
    }
    
    // Проверяем, что новый пароль и подтверждение совпадают
    if ($new_password !== $confirm_password) {
        header("Location: account.php?error=password_mismatch");
        exit();
    }
    
    // Проверяем длину нового пароля
    if (strlen($new_password) < 6) {
        header("Location: account.php?error=password_too_short");
        exit();
    }
    
    // Получаем текущий пароль пользователя
    $stmt = $mysqli->prepare("SELECT Password FROM Users WHERE ID = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        header("Location: account.php?error=user_not_found");
        exit();
    }
    
    // Проверяем текущий пароль
    if (!password_verify($current_password, $user['Password'])) {
        header("Location: account.php?error=wrong_current_password");
        exit();
    }
    
    // Хешируем новый пароль
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $current_date = date('Y-m-d H:i:s');
    
    // Обновляем пароль в базе данных
    $update_stmt = $mysqli->prepare("UPDATE Users SET Password = ?, Date_of_change = ? WHERE ID = ?");
    $update_stmt->bind_param("ssi", $hashed_password, $current_date, $user_id);
    
    if ($update_stmt->execute()) {
        $update_stmt->close();
        header("Location: account.php?success=password_changed");
        exit();
    } else {
        header("Location: account.php?error=password_change_failed");
        exit();
    }
}

// Обработка загрузки аватарки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['avatar'])) {
    $user_id = $_SESSION['user_id'];
    $upload_dir = 'avatars/';
    
    // Создаем папку для аватарок, если её нет
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $avatar = $_FILES['avatar'];
    
    // Проверяем ошибки загрузки
    if ($avatar['error'] !== UPLOAD_ERR_OK) {
        header("Location: account.php?error=upload_error");
        exit();
    }
    
    // Проверка типа файла по расширению
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
    $file_extension = strtolower(pathinfo($avatar['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, $allowed_extensions)) {
        header("Location: account.php?error=invalid_file_type");
        exit();
    }
    
    // Проверка размера файла (2MB)
    if ($avatar['size'] > 2 * 1024 * 1024) {
        header("Location: account.php?error=file_too_large");
        exit();
    }
    
    // Генерируем уникальное имя файла
    $new_filename = 'avatar_' . $user_id . '_' . time() . '.' . $file_extension;
    $upload_path = $upload_dir . $new_filename;
    
    // Сохраняем изображение
    if (move_uploaded_file($avatar['tmp_name'], $upload_path)) {
        // Удаляем старый аватар, если он существует
        $old_avatar_stmt = $mysqli->prepare("SELECT avatar_path FROM Users WHERE ID = ?");
        $old_avatar_stmt->bind_param("i", $user_id);
        $old_avatar_stmt->execute();
        $old_avatar_result = $old_avatar_stmt->get_result();
        $old_avatar = $old_avatar_result->fetch_assoc();
        $old_avatar_stmt->close();
        
        if ($old_avatar && !empty($old_avatar['avatar_path']) && file_exists($old_avatar['avatar_path']) && $old_avatar['avatar_path'] != 'default-avatar.jpg') {
            unlink($old_avatar['avatar_path']);
        }
        
        // Обновляем путь к аватарке в базе данных
        $update_stmt = $mysqli->prepare("UPDATE Users SET avatar_path = ? WHERE ID = ?");
        $update_stmt->bind_param("si", $upload_path, $user_id);
        
        if ($update_stmt->execute()) {
            $update_stmt->close();
            
            // Обновляем информацию в сессии
            $_SESSION['avatar_path'] = $upload_path;
            
            header("Location: account.php?success=avatar_updated");
            exit();
        } else {
            // Если не удалось обновить БД, удаляем файл
            unlink($upload_path);
            header("Location: account.php?error=db_update_failed");
            exit();
        }
    } else {
        header("Location: account.php?error=upload_failed");
        exit();
    }
}

// Обработка изменения имени пользователя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_name'])) {
    $user_id = $_SESSION['user_id'];
    $user_name = trim($_POST['user_name']);
    
    if (!empty($user_name)) {
        // Проверка длины имени (используем mb_strlen для корректного подсчета кириллических символов)
        if (mb_strlen($user_name, 'UTF-8') > 35) {
            header("Location: account.php?error=name_too_long");
            exit();
        }
        
        $update_stmt = $mysqli->prepare("UPDATE Users SET Name = ? WHERE ID = ?");
        $update_stmt->bind_param("si", $user_name, $user_id);
        
        if ($update_stmt->execute()) {
            $update_stmt->close();
            header("Location: account.php?success=name_updated");
            exit();
        } else {
            header("Location: account.php?error=name_update_failed");
            exit();
        }
    }
}

// Обработка изменения email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_email'])) {
    $user_id = $_SESSION['user_id'];
    $user_email = trim($_POST['user_email']);
    
    if (!empty($user_email)) {
        // Проверка валидности email
        if (!filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
            header("Location: account.php?error=invalid_email");
            exit();
        }
        
        // Проверка существования email у другого пользователя
        $check_stmt = $mysqli->prepare("SELECT ID FROM Users WHERE Email = ? AND ID != ?");
        $check_stmt->bind_param("si", $user_email, $user_id);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            $check_stmt->close();
            header("Location: account.php?error=email_exists");
            exit();
        }
        $check_stmt->close();
        
        $update_stmt = $mysqli->prepare("UPDATE Users SET Email = ? WHERE ID = ?");
        $update_stmt->bind_param("si", $user_email, $user_id);
        
        if ($update_stmt->execute()) {
            $update_stmt->close();
            $_SESSION['user_email'] = $user_email;
            header("Location: account.php?success=email_updated");
            exit();
        } else {
            header("Location: account.php?error=email_update_failed");
            exit();
        }
    }
}

// Обработка изменения логина
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_login'])) {
    $user_id = $_SESSION['user_id'];
    $user_login = trim($_POST['user_login']);
    
    if (!empty($user_login)) {
        // Проверка длины логина
        if (mb_strlen($user_login, 'UTF-8') > 35) {
            header("Location: account.php?error=login_too_long");
            exit();
        }
        
        // Проверка существования логина у другого пользователя
        $check_stmt = $mysqli->prepare("SELECT ID FROM Users WHERE Login = ? AND ID != ?");
        $check_stmt->bind_param("si", $user_login, $user_id);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            $check_stmt->close();
            header("Location: account.php?error=login_exists");
            exit();
        }
        $check_stmt->close();
        
        $update_stmt = $mysqli->prepare("UPDATE Users SET Login = ? WHERE ID = ?");
        $update_stmt->bind_param("si", $user_login, $user_id);
        
        if ($update_stmt->execute()) {
            $update_stmt->close();
            $_SESSION['user_login'] = $user_login;
            header("Location: account.php?success=login_updated");
            exit();
        } else {
            header("Location: account.php?error=login_update_failed");
            exit();
        }
    }
}

// Обработка удаления из избранного
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_favorite'])) {
    $article_id = intval($_POST['article_id']);
    $user_id = $_SESSION['user_id'];
    
    $delete_stmt = $mysqli->prepare("DELETE FROM user_favorites WHERE user_id = ? AND article_id = ?");
    $delete_stmt->bind_param("ii", $user_id, $article_id);
    
    if ($delete_stmt->execute()) {
        header("Location: account.php?success=favorite_removed");
        exit();
    } else {
        header("Location: account.php?error=favorite_remove_failed");
        exit();
    }
}

// Получаем данные пользователя с информацией о роли
$user_id = $_SESSION['user_id'];
$stmt = $mysqli->prepare("
    SELECT u.ID, u.Login, u.Email, u.Password, u.Date_of_reg, u.Date_of_change, u.Ban, u.Role_ID, u.avatar_path, u.Name, r.Name as RoleName 
    FROM Users u 
    LEFT JOIN Role r ON u.Role_ID = r.ID 
    WHERE u.ID = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    // Если пользователь не найден, разлогиниваем
    session_destroy();
    header("Location: auth.php");
    exit();
}

// Получаем избранные статьи
$favorite_articles = [];
if ($mysqli->query("SHOW TABLES LIKE 'user_favorites'")->num_rows > 0) {
    $favorite_stmt = $mysqli->prepare("
        SELECT v.ID, v.Name, v.History 
        FROM Vehicle v 
        INNER JOIN user_favorites uf ON v.ID = uf.article_id 
        WHERE uf.user_id = ? 
        ORDER BY uf.added_at DESC 
        LIMIT 10
    ");
    $favorite_stmt->bind_param("i", $user_id);
    $favorite_stmt->execute();
    $favorite_result = $favorite_stmt->get_result();
    while ($row = $favorite_result->fetch_assoc()) {
        $favorite_articles[] = $row;
    }
    $favorite_stmt->close();
}

// Определяем статус аккаунта
$status = isset($user['Ban']) && $user['Ban'] == 1 ? 'Заблокирован' : 'Активный';
$status_color = isset($user['Ban']) && $user['Ban'] == 1 ? '#ff6b6b' : '#8b966c';

// Дата последней смены пароля
$last_password_change = isset($user['Date_of_change']) && $user['Date_of_change'] ? 
    date('d.m.Y H:i', strtotime($user['Date_of_change'])) : 'Не менялся';

// Дата регистрации
$reg_date = isset($user['Date_of_reg']) ? date('d.m.Y H:i', strtotime($user['Date_of_reg'])) : 'Неизвестно';

// Роль пользователя
$user_role = isset($user['RoleName']) ? $user['RoleName'] : 'Пользователь';

// Email пользователя
$user_email = isset($user['Email']) ? $user['Email'] : 'Не указан';

// Логин пользователя
$user_login = isset($user['Login']) ? $user['Login'] : 'Не указан';

// Путь к аватарке
$avatar_path = 'default-avatar.jpg'; // значение по умолчанию

if (isset($user['avatar_path']) && !empty($user['avatar_path'])) {
    // Проверяем существование файла аватарки
    if (file_exists($user['avatar_path'])) {
        $avatar_path = $user['avatar_path'];
    } else {
        // Если файл не существует, проверяем стандартные аватары
        $standard_avatar_path = $user['avatar_path'];
        if (file_exists($standard_avatar_path)) {
            $avatar_path = $standard_avatar_path;
        }
    }
}

// Имя пользователя (если не задано, используем логин)
$user_name = isset($user['Name']) && !empty($user['Name']) ? $user['Name'] : $user['Login'];

// Проверяем, является ли пользователь администратором (Role_ID = 2) или главным администратором (Role_ID = 4)
$is_admin = isset($user['Role_ID']) && ($user['Role_ID'] == 2 || $user['Role_ID'] == 4);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет - Справочник военной техники</title>
    <link rel="stylesheet" href="account.css">
</head>
<body>
    <div class="top-bar">
        <nav class="nav-menu">
            <div class="nav-item">
                <a href="main.php" class="nav-link">Главная</a>
            </div>
        </nav>
        <h1 class="page-title-top">Личный кабинет</h1>
        <div style="width: 100px;"></div>
    </div>

    <!-- Контейнер для уведомлений -->
    <div class="notification-container" id="notificationContainer">
        <?php if (isset($_GET['success'])): ?>
            <div class="notification notification-success" data-type="success">
                <span class="notification-close" onclick="this.parentElement.remove()">×</span>
                <?php
                $successMessages = [
                    'avatar_updated' => 'Аватар успешно обновлен!',
                    'name_updated' => 'Имя пользователя успешно обновлено!',
                    'email_updated' => 'Email успешно обновлен!',
                    'login_updated' => 'Логин успешно обновлен!',
                    'favorite_removed' => 'Статья удалена из избранного!',
                    'password_changed' => 'Пароль успешно изменен!'
                ];
                echo $successMessages[$_GET['success']] ?? 'Операция выполнена успешно';
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="notification notification-error" data-type="error">
                <span class="notification-close" onclick="this.parentElement.remove()">×</span>
                <?php
                $errorMessages = [
                    'invalid_file_type' => 'Недопустимый тип файла. Разрешены: JPG, JPEG, PNG, GIF',
                    'file_too_large' => 'Файл слишком большой. Максимальный размер: 2MB',
                    'upload_failed' => 'Ошибка загрузки файла',
                    'name_update_failed' => 'Ошибка обновления имени',
                    'name_too_long' => 'Имя слишком большое. Максимум 35 символов',
                    'upload_error' => 'Ошибка загрузки файла',
                    'db_update_failed' => 'Ошибка обновления базы данных',
                    'favorite_remove_failed' => 'Ошибка удаления из избранного',
                    'invalid_email' => 'Некорректный email адрес',
                    'email_exists' => 'Пользователь с таким email уже существует',
                    'email_update_failed' => 'Ошибка обновления email',
                    'login_too_long' => 'Логин слишком большой. Максимум 35 символов',
                    'login_exists' => 'Пользователь с таким логином уже существует',
                    'login_update_failed' => 'Ошибка обновления логина',
                    'empty_password_fields' => 'Все поля пароля должны быть заполнены',
                    'password_mismatch' => 'Новый пароль и подтверждение не совпадают',
                    'password_too_short' => 'Пароль должен содержать минимум 6 символов',
                    'wrong_current_password' => 'Текущий пароль указан неверно',
                    'password_change_failed' => 'Ошибка при изменении пароля',
                    'user_not_found' => 'Пользователь не найден'
                ];
                echo $errorMessages[$_GET['error']] ?? 'Произошла ошибка';
                ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="main-content">
        <div class="account-container">
            <div class="account-content">
                <!-- Левая колонка - профиль -->
                <div class="profile-section">
                    <div class="profile-info">
                        <img src="<?php echo $avatar_path; ?>" 
                             alt="Аватар" 
                             class="profile-avatar"
                             id="mainAvatar"
                             onclick="document.getElementById('avatarInput').click()"
                             onerror="this.src='default-avatar.jpg'">
                        
                        <img src="" alt="Превью аватара" class="avatar-preview" id="avatarPreview">
                        
                        <h2>
                            <div class="user-name-container">
                                <span id="userNameDisplay"><?php echo htmlspecialchars($user_name); ?></span>
                                <button class="edit-name-btn" onclick="toggleNameEdit()">✎</button>
                            </div>
                            <form id="nameEditForm" class="name-edit-inline" action="account.php" method="POST">
                                <input type="text" name="user_name" id="userNameInput" class="name-edit-input" value="<?php echo htmlspecialchars($user_name); ?>" placeholder="Введите ваше имя" required maxlength="35">
                                <div class="name-length-info" id="nameLengthInfo">Символов: <span id="currentLength"><?php echo mb_strlen($user_name, 'UTF-8'); ?></span>/35</div>
                                <div class="name-length-warning" id="nameLengthWarning">Имя слишком большое! Максимум 35 символов</div>
                                <div class="name-edit-controls">
                                    <button type="button" class="upload-btn cancel" onclick="toggleNameEdit()">Отмена</button>
                                    <button type="submit" class="upload-btn" id="saveNameBtn">Сохранить</button>
                                </div>
                            </form>
                            <div class="user-role"><?php echo htmlspecialchars($user_role); ?></div>
                        </h2>
                    </div>

                    <div class="profile-details">
                        <div class="profile-detail" id="loginDetail">
                            <label>Логин:</label>
                            <div class="profile-detail-value">
                                <span id="userLoginDisplay"><?php echo htmlspecialchars($user_login); ?></span>
                                <button class="edit-login-btn" onclick="toggleLoginEdit()">✎</button>
                            </div>
                            <form id="loginEditForm" class="login-edit-inline" action="account.php" method="POST">
                                <input type="text" name="user_login" id="userLoginInput" class="login-edit-input" value="<?php echo htmlspecialchars($user_login); ?>" placeholder="Введите ваш логин" required maxlength="35">
                                <div class="login-length-info" id="loginLengthInfo">Символов: <span id="loginCurrentLength"><?php echo mb_strlen($user_login, 'UTF-8'); ?></span>/35</div>
                                <div class="login-length-warning" id="loginLengthWarning">Логин слишком большой! Максимум 35 символов</div>
                                <div class="login-exists-warning" id="loginExistsWarning">Пользователь с таким логином уже существует</div>
                                <div class="login-edit-controls">
                                    <button type="button" class="upload-btn cancel" onclick="toggleLoginEdit()">Отмена</button>
                                    <button type="submit" class="upload-btn" id="saveLoginBtn">Сохранить</button>
                                </div>
                            </form>
                        </div>

                        <div class="profile-detail" id="emailDetail">
                            <label>Email:</label>
                            <div class="profile-detail-value">
                                <span id="userEmailDisplay"><?php echo htmlspecialchars($user_email); ?></span>
                                <button class="edit-email-btn" onclick="toggleEmailEdit()">✎</button>
                            </div>
                            <form id="emailEditForm" class="email-edit-inline" action="account.php" method="POST">
                                <input type="email" name="user_email" id="userEmailInput" class="email-edit-input" value="<?php echo htmlspecialchars($user_email); ?>" placeholder="Введите ваш email" required>
                                <div class="email-edit-warning" id="emailWarning">Введите корректный email адрес</div>
                                <div class="email-edit-controls">
                                    <button type="button" class="upload-btn cancel" onclick="toggleEmailEdit()">Отмена</button>
                                    <button type="submit" class="upload-btn" id="saveEmailBtn">Сохранить</button>
                                </div>
                            </form>
                        </div>

                        <div class="profile-detail">
                            <label>Статус аккаунта:</label>
                            <div class="profile-detail-value">
                                <span style="color: <?php echo $status_color; ?>"><?php echo $status; ?></span>
                            </div>
                        </div>

                        <div class="profile-detail">
                            <label>Дата регистрации:</label>
                            <div class="profile-detail-value">
                                <span><?php echo $reg_date; ?></span>
                            </div>
                        </div>

                        <div class="profile-detail">
                            <label>Последняя смена пароля:</label>
                            <div class="profile-detail-value">
                                <span><?php echo $last_password_change; ?></span>
                            </div>
                        </div>
                    </div>
                        
                    <div class="avatar-upload-container" id="avatarUploadContainer">
                        <form id="avatarUploadForm" action="account.php" method="POST" enctype="multipart/formdata">
                            <input type="file" id="avatarInput" name="avatar" class="file-input" accept=".jpg,.jpeg,.png,.gif" onchange="showAvatarPreview(this)">
                            <div class="avatar-upload-controls">
                                <button type="button" class="upload-btn cancel" onclick="cancelAvatarUpload()">Отмена</button>
                                <button type="submit" class="upload-btn">Сохранить аватар</button>
                            </div>
                        </form>
                    </div>

                    <button type="button" class="change-password-btn" onclick="openPasswordModal()">
                        Сменить пароль
                    </button>

                    <?php if ($is_admin): ?>
                    <a href="admin_panel.php" class="admin-panel-btn">Панель администратора</a>
                    <?php endif; ?>

                    <a href="auth.php?logout=true" class="logout-btn">Выйти из системы</a>
                </div>

                <!-- Правая колонка - избранные статьи -->
                <div class="articles-section">
                    <div class="articles-block">
                        <h3>Избранные статьи</h3>
                        <?php if (!empty($favorite_articles)): ?>
                            <ul class="articles-list">
                                <?php foreach ($favorite_articles as $article): ?>
                                    <li class="favorite-item">
                                        <div class="favorite-item-content">
                                            <!-- ИЗМЕНЕНИЕ: Ссылка ведет на favorite_detail.php -->
                                            <a href="vehicle/favorite_detail.php?id=<?php echo $article['ID']; ?>">
                                                <?php echo htmlspecialchars($article['Name'] ?? 'Без названия'); ?>
                                            </a>
                                        </div>
                                        <form method="POST" style="margin: 0;">
                                            <input type="hidden" name="article_id" value="<?php echo $article['ID']; ?>">
                                            <button type="submit" name="remove_favorite" class="remove-favorite-btn" title="Удалить из избранного">
                                                ×
                                            </button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="empty-message">У вас нет избранных статей</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно смены пароля -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Смена пароля</h3>
                <button type="button" class="modal-close" onclick="closePasswordModal()">×</button>
            </div>
            <form id="passwordChangeForm" method="POST" action="account.php">
                <input type="hidden" name="change_password" value="1">
                
                <div class="password-form-group">
                    <label for="current_password">Текущий пароль:</label>
                    <div class="password-input-wrapper">
                        <input type="password" 
                               id="current_password" 
                               name="current_password" 
                               class="password-form-input" 
                               required 
                               autocomplete="current-password">
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility('current_password')">
                            👁
                        </button>
                    </div>
                </div>

                <div class="password-form-group">
                    <label for="new_password">Новый пароль:</label>
                    <div class="password-input-wrapper">
                        <input type="password" 
                               id="new_password" 
                               name="new_password" 
                               class="password-form-input" 
                               required 
                               autocomplete="new-password"
                               oninput="checkPasswordStrength()">
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility('new_password')">
                            👁
                        </button>
                    </div>
                    <div class="password-strength" id="passwordStrength"></div>
                </div>

                <div class="password-form-group">
                    <label for="confirm_password">Подтвердите новый пароль:</label>
                    <div class="password-input-wrapper">
                        <input type="password" 
                               id="confirm_password" 
                               name="confirm_password" 
                               class="password-form-input" 
                               required 
                               autocomplete="new-password"
                               oninput="checkPasswordMatch()">
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility('confirm_password')">
                            👁
                        </button>
                    </div>
                    <div class="password-strength" id="passwordMatch"></div>
                </div>

                <div class="password-requirements">
                    <strong>Требования к паролю:</strong>
                    <ul>
                        <li id="reqLength" class="requirement-not-met">
                            <span class="requirement-icon">●</span>
                            Минимум 6 символов
                        </li>
                        <li id="reqMatch" class="requirement-not-met">
                            <span class="requirement-icon">●</span>
                            Пароли совпадают
                        </li>
                    </ul>
                </div>

                <div class="password-form-actions">
                    <button type="button" class="password-btn password-btn-secondary" onclick="closePasswordModal()">
                        Отмена
                    </button>
                    <button type="submit" class="password-btn password-btn-primary" id="submitPasswordBtn" disabled>
                        Сменить пароль
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Функция для показа превью аватара
        function showAvatarPreview(input) {
            const preview = document.getElementById('avatarPreview');
            const mainAvatar = document.getElementById('mainAvatar');
            const uploadContainer = document.getElementById('avatarUploadContainer');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                const allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                const fileExtension = file.name.split('.').pop().toLowerCase();
                
                if (!allowedExtensions.includes(fileExtension)) {
                    alert('Недопустимый тип файла. Разрешены: JPG, JPEG, PNG, GIF');
                    return;
                }
                
                if (file.size > 2 * 1024 * 1024) {
                    alert('Файл слишком большой. Максимальный размер: 2MB');
                    return;
                }
                
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    mainAvatar.style.display = 'none';
                    preview.style.display = 'block';
                    uploadContainer.classList.add('active');
                }
                
                reader.readAsDataURL(file);
            }
        }
        
        function cancelAvatarUpload() {
            const preview = document.getElementById('avatarPreview');
            const mainAvatar = document.getElementById('mainAvatar');
            const uploadContainer = document.getElementById('avatarUploadContainer');
            const fileInput = document.getElementById('avatarInput');
            
            preview.style.display = 'none';
            mainAvatar.style.display = 'block';
            uploadContainer.classList.remove('active');
            fileInput.value = '';
        }
        
        function toggleNameEdit() {
            const nameDisplay = document.getElementById('userNameDisplay');
            const nameForm = document.getElementById('nameEditForm');
            const editNameBtn = document.querySelector('.edit-name-btn');
            const userRole = document.querySelector('.user-role');
            
            if (nameForm.style.display === 'none') {
                nameDisplay.style.display = 'none';
                editNameBtn.style.display = 'none';
                userRole.style.display = 'none';
                nameForm.style.display = 'block';
                resetNameWarning();
            } else {
                nameDisplay.style.display = 'inline';
                editNameBtn.style.display = 'inline-block';
                userRole.style.display = 'block';
                nameForm.style.display = 'none';
            }
        }

        function toggleLoginEdit() {
            const loginDisplay = document.getElementById('userLoginDisplay');
            const loginForm = document.getElementById('loginEditForm');
            const editLoginBtn = document.querySelector('.edit-login-btn');
            
            if (loginForm.style.display === 'none') {
                loginDisplay.style.display = 'none';
                editLoginBtn.style.display = 'none';
                loginForm.style.display = 'block';
                resetLoginWarning();
            } else {
                loginDisplay.style.display = 'inline';
                editLoginBtn.style.display = 'inline-block';
                loginForm.style.display = 'none';
            }
        }

        function toggleEmailEdit() {
            const emailDisplay = document.getElementById('userEmailDisplay');
            const emailForm = document.getElementById('emailEditForm');
            const editEmailBtn = document.querySelector('.edit-email-btn');
            
            if (emailForm.style.display === 'none') {
                emailDisplay.style.display = 'none';
                editEmailBtn.style.display = 'none';
                emailForm.style.display = 'block';
                resetEmailWarning();
            } else {
                emailDisplay.style.display = 'inline';
                editEmailBtn.style.display = 'inline-block';
                emailForm.style.display = 'none';
            }
        }

        function checkNameLength() {
            const nameInput = document.getElementById('userNameInput');
            const currentLengthSpan = document.getElementById('currentLength');
            const nameLengthInfo = document.getElementById('nameLengthInfo');
            const nameLengthWarning = document.getElementById('nameLengthWarning');
            const saveNameBtn = document.getElementById('saveNameBtn');
            
            const currentLength = nameInput.value.length;
            currentLengthSpan.textContent = currentLength;
            
            if (currentLength > 35) {
                nameInput.classList.add('error');
                nameLengthInfo.style.display = 'none';
                nameLengthWarning.style.display = 'block';
                saveNameBtn.disabled = true;
                saveNameBtn.style.opacity = '0.5';
                saveNameBtn.style.cursor = 'not-allowed';
            } else {
                nameInput.classList.remove('error');
                nameLengthInfo.style.display = 'block';
                nameLengthWarning.style.display = 'none';
                saveNameBtn.disabled = false;
                saveNameBtn.style.opacity = '1';
                saveNameBtn.style.cursor = 'pointer';
            }
        }

        function checkLoginLength() {
            const loginInput = document.getElementById('userLoginInput');
            const currentLengthSpan = document.getElementById('loginCurrentLength');
            const loginLengthInfo = document.getElementById('loginLengthInfo');
            const loginLengthWarning = document.getElementById('loginLengthWarning');
            const saveLoginBtn = document.getElementById('saveLoginBtn');
            
            const currentLength = loginInput.value.length;
            currentLengthSpan.textContent = currentLength;
            
            if (currentLength > 35) {
                loginInput.classList.add('error');
                loginLengthInfo.style.display = 'none';
                loginLengthWarning.style.display = 'block';
                saveLoginBtn.disabled = true;
                saveLoginBtn.style.opacity = '0.5';
                saveLoginBtn.style.cursor = 'not-allowed';
            } else {
                loginInput.classList.remove('error');
                loginLengthInfo.style.display = 'block';
                loginLengthWarning.style.display = 'none';
                saveLoginBtn.disabled = false;
                saveLoginBtn.style.opacity = '1';
                saveLoginBtn.style.cursor = 'pointer';
            }
        }

        function checkEmailValidity() {
            const emailInput = document.getElementById('userEmailInput');
            const emailWarning = document.getElementById('emailWarning');
            const saveEmailBtn = document.getElementById('saveEmailBtn');
            
            const email = emailInput.value;
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (!emailRegex.test(email)) {
                emailInput.classList.add('error');
                emailWarning.style.display = 'block';
                saveEmailBtn.disabled = true;
                saveEmailBtn.style.opacity = '0.5';
                saveEmailBtn.style.cursor = 'not-allowed';
            } else {
                emailInput.classList.remove('error');
                emailWarning.style.display = 'none';
                saveEmailBtn.disabled = false;
                saveEmailBtn.style.opacity = '1';
                saveEmailBtn.style.cursor = 'pointer';
            }
        }

        function resetNameWarning() {
            const nameInput = document.getElementById('userNameInput');
            const currentLengthSpan = document.getElementById('currentLength');
            const nameLengthInfo = document.getElementById('nameLengthInfo');
            const nameLengthWarning = document.getElementById('nameLengthWarning');
            const saveNameBtn = document.getElementById('saveNameBtn');
            
            const currentLength = nameInput.value.length;
            currentLengthSpan.textContent = currentLength;
            
            nameInput.classList.remove('error');
            nameLengthInfo.style.display = 'block';
            nameLengthWarning.style.display = 'none';
            saveNameBtn.disabled = false;
            saveNameBtn.style.opacity = '1';
            saveNameBtn.style.cursor = 'pointer';
        }

        function resetLoginWarning() {
            const loginInput = document.getElementById('userLoginInput');
            const currentLengthSpan = document.getElementById('loginCurrentLength');
            const loginLengthInfo = document.getElementById('loginLengthInfo');
            const loginLengthWarning = document.getElementById('loginLengthWarning');
            const loginExistsWarning = document.getElementById('loginExistsWarning');
            const saveLoginBtn = document.getElementById('saveLoginBtn');
            
            const currentLength = loginInput.value.length;
            currentLengthSpan.textContent = currentLength;
            
            loginInput.classList.remove('error');
            loginLengthInfo.style.display = 'block';
            loginLengthWarning.style.display = 'none';
            loginExistsWarning.style.display = 'none';
            saveLoginBtn.disabled = false;
            saveLoginBtn.style.opacity = '1';
            saveLoginBtn.style.cursor = 'pointer';
        }

        function resetEmailWarning() {
            const emailInput = document.getElementById('userEmailInput');
            const emailWarning = document.getElementById('emailWarning');
            const saveEmailBtn = document.getElementById('saveEmailBtn');
            
            emailInput.classList.remove('error');
            emailWarning.style.display = 'none';
            saveEmailBtn.disabled = false;
            saveEmailBtn.style.opacity = '1';
            saveEmailBtn.style.cursor = 'pointer';
        }

        // Функции для работы с модальным окном смены пароля
        function openPasswordModal() {
            const modal = document.getElementById('passwordModal');
            modal.classList.remove('hiding');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
            
            // Сброс формы при открытии
            document.getElementById('passwordChangeForm').reset();
            resetPasswordRequirements();
            
            // Фокус на первое поле
            setTimeout(() => {
                document.getElementById('current_password').focus();
            }, 400);
        }

        function closePasswordModal() {
            const modal = document.getElementById('passwordModal');
            modal.classList.remove('show');
            modal.classList.add('hiding');
            document.body.style.overflow = 'auto';
            
            // Удаляем класс hiding после завершения анимации
            setTimeout(() => {
                modal.classList.remove('hiding');
            }, 400);
        }

        function togglePasswordVisibility(inputId) {
            const input = document.getElementById(inputId);
            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
        }

        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthElement = document.getElementById('passwordStrength');
            const reqLength = document.getElementById('reqLength');
            
            let strength = '';
            let strengthClass = '';
            
            if (password.length === 0) {
                strength = '';
                strengthClass = '';
            } else if (password.length < 6) {
                strength = 'Слабый';
                strengthClass = 'password-weak';
                reqLength.className = 'requirement-not-met';
            } else {
                strength = 'Хороший';
                strengthClass = 'password-strong';
                reqLength.className = 'requirement-met';
            }
            
            strengthElement.textContent = strength;
            strengthElement.className = 'password-strength ' + strengthClass;
            
            checkAllRequirements();
        }

        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchElement = document.getElementById('passwordMatch');
            const reqMatch = document.getElementById('reqMatch');
            
            if (confirmPassword.length === 0) {
                matchElement.textContent = '';
                reqMatch.className = 'requirement-not-met';
            } else if (password === confirmPassword) {
                matchElement.textContent = 'Пароли совпадают';
                matchElement.className = 'password-strength password-strong';
                reqMatch.className = 'requirement-met';
            } else {
                matchElement.textContent = 'Пароли не совпадают';
                matchElement.className = 'password-strength password-weak';
                reqMatch.className = 'requirement-not-met';
            }
            
            checkAllRequirements();
        }

        function checkAllRequirements() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const submitBtn = document.getElementById('submitPasswordBtn');
            
            const isLengthValid = password.length >= 6;
            const isMatchValid = password === confirmPassword && password.length > 0;
            
            if (isLengthValid && isMatchValid) {
                submitBtn.disabled = false;
            } else {
                submitBtn.disabled = true;
            }
        }

        function resetPasswordRequirements() {
            document.getElementById('passwordStrength').textContent = '';
            document.getElementById('passwordMatch').textContent = '';
            document.getElementById('reqLength').className = 'requirement-not-met';
            document.getElementById('reqMatch').className = 'requirement-not-met';
            document.getElementById('submitPasswordBtn').disabled = true;
        }

        // Анимация уведомлений
        document.addEventListener('DOMContentLoaded', () => {
            const notifications = document.querySelectorAll('.notification');
            notifications.forEach((notification, index) => {
                setTimeout(() => {
                    notification.classList.add('show');
                    
                    setTimeout(() => {
                        notification.classList.remove('show');
                        notification.classList.add('hide');
                        
                        setTimeout(() => {
                            notification.remove();
                        }, 400);
                    }, 10000);
                    
                }, index * 200);
            });

            document.querySelectorAll('.notification-close').forEach(closeBtn => {
                closeBtn.addEventListener('click', function() {
                    const notification = this.parentElement;
                    notification.classList.remove('show');
                    notification.classList.add('hide');
                    
                    setTimeout(() => {
                        notification.remove();
                    }, 400);
                });
            });
        });

        // Инициализация при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            const nameInput = document.getElementById('userNameInput');
            if (nameInput) {
                nameInput.addEventListener('input', checkNameLength);
            }
            
            const loginInput = document.getElementById('userLoginInput');
            if (loginInput) {
                loginInput.addEventListener('input', checkLoginLength);
            }
            
            const emailInput = document.getElementById('userEmailInput');
            if (emailInput) {
                emailInput.addEventListener('input', checkEmailValidity);
            }
        });

        // Закрытие модального окна при клике вне его
        document.getElementById('passwordModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePasswordModal();
            }
        });

        // Закрытие модального окна при нажатии Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePasswordModal();
            }
        });

        // Предотвращение отправки формы при нажатии Enter вне полей ввода
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.tagName !== 'INPUT') {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>