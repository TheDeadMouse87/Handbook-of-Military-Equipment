<?php
session_start();

// Функция для полной очистки от экранирования
function deep_stripslashes($value) {
    if (is_array($value)) {
        return array_map('deep_stripslashes', $value);
    }
    return stripslashes($value);
}

// Очищаем все входные данные
$_POST = deep_stripslashes($_POST);
$_GET = deep_stripslashes($_GET);
$_REQUEST = deep_stripslashes($_REQUEST);

include '../connect.php';

// Подключаем класс Logger
include '../Logger.php';
$logger = new Logger($mysqli);

// Получаем ID ПВО из GET параметра
$pvo_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Определяем страницу для кнопки "Назад" на основе параметра type
$back_url = '../vehicle/pvo.php?type=19'; // значение по умолчанию (ЗРК и ЗРПК)

if (isset($_GET['type'])) {
    $type = intval($_GET['type']);
    $back_url = '../vehicle/pvo.php?type=' . $type;
} elseif (isset($_GET['from']) && $_GET['from'] == 'pvo') {
    // Если пришли из общего списка ПВО
    $back_url = '../vehicle/pvo.php?type=19';
}

if ($pvo_id <= 0) {
    header("Location: " . $back_url);
    exit();
}

// Проверяем права доступа для редактирования
$can_edit_article = false;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $mysqli->prepare("SELECT Role_ID FROM Users WHERE ID = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if ($user && in_array($user['Role_ID'], [2, 3, 4])) {
        $can_edit_article = true;
    }
}

