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

// Функция для создания бэкапа базы данных
function createBackup($mysqli) {
    $backupDir = 'backups/';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = $backupDir . 'backup_' . $timestamp . '.sql';
    
    // Получаем все таблицы
    $tables = [];
    $result = $mysqli->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
    
    $sqlScript = "-- Backup created on " . date('Y-m-d H:i:s') . "\n\n";
    
    foreach ($tables as $table) {
        // Получаем структуру таблицы
        $sqlScript .= "--\n-- Table structure for table `$table`\n--\n";
        $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n";
        
        $createTable = $mysqli->query("SHOW CREATE TABLE `$table`");
        $row = $createTable->fetch_row();
        $sqlScript .= $row[1] . ";\n\n";
        
        // Получаем данные таблицы
        $sqlScript .= "--\n-- Dumping data for table `$table`\n--\n";
        
        $data = $mysqli->query("SELECT * FROM `$table`");
        while ($row = $data->fetch_assoc()) {
            $columns = array_map(function($col) {
                return "`$col`";
            }, array_keys($row));
            
            $values = array_map(function($value) use ($mysqli) {
                if ($value === null) return 'NULL';
                return "'" . $mysqli->real_escape_string($value) . "'";
            }, array_values($row));
            
            $sqlScript .= "INSERT INTO `$table` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
        }
        $sqlScript .= "\n";
    }
    
    // Сохраняем файл
    if (file_put_contents($backupFile, $sqlScript)) {
        return [
            'success' => true,
            'file' => $backupFile,
            'size' => filesize($backupFile),
            'timestamp' => $timestamp
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Не удалось сохранить файл бэкапа'
        ];
    }
}

// Функция для бэкапа файлов
function backupFiles($sourceDir, $backupDir) {
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = $backupDir . 'files_backup_' . $timestamp . '.zip';
    
    // Создаем ZIP архив
    $zip = new ZipArchive();
    if ($zip->open($backupFile, ZipArchive::CREATE) === TRUE) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourceDir) + 1);
                
                // Пропускаем папку backups
                if (strpos($relativePath, 'backups/') === 0) {
                    continue;
                }
                
                $zip->addFile($filePath, $relativePath);
            }
        }
        
        $zip->close();
        
        return [
            'success' => true,
            'file' => $backupFile,
            'size' => filesize($backupFile),
            'timestamp' => $timestamp
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Не удалось создать ZIP архив'
        ];
    }
}

// Обработка действий
$message = '';
$backupResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['backup_database'])) {
        $backupResult = createBackup($mysqli);
    } elseif (isset($_POST['backup_files'])) {
        $backupResult = backupFiles('.', 'backups/');
    } elseif (isset($_POST['backup_full'])) {
        $dbBackup = createBackup($mysqli);
        $filesBackup = backupFiles('.', 'backups/');
        $backupResult = [
            'success' => $dbBackup['success'] && $filesBackup['success'],
            'database' => $dbBackup,
            'files' => $filesBackup
        ];
    } elseif (isset($_POST['delete_file'])) {
        $filename = $_POST['delete_file'];
        $filepath = 'backups/' . $filename;
        if (file_exists($filepath) && unlink($filepath)) {
            header("Location: backup.php?success=file_deleted");
            exit();
        } else {
            header("Location: backup.php?error=delete_failed");
            exit();
        }
    }
}

