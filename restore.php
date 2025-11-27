<?php
session_start();
include 'connect.php';

// Увеличиваем время выполнения и память
set_time_limit(300); // 5 минут
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);

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
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm_restore']) && isset($_FILES['backup_file'])) {
        if ($_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['backup_file']['tmp_name'];
            $fileName = $_FILES['backup_file']['name'];
            
            // Проверяем, что файл действительно SQL
            if (pathinfo($fileName, PATHINFO_EXTENSION) !== 'sql') {
                $message = "❌ Файл должен иметь расширение .sql";
                $messageType = 'error';
            } else {
                // Отключаем проверку внешних ключей и авто-коммит
                $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
                $mysqli->query("SET AUTOCOMMIT = 0");
                $mysqli->query("START TRANSACTION");
                
                try {
                    // Получаем список всех таблиц и очищаем их
                    $tables = [];
                    $result = $mysqli->query("SHOW TABLES");
                    while ($row = $result->fetch_array()) {
                        $tables[] = $row[0];
                    }
                    
                    // Удаляем все таблицы в обратном порядке (для избежания ошибок внешних ключей)
                    foreach (array_reverse($tables) as $table) {
                        $mysqli->query("DROP TABLE IF EXISTS `$table`");
                    }
                    
                    // Восстанавливаем из SQL файла
                    $successCount = 0;
                    $errorCount = 0;
                    $errors = [];
                    
                    // Читаем SQL файл по частям
                    $fileHandle = fopen($tmpName, 'r');
                    if (!$fileHandle) {
                        throw new Exception("Не удалось открыть файл бэкапа");
                    }
                    
                    $currentQuery = '';
                    $inString = false;
                    $stringChar = '';
                    $escapeNext = false;
                    $batchCount = 0;
                    
                    while (!feof($fileHandle)) {
                        $line = fgets($fileHandle);
                        if ($line === false) break;
                        
                        // Пропускаем комментарии
                        $trimmedLine = trim($line);
                        if (empty($trimmedLine) || 
                            strpos($trimmedLine, '--') === 0 || 
                            strpos($trimmedLine, '/*') === 0) {
                            continue;
                        }
                        
                        // Обрабатываем строку посимвольно
                        for ($i = 0; $i < strlen($line); $i++) {
                            $char = $line[$i];
                            
                            if ($escapeNext) {
                                $currentQuery .= $char;
                                $escapeNext = false;
                                continue;
                            }
                            
                            // Обработка экранирования
                            if ($char == '\\') {
                                $escapeNext = true;
                                $currentQuery .= $char;
                                continue;
                            }
                            
                            // Обработка строк
                            if (($char == "'" || $char == '"') && !$inString) {
                                $inString = true;
                                $stringChar = $char;
                                $currentQuery .= $char;
                            } else if (($char == "'" || $char == '"') && $inString && $char == $stringChar) {
                                $inString = false;
                                $currentQuery .= $char;
                            } else if ($char == ';' && !$inString) {
                                // Конец запроса
                                $currentQuery = trim($currentQuery);
                                if (!empty($currentQuery) && strlen($currentQuery) > 10) {
                                    // Пропускаем служебные команды
                                    if (!preg_match('/^\s*(CREATE DATABASE|USE|SET|DELIMITER)/i', $currentQuery)) {
                                        if ($mysqli->query($currentQuery)) {
                                            $successCount++;
                                            $batchCount++;
                                        } else {
                                            $errorCount++;
                                            $errorMsg = $mysqli->error;
                                            $shortQuery = substr($currentQuery, 0, 100);
                                            $errors[] = "Ошибка: " . $errorMsg . " в запросе: " . $shortQuery . "...";
                                            
                                            // Если ошибка критическая - прерываем
                                            if (strpos($errorMsg, 'parse error') !== false || 
                                                strpos($errorMsg, 'syntax error') !== false) {
                                                throw new Exception("Критическая ошибка SQL: " . $errorMsg);
                                            }
                                        }
                                    }
                                    
                                    // Сбрасываем счетчик батча каждые 100 запросов
                                    if ($batchCount >= 100) {
                                        $batchCount = 0;
                                    }
                                }
                                $currentQuery = '';
                            } else {
                                $currentQuery .= $char;
                            }
                        }
                    }
                    
                    fclose($fileHandle);
                    
                    // Обрабатываем последний запрос, если он есть
                    $lastQuery = trim($currentQuery);
                    if (!empty($lastQuery) && strlen($lastQuery) > 10 && 
                        !preg_match('/^\s*(CREATE DATABASE|USE|SET|DELIMITER)/i', $lastQuery)) {
                        if ($mysqli->query($lastQuery)) {
                            $successCount++;
                        } else {
                            $errorCount++;
                            $errorMsg = $mysqli->error;
                            $errors[] = "Ошибка в последнем запросе: " . $errorMsg;
                        }
                    }
                    
                    // Фиксируем транзакцию если нет ошибок
                    if ($errorCount === 0) {
                        $mysqli->query("COMMIT");
                        $message = "✅ База данных успешно восстановлена из файла <strong>$fileName</strong>! Выполнено запросов: $successCount";
                        $messageType = 'success';
                    } else {
                        $mysqli->query("ROLLBACK");
                        $message = "⚠️ Восстановление отменено из-за ошибок. Успешно: $successCount, Ошибок: $errorCount";
                        $messageType = 'error';
                    }
                    
                } catch (Exception $e) {
                    // Откатываем транзакцию при ошибке
                    $mysqli->query("ROLLBACK");
                    $message = "❌ Ошибка восстановления: " . $e->getMessage();
                    $messageType = 'error';
                }
                
                // Включаем проверку внешних ключей и авто-коммит обратно
                $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
                $mysqli->query("SET AUTOCOMMIT = 1");
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
                            <strong>Детали ошибок (первые 5):</strong>
                            <?php foreach (array_slice($errors, 0, 5) as $error): ?>
                                <div><?php echo $error; ?></div>
                            <?php endforeach; ?>
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
    function showNotification(message, type = 'success') {
        const container = document.getElementById('notification-container');
        if (!container) return;
        
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div style="flex: 1;">${message}</div>
                <button type="button" class="notification-close" onclick="closeNotification(this)">&times;</button>
            </div>
        `;
        
        container.appendChild(notification);
        setTimeout(() => notification.classList.add('show'), 100);
        setTimeout(() => closeNotification(notification.querySelector('.notification-close')), 8000);
    }

    function closeNotification(closeBtn) {
        const notification = closeBtn.closest('.notification');
        if (!notification) return;
        notification.classList.remove('show');
        notification.classList.add('hide');
        setTimeout(() => notification.remove(), 400);
    }

    function showRestoreModal() {
        const fileInput = document.getElementById('backup_file');
        const file = fileInput.files[0];
        
        if (!file) {
            showNotification('❌ Пожалуйста, выберите файл бэкапа', 'error');
            return;
        }
        
        if (!file.name.toLowerCase().endsWith('.sql')) {
            showNotification('❌ Файл должен иметь расширение .sql', 'error');
            return;
        }

        // Создаем FormData для передачи файла
        const restoreForm = document.getElementById('restoreForm');
        const existingFileInput = restoreForm.querySelector('input[type="file"]');
        if (existingFileInput) {
            existingFileInput.remove();
        }

        // Создаем новый input для файла в форме модального окна
        const newFileInput = document.createElement('input');
        newFileInput.type = 'file';
        newFileInput.name = 'backup_file';
        newFileInput.style.display = 'none';
        restoreForm.appendChild(newFileInput);

        // Копируем файл используя File constructor
        const newFile = new File([file], file.name, { type: file.type });
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(newFile);
        newFileInput.files = dataTransfer.files;

        document.getElementById('restoreModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeRestoreModal() {
        document.getElementById('restoreModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    window.addEventListener('click', function(event) {
        const modal = document.getElementById('restoreModal');
        if (event.target === modal) closeRestoreModal();
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') closeRestoreModal();
    });

    document.getElementById('restoreButton').addEventListener('click', showRestoreModal);

    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        showRestoreModal();
    });

    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($message): ?>
            showNotification(`<?php echo addslashes($message); ?>`, '<?php echo $messageType ?? 'success'; ?>');
        <?php endif; ?>
    });

    document.getElementById('backup_file').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            if (!file.name.toLowerCase().endsWith('.sql')) {
                showNotification('❌ Пожалуйста, выберите файл с расширением .sql', 'error');
                e.target.value = '';
            } else {
                showNotification(`✅ Файл "${file.name}" выбран для восстановления`, 'success');
            }
        }
    });
    </script>
</body>
</html>