// Обработка обновления данных ПВО
if ($can_edit_article && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_pvo'])) {
    // Получаем текущие данные ПВО ДО обновления для логирования
    $old_data_stmt = $mysqli->prepare("
        SELECT 
            v.Name, v.History, v.Country_ID, v.Weapon_ID, v.War_ID,
            c.Name as CountryName,
            w.Name as WeaponName, w.Type as WeaponType, w.Calibre as WeaponCalibre,
            war.Name as WarPeriod,
            s.Year_of_commissioning, s.Year_of_decommissioning, s.In_service
        FROM Vehicle v
        LEFT JOIN Country c ON v.Country_ID = c.ID
        LEFT JOIN Weapon w ON v.Weapon_ID = w.ID
        LEFT JOIN War war ON v.War_ID = war.ID
        LEFT JOIN Service s ON v.Service_ID = s.ID
        WHERE v.ID = ?
    ");
    $old_data_stmt->bind_param("i", $pvo_id);
    $old_data_stmt->execute();
    $old_data_result = $old_data_stmt->get_result();
    $old_pvo_data = $old_data_result->fetch_assoc();
    $old_data_stmt->close();

    // Получаем данные БЕЗ дополнительного экранирования
    $name = trim($_POST['name'] ?? '');
    $history = trim($_POST['history'] ?? '');
    $country_id = intval($_POST['country_id'] ?? 0);
    $year_commissioning = intval($_POST['year_commissioning'] ?? 0);
    $year_decommissioning = intval($_POST['year_decommissioning'] ?? 0);
    
    // Автоматически определяем статус службы
    $in_service = ($year_decommissioning == 0 || $year_decommissioning > date('Y')) ? 1 : 0;
    
    // Данные вооружения
    $weapon_name = trim($_POST['weapon_name'] ?? '');
    $weapon_type = trim($_POST['weapon_type'] ?? '');
    $weapon_calibre = trim($_POST['weapon_calibre'] ?? '');
    
    // Данные войны
    $war_name = trim($_POST['war_name'] ?? '');
    
    // Начинаем транзакцию
    $mysqli->begin_transaction();
    
    try {
        // Обработка вооружения
        $weapon_id = null;
        if (!empty($weapon_type) || !empty($weapon_calibre) || !empty($weapon_name)) {
            // Экранируем только для запроса к БД
            $weapon_name_escaped = $mysqli->real_escape_string($weapon_name);
            $weapon_type_escaped = $mysqli->real_escape_string($weapon_type);
            $weapon_calibre_escaped = $mysqli->real_escape_string($weapon_calibre);
            
            // Проверяем существование вооружения
            $weapon_check = $mysqli->prepare("SELECT ID FROM Weapon WHERE Type = ? AND Calibre = ? AND Name = ?");
            $weapon_check->bind_param("sss", $weapon_type_escaped, $weapon_calibre_escaped, $weapon_name_escaped);
            $weapon_check->execute();
            $weapon_result = $weapon_check->get_result();
            
            if ($weapon_result->num_rows > 0) {
                $existing_weapon = $weapon_result->fetch_assoc();
                $weapon_id = $existing_weapon['ID'];
            } else {
                // Создаем новое вооружение
                $weapon_insert = $mysqli->prepare("INSERT INTO Weapon (Type, Calibre, Name) VALUES (?, ?, ?)");
                $weapon_insert->bind_param("sss", $weapon_type_escaped, $weapon_calibre_escaped, $weapon_name_escaped);
                $weapon_insert->execute();
                $weapon_id = $weapon_insert->insert_id;
                $weapon_insert->close();
            }
            $weapon_check->close();
        }
        
        // Обработка войны
        $war_id = null;
        if (!empty($war_name)) {
            $war_name_escaped = $mysqli->real_escape_string($war_name);
            
            $war_check = $mysqli->prepare("SELECT ID FROM War WHERE Name = ?");
            $war_check->bind_param("s", $war_name_escaped);
            $war_check->execute();
            $war_result = $war_check->get_result();
            
            if ($war_result->num_rows > 0) {
                $existing_war = $war_result->fetch_assoc();
                $war_id = $existing_war['ID'];
            } else {
                $war_insert = $mysqli->prepare("INSERT INTO War (Name) VALUES (?)");
                $war_insert->bind_param("s", $war_name_escaped);
                $war_insert->execute();
                $war_id = $war_insert->insert_id;
                $war_insert->close();
            }
            $war_check->close();
        }
        
        // Экранируем основные данные для БД
        $name_escaped = $mysqli->real_escape_string($name);
        $history_escaped = $mysqli->real_escape_string($history);
        
        // Обновляем основную информацию о ПВО
        $update_stmt = $mysqli->prepare("UPDATE Vehicle SET Name = ?, History = ?, Country_ID = ?, Weapon_ID = ?, War_ID = ? WHERE ID = ?");
        $update_stmt->bind_param("ssiiii", $name_escaped, $history_escaped, $country_id, $weapon_id, $war_id, $pvo_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Получаем Service_ID для этого Vehicle
        $service_stmt = $mysqli->prepare("SELECT Service_ID FROM Vehicle WHERE ID = ?");
        $service_stmt->bind_param("i", $pvo_id);
        $service_stmt->execute();
        $service_result = $service_stmt->get_result();
        $vehicle_data = $service_result->fetch_assoc();
        $service_stmt->close();
        
        if ($vehicle_data && $vehicle_data['Service_ID']) {
            // Обновляем информацию о службе
            $update_service_stmt = $mysqli->prepare("UPDATE Service SET Year_of_commissioning = ?, Year_of_decommissioning = ?, In_service = ? WHERE ID = ?");
            $update_service_stmt->bind_param("iiii", $year_commissioning, $year_decommissioning, $in_service, $vehicle_data['Service_ID']);
            $update_service_stmt->execute();
            $update_service_stmt->close();
        }
        
        // Фиксируем транзакцию
        $mysqli->commit();
        
        // ЛОГИРОВАНИЕ: Записываем изменение в логи
        $new_pvo_data = [
            'Name' => $name,
            'History' => $history,
            'Country_ID' => $country_id,
            'Weapon_ID' => $weapon_id,
            'War_ID' => $war_id,
            'Year_of_commissioning' => $year_commissioning,
            'Year_of_decommissioning' => $year_decommissioning,
            'In_service' => $in_service
        ];
        
        $old_values_for_log = [
            'Name' => $old_pvo_data['Name'] ?? '',
            'History' => $old_pvo_data['History'] ?? '',
            'Country_ID' => $old_pvo_data['Country_ID'] ?? 0,
            'Weapon_ID' => $old_pvo_data['Weapon_ID'] ?? null,
            'War_ID' => $old_pvo_data['War_ID'] ?? null,
            'Year_of_commissioning' => $old_pvo_data['Year_of_commissioning'] ?? 0,
            'Year_of_decommissioning' => $old_pvo_data['Year_of_decommissioning'] ?? 0,
            'In_service' => $old_pvo_data['In_service'] ?? 0
        ];
        
        $new_values_for_log = [
            'Name' => $name,
            'History' => $history,
            'Country_ID' => $country_id,
            'Weapon_ID' => $weapon_id,
            'War_ID' => $war_id,
            'Year_of_commissioning' => $year_commissioning,
            'Year_of_decommissioning' => $year_decommissioning,
            'In_service' => $in_service
        ];
        
        // Добавляем информацию о вооружении и войне для лучшей читаемости
        if ($old_pvo_data) {
            $old_values_for_log['CountryName'] = $old_pvo_data['CountryName'] ?? '';
            $old_values_for_log['WeaponName'] = $old_pvo_data['WeaponName'] ?? '';
            $old_values_for_log['WeaponType'] = $old_pvo_data['WeaponType'] ?? '';
            $old_values_for_log['WeaponCalibre'] = $old_pvo_data['WeaponCalibre'] ?? '';
            $old_values_for_log['WarPeriod'] = $old_pvo_data['WarPeriod'] ?? '';
        }
        
        // Получаем новое название страны для лога
        $new_country_stmt = $mysqli->prepare("SELECT Name FROM Country WHERE ID = ?");
        $new_country_stmt->bind_param("i", $country_id);
        $new_country_stmt->execute();
        $new_country_result = $new_country_stmt->get_result();
        $new_country = $new_country_result->fetch_assoc();
        $new_country_stmt->close();
        
        $new_values_for_log['CountryName'] = $new_country['Name'] ?? '';
        $new_values_for_log['WeaponName'] = $weapon_name;
        $new_values_for_log['WeaponType'] = $weapon_type;
        $new_values_for_log['WeaponCalibre'] = $weapon_calibre;
        $new_values_for_log['WarPeriod'] = $war_name;
        
        $description = "Система ПВО '{$old_pvo_data['Name']}' отредактирована пользователем ID: {$_SESSION['user_id']}";
        
        // Записываем лог
        $logger->logUpdate('Vehicle', $pvo_id, $old_values_for_log, $new_values_for_log, $description);
        
        $_SESSION['favorite_message'] = 'Данные ПВО успешно обновлены!';
        $_SESSION['favorite_type'] = 'success';
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['favorite_message'] = 'Ошибка при обновлении данных: ' . $e->getMessage();
        $_SESSION['favorite_type'] = 'error';
    }
    
    // Редирект для обновления страницы
    $redirect_params = 'id=' . $pvo_id;
    if (isset($_GET['type'])) {
        $redirect_params .= '&type=' . $_GET['type'];
    }
    if (isset($_GET['from'])) {
        $redirect_params .= '&from=' . $_GET['from'];
    }
    
    header("Location: pvo_detail.php?" . $redirect_params);
    exit();
}

// Обработка добавления/удаления из избранного
if (isset($_SESSION['user_id']) && isset($_POST['toggle_favorite'])) {
    $user_id = $_SESSION['user_id'];
    
    $check_favorite = $mysqli->prepare("SELECT id FROM user_favorites WHERE user_id = ? AND article_id = ?");
    $check_favorite->bind_param("ii", $user_id, $pvo_id);
    $check_favorite->execute();
    $check_favorite->store_result();
    
    if ($check_favorite->num_rows > 0) {
        // Удаление из избранного
        $delete_favorite = $mysqli->prepare("DELETE FROM user_favorites WHERE user_id = ? AND article_id = ?");
        $delete_favorite->bind_param("ii", $user_id, $pvo_id);
        $delete_favorite->execute();
        $is_favorite = false;
        
        // ЛОГИРОВАНИЕ: Удаление из избранного
        $logger->log('favorite_remove', 'user_favorites', $pvo_id, 
            "Система ПВО '{$pvo['Name']}' удалена из избранного пользователем ID: $user_id");
        
        $_SESSION['favorite_message'] = 'Статья удалена из избранного';
        $_SESSION['favorite_type'] = 'info';
    } else {
        // Добавление в избранное
        $add_favorite = $mysqli->prepare("INSERT INTO user_favorites (user_id, article_id) VALUES (?, ?)");
        $add_favorite->bind_param("ii", $user_id, $pvo_id);
        $add_favorite->execute();
        $is_favorite = true;
        
        // ЛОГИРОВАНИЕ: Добавление в избранное
        $logger->log('favorite_add', 'user_favorites', $pvo_id, 
            "Система ПВО '{$pvo['Name']}' добавлена в избранное пользователем ID: $user_id");
        
        $_SESSION['favorite_message'] = 'Статья добавлена в избранное!';
        $_SESSION['favorite_type'] = 'success';
    }
    
    $redirect_params = 'id=' . $pvo_id;
    if (isset($_GET['type'])) {
        $redirect_params .= '&type=' . $_GET['type'];
    }
    if (isset($_GET['from'])) {
        $redirect_params .= '&from=' . $_GET['from'];
    }
    
    header("Location: pvo_detail.php?" . $redirect_params);
    exit();
}

// Обработка загрузки нового изображения
if ($can_edit_article && isset($_POST['upload_image'])) {
    if (isset($_FILES['new_image']) && $_FILES['new_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/vehicle_images/';
        
        // Создаем директорию если не существует
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['new_image']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            // Генерируем уникальное имя файла
            $new_filename = 'pvo_' . $pvo_id . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            
            // Проверяем размер файла (максимум 5MB)
            if ($_FILES['new_image']['size'] <= 5 * 1024 * 1024) {
                if (move_uploaded_file($_FILES['new_image']['tmp_name'], $upload_path)) {
                    // Определяем MIME-тип по расширению файла
                    $mime_types = [
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'gif' => 'image/gif',
                        'webp' => 'image/webp'
                    ];
                    $image_type = $mime_types[$file_extension] ?? 'image/jpeg';
                    
                    $relative_path = 'uploads/vehicle_images/' . $new_filename;
                    
                    // Проверяем, есть ли уже изображение для этого vehicle
                    $check_image = $mysqli->prepare("SELECT id, ImagePath FROM vehicle_images WHERE Vehicle_ID = ?");
                    $check_image->bind_param("i", $pvo_id);
                    $check_image->execute();
                    $check_image->store_result();
                    
                    if ($check_image->num_rows > 0) {
                        // Обновляем существующую запись
                        $check_image->bind_result($image_id, $old_image_path);
                        $check_image->fetch();
                        
                        // Удаляем старый файл если он существует
                        if ($old_image_path && file_exists('../' . $old_image_path)) {
                            unlink('../' . $old_image_path);
                        }
                        
                        $update_image = $mysqli->prepare("UPDATE vehicle_images SET ImagePath = ?, ImageType = ?, UploadDate = NOW() WHERE Vehicle_ID = ?");
                        $update_image->bind_param("ssi", $relative_path, $image_type, $pvo_id);
                        
                        if ($update_image->execute()) {
                            // ЛОГИРОВАНИЕ: Записываем обновление изображения
                            $description = "Обновлено изображение для системы ПВО '{$pvo['Name']}'";
                            $logger->logUpdate('Vehicle', $pvo_id, 
                                ['image_action' => 'image_updated', 'old_image_path' => $old_image_path], 
                                ['image_action' => 'image_updated', 'new_image_path' => $relative_path], 
                                $description
                            );
                            
                            $_SESSION['favorite_message'] = 'Изображение успешно обновлено!';
                            $_SESSION['favorite_type'] = 'success';
                        } else {
                            $_SESSION['favorite_message'] = 'Ошибка при обновлении записи в БД: ' . $update_image->error;
                            $_SESSION['favorite_type'] = 'error';
                        }
                        $update_image->close();
                    } else {
                        // Создаем новую запись
                        $insert_image = $mysqli->prepare("INSERT INTO vehicle_images (Vehicle_ID, ImagePath, ImageType, UploadDate) VALUES (?, ?, ?, NOW())");
                        $insert_image->bind_param("iss", $pvo_id, $relative_path, $image_type);
                        
                        if ($insert_image->execute()) {
                            // ЛОГИРОВАНИЕ: Записываем загрузку изображения
                            $description = "Загружено новое изображение для системы ПВО '{$pvo['Name']}'";
                            $logger->logUpdate('Vehicle', $pvo_id, 
                                ['image_action' => 'no_image_before'], 
                                ['image_action' => 'image_uploaded', 'image_path' => $relative_path], 
                                $description
                            );
                            
                            $_SESSION['favorite_message'] = 'Изображение успешно загружено!';
                            $_SESSION['favorite_type'] = 'success';
                        } else {
                            $_SESSION['favorite_message'] = 'Ошибка при создании записи в БД: ' . $insert_image->error;
                            $_SESSION['favorite_type'] = 'error';
                        }
                        $insert_image->close();
                    }
                    
                    $check_image->close();
                } else {
                    $_SESSION['favorite_message'] = 'Ошибка при перемещении файла';
                    $_SESSION['favorite_type'] = 'error';
                }
            } else {
                $_SESSION['favorite_message'] = 'Файл слишком большой (максимум 5MB)';
                $_SESSION['favorite_type'] = 'error';
            }
        } else {
            $_SESSION['favorite_message'] = 'Недопустимый формат файла. Разрешены: JPG, JPEG, PNG, GIF, WEBP';
            $_SESSION['favorite_type'] = 'error';
        }
    } else {
        $error_message = 'Ошибка при загрузке файла: ';
        switch ($_FILES['new_image']['error']) {
            case UPLOAD_ERR_INI_SIZE:
                $error_message .= 'Файл слишком большой';
                break;
            case UPLOAD_ERR_FORM_SIZE:
                $error_message .= 'Превышен размер формы';
                break;
            case UPLOAD_ERR_PARTIAL:
                $error_message .= 'Файл загружен частично';
                break;
            case UPLOAD_ERR_NO_FILE:
                $error_message .= 'Файл не выбран';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $error_message .= 'Отсутствует временная директория';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $error_message .= 'Ошибка записи на диск';
                break;
            default:
                $error_message .= 'Неизвестная ошибка';
        }
        $_SESSION['favorite_message'] = $error_message;
        $_SESSION['favorite_type'] = 'error';
    }
    
    // Редирект после загрузки
    header("Location: pvo_detail.php?id=" . $pvo_id . (isset($_GET['type']) ? '&type=' . $_GET['type'] : '') . (isset($_GET['from']) ? '&from=' . $_GET['from'] : ''));
    exit();
}

// Обработка удаления изображения
if ($can_edit_article && isset($_POST['delete_image'])) {
    // Получаем информацию об изображении
    $check_image = $mysqli->prepare("SELECT id, ImagePath FROM vehicle_images WHERE Vehicle_ID = ?");
    $check_image->bind_param("i", $pvo_id);
    $check_image->execute();
    $check_image->store_result();
    
    if ($check_image->num_rows > 0) {
        $check_image->bind_result($image_id, $image_path);
        $check_image->fetch();
        
        // ЛОГИРОВАНИЕ: Записываем факт удаления изображения перед самим удалением
        $description = "Удалено изображение для системы ПВО '{$pvo['Name']}'";
        $logger->logUpdate('Vehicle', $pvo_id, 
            ['image_action' => 'image_existed', 'image_path' => $image_path], 
            ['image_action' => 'image_deleted'], 
            $description
        );
        
        // Удаляем файл изображения если он существует
        $file_deleted = true;
        if ($image_path && file_exists('../' . $image_path)) {
            if (!unlink('../' . $image_path)) {
                $file_deleted = false;
                $_SESSION['favorite_message'] = 'Ошибка при удалении файла изображения';
                $_SESSION['favorite_type'] = 'error';
            }
        }
        
        if ($file_deleted) {
            // Удаляем запись из базы данных
            $delete_image = $mysqli->prepare("DELETE FROM vehicle_images WHERE Vehicle_ID = ?");
            $delete_image->bind_param("i", $pvo_id);
            
            if ($delete_image->execute()) {
                $_SESSION['favorite_message'] = 'Изображение успешно удалено!';
                $_SESSION['favorite_type'] = 'success';
            } else {
                $_SESSION['favorite_message'] = 'Ошибка при удалении записи из БД: ' . $delete_image->error;
                $_SESSION['favorite_type'] = 'error';
            }
            $delete_image->close();
        }
    } else {
        $_SESSION['favorite_message'] = 'Изображение не найдено в базе данных';
        $_SESSION['favorite_type'] = 'error';
    }
    
    $check_image->close();
    
    // Редирект после удаления
    header("Location: pvo_detail.php?id=" . $pvo_id . (isset($_GET['type']) ? '&type=' . $_GET['type'] : '') . (isset($_GET['from']) ? '&from=' . $_GET['from'] : ''));
    exit();
}

// Получаем полную информацию о системе ПВО
$query = "
    SELECT 
        v.ID, 
        v.Name, 
        v.History, 
        v.Country_ID,
        v.Weapon_ID,
        v.War_ID,
        v.Service_ID,
        c.Name as CountryName,
        cls.Name as ClassName,
        w.Name as WeaponName,
        w.Type as WeaponType,
        w.Calibre as WeaponCalibre,
        war.Name as WarPeriod,
        s.Year_of_commissioning,
        s.Year_of_decommissioning,
        s.In_service
    FROM Vehicle v
    LEFT JOIN Country c ON v.Country_ID = c.ID
    LEFT JOIN Class cls ON v.Class_ID = cls.ID
    LEFT JOIN Weapon w ON v.Weapon_ID = w.ID
    LEFT JOIN War war ON v.War_ID = war.ID
    LEFT JOIN Service s ON v.Service_ID = s.ID
    WHERE v.ID = ?
";

$stmt = $mysqli->prepare($query);
$stmt->bind_param("i", $pvo_id);
$stmt->execute();
$result = $stmt->get_result();
$pvo = $result->fetch_assoc();

if (!$pvo) {
    header("Location: " . $back_url);
    exit();
}

// Очищаем данные от экранирования при выводе
function clean_output($data) {
    return stripslashes($data);
}

// Применяем очистку к данным из БД
if ($pvo) {
    $pvo['Name'] = clean_output($pvo['Name']);
    $pvo['History'] = clean_output($pvo['History'] ?? '');
}

// Получаем список стран для формы редактирования
$countries = [];
$country_stmt = $mysqli->prepare("SELECT ID, Name FROM Country ORDER BY Name");
if ($country_stmt) {
    $country_stmt->execute();
    $country_result = $country_stmt->get_result();
    while ($row = $country_result->fetch_assoc()) {
        $countries[] = $row;
    }
    $country_stmt->close();
}

// Получаем информацию о текущем вооружении
$current_weapon = null;
if ($pvo['Weapon_ID']) {
    $weapon_stmt = $mysqli->prepare("SELECT Name, Type, Calibre FROM Weapon WHERE ID = ?");
    $weapon_stmt->bind_param("i", $pvo['Weapon_ID']);
    $weapon_stmt->execute();
    $weapon_result = $weapon_stmt->get_result();
    $current_weapon = $weapon_result->fetch_assoc();
    if ($current_weapon) {
        $current_weapon['Name'] = clean_output($current_weapon['Name'] ?? '');
        $current_weapon['Type'] = clean_output($current_weapon['Type'] ?? '');
        $current_weapon['Calibre'] = clean_output($current_weapon['Calibre'] ?? '');
    }
    $weapon_stmt->close();
}

// Получаем информацию о текущей войне
$current_war = null;
if ($pvo['War_ID']) {
    $war_stmt = $mysqli->prepare("SELECT Name FROM War WHERE ID = ?");
    $war_stmt->bind_param("i", $pvo['War_ID']);
    $war_stmt->execute();
    $war_result = $war_stmt->get_result();
    $current_war = $war_result->fetch_assoc();
    if ($current_war) {
        $current_war['Name'] = clean_output($current_war['Name'] ?? '');
    }
    $war_stmt->close();
}

// Проверяем, находится ли статья в избранном у текущего пользователя
$is_favorite = false;
if (isset($_SESSION['user_id'])) {
    $check_favorite = $mysqli->prepare("SELECT id FROM user_favorites WHERE user_id = ? AND article_id = ?");
    $check_favorite->bind_param("ii", $_SESSION['user_id'], $pvo_id);
    $check_favorite->execute();
    $check_favorite->store_result();
    $is_favorite = $check_favorite->num_rows > 0;
    $check_favorite->close();
}

// Получаем путь к изображению
$image_path = null;
$image_id = null;
$image_exists = false;

$image_query = "SELECT id, ImagePath FROM vehicle_images WHERE Vehicle_ID = ? LIMIT 1";
$image_stmt = $mysqli->prepare($image_query);
if ($image_stmt) {
    $image_stmt->bind_param("i", $pvo_id);
    $image_stmt->execute();
    $image_result = $image_stmt->get_result();
    if ($image_row = $image_result->fetch_assoc()) {
        $image_path = $image_row['ImagePath'];
        $image_id = $image_row['id'];
        
        // Проверяем существование файла изображения
        if ($image_path && file_exists('../' . $image_path)) {
            $image_exists = true;
        }
    }
    $image_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pvo['Name'], ENT_QUOTES, 'UTF-8'); ?> - Военный справочник</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="detail.css">
</head>
<body>
    <div class="top-bar">
        <nav class="nav-menu">
            <div class="nav-item">
                <a href="../main.php" class="nav-link">Главная</a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $back_url; ?>" class="nav-link">Назад к списку</a>
            </div>
        </nav>
        <div class="auth-section">
            <?php if (isset($_SESSION['user_id'])): ?>
                <!-- Если пользователь авторизован -->
                <div class="user-menu">
                    <div class="user-btn">
                        <?php echo htmlspecialchars($_SESSION['user_login']); ?>
                    </div>
                    <div class="user-dropdown">
                        <a href="../account.php" class="user-item">Перейти в профиль</a>
                        <a href="../auth.php?logout=true" class="user-item">Выйти из аккаунта</a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Если пользователь не авторизован -->
                <a href="../auth.php?register=true" class="auth-btn">Регистрация</a>
                <a href="../auth.php" class="auth-btn">Авторизация</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Контейнер для уведомлений -->
    <div class="notification-container" id="notificationContainer">
        <?php if (isset($_SESSION['favorite_message'])): ?>
            <div class="notification notification-<?php echo $_SESSION['favorite_type']; ?>" data-type="<?php echo $_SESSION['favorite_type']; ?>">
                <span class="notification-close">×</span>
                <?php echo $_SESSION['favorite_message']; ?>
            </div>
            <?php 
            unset($_SESSION['favorite_message']);
            unset($_SESSION['favorite_type']);
            ?>
        <?php endif; ?>
    </div>

    <div class="main-content">
        <div class="vehicle-detail">
            <div class="vehicle-header">
                <div class="image-section">
                    <div class="vehicle-image-large" id="vehicleImageContainer">
                        <?php if ($image_exists): ?>
                            <img src="../<?php echo htmlspecialchars($image_path); ?>" alt="<?php echo htmlspecialchars($pvo['Name'], ENT_QUOTES, 'UTF-8'); ?>" id="vehicleImage">
                        <?php else: ?>
                            <div class="no-image-placeholder" id="noImagePlaceholder">
                                <span>Изображение отсутствует</span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($can_edit_article): ?>
                            <!-- Кнопка для открытия модального окна -->
                            <div class="image-edit-trigger" onclick="openImageModal()">
                                <div class="image-edit-icon">✏️</div>
                                <span class="image-edit-text">Управление изображением</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="vehicle-info">
                    <div class="vehicle-title-section">
                        <h1 class="vehicle-name"><?php echo htmlspecialchars($pvo['Name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                        <?php if ($can_edit_article): ?>
                            <button type="button" class="edit-main-btn" onclick="toggleEditForm()" title="Редактировать данные">
                                ✏️
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="vehicle-specs-grid">
                        <?php if (!empty($pvo['CountryName'])): ?>
                            <div class="spec-card">
                                <div class="spec-label">Страна</div>
                                <div class="spec-value"><?php echo htmlspecialchars($pvo['CountryName']); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($pvo['ClassName'])): ?>
                            <div class="spec-card">
                                <div class="spec-label">Класс</div>
                                <div class="spec-value"><?php echo htmlspecialchars($pvo['ClassName']); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($pvo['WeaponName']) || !empty($pvo['WeaponType']) || !empty($pvo['WeaponCalibre'])): ?>
                            <div class="spec-card">
                                <div class="spec-label">Вооружение</div>
                                <div class="spec-value">
                                    <?php if (!empty($pvo['WeaponName'])): ?>
                                        <?php echo htmlspecialchars($pvo['WeaponName'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="weapon-details">
                                    <?php if (!empty($pvo['WeaponType'])): ?>
                                        <div class="weapon-detail">Тип: <?php echo htmlspecialchars($pvo['WeaponType'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($pvo['WeaponCalibre'])): ?>
                                        <div class="weapon-detail">Калибр/Дальность: <?php echo htmlspecialchars($pvo['WeaponCalibre'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($pvo['WarPeriod'])): ?>
                            <div class="spec-card">
                                <div class="spec-label">Участия в войнах или конфликтах</div>
                                <div class="spec-value"><?php echo htmlspecialchars($pvo['WarPeriod'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($pvo['Year_of_commissioning'])): ?>
                            <div class="spec-card">
                                <div class="spec-label">Начало службы</div>
                                <div class="spec-value"><?php echo htmlspecialchars($pvo['Year_of_commissioning']); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($pvo['Year_of_decommissioning'])): ?>
                            <div class="spec-card">
                                <div class="spec-label">Конец службы</div>
                                <div class="spec-value"><?php echo htmlspecialchars($pvo['Year_of_decommissioning']); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="spec-card">
                            <div class="spec-label">Статус</div>
                            <div class="spec-value">
                                <span class="service-status <?php echo ($pvo['In_service'] == 1) ? 'service-active' : 'service-inactive'; ?>">
                                    <?php echo ($pvo['In_service'] == 1) ? 'В эксплуатации' : 'Снят с эксплуатации'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Форма редактирования -->
            <?php if ($can_edit_article): ?>
                <div class="edit-form-container" id="editFormContainer" style="display: none;">
                    <form method="POST" class="edit-form" id="editForm">
                        <h2 class="edit-form-title">Редактирование данных ПВО</h2>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label required">Название системы ПВО</label>
                                <input type="text" name="name" value="<?php echo htmlspecialchars($pvo['Name'], ENT_QUOTES, 'UTF-8'); ?>" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Страна</label>
                                <select name="country_id" class="form-select" required>
                                    <option value="">Выберите страну</option>
                                    <?php foreach ($countries as $country): ?>
                                        <option value="<?php echo $country['ID']; ?>" 
                                            <?php echo $pvo['Country_ID'] == $country['ID'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($country['Name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Год ввода в эксплуатацию</label>
                                <input type="number" name="year_commissioning" value="<?php echo !empty($pvo['Year_of_commissioning']) ? htmlspecialchars($pvo['Year_of_commissioning']) : ''; ?>" class="form-input" min="1900" max="2030">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Год снятия с эксплуатации</label>
                                <input type="number" name="year_decommissioning" value="<?php echo !empty($pvo['Year_of_decommissioning']) ? htmlspecialchars($pvo['Year_of_decommissioning']) : ''; ?>" class="form-input" min="1900" max="2030">
                                <div class="form-hint">Оставьте пустым, если система всё ещё в эксплуатации</div>
                            </div>
                        </div>

                        <!-- Секция вооружения -->
                        <div class="form-section">
                            <h3 class="form-section-title">Вооружение</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Тип вооружения</label>
                                    <input type="text" name="weapon_type" value="<?php echo !empty($current_weapon['Type']) ? htmlspecialchars($current_weapon['Type'], ENT_QUOTES, 'UTF-8') : ''; ?>" class="form-input" placeholder="Например: Зенитный ракетный комплекс, Зенитная пушка">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Калибр/Дальность</label>
                                    <input type="text" name="weapon_calibre" value="<?php echo !empty($current_weapon['Calibre']) ? htmlspecialchars($current_weapon['Calibre'], ENT_QUOTES, 'UTF-8') : ''; ?>" class="form-input" placeholder="Например: 100 мм, 50 км">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Название вооружения</label>
                                    <input type="text" name="weapon_name" value="<?php echo !empty($current_weapon['Name']) ? htmlspecialchars($current_weapon['Name'], ENT_QUOTES, 'UTF-8') : ''; ?>" class="form-input" placeholder="Например: С-300, Тор">
                                </div>
                            </div>
                        </div>

                        <!-- Секция войны -->
                        <div class="form-section">
                            <h3 class="form-section-title">Участие в войнах/конфликтах</h3>
                            <div class="form-group">
                                <label class="form-label">Название войны или конфликта</label>
                                <input type="text" name="war_name" value="<?php echo !empty($current_war['Name']) ? htmlspecialchars($current_war['Name'], ENT_QUOTES, 'UTF-8') : ''; ?>" class="form-input" placeholder="Например: Вторая мировая война, Холодная война">
                            </div>
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="form-label">История и характеристики</label>
                            <textarea name="history" class="form-textarea" rows="8"><?php echo htmlspecialchars($pvo['History'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="update_pvo" class="form-btn save-btn">💾 Сохранить изменения</button>
                            <button type="button" class="form-btn cancel-btn" onclick="toggleEditForm()">❌ Отмена</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <div class="history-section">
                <h2 class="history-title">История и характеристики</h2>
                <div class="history-content">
                    <?php echo nl2br(htmlspecialchars($pvo['History'] ?? 'Описание отсутствует')); ?>
                </div>
            </div>

            <div class="action-buttons">
                <a href="<?php echo $back_url; ?>" class="back-button">← Назад к списку ПВО</a>
                
                <?php if (isset($_SESSION['user_id'])): ?>
                    <form method="POST" class="favorite-form">
                        <button type="submit" name="toggle_favorite" class="favorite-button <?php echo $is_favorite ? 'favorited' : ''; ?>">
                            <?php echo $is_favorite ? '★ В избранном' : '☆ Добавить в избранное'; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Модальное окно для управления изображением -->
    <?php if ($can_edit_article): ?>
    <div class="modal" id="imageModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeImageModal()">×</button>
            <h2 class="modal-title">Управление изображением</h2>
            
            <div class="modal-body">
                <!-- Текущее изображение -->
                <div class="current-image-section">
                    <h3>Текущее изображение</h3>
                    <?php if ($image_exists): ?>
                        <div class="current-image-preview">
                            <img src="../<?php echo htmlspecialchars($image_path); ?>" alt="Текущее изображение">
                        </div>
                        <form method="POST" class="delete-image-form">
                            <input type="hidden" name="delete_image" value="1">
                            <button type="submit" class="btn btn-danger" onclick="return confirm('Вы уверены, что хотите удалить изображение?')">
                                🗑️ Удалить изображение
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="no-image-message">
                            <p>Изображение не загружено</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Загрузка нового изображения -->
                <div class="upload-image-section">
                    <h3>Загрузить новое изображение</h3>
                    <form method="POST" enctype="multipart/form-data" class="upload-image-form">
                        <input type="hidden" name="upload_image" value="1">
                        
                        <div class="form-group">
                            <label class="form-label">Выберите файл изображения:</label>
                            <input type="file" name="new_image" accept="image/*" class="file-input" id="imageFileInput" required>
                            <div class="file-info" id="fileInfo"></div>
                        </div>
                        
                        <div class="form-hint">
                            <p>Разрешены форматы: JPG, JPEG, PNG, GIF, WEBP</p>
                            <p>Максимальный размер: 5MB</p>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="uploadSubmitBtn">📤 Загрузить изображение</button>
                            <button type="button" class="btn btn-secondary" onclick="closeImageModal()">❌ Отмена</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Функции для модального окна изображения
        function openImageModal() {
            const modal = document.getElementById('imageModal');
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
                // Сброс формы
                const fileInput = document.getElementById('imageFileInput');
                if (fileInput) {
                    fileInput.value = '';
                }
                const fileInfo = document.getElementById('fileInfo');
                if (fileInfo) {
                    fileInfo.innerHTML = '';
                }
            }
        }

        // Закрытие модального окна при клике вне его
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('imageModal');
            if (modal && modal.classList.contains('active')) {
                if (event.target === modal) {
                    closeImageModal();
                }
            }
        });

        // Закрытие модального окна по Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeImageModal();
            }
        });

        // Обработка выбора файла
        document.addEventListener('DOMContentLoaded', function() {
            const fileInput = document.getElementById('imageFileInput');
            const fileInfo = document.getElementById('fileInfo');
            const uploadBtn = document.getElementById('uploadSubmitBtn');

            if (fileInput && fileInfo) {
                fileInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    
                    if (file) {
                        // Валидация размера файла
                        if (file.size > 5 * 1024 * 1024) {
                            fileInfo.innerHTML = '<span class="error">Файл слишком большой. Максимальный размер: 5MB</span>';
                            fileInput.value = '';
                            if (uploadBtn) uploadBtn.disabled = true;
                            return;
                        }
                        
                        // Валидация типа файла
                        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                        if (!allowedTypes.includes(file.type)) {
                            fileInfo.innerHTML = '<span class="error">Недопустимый формат файла. Разрешены: JPG, JPEG, PNG, GIF, WEBP</span>';
                            fileInput.value = '';
                            if (uploadBtn) uploadBtn.disabled = true;
                            return;
                        }
                        
                        // Показываем информацию о файле
                        fileInfo.innerHTML = `
                            <span class="success">Файл выбран:</span>
                            <div>Имя: ${file.name}</div>
                            <div>Размер: ${(file.size / 1024 / 1024).toFixed(2)} MB</div>
                            <div>Тип: ${file.type}</div>
                        `;
                        
                        if (uploadBtn) uploadBtn.disabled = false;
                    } else {
                        fileInfo.innerHTML = '';
                        if (uploadBtn) uploadBtn.disabled = true;
                    }
                });
            }

            // Валидация формы перед отправкой
            const uploadForm = document.querySelector('.upload-image-form');
            if (uploadForm) {
                uploadForm.addEventListener('submit', function(e) {
                    const fileInput = this.querySelector('input[type="file"]');
                    if (!fileInput || !fileInput.files[0]) {
                        e.preventDefault();
                        alert('Пожалуйста, выберите файл для загрузки');
                        return;
                    }
                    
                    // Показываем индикатор загрузки
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '⏳ Загрузка...';
                        submitBtn.disabled = true;
                    }
                });
            }

            // Валидация формы редактирования
            const editForm = document.getElementById('editForm');
            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    const nameInput = this.querySelector('input[name="name"]');
                    const countrySelect = this.querySelector('select[name="country_id"]');
                    
                    if (!nameInput.value.trim()) {
                        e.preventDefault();
                        alert('Пожалуйста, введите название системы ПВО');
                        nameInput.focus();
                        return;
                    }
                    
                    if (!countrySelect.value) {
                        e.preventDefault();
                        alert('Пожалуйста, выберите страну');
                        countrySelect.focus();
                        return;
                    }
                });
            }

            // Улучшенная система уведомлений
            initNotifications();
        });

        // Функции для формы редактирования
        function toggleEditForm() {
            const editFormContainer = document.getElementById('editFormContainer');
            
            if (editFormContainer) {
                const isVisible = editFormContainer.style.display === 'block';
                
                if (isVisible) {
                    editFormContainer.style.display = 'none';
                } else {
                    editFormContainer.style.display = 'block';
                    
                    // Прокрутка к форме редактирования при открытии
                    setTimeout(() => {
                        editFormContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 100);
                }
            }
        }

        // Улучшенная система уведомлений
        function initNotifications() {
            const notifications = document.querySelectorAll('.notification');
            
            notifications.forEach((notification, index) => {
                // Показываем уведомление с задержкой
                setTimeout(() => {
                    notification.classList.add('show');
                    
                    // Автоматическое скрытие через 5 секунд
                    const hideTimeout = setTimeout(() => {
                        hideNotification(notification);
                    }, 5000);
                    
                    // Сохраняем timeout ID для возможности отмены
                    notification.dataset.hideTimeout = hideTimeout;
                    
                }, index * 200);
            });

            // Обработка клика на кнопку закрытия
            document.querySelectorAll('.notification-close').forEach(closeBtn => {
                closeBtn.addEventListener('click', function() {
                    const notification = this.parentElement;
                    hideNotification(notification);
                });
            });
        }

        function hideNotification(notification) {
            // Отменяем автоматическое скрытие если оно еще не сработало
            if (notification.dataset.hideTimeout) {
                clearTimeout(parseInt(notification.dataset.hideTimeout));
            }
            
            notification.classList.remove('show');
            notification.classList.add('hide');
            
            // Удаление из DOM после анимации
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 600);
        }

        // Функция для показа новых уведомлений (если понадобится динамически)
        function showNotification(message, type = 'success') {
            const container = document.getElementById('notificationContainer');
            if (!container) return;
            
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <span class="notification-close">×</span>
                ${message}
            `;
            
            container.appendChild(notification);
            
            // Инициализируем новое уведомление
            setTimeout(() => {
                notification.classList.add('show');
                
                const hideTimeout = setTimeout(() => {
                    hideNotification(notification);
                }, 5000);
                
                notification.dataset.hideTimeout = hideTimeout;
                
                // Добавляем обработчик для кнопки закрытия
                notification.querySelector('.notification-close').addEventListener('click', function() {
                    hideNotification(notification);
                });
            }, 100);
        }
    </script>
</body>
</html>