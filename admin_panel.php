<?php
session_start();

// Проверка авторизации и прав администратора
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php");
    exit();
}

// Подключаем базу данных
include 'connect.php';

// Проверяем, является ли пользователь администратором
$user_id = $_SESSION['user_id'];
$stmt = $mysqli->prepare("SELECT Role_ID FROM Users WHERE ID = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Разрешаем доступ только администраторам (Role_ID = 2) и главным администраторам (Role_ID = 4)
if (!$user || ($user['Role_ID'] != 2 && $user['Role_ID'] != 4)) {
    header("Location: main.php");
    exit();
}

// Определяем права доступа
$is_main_admin = ($user['Role_ID'] == 4); // Главный администратор
$is_admin = ($user['Role_ID'] == 2); // Обычный администратор

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['user_id'])) {
        $target_user_id = intval($_POST['user_id']);
        $action = $_POST['action'];
        
        // Защита от изменения собственного аккаунта
        if ($target_user_id == $user_id) {
            header("Location: admin_panel.php?error=self_modification");
            exit();
        }
        
        switch ($action) {
            case 'ban':
                $update_stmt = $mysqli->prepare("UPDATE Users SET Ban = 1 WHERE ID = ?");
                $update_stmt->bind_param("i", $target_user_id);
                if ($update_stmt->execute()) {
                    header("Location: admin_panel.php?success=user_banned");
                } else {
                    header("Location: admin_panel.php?error=ban_failed");
                }
                $update_stmt->close();
                exit();
                
            case 'unban':
                $update_stmt = $mysqli->prepare("UPDATE Users SET Ban = 0 WHERE ID = ?");
                $update_stmt->bind_param("i", $target_user_id);
                if ($update_stmt->execute()) {
                    header("Location: admin_panel.php?success=user_unbanned");
                } else {
                    header("Location: admin_panel.php?error=unban_failed");
                }
                $update_stmt->close();
                exit();
                
            case 'delete':
                $delete_stmt = $mysqli->prepare("DELETE FROM Users WHERE ID = ?");
                $delete_stmt->bind_param("i", $target_user_id);
                if ($delete_stmt->execute()) {
                    header("Location: admin_panel.php?success=user_deleted");
                } else {
                    header("Location: admin_panel.php?error=delete_failed");
                }
                $delete_stmt->close();
                exit();
                
            case 'change_role':
                // Проверяем, может ли текущий пользователь изменять роли
                if (!$is_main_admin) { // Если не главный администратор
                    header("Location: admin_panel.php?error=no_permission");
                    exit();
                }
                
                if (isset($_POST['new_role'])) {
                    $new_role = intval($_POST['new_role']);
                    $update_stmt = $mysqli->prepare("UPDATE Users SET Role_ID = ? WHERE ID = ?");
                    $update_stmt->bind_param("ii", $new_role, $target_user_id);
                    if ($update_stmt->execute()) {
                        header("Location: admin_panel.php?success=role_changed");
                    } else {
                        header("Location: admin_panel.php?error=role_change_failed");
                    }
                    $update_stmt->close();
                    exit();
                }
                break;
        }
    }
}

// Обработка создания бэкапа из быстрых действий
if (isset($_GET['backup_full'])) {
    // Перенаправляем на страницу бэкапа с параметром
    header("Location: backup.php?quick_action=full");
    exit();
}

