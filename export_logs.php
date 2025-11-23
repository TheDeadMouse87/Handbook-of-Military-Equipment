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

// Получаем параметры фильтров
$action_filter = isset($_GET['action']) ? $_GET['action'] : '';
$table_filter = isset($_GET['table']) ? $_GET['table'] : '';
$user_filter = isset($_GET['user']) ? $_GET['user'] : '';

// Строим запрос с учетом фильтров
$query = "
    SELECT l.*, u.Login as User_Login 
    FROM Logs l 
    LEFT JOIN Users u ON l.User_ID = u.ID 
    WHERE l.User_ID IS NOT NULL
";

$params = [];
$types = '';

if ($action_filter) {
    $query .= " AND l.Action_Type = ?";
    $params[] = $action_filter;
    $types .= 's';
}

if ($table_filter) {
    $query .= " AND l.Table_Name = ?";
    $params[] = $table_filter;
    $types .= 's';
}

if ($user_filter) {
    $query .= " AND l.User_ID = ?";
    $params[] = $user_filter;
    $types .= 'i';
}

$query .= " ORDER BY l.Created_At DESC";

// Подготавливаем и выполняем запрос
$stmt = $mysqli->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
}
$stmt->close();

// Функции для форматирования (такие же как в logging.php)
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

// Создаем CSV файл
$filename = 'logs_export_' . date('Y-m-d_H-i-s') . '.csv';

// Устанавливаем заголовки для скачивания
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Создаем output stream
$output = fopen('php://output', 'w');

// Добавляем BOM для корректного отображения кириллицы в Excel
fputs($output, "\xEF\xBB\xBF");

// Заголовки CSV
$headers = [
    'Дата/Время',
    'Пользователь',
    'Действие', 
    'Таблица',
    'ID записи',
    'Описание',
    'Старые значения',
    'Новые значения',
    'IP-адрес',
    'User Agent'
];

// Исправленный вызов fputcsv с явным указанием escape параметра
fputcsv($output, $headers, ';', '"', '\\');

// Данные
foreach ($logs as $log) {
    // Форматируем старые значения
    $old_values = '';
    if ($log['Old_Values']) {
        $old_data = json_decode($log['Old_Values'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($old_data)) {
            $old_values = json_encode($old_data, JSON_UNESCAPED_UNICODE);
        } else {
            $old_values = $log['Old_Values'];
        }
    }

    // Форматируем новые значения
    $new_values = '';
    if ($log['New_Values']) {
        $new_data = json_decode($log['New_Values'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($new_data)) {
            $new_values = json_encode($new_data, JSON_UNESCAPED_UNICODE);
        } else {
            $new_values = $log['New_Values'];
        }
    }

    $row = [
        $log['Created_At'],
        $log['User_Login'] ?: 'Пользователь удален',
        formatActionType($log['Action_Type']),
        $log['Table_Name'] ? formatTableName($log['Table_Name']) : '',
        $log['Record_ID'] ?: '',
        $log['Description'] ?: '',
        $old_values,
        $new_values,
        $log['IP_Address'],
        $log['User_Agent'] ?: ''
    ];

    // Исправленный вызов fputcsv с явным указанием escape параметра
    fputcsv($output, $row, ';', '"', '\\');
}

fclose($output);
exit();
?>