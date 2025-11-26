<?php
session_start();
include '../connect.php';
include '../Logger.php';

// Инициализируем логгер
$logger = new Logger($mysqli);

// Получаем тип вертолета из параметра URL
$helicopter_type = isset($_GET['type']) ? intval($_GET['type']) : 6;
$helicopter_type = in_array($helicopter_type, [6, 7, 8, 9]) ? $helicopter_type : 6;

// Названия типов вертолётов для отображения
$helicopter_type_names = [
    6 => 'Боевые вертолёты',
    7 => 'Транспортно-боевые вертолёты', 
    8 => 'Транспортные вертолёты',
    9 => 'Специальные вертолёты'
];

$page_title = "Добавить " . mb_strtolower($helicopter_type_names[$helicopter_type]);

// Проверяем права доступа
$can_add_article = false;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $mysqli->prepare("SELECT Role_ID FROM Users WHERE ID = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if ($user && in_array($user['Role_ID'], [2, 3, 4])) {
        $can_add_article = true;
    }
}

// Если нет прав, перенаправляем на страницу соответствующего типа
if (!$can_add_article) {
    header("Location: helicopters.php?type=" . $helicopter_type);
    exit();
}

// Получаем данные для выпадающих списков
$countries = [];
$classes = [];

// Страны
$country_stmt = $mysqli->prepare("SELECT ID, Name FROM Country ORDER BY Name");
if ($country_stmt) {
    $country_stmt->execute();
    $country_result = $country_stmt->get_result();
    while ($row = $country_result->fetch_assoc()) {
        $countries[] = $row;
    }
    $country_stmt->close();
}

// Классы (ищем вертолёты - Class_ID = 7)
$class_stmt = $mysqli->prepare("SELECT ID, Name FROM Class WHERE ID = 7 ORDER BY Name");
if ($class_stmt) {
    $class_stmt->execute();
    $class_result = $class_stmt->get_result();
    while ($row = $class_result->fetch_assoc()) {
        $classes[] = $row;
    }
    $class_stmt->close();
}