// Получаем список пользователей (только Role_ID 1, 2, 3)
$users_stmt = $mysqli->prepare("
    SELECT u.ID, u.Login, u.Date_of_reg, u.Date_of_change, u.Ban, u.Role_ID, u.Name, r.Name as Role_Name 
    FROM Users u 
    LEFT JOIN Role r ON u.Role_ID = r.ID 
    WHERE u.Role_ID IN (1, 2, 3) 
    ORDER BY u.Role_ID, u.Date_of_reg DESC
");
$users_stmt->execute();
$users_result = $users_stmt->get_result();
$users = [];
while ($row = $users_result->fetch_assoc()) {
    $users[] = $row;
}
$users_stmt->close();

// Получаем список ролей для выпадающего списка
$roles_stmt = $mysqli->prepare("SELECT ID, Name FROM Role WHERE ID IN (1, 2, 3) ORDER BY ID");
$roles_stmt->execute();
$roles_result = $roles_stmt->get_result();
$roles = [];
while ($row = $roles_result->fetch_assoc()) {
    $roles[] = $row;
}
$roles_stmt->close();

// Определяем, может ли текущий пользователь изменять роли
$can_change_roles = $is_main_admin; // Только главный администратор

// Получаем статистику для информационных карточек
$total_users = count($users);
$banned_count = 0;
$admin_count = 0;
$moderator_count = 0;

foreach ($users as $user_item) {
    if ($user_item['Ban'] == 1) $banned_count++;
    if ($user_item['Role_ID'] == 2) $admin_count++;
    if ($user_item['Role_ID'] == 3) $moderator_count++;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель - Военный справочник</title>
    <link rel="stylesheet" href="admin_panel.css">
</head>
<body>
    <div class="top-bar">
        <nav class="nav-menu">
            <div class="nav-item">
                <a href="main.php" class="nav-link">Главная</a>
            </div>
        </nav>
        <div class="admin-title">
            <h1>Панель администратора</h1>
        </div>
    </div>

    <div class="auth-section">
        <?php if (isset($_SESSION['user_id'])): ?>
            <!-- Если пользователь авторизован -->
            <div class="user-menu">
                <div class="user-btn">
                    <?php echo htmlspecialchars($_SESSION['user_login']); ?>
                </div>
                <div class="user-dropdown">
                    <a href="account.php" class="user-item">Перейти в профиль</a>
                    <a href="auth.php?logout=true" class="user-item">Выйти из аккаунта</a>
                </div>
            </div>
        <?php else: ?>
            <!-- Если пользователь не авторизован -->
            <a href="auth.php?register=true" class="auth-btn">Регистрация</a>
            <a href="auth.php" class="auth-btn">Авторизация</a>
        <?php endif; ?>
    </div>

    <!-- Контейнер для уведомлений -->
    <div class="notification-container" id="notificationContainer">
        <?php if (isset($_GET['success'])): ?>
            <div class="notification notification-success" data-type="success" data-duration="8000">
                <span class="notification-close" onclick="closeNotification(this.parentElement)">×</span>
                <?php
                $successMessages = [
                    'user_banned' => '✅ Пользователь заблокирован',
                    'user_unbanned' => '✅ Пользователь разблокирован',
                    'user_deleted' => '✅ Пользователь удален',
                    'role_changed' => '✅ Роль пользователя изменена',
                    'backup_database' => '✅ Бэкап базы данных успешно создан!',
                    'backup_files' => '✅ Бэкап файлов успешно создан!',
                    'backup_full' => '✅ Полный бэкап успешно создан!'
                ];
                echo $successMessages[$_GET['success']] ?? 'Операция выполнена успешно';
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="notification notification-error" data-type="error" data-duration="8000">
                <span class="notification-close" onclick="closeNotification(this.parentElement)">×</span>
                <?php
                $errorMessages = [
                    'self_modification' => '❌ Нельзя изменять свой собственный аккаунт',
                    'ban_failed' => '❌ Ошибка блокировки пользователя',
                    'unban_failed' => '❌ Ошибка разблокировки пользователя',
                    'delete_failed' => '❌ Ошибка удаления пользователя',
                    'role_change_failed' => '❌ Ошибка изменения роли пользователя',
                    'no_permission' => '❌ Недостаточно прав для изменения ролей',
                    'backup_failed' => '❌ Ошибка при создании бэкапа'
                ];
                echo $errorMessages[$_GET['error']] ?? 'Произошла ошибка';
                ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно для подтверждения бана -->
    <div id="banModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Подтверждение блокировки</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <p class="modal-text">Вы действительно хотите заблокировать пользователя?</p>
                <div class="user-info">
                    <strong>Логин:</strong> <span id="banUserLogin"></span><br>
                    <strong>Имя:</strong> <span id="banUserName"></span><br>
                    <strong>Роль:</strong> <span id="banUserRole"></span>
                </div>
                <p class="modal-warning">
                    Пользователь не сможет войти в систему до разблокировки.
                </p>
            </div>
            <div class="modal-buttons">
                <form id="banForm" method="POST" style="display: none;">
                    <input type="hidden" name="user_id" id="banUserId">
                    <input type="hidden" name="action" value="ban">
                </form>
                <button id="confirmBan" class="modal-btn confirm-btn">Заблокировать</button>
                <button id="cancelBan" class="modal-btn cancel-btn">Отмена</button>
            </div>
        </div>
    </div>

    <!-- Модальное окно для подтверждения создания полного бэкапа -->
    <div id="backupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Подтверждение создания бэкапа</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <p class="modal-text">Вы действительно хотите создать полный бэкап системы?</p>
                <div class="user-info">
                    <strong>Будет создана резервная копия:</strong><br>
                    • Базы данных<br>
                    • Файлов системы<br>
                    • Конфигураций
                </div>
                <p class="modal-warning">
                    Процесс может занять несколько минут в зависимости от размера данных.
                </p>
            </div>
            <div class="modal-buttons">
                <a href="admin_panel.php?backup_full=1" id="confirmBackup" class="modal-btn confirm-btn">Создать бэкап</a>
                <button id="cancelBackup" class="modal-btn cancel-btn">Отмена</button>
            </div>
        </div>
    </div>

    <div class="main-content">
        <!-- Панель управления администратора -->
        <div class="admin-controls">
            <a href="logging.php" class="admin-control-btn">
                📊 Просмотр логов
            </a>
            <a href="backup.php" class="admin-control-btn">
                💾 Резервное копирование
            </a>
            <?php if ($is_main_admin): ?>
                <a href="restore.php" class="admin-control-btn">
                    🔄 Восстановление
                </a>
            <?php else: ?>
                <span class="admin-control-btn disabled" title="Доступно только главному администратору">
                    🔒 Восстановление
                </span>
            <?php endif; ?>
            <a href="stats.php" class="admin-control-btn">
                📈 Статистика системы
            </a>
            <!-- НОВАЯ КНОПКА: Перейти к эмулятору -->
            <a href="emulator.php" class="admin-control-btn">
                🔧 Эмулятор ФИО
            </a>
        </div>

        <!-- Секция взаимодействия с пользователями -->
        <div class="users-section">
            <h2 class="section-title">Управление пользователями</h2>
            <div class="users-table-container">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>Логин</th>
                            <th>Имя</th>
                            <th>Дата регистрации</th>
                            <th>Последнее изменение</th>
                            <th>Статус</th>
                            <th>Роль</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="7" class="no-data">
                                    📭 Пользователи не найдены
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user_item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user_item['Login']); ?></td>
                                    <td><?php echo htmlspecialchars($user_item['Name'] ?? 'Не указано'); ?></td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($user_item['Date_of_reg'])); ?></td>
                                    <td><?php echo $user_item['Date_of_change'] ? date('d.m.Y H:i', strtotime($user_item['Date_of_change'])) : 'Не менялся'; ?></td>
                                    <td>
                                        <span class="<?php echo $user_item['Ban'] == 1 ? 'status-banned' : 'status-active'; ?>">
                                            <?php if ($user_item['Ban'] == 1): ?>
                                                🔴 Заблокирован
                                            <?php else: ?>
                                                🟢 Активен
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="role-badge">
                                            <?php echo htmlspecialchars($user_item['Role_Name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($user_item['Ban'] == 0): ?>
                                                <button type="button" class="action-btn" 
                                                        onclick="openBanModal(
                                                            <?php echo $user_item['ID']; ?>, 
                                                            '<?php echo htmlspecialchars($user_item['Login']); ?>', 
                                                            '<?php echo htmlspecialchars($user_item['Name'] ?? 'Не указано'); ?>', 
                                                            '<?php echo htmlspecialchars($user_item['Role_Name']); ?>'
                                                        )">
                                                    🚫 Забанить
                                                </button>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $user_item['ID']; ?>">
                                                    <input type="hidden" name="action" value="unban">
                                                    <button type="submit" class="action-btn" onclick="return confirm('Разблокировать пользователя?')">
                                                        ✅ Разбанить
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($can_change_roles): ?>
                                                <form method="POST" class="role-select-form">
                                                    <input type="hidden" name="user_id" value="<?php echo $user_item['ID']; ?>">
                                                    <input type="hidden" name="action" value="change_role">
                                                    <select name="new_role" class="role-select" onchange="this.form.submit()">
                                                        <?php foreach ($roles as $role): ?>
                                                            <option value="<?php echo $role['ID']; ?>" <?php echo $user_item['Role_ID'] == $role['ID'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($role['Name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </form>
                                            <?php else: ?>
                                                <select class="role-select" disabled title="Недостаточно прав для изменения ролей">
                                                    <option selected><?php echo htmlspecialchars($user_item['Role_Name']); ?></option>
                                                </select>
                                            <?php endif; ?>

                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="user_id" value="<?php echo $user_item['ID']; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit" class="action-btn" onclick="return confirm('Удалить пользователя? Это действие нельзя отменить!')">
                                                    🗑️ Удалить
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Дополнительная информация -->
        <div class="admin-info">
            <div class="info-card">
                <h3>📊 Статистика системы</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Всего пользователей:</span>
                        <span class="info-value"><?php echo $total_users; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Заблокированных:</span>
                        <span class="info-value"><?php echo $banned_count; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Администраторов:</span>
                        <span class="info-value"><?php echo $admin_count; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Модераторов:</span>
                        <span class="info-value"><?php echo $moderator_count; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Обычных пользователей:</span>
                        <span class="info-value"><?php echo $total_users - $admin_count - $moderator_count; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Активных:</span>
                        <span class="info-value"><?php echo $total_users - $banned_count; ?></span>
                    </div>
                </div>
            </div>
            
            <div class="info-card">
                <h3>⚡ Быстрые действия</h3>
                <div class="quick-actions">
                    <a href="#" class="quick-btn" onclick="openBackupModal(); return false;">
                        💾 Создать полный бэкап
                    </a>
                    <a href="logging.php" class="quick-btn">
                        📋 Посмотреть логи
                    </a>
                    <a href="stats.php" class="quick-btn">
                        📈 Общая статистика
                    </a>
                    <a href="backup.php" class="quick-btn">
                        🗂️ Управление бэкапами
                    </a>
                    <!-- НОВАЯ КНОПКА: Перейти к эмулятору -->
                    <a href="emulator.php" class="quick-btn">
                        🔧 Эмулятор ФИО
                    </a>
                    <?php if ($is_main_admin): ?>
                        <a href="restore.php" class="quick-btn">
                            🔄 Восстановление
                        </a>
                    <?php else: ?>
                        <span class="quick-btn disabled" title="Доступно только главному администратору">
                            🔒 Восстановление
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-card">
                <h3>🔧 Системная информация</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Текущий пользователь:</span>
                        <span class="info-value"><?php echo htmlspecialchars($_SESSION['user_login']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Ваша роль:</span>
                        <span class="info-value">
                            <?php 
                            $roleNames = [1 => 'Пользователь', 2 => 'Администратор', 3 => 'Модератор', 4 => 'Главный администратор'];
                            echo $roleNames[$user['Role_ID']] ?? 'Неизвестно';
                            ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Дата входа:</span>
                        <span class="info-value"><?php echo date('d.m.Y H:i'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Права:</span>
                        <span class="info-value">
                            <?php 
                            if ($user['Role_ID'] == 4) {
                                echo 'Полные (главный администратор)';
                            } elseif ($user['Role_ID'] == 2) {
                                echo 'Администратор (ограниченные)';
                            } else {
                                echo 'Ограниченные';
                            }
                            ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Доступ к восстановлению:</span>
                        <span class="info-value">
                            <?php echo $is_main_admin ? '✅ Разрешено' : '❌ Запрещено'; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Изменение ролей:</span>
                        <span class="info-value">
                            <?php echo $is_main_admin ? '✅ Разрешено' : '❌ Запрещено'; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Анимация уведомлений
        document.addEventListener('DOMContentLoaded', () => {
            const notifications = document.querySelectorAll('.notification');
            notifications.forEach((notification, index) => {
                setTimeout(() => {
                    notification.classList.add('show');
                    
                    // Автоматическое скрытие через указанное время
                    const duration = notification.getAttribute('data-duration') || 4000;
                    setTimeout(() => {
                        closeNotification(notification);
                    }, parseInt(duration));
                    
                }, index * 200);
            });

            // Закрытие при клике на крестик
            document.querySelectorAll('.notification-close').forEach(closeBtn => {
                closeBtn.addEventListener('click', function() {
                    closeNotification(this.parentElement);
                });
            });
        });

        // Функция закрытия уведомления
        function closeNotification(notification) {
            notification.classList.remove('show');
            notification.classList.add('hide');
            
            setTimeout(() => {
                notification.remove();
                
                // Убираем параметры из URL
                const url = new URL(window.location);
                url.searchParams.delete('success');
                url.searchParams.delete('error');
                window.history.replaceState({}, '', url);
            }, 400);
        }

        // Функции для модального окна бана
        function openBanModal(userId, userLogin, userName, userRole) {
            document.getElementById('banUserId').value = userId;
            document.getElementById('banUserLogin').textContent = userLogin;
            document.getElementById('banUserName').textContent = userName;
            document.getElementById('banUserRole').textContent = userRole;
            
            const modal = document.getElementById('banModal');
            modal.style.display = 'block';
            
            // Добавляем анимацию появления
            setTimeout(() => {
                modal.querySelector('.modal-content').style.opacity = '1';
            }, 10);
        }

        function closeBanModal() {
            const modal = document.getElementById('banModal');
            modal.querySelector('.modal-content').style.opacity = '0';
            
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        }

        // Функции для модального окна бэкапа
        function openBackupModal() {
            const modal = document.getElementById('backupModal');
            modal.style.display = 'block';
            
            setTimeout(() => {
                modal.querySelector('.modal-content').style.opacity = '1';
            }, 10);
        }

        function closeBackupModal() {
            const modal = document.getElementById('backupModal');
            modal.querySelector('.modal-content').style.opacity = '0';
            
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        }

        // Обработчики событий для модальных окон
        document.addEventListener('DOMContentLoaded', () => {
            // Модальное окно бана
            const modal = document.getElementById('banModal');
            const closeBtn = document.querySelector('.close-modal');
            const cancelBtn = document.getElementById('cancelBan');
            const confirmBtn = document.getElementById('confirmBan');
            const banForm = document.getElementById('banForm');

            // Закрытие модального окна бана
            closeBtn.addEventListener('click', closeBanModal);
            cancelBtn.addEventListener('click', closeBanModal);

            // Подтверждение бана
            confirmBtn.addEventListener('click', () => {
                banForm.submit();
            });

            // Модальное окно бэкапа
            const backupModal = document.getElementById('backupModal');
            const backupCloseBtn = backupModal.querySelector('.close-modal');
            const backupCancelBtn = document.getElementById('cancelBackup');
            const confirmBackupBtn = document.getElementById('confirmBackup');

            // Закрытие модального окна бэкапа
            backupCloseBtn.addEventListener('click', closeBackupModal);
            backupCancelBtn.addEventListener('click', closeBackupModal);

            // Обработчик для кнопки создания бэкапа
            confirmBackupBtn.addEventListener('click', function(e) {
                // Показываем уведомление о начале процесса
                showTemporaryNotification('🔄 Создание полного бэкапа...', 'success', 3000);
                
                // Закрываем модальное окно
                closeBackupModal();
                
                // Даем время на анимацию перед переходом
                setTimeout(() => {
                    window.location.href = this.href;
                }, 500);
            });

            // Закрытие при клике вне модальных окон
            window.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeBanModal();
                }
                if (event.target === backupModal) {
                    closeBackupModal();
                }
            });

            // Закрытие при нажатии Escape
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    if (modal.style.display === 'block') {
                        closeBanModal();
                    }
                    if (backupModal.style.display === 'block') {
                        closeBackupModal();
                    }
                }
            });
        });

        // Анимация для кнопок управления
        document.addEventListener('DOMContentLoaded', () => {
            const controlButtons = document.querySelectorAll('.admin-control-btn');
            controlButtons.forEach((btn, index) => {
                setTimeout(() => {
                    btn.style.opacity = '1';
                    btn.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Анимация для карточек информации
            const infoCards = document.querySelectorAll('.info-card');
            infoCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 150 + 300);
            });
        });

        // Функция для показа временного уведомления
        function showTemporaryNotification(message, type = 'success', duration = 8000) {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.setAttribute('data-duration', duration);
            
            notification.innerHTML = `
                <span class="notification-close" onclick="closeNotification(this.parentElement)">×</span>
                ${message}
            `;
            
            container.appendChild(notification);
            
            // Анимация появления
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            // Автоматическое скрытие
            setTimeout(() => {
                closeNotification(notification);
            }, duration);
        }

        // Автоматическое обновление времени
        function updateCurrentTime() {
            const now = new Date();
            const timeElement = document.querySelector('.info-item:nth-child(3) .info-value');
            if (timeElement) {
                timeElement.textContent = now.toLocaleString('ru-RU', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }
        }

        // Обновляем время каждую минуту
        setInterval(updateCurrentTime, 60000);
    </script>
</body>
</html>