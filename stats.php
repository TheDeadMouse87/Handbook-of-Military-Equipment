<?php
session_start();
include 'connect.php';

// Проверка прав администратора
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

// Получаем статистику
// Статистика пользователей
$users_stats = $mysqli->query("
    SELECT 
        COUNT(*) as total_users,
        SUM(Ban = 1) as banned_users,
        SUM(Role_ID = 2) as admins,
        SUM(Role_ID = 3) as moderators,
        SUM(Role_ID = 1) as regular_users
    FROM Users 
    WHERE Role_ID IN (1, 2, 3)
")->fetch_assoc();

// Статистика по типам техники
$vehicles_stats = $mysqli->query("
    SELECT 
        c.Name as class_name,
        COUNT(*) as count
    FROM Vehicle v
    LEFT JOIN Class c ON v.Class_ID = c.ID
    GROUP BY v.Class_ID
")->fetch_all(MYSQLI_ASSOC);

// Статистика активности (только действия пользователей)
$activity_stats = $mysqli->query("
    SELECT 
        DATE(Created_At) as date,
        COUNT(*) as actions
    FROM Logs 
    WHERE Created_At >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND User_ID IS NOT NULL
    GROUP BY DATE(Created_At)
    ORDER BY date DESC
    LIMIT 7
")->fetch_all(MYSQLI_ASSOC);

// Последние действия (только действия пользователей)
$recent_actions = $mysqli->query("
    SELECT l.*, u.Login as user_login
    FROM Logs l
    LEFT JOIN Users u ON l.User_ID = u.ID
    WHERE l.User_ID IS NOT NULL
    ORDER BY l.Created_At DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Статистика - Военный справочник</title>
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
            <h1>Статистика системы</h1>
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
        <div class="stats-container">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $users_stats['total_users']; ?></div>
                    <div class="stat-label">Всего пользователей</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $users_stats['admins']; ?></div>
                    <div class="stat-label">Администраторов</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $users_stats['banned_users']; ?></div>
                    <div class="stat-label">Заблокировано</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">
                        <?php 
                        $total_vehicles = 0;
                        foreach ($vehicles_stats as $stat) {
                            $total_vehicles += $stat['count'];
                        }
                        echo $total_vehicles;
                        ?>
                    </div>
                    <div class="stat-label">Единиц техники</div>
                </div>
            </div>

            <div class="chart-container">
                <h3>📊 Распределение техники по классам</h3>
                <div style="height: 300px; display: flex; align-items: end; gap: 10px; margin-top: 1rem;">
                    <?php foreach ($vehicles_stats as $stat): ?>
                        <div style="text-align: center; flex: 1;">
                            <div style="background: #8b966c; height: <?php echo ($stat['count'] / $total_vehicles) * 200; ?>px; 
                                      border-radius: 4px 4px 0 0;"></div>
                            <div style="font-size: 0.8rem; margin-top: 0.5rem;"><?php echo $stat['class_name']; ?></div>
                            <div style="font-weight: bold;"><?php echo $stat['count']; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="recent-actions">
                <h3>🕒 Последние действия</h3>
                <?php if (empty($recent_actions)): ?>
                    <div style="text-align: center; padding: 2rem; color: #6b705a; font-style: italic;">
                        Нет действий пользователей
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_actions as $action): ?>
                        <div class="action-item">
                            <div>
                                <strong><?php echo htmlspecialchars($action['user_login'] ?? 'Пользователь удален'); ?></strong>
                                - <?php echo htmlspecialchars($action['Description'] ?? $action['Action_Type']); ?>
                            </div>
                            <div>
                                <span class="action-type action-<?php echo $action['Action_Type']; ?>">
                                    <?php echo $action['Action_Type']; ?>
                                </span>
                                <small style="color: #6c757d; margin-left: 1rem;">
                                    <?php echo date('H:i', strtotime($action['Created_At'])); ?>
                                </small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>