// Обработка формы добавления
$success_message = '';
$error_message = '';
$show_success_notification = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $history = trim($_POST['history'] ?? '');
    $weapon_name = trim($_POST['weapon_name'] ?? '');
    $weapon_type = trim($_POST['weapon_type'] ?? '');
    $weapon_calibre = trim($_POST['weapon_calibre'] ?? '');
    $war_name = trim($_POST['war_name'] ?? '');
    $country_id = intval($_POST['country_id'] ?? 0);
    $class_id = 7; // Вертолёты
    $service_start = trim($_POST['service_start'] ?? '');
    $service_end = trim($_POST['service_end'] ?? '');
    $generation_id = $helicopter_type; // Используем переданный тип вертолета как Generation_ID
    $type_id = 1; // Устанавливаем Type_ID = 1 по умолчанию

    // Валидация
    if (empty($name)) {
        $error_message = 'Название вертолёта обязательно для заполнения';
    } elseif (empty($history)) {
        $error_message = 'Описание истории обязательно для заполнения';
    } elseif ($country_id <= 0) {
        $error_message = 'Выберите страну';
    } elseif (empty($service_start)) {
        $error_message = 'Год начала эксплуатации обязателен для заполнения';
    } elseif (!is_numeric($service_start) || intval($service_start) <= 0) {
        $error_message = 'Год начала эксплуатации должен быть положительным числом';
    } elseif (!empty($service_end) && (!is_numeric($service_end) || intval($service_end) <= 0)) {
        $error_message = 'Год окончания эксплуатации должен быть положительным числом';
    } elseif (!empty($service_end) && intval($service_start) > intval($service_end)) {
        $error_message = 'Год окончания эксплуатации не может быть раньше года начала';
    } else {
        // Обработка вооружения - все поля теперь необязательные
        $weapon_id = NULL;
        if (!empty($weapon_name) || !empty($weapon_type) || !empty($weapon_calibre)) {
            // Если заполнено хотя бы одно поле вооружения, создаем/ищем запись
            // Устанавливаем пустые строки для незаполненных полей
            $weapon_name = empty($weapon_name) ? '' : $weapon_name;
            $weapon_type = empty($weapon_type) ? '' : $weapon_type;
            $weapon_calibre = empty($weapon_calibre) ? '' : $weapon_calibre;
            
            // Проверяем, существует ли уже такое вооружение
            $weapon_check = $mysqli->prepare("SELECT ID FROM Weapon WHERE Name = ? AND Type = ? AND Calibre = ?");
            $weapon_check->bind_param("sss", $weapon_name, $weapon_type, $weapon_calibre);
            $weapon_check->execute();
            $weapon_result = $weapon_check->get_result();
            
            if ($weapon_row = $weapon_result->fetch_assoc()) {
                $weapon_id = $weapon_row['ID'];
            } else {
                // Создаем новое вооружение
                $weapon_insert = $mysqli->prepare("INSERT INTO Weapon (Name, Type, Calibre) VALUES (?, ?, ?)");
                $weapon_insert->bind_param("sss", $weapon_name, $weapon_type, $weapon_calibre);
                if ($weapon_insert->execute()) {
                    $weapon_id = $mysqli->insert_id;
                }
                $weapon_insert->close();
            }
            $weapon_check->close();
        }

        // Обработка войны - War_ID теперь необязательный
        $war_id = NULL;
        if (!empty($war_name)) {
            // Проверяем, существует ли уже такая война
            $war_check = $mysqli->prepare("SELECT ID FROM War WHERE Name = ?");
            $war_check->bind_param("s", $war_name);
            $war_check->execute();
            $war_result = $war_check->get_result();
            
            if ($war_row = $war_result->fetch_assoc()) {
                $war_id = $war_row['ID'];
            } else {
                // Создаем новую войну
                $war_insert = $mysqli->prepare("INSERT INTO War (Name) VALUES (?)");
                $war_insert->bind_param("s", $war_name);
                if ($war_insert->execute()) {
                    $war_id = $mysqli->insert_id;
                }
                $war_insert->close();
            }
            $war_check->close();
        }

        // Обработка службы (эксплуатации)
        $service_id = 0;
        if (!empty($service_start)) {
            // Определяем статус эксплуатации: 1 - в эксплуатации, 0 - снят с эксплуатации
            $in_service = empty($service_end) ? 1 : 0;
            
            // Проверяем, существует ли уже такая служба
            $service_check = $mysqli->prepare("SELECT ID FROM Service WHERE In_service = ? AND Year_of_commissioning = ? AND Year_of_decommissioning = ?");
            $service_check->bind_param("iss", $in_service, $service_start, $service_end);
            $service_check->execute();
            $service_result = $service_check->get_result();
            
            if ($service_row = $service_result->fetch_assoc()) {
                $service_id = $service_row['ID'];
            } else {
                // Создаем новую службу
                $service_insert = $mysqli->prepare("INSERT INTO Service (In_service, Year_of_commissioning, Year_of_decommissioning) VALUES (?, ?, ?)");
                $service_insert->bind_param("iss", $in_service, $service_start, $service_end);
                if ($service_insert->execute()) {
                    $service_id = $mysqli->insert_id;
                }
                $service_insert->close();
            }
            $service_check->close();
        }

        // Вставляем данные в базу с указанием типа вертолета как Generation_ID и Type_ID = 1
        // Используем NULL для необязательных полей
        $insert_stmt = $mysqli->prepare("
            INSERT INTO Vehicle (Name, History, Weapon_ID, War_ID, Country_ID, Class_ID, Service_ID, Generation_ID, Type_ID) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        // Привязываем параметры, учитывая что War_ID и Weapon_ID могут быть NULL
        $insert_stmt->bind_param("ssiiiiiii", 
            $name, 
            $history, 
            $weapon_id, 
            $war_id, 
            $country_id, 
            $class_id, 
            $service_id, 
            $generation_id, // Используем helicopter_type как Generation_ID
            $type_id  // Добавляем Type_ID = 1
        );
        
        if ($insert_stmt->execute()) {
            $vehicle_id = $mysqli->insert_id;
            
            // Логируем создание вертолета
            $logger->logCreate('Vehicle', $vehicle_id, "Добавлен новый вертолет: " . $name);
            
            // Обработка загрузки изображения
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $filename = 'helicopter_' . $vehicle_id . '_' . time() . '.' . $file_extension;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
                    // Определяем тип изображения на основе расширения файла
                    $image_type = '';
                    switch (strtolower($file_extension)) {
                        case 'jpg':
                        case 'jpeg':
                            $image_type = 'JPEG';
                            break;
                        case 'png':
                            $image_type = 'PNG';
                            break;
                        case 'gif':
                            $image_type = 'GIF';
                            break;
                        case 'webp':
                            $image_type = 'WEBP';
                            break;
                        default:
                            $image_type = 'JPEG';
                    }
                    
                    // Сохраняем путь к изображению в базу с указанием типа
                    $image_stmt = $mysqli->prepare("INSERT INTO vehicle_images (Vehicle_ID, ImagePath, ImageType) VALUES (?, ?, ?)");
                    $image_stmt->bind_param("iss", $vehicle_id, $filepath, $image_type);
                    $image_stmt->execute();
                    $image_stmt->close();
                    
                    // Логируем добавление изображения
                    $logger->logCreate('vehicle_images', $mysqli->insert_id, "Добавлено изображение для вертолета: " . $name);
                }
            }
            
            // Устанавливаем сообщение об успехе
            $_SESSION['success_message'] = 'Вертолёт успешно добавлен!';
            
            // Перенаправляем на страницу со списком вертолётов соответствующего типа
            header("Location: helicopters.php?type=" . $helicopter_type . "&added=1");
            exit();
            
        } else {
            $error_message = 'Ошибка при добавлении вертолёта: ' . $mysqli->error;
        }
        $insert_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Военный справочник</title>
    <link rel="stylesheet" href="add.css">