// Получаем список существующих бэкапов
$backups = [];
if (is_dir('backups/')) {
    $files = scandir('backups/');
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $filePath = 'backups/' . $file;
            // Проверяем существование файла перед получением информации
            if (file_exists($filePath)) {
                $backups[] = [
                    'name' => $file,
                    'path' => $filePath,
                    'size' => filesize($filePath),
                    'modified' => filemtime($filePath)
                ];
            }
        }
    }
    
    // Сортируем по дате изменения (новые сверху)
    usort($backups, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Резервное копирование - Военный справочник</title>
    <link rel="stylesheet" href="admin_panel.css">
</head>
<body>
    <div id="notification-container" class="notification-container"></div>

    <!-- Модальное окно подтверждения удаления -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Подтверждение удаления</h3>
                <button type="button" class="close-modal" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-text">
                    Вы действительно хотите удалить бэкап <strong id="fileName"></strong>?
                </div>
                <div class="modal-warning">
                    Это действие нельзя отменить!
                </div>
            </div>
            <div class="modal-buttons">
                <form id="deleteForm" method="POST" style="display: inline;">
                    <input type="hidden" name="delete_file" id="deleteFileName">
                    <button type="submit" class="modal-btn confirm-btn">Удалить</button>
                </form>
                <button type="button" class="modal-btn cancel-btn" onclick="closeDeleteModal()">Отмена</button>
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
        </nav>
        <div class="admin-title">
            <h1>Резервное копирование</h1>
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
        <div class="backup-container">
            <div class="backup-actions">
                <div class="backup-card">
                    <h3>Бэкап базы данных</h3>
                    <p>Создает SQL дамп всей базы данных</p>
                    <form method="POST">
                        <button type="submit" name="backup_database" class="backup-btn">
                            Создать бэкап БД
                        </button>
                    </form>
                </div>
                
                <div class="backup-card">
                    <h3>Бэкап файлов</h3>
                    <p>Создает ZIP архив всех файлов проекта</p>
                    <form method="POST">
                        <button type="submit" name="backup_files" class="backup-btn">
                            Создать бэкап файлов
                        </button>
                    </form>
                </div>
                
                <div class="backup-card">
                    <h3>Полный бэкап</h3>
                    <p>Создает бэкап базы данных и файлов</p>
                    <form method="POST">
                        <button type="submit" name="backup_full" class="backup-btn full">
                            Полный бэкап
                        </button>
                    </form>
                </div>
            </div>

            <h2 class="section-title">Существующие бэкапы</h2>
            <?php if (empty($backups)): ?>
                <div class="empty-backups">
                    <div class="backup-icon">📁</div>
                    <p>Бэкапы не найдены</p>
                    <small>Создайте первый бэкап используя кнопки выше</small>
                </div>
            <?php else: ?>
                <div class="backup-list">
                    <div class="backup-item backup-item-header">
                        <div>Имя файла</div>
                        <div>Размер</div>
                        <div>Дата создания</div>
                        <div>Действия</div>
                    </div>
                    <?php foreach ($backups as $backup): ?>
                        <div class="backup-item">
                            <div><?php echo htmlspecialchars($backup['name']); ?></div>
                            <div class="file-size">
                                <?php 
                                if ($backup['size'] > 1024 * 1024) {
                                    echo round($backup['size'] / 1024 / 1024, 2) . ' MB';
                                } else {
                                    echo round($backup['size'] / 1024, 2) . ' KB';
                                }
                                ?>
                            </div>
                            <div><?php echo date('d.m.Y H:i', $backup['modified']); ?></div>
                            <div class="backup-actions-small">
                                <a href="<?php echo $backup['path']; ?>" download class="btn-small btn-download">
                                    Скачать
                                </a>
                                <button type="button" class="btn-small btn-delete" 
                                        onclick="showDeleteModal('<?php echo $backup['name']; ?>')">
                                    Удалить
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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

    // Функции для модального окна удаления
    function showDeleteModal(filename) {
        const modal = document.getElementById('deleteModal');
        const fileNameElement = document.getElementById('fileName');
        const deleteFileNameInput = document.getElementById('deleteFileName');
        
        fileNameElement.textContent = filename;
        deleteFileNameInput.value = filename;
        
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeDeleteModal() {
        const modal = document.getElementById('deleteModal');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    // Закрытие модального окна при клике вне его
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('deleteModal');
        if (event.target === modal) {
            closeDeleteModal();
        }
    });

    // Закрытие модального окна при нажатии Escape
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeDeleteModal();
        }
    });

    // Показ уведомлений при загрузке страницы
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (isset($_GET['success'])): ?>
            <?php
            $successMessages = [
                'file_deleted' => 'Файл бэкапа успешно удален!'
            ];
            $message = $successMessages[$_GET['success']] ?? 'Операция выполнена успешно';
            ?>
            showNotification('<?php echo $message; ?>', 'success');
        <?php endif; ?>

        <?php if ($backupResult && $backupResult['success']): ?>
            <?php if (isset($backupResult['database'])): ?>
                showNotification(
                    '✅ <strong>Полный бэкап успешно создан!</strong><br>' +
                    '📊 База данных: <?php echo basename($backupResult['database']['file']); ?> (<?php echo round($backupResult['database']['size'] / 1024, 2); ?> KB)<br>' +
                    '📁 Файлы: <?php echo basename($backupResult['files']['file']); ?> (<?php echo round($backupResult['files']['size'] / 1024 / 1024, 2); ?> MB)',
                    'success'
                );
            <?php else: ?>
                showNotification(
                    '✅ Бэкап успешно создан:<br><?php echo basename($backupResult['file']); ?> (<?php echo round($backupResult['size'] / 1024, 2); ?> KB)',
                    'success'
                );
            <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <?php
            $errorMessages = [
                'delete_failed' => 'Ошибка удаления файла'
            ];
            $message = $errorMessages[$_GET['error']] ?? 'Произошла ошибка';
            ?>
            showNotification('❌ <?php echo $message; ?>', 'error');
        <?php endif; ?>

        <?php if ($backupResult && !$backupResult['success']): ?>
            showNotification('❌ Ошибка при создании бэкапа: <?php echo $backupResult['error']; ?>', 'error');
        <?php endif; ?>
    });
    </script>
</body>
</html>