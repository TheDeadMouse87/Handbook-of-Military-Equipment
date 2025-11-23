<?php
session_start();
include 'connect.php';

// Проверяем права администратора
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$stmt = $mysqli->prepare("SELECT Role_ID FROM Users WHERE ID = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user || ($user['Role_ID'] != 2 && $user['Role_ID'] != 4)) {
    header("Location: main.php");
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm_restore']) && isset($_FILES['backup_file'])) {
        if ($_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['backup_file']['tmp_name'];
            $fileName = $_FILES['backup_file']['name'];
            $content = file_get_contents($tmpName);
            
            // Отключаем проверку внешних ключей
            $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
            
            // Получаем список всех таблиц и очищаем их
            $tables = [];
            $result = $mysqli->query("SHOW TABLES");
            while ($row = $result->fetch_array()) {
                $tables[] = $row[0];
            }
            
            // Удаляем все таблицы (в правильном порядке для избежания ошибок внешних ключей)
            foreach ($tables as $table) {
                $mysqli->query("DROP TABLE IF EXISTS `$table`");
            }
            
            // Улучшенный парсинг SQL-файла
            $successCount = 0;
            $errorCount = 0;
            $errors = [];
            
            // Удаляем комментарии и разбиваем на запросы
            $content = preg_replace('/--.*$/m', '', $content); // Удаляем однострочные комментарии
            $content = preg_replace('/\/\*.*?\*\//s', '', $content); // Удаляем многострочные комментарии
            
            // Разбиваем на отдельные запросы
            $queries = [];
            $currentQuery = '';
            $inString = false;
            $stringChar = '';
            
            for ($i = 0; $i < strlen($content); $i++) {
                $char = $content[$i];
                
                // Проверяем, находимся ли мы внутри строки
                if (($char == "'" || $char == '"') && ($i == 0 || $content[$i-1] != "\\")) {
                    if (!$inString) {
                        $inString = true;
                        $stringChar = $char;
                    } else if ($char == $stringChar) {
                        $inString = false;
                    }
                }
                
                // Если встречаем точку с запятой и не внутри строки - это конец запроса
                if ($char == ';' && !$inString) {
                    $currentQuery = trim($currentQuery);
                    if (!empty($currentQuery)) {
                        $queries[] = $currentQuery;
                    }
                    $currentQuery = '';
                } else {
                    $currentQuery .= $char;
                }
            }
            
            // Добавляем последний запрос, если он есть
            $lastQuery = trim($currentQuery);
            if (!empty($lastQuery)) {
                $queries[] = $lastQuery;
            }
            
            // Выполняем запросы
            foreach ($queries as $query) {
                $query = trim($query);
                
                // Пропускаем пустые запросы и служебные команды
                if (empty($query) || 
                    preg_match('/^\s*(CREATE DATABASE|USE|SET|DELIMITER)/i', $query) ||
                    strlen($query) < 10) {
                    continue;
                }
                
                if ($mysqli->query($query)) {
                    $successCount++;
                } else {
                    $errorCount++;
                    $errorMsg = $mysqli->error;
                    // Обрезаем длинный запрос для отображения
                    $displayQuery = strlen($query) > 100 ? substr($query, 0, 100) . '...' : $query;
                    $errors[] = "Ошибка: $errorMsg<br>Запрос: " . htmlspecialchars($displayQuery);
                    error_log("Restore error: " . $errorMsg . " in query: " . substr($query, 0, 200));
                }
            }
            
            // Включаем проверку внешних ключей обратно
            $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
            
            if ($errorCount === 0) {
                $message = "✅ База данных успешно восстановлена из файла <strong>$fileName</strong>! Выполнено запросов: $successCount";
                $messageType = 'success';
            } else {
                $message = "⚠️ Восстановление из файла <strong>$fileName</strong> завершено с ошибками. Успешно: $successCount, Ошибок: $errorCount";
                $messageType = 'warning';
            }
        } else {
            $message = "❌ Ошибка загрузки файла. Код ошибки: " . $_FILES['backup_file']['error'];
            $messageType = 'error';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Восстановление - Военный справочник</title>
    <link rel="stylesheet" href="admin_panel.css">
</head>
<body>
    <div id="notification-container" class="notification-container"></div>

    <!-- Модальное окно подтверждения восстановления -->
    <div id="restoreModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">ВНИМАНИЕ! Подтверждение восстановления</h3>
                <button type="button" class="close-modal" onclick="closeRestoreModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-text">
                    <strong>Вы собираетесь восстановить базу данных из бэкапа!</strong>
                </div>
                <div class="user-info">
                    Это действие:<br>
                    • <strong>Удалит все текущие данные</strong><br>
                    • Заменит их данными из бэкапа<br>
                    • Может занять несколько минут<br>
                    • <strong>Не может быть отменено!</strong>
                </div>
                <div class="modal-warning">
                    ⚠️ Убедитесь, что у вас есть свежий бэкап текущего состояния системы!
                </div>
            </div>
            <div class="modal-buttons">
                <form id="restoreForm" method="POST" enctype="multipart/form-data" style="display: inline;">
                    <input type="hidden" name="confirm_restore" value="1">
                    <input type="hidden" name="backup_file_temp" id="backupFileTemp">
                    <button type="submit" class="modal-btn confirm-btn">Продолжить восстановление</button>
                </form>
                <button type="button" class="modal-btn cancel-btn" onclick="closeRestoreModal()">Отмена</button>
            </div>
        </div>
    </div>

    <div class="top-bar">
        <nav class="nav-menu">
            <div class="nav-item">
                <a href="main.php" class="nav-link">Главная</a>
            </div>
            <div class="nav-item">
                <a href="admin_panel.php" class="nav-link">Админ-панель</a>
            </div>
            <div class="nav-item">
                <a href="backup.php" class="nav-link">Бэкап</a>
            </div>
        </nav>
        <div class="admin-title">
            <h1>Восстановление базы данных</h1>
        </div>
    </div>

    <div class="auth-section">
        <?php if (isset($_SESSION['user_id'])): ?>
            <div class="user-menu">
                <div class="user-btn">
                    <?php echo htmlspecialchars($_SESSION['user_login']); ?>
                </div>
                <div class="user-dropdown">
                    <a href="account.php" class="user-item">Перейти в профиль</a>
                    <a href="auth.php?logout=true" class="user-item">Выйти из аккаунта</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="main-content">
        <div class="restore-container">
            <?php if ($message): ?>
                <div class="<?php 
                    echo $messageType === 'success' ? 'success-message' : 
                         ($messageType === 'warning' ? 'warning-box' : 'error-message'); 
                ?>">
                    <?php echo $message; ?>
                    <?php if (isset($errors) && count($errors) > 0): ?>
                        <div class="error-details">
                            <strong>Детали ошибок:</strong>
                            <?php foreach (array_slice($errors, 0, 10) as $error): ?>
                                <div><?php echo $error; ?></div>
                            <?php endforeach; ?>
                            <?php if (count($errors) > 10): ?>
                                <div>... и ещё <?php echo count($errors) - 10; ?> ошибок</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="warning-box">
                <strong>⚠️ Внимание!</strong>
                <p>Восстановление базы данных <strong>УДАЛИТ ВСЕ СУЩЕСТВУЮЩИЕ ДАННЫЕ</strong> и заменит их данными из бэкапа.</p>
                <p>Убедитесь, что у вас есть актуальный бэкап перед продолжением.</p>
            </div>

            <form method="POST" enctype="multipart/form-data" id="uploadForm" class="restore-form">
                <div class="file-input">
                    <label for="backup_file">Выберите файл бэкапа (.sql):</label><br>
                    <input type="file" name="backup_file" id="backup_file" accept=".sql" required>
                </div>
                
                <button type="button" class="restore-btn" id="restoreButton">
                    Восстановить базу данных
                </button>
            </form>
        </div>
    </div>

    <script>
    // Функция для показа уведомлений
    function showNotification(message, type = 'success') {
        const container = document.getElementById('notification-container');
        if (!container) {
            console.error('Notification container not found');
            return;
        }
        
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.setAttribute('data-duration', '8000');
        
        notification.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div style="flex: 1;">${message}</div>
                <button type="button" class="notification-close" onclick="closeNotification(this)">&times;</button>
            </div>
        `;
        
        container.appendChild(notification);
        
        // Анимация появления
        setTimeout(() => {
            notification.classList.add('show');
        }, 100);
        
        // Автоматическое удаление через 8 секунд
        setTimeout(() => {
            closeNotification(notification.querySelector('.notification-close'));
        }, 8000);
    }

    // Функция для закрытия уведомления
    function closeNotification(closeBtn) {
        const notification = closeBtn.closest('.notification');
        if (!notification) return;
        
        notification.classList.remove('show');
        notification.classList.add('hide');
        
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 400);
    }

    // Функции для модального окна восстановления
    function showRestoreModal() {
        const fileInput = document.getElementById('backup_file');
        const fileName = fileInput.files[0] ? fileInput.files[0].name : '';
        
        if (!fileName) {
            showNotification('❌ Пожалуйста, выберите файл бэкапа', 'error');
            return;
        }
        
        const modal = document.getElementById('restoreModal');
        const backupFileTemp = document.getElementById('backupFileTemp');
        
        // Здесь можно добавить логику для временного сохранения файла
        // Пока просто показываем модальное окно
        backupFileTemp.value = fileName;
        
        modal.style.display = 'block';
        modal.classList.add('modal-restore');
        document.body.style.overflow = 'hidden';
    }

    function closeRestoreModal() {
        const modal = document.getElementById('restoreModal');
        modal.style.display = 'none';
        modal.classList.remove('modal-restore');
        document.body.style.overflow = 'auto';
    }

    // Закрытие модального окна при клике вне его
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('restoreModal');
        if (event.target === modal) {
            closeRestoreModal();
        }
    });

    // Закрытие модального окна при нажатии Escape
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeRestoreModal();
        }
    });

    // Обработчик для кнопки восстановления
    document.getElementById('restoreButton').addEventListener('click', showRestoreModal);

    // Обработчик для формы загрузки файла
    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        showRestoreModal();
    });

    // Показ уведомлений при загрузке страницы
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($message): ?>
            showNotification(`<?php echo addslashes($message); ?>`, '<?php echo $messageType ?? 'success'; ?>');
        <?php endif; ?>

        // Показываем предупреждение при загрузке страницы
        showNotification(
            '⚠️ <strong>Внимание!</strong> Восстановление базы данных удалит все существующие данные. Убедитесь, что у вас есть свежий бэкап.',
            'warning'
        );
    });

    // Валидация файла
    document.getElementById('backup_file').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const fileName = file.name.toLowerCase();
            if (!fileName.endsWith('.sql')) {
                showNotification('❌ Пожалуйста, выберите файл с расширением .sql', 'error');
                e.target.value = '';
            } else if (file.size > 50 * 1024 * 1024) { // 50MB limit
                showNotification('❌ Файл слишком большой. Максимальный размер: 50MB', 'error');
                e.target.value = '';
            } else {
                showNotification(`✅ Файл "${file.name}" выбран для восстановления`, 'success');
            }
        }
    });
    </script>
</body>
</html>