</head>
<body>
    <div class="top-bar">
        <nav class="nav-menu">
            <div class="nav-item">
                <a href="../main.php" class="nav-link">Главная</a>
            </div>
        </nav>
        <h1 class="page-title-top"><?php echo $page_title; ?></h1>
        <div class="auth-section">
            <?php if (isset($_SESSION['user_id'])): ?>
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
                <a href="../auth.php?register=true" class="auth-btn">Регистрация</a>
                <a href="../auth.php" class="auth-btn">Авторизация</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-header">
            <a href="helicopters.php?type=<?php echo $helicopter_type; ?>" class="back-btn">← Назад к списку вертолётов</a>
        </div>

        <?php if ($error_message): ?>
            <div class="notification notification-error">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <form class="add-form" method="POST" enctype="multipart/form-data" onsubmit="return validateForm()">
            <!-- Скрытое поле для передачи типа вертолета -->
            <input type="hidden" name="type" value="<?php echo $helicopter_type; ?>">
            
            <div class="form-group">
                <label class="form-label">Название вертолёта *</label>
                <input type="text" class="form-input" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">История и описание *</label>
                <textarea class="form-textarea" name="history" required><?php echo htmlspecialchars($_POST['history'] ?? ''); ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Страна *</label>
                    <select class="form-select" name="country_id" required>
                        <option value="">Выберите страну</option>
                        <?php foreach ($countries as $country): ?>
                            <option value="<?php echo $country['ID']; ?>" <?php echo (($_POST['country_id'] ?? '') == $country['ID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($country['Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Война</label>
                    <input type="text" class="form-input" name="war_name" value="<?php echo htmlspecialchars($_POST['war_name'] ?? ''); ?>" placeholder="Введите название войны (необязательно)">
                    <span class="input-hint">Можно оставить пустым</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Вооружение</label>
                <div class="weapon-row">
                    <div>
                        <label class="form-label">Название</label>
                        <input type="text" class="form-input" name="weapon_name" value="<?php echo htmlspecialchars($_POST['weapon_name'] ?? ''); ?>" placeholder="Название вооружения">
                    </div>
                    <div>
                        <label class="form-label">Тип</label>
                        <input type="text" class="form-input" name="weapon_type" value="<?php echo htmlspecialchars($_POST['weapon_type'] ?? ''); ?>" placeholder="Тип вооружения">
                    </div>
                    <div>
                        <label class="form-label">Калибр</label>
                        <input type="text" class="form-input" name="weapon_calibre" value="<?php echo htmlspecialchars($_POST['weapon_calibre'] ?? ''); ?>" placeholder="Калибр">
                    </div>
                </div>
                <span class="input-hint">Все поля вооружения необязательные. Можно заполнить одно, несколько или все поля.</span>
            </div>

            <div class="form-group">
                <label class="form-label">Эксплуатация *</label>
                <div class="service-row">
                    <div>
                        <label class="form-label">Начало эксплуатации *</label>
                        <input type="text" 
                               class="form-input <?php echo (isset($_POST['service_start']) && !is_numeric($_POST['service_start'])) ? 'input-error' : ''; ?>" 
                               name="service_start" 
                               id="service_start"
                               value="<?php echo htmlspecialchars($_POST['service_start'] ?? ''); ?>" 
                               placeholder="Год (например: 1995)" 
                               required
                               oninput="validateYear(this)">
                        <span class="input-hint">Только числовое значение (год)</span>
                    </div>
                    <div>
                        <label class="form-label">Конец эксплуатации</label>
                        <input type="text" 
                               class="form-input <?php echo (isset($_POST['service_end']) && !empty($_POST['service_end']) && !is_numeric($_POST['service_end'])) ? 'input-error' : ''; ?>" 
                               name="service_end" 
                               id="service_end"
                               value="<?php echo htmlspecialchars($_POST['service_end'] ?? ''); ?>" 
                               placeholder="Год (оставьте пустым, если в эксплуатации)"
                               oninput="validateYear(this)">
                        <span class="input-hint">Только числовое значение (год) или оставьте пустым</span>
                        <small style="color: #8b966c; font-size: 0.8rem; display: block; margin-top: 0.3rem;">Если оставить пустым, вертолёт будет отмечен как "в эксплуатации"</small>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Изображение</label>
                <input type="file" class="form-file" name="image" accept="image/*">
            </div>

            <button type="submit" class="submit-btn">Добавить вертолёт</button>
        </form>
    </div>

    <script>
        function validateYear(input) {
            const value = input.value.trim();
            if (value === '') {
                input.classList.remove('input-error');
                return true;
            }
            
            if (!/^\d+$/.test(value)) {
                input.classList.add('input-error');
                return false;
            } else {
                input.classList.remove('input-error');
                return true;
            }
        }

        function validateForm() {
            const serviceStart = document.getElementById('service_start');
            const serviceEnd = document.getElementById('service_end');
            
            let isValid = true;
            
            // Проверка начала эксплуатации
            if (!validateYear(serviceStart)) {
                isValid = false;
            }
            
            // Проверка конца эксплуатации
            if (serviceEnd.value.trim() !== '' && !validateYear(serviceEnd)) {
                isValid = false;
            }
            
            // Проверка логики дат
            if (isValid && serviceStart.value.trim() !== '' && serviceEnd.value.trim() !== '') {
                const startYear = parseInt(serviceStart.value);
                const endYear = parseInt(serviceEnd.value);
                
                if (startYear > endYear) {
                    alert('Год окончания эксплуатации не может быть раньше года начала');
                    serviceEnd.classList.add('input-error');
                    isValid = false;
                }
            }
            
            if (!isValid) {
                alert('Пожалуйста, проверьте правильность введенных данных в полях эксплуатации');
            }
            
            return isValid;
        }

        // Валидация при загрузке страницы для отображения ошибок
        document.addEventListener('DOMContentLoaded', function() {
            validateYear(document.getElementById('service_start'));
            validateYear(document.getElementById('service_end'));
        });
    </script>
</body>
</html>