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

// Получаем логи с пагинацией
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Получаем общее количество записей
$count_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM Logs WHERE User_ID IS NOT NULL");
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_logs = $count_result->fetch_assoc()['total'];
$count_stmt->close();

// Вычисляем общее количество страниц
$total_pages = ceil($total_logs / $limit);

// Получаем логи с информацией о пользователе (только с User_ID)
$logs_stmt = $mysqli->prepare("
    SELECT l.*, u.Login as User_Login 
    FROM Logs l 
    LEFT JOIN Users u ON l.User_ID = u.ID 
    WHERE l.User_ID IS NOT NULL
    ORDER BY l.Created_At DESC 
    LIMIT ? OFFSET ?
");
$logs_stmt->bind_param("ii", $limit, $offset);
$logs_stmt->execute();
$logs_result = $logs_stmt->get_result();
$logs = [];
while ($row = $logs_result->fetch_assoc()) {
    $logs[] = $row;
}
$logs_stmt->close();

// Функции для форматирования логов
function formatActionType($type) {
    $types = [
        'login' => 'Вход в систему',
        'logout' => 'Выход из системы',
        'create' => 'Создание',
        'update' => 'Редактирование',
        'delete' => 'Удаление',
        'ban' => 'Блокировка',
        'unban' => 'Разблокировка',
        'role_change' => 'Изменение роли',
        'favorite_add' => 'Добавление в избранное',
        'favorite_remove' => 'Удаление из избранного'
    ];
    return $types[$type] ?? $type;
}

function formatTableName($table) {
    $tables = [
        'Users' => 'Пользователи',
        'Articles' => 'Статьи',
        'Categories' => 'Категории',
        'Vehicle' => 'Техника',
        'user_favorites' => 'Избранное'
    ];
    return $tables[$table] ?? $table;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Логи системы - Военный справочник</title>
    <link rel="stylesheet" href="admin_panel.css">
</head>
<body>
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
            <h1>Логи системы</h1>
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
        <div class="logs-container">
            <div class="section-title">Журнал событий системы</div>
            
            <!-- Статистика и управление -->
            <div class="logs-stats">
                <div class="stats-info">
                    Всего записей: <strong><?php echo $total_logs; ?></strong> 
                    | Страница <?php echo $page; ?> из <?php echo $total_pages; ?>
                </div>
                <a href="export_logs.php" class="export-btn">Экспорт логов</a>
            </div>
            
            <!-- Фильтры -->
            <div class="filters">
                <div class="filter-group">
                    <span class="filter-label">Тип действия</span>
                    <select class="filter-select" onchange="filterLogs()" id="actionFilter">
                        <option value="">Все действия</option>
                        <option value="login">Вход в систему</option>
                        <option value="logout">Выход из системы</option>
                        <option value="create">Создание</option>
                        <option value="update">Редактирование</option>
                        <option value="delete">Удаление</option>
                        <option value="ban">Блокировка</option>
                        <option value="unban">Разблокировка</option>
                        <option value="role_change">Изменение роли</option>
                        <option value="favorite_add">Добавление в избранное</option>
                        <option value="favorite_remove">Удаление из избранного</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <span class="filter-label">Таблица</span>
                    <select class="filter-select" onchange="filterLogs()" id="tableFilter">
                        <option value="">Все таблицы</option>
                        <option value="Users">Пользователи</option>
                        <option value="Vehicle">Техника</option>
                        <option value="Articles">Статьи</option>
                        <option value="Categories">Категории</option>
                        <option value="user_favorites">Избранное</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <span class="filter-label">Пользователь</span>
                    <select class="filter-select" onchange="filterLogs()" id="userFilter">
                        <option value="">Все пользователи</option>
                        <?php
                        // Получаем список пользователей для фильтра
                        $users_stmt = $mysqli->prepare("SELECT ID, Login FROM Users ORDER BY Login");
                        $users_stmt->execute();
                        $users_result = $users_stmt->get_result();
                        while ($user_row = $users_result->fetch_assoc()): ?>
                            <option value="<?php echo $user_row['ID']; ?>">
                                <?php echo htmlspecialchars($user_row['Login']); ?>
                            </option>
                        <?php endwhile; 
                        $users_stmt->close();
                        ?>
                    </select>
                </div>
            </div>
            
            <?php if (empty($logs)): ?>
                <div class="empty-logs">
                    <p>Логи не найдены</p>
                    <p style="font-size: 0.8rem; margin-top: 0.5rem;">В системе пока не было зарегистрировано действий</p>
                </div>
            <?php else: ?>
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>Дата/Время</th>
                            <th>Пользователь</th>
                            <th>Действие</th>
                            <th>Объект</th>
                            <th>Описание</th>
                            <th>IP-адрес</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr class="log-row" 
                                data-action="<?php echo $log['Action_Type']; ?>"
                                data-table="<?php echo $log['Table_Name']; ?>"
                                data-user="<?php echo $log['User_ID']; ?>">
                                <td>
                                    <div style="font-weight: bold;">
                                        <?php echo date('d.m.Y', strtotime($log['Created_At'])); ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: #6b705a;">
                                        <?php echo date('H:i:s', strtotime($log['Created_At'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($log['User_Login']): ?>
                                        <div style="font-weight: bold;">
                                            <?php echo htmlspecialchars($log['User_Login']); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: #6b705a;">
                                            ID: <?php echo $log['User_ID']; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #6b705a; font-style: italic;">Пользователь удален</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="action-badge action-<?php echo $log['Action_Type']; ?>">
                                        <?php echo formatActionType($log['Action_Type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($log['Table_Name']): ?>
                                        <div style="font-weight: bold;">
                                            <?php echo formatTableName($log['Table_Name']); ?>
                                        </div>
                                        <?php if ($log['Record_ID']): ?>
                                            <div style="font-size: 0.75rem; color: #6b705a;">
                                                ID: <?php echo $log['Record_ID']; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #6b705a;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width: 300px;">
                                    <?php if ($log['Description']): ?>
                                        <div style="margin-bottom: 0.3rem;">
                                            <?php echo htmlspecialchars($log['Description']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($log['Old_Values'] || $log['New_Values']): ?>
                                        <button class="details-toggle" onclick="toggleDetails(this)">
                                            Показать детали
                                        </button>
                                        <div class="log-details" style="display: none;">
                                            <?php if ($log['Old_Values']): ?>
                                                <div style="margin-bottom: 0.8rem;">
                                                    <h4>Старые значения:</h4>
                                                    <div class="json-data"><?php 
                                                        $old_values = json_decode($log['Old_Values'], true);
                                                        if (json_last_error() === JSON_ERROR_NONE && is_array($old_values)) {
                                                            echo htmlspecialchars(json_encode($old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                                        } else {
                                                            echo htmlspecialchars($log['Old_Values']);
                                                        }
                                                    ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($log['New_Values']): ?>
                                                <div>
                                                    <h4>Новые значения:</h4>
                                                    <div class="json-data"><?php 
                                                        $new_values = json_decode($log['New_Values'], true);
                                                        if (json_last_error() === JSON_ERROR_NONE && is_array($new_values)) {
                                                            echo htmlspecialchars(json_encode($new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                                        } else {
                                                            echo htmlspecialchars($log['New_Values']);
                                                        }
                                                    ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-family: monospace; font-size: 0.8rem;">
                                        <?php echo htmlspecialchars($log['IP_Address']); ?>
                                    </div>
                                    <?php if ($log['User_Agent']): ?>
                                        <div style="font-size: 0.7rem; color: #6b705a; margin-top: 0.3rem;">
                                            <?php 
                                            $user_agent = $log['User_Agent'];
                                            if (strlen($user_agent) > 30) {
                                                echo htmlspecialchars(substr($user_agent, 0, 30) . '...');
                                            } else {
                                                echo htmlspecialchars($user_agent);
                                            }
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Пагинация -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>" title="Предыдущая страница">← Назад</a>
                        <?php endif; ?>
                        
                        <?php 
                        // Показываем ограниченное количество страниц вокруг текущей
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1): ?>
                            <a href="?page=1">1</a>
                            <?php if ($start_page > 2): ?>
                                <span>...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span>...</span>
                            <?php endif; ?>
                            <a href="?page=<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a>
                        <?php endif; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>" title="Следующая страница">Вперед →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Функция для переключения отображения деталей
        function toggleDetails(button) {
            const details = button.nextElementSibling;
            const isVisible = details.style.display === 'block';
            
            if (isVisible) {
                details.style.display = 'none';
                button.textContent = 'Показать детали';
            } else {
                details.style.display = 'block';
                button.textContent = 'Скрыть детали';
            }
        }
        
        // Функция для фильтрации логов
        function filterLogs() {
            const actionFilter = document.getElementById('actionFilter').value;
            const tableFilter = document.getElementById('tableFilter').value;
            const userFilter = document.getElementById('userFilter').value;
            
            const rows = document.querySelectorAll('.log-row');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const actionMatch = !actionFilter || row.getAttribute('data-action') === actionFilter;
                const tableMatch = !tableFilter || row.getAttribute('data-table') === tableFilter;
                const userMatch = !userFilter || row.getAttribute('data-user') === userFilter;
                
                if (actionMatch && tableMatch && userMatch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Показываем сообщение если нет результатов
            const tbody = document.querySelector('.logs-table tbody');
            let noResults = tbody.querySelector('.no-results');
            
            if (visibleCount === 0) {
                if (!noResults) {
                    noResults = document.createElement('tr');
                    noResults.className = 'no-results';
                    noResults.innerHTML = `
                        <td colspan="6" style="text-align: center; padding: 2rem; color: #6b705a; font-style: italic;">
                            Нет записей, соответствующих выбранным фильтрам
                        </td>
                    `;
                    tbody.appendChild(noResults);
                }
            } else if (noResults) {
                noResults.remove();
            }
        }
        
        // Восстанавливаем значения фильтров из URL параметров
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            
            if (urlParams.has('action')) {
                document.getElementById('actionFilter').value = urlParams.get('action');
            }
            if (urlParams.has('table')) {
                document.getElementById('tableFilter').value = urlParams.get('table');
            }
            if (urlParams.has('user')) {
                document.getElementById('userFilter').value = urlParams.get('user');
            }
            
            // Применяем фильтры при загрузке если есть параметры
            if (urlParams.has('action') || urlParams.has('table') || urlParams.has('user')) {
                filterLogs();
            }
            
            // Автоматически раскрываем детали если в URL есть параметр details
            if (urlParams.has('details')) {
                const detailsButtons = document.querySelectorAll('.details-toggle');
                detailsButtons.forEach(button => {
                    button.click();
                });
            }
        });
        
        // Функция для экспорта фильтрованных данных
        function exportFilteredLogs() {
            const actionFilter = document.getElementById('actionFilter').value;
            const tableFilter = document.getElementById('tableFilter').value;
            const userFilter = document.getElementById('userFilter').value;
            
            let exportUrl = 'export_logs.php?';
            const params = [];
            
            if (actionFilter) params.push(`action=${encodeURIComponent(actionFilter)}`);
            if (tableFilter) params.push(`table=${encodeURIComponent(tableFilter)}`);
            if (userFilter) params.push(`user=${encodeURIComponent(userFilter)}`);
            
            if (params.length > 0) {
                exportUrl += params.join('&');
            }
            
            window.location.href = exportUrl;
        }
        
        // Добавляем обработчик для кнопки экспорта
        document.addEventListener('DOMContentLoaded', function() {
            const exportBtn = document.querySelector('.export-btn');
            if (exportBtn) {
                exportBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    exportFilteredLogs();
                });
            }
        });
    </script>
</body>
</html>