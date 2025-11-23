<?php
session_start();
include '../connect.php';
include '../Logger.php';

// Инициализируем логгер
$logger = new Logger($mysqli);

// Определяем какой тип танков отображать из параметра URL
$tank_type = isset($_GET['type']) ? intval($_GET['type']) : 14;
$tank_type = in_array($tank_type, [14, 15, 16, 18]) ? $tank_type : 14;

// Названия типов танков для отображения
$tank_type_names = [
    14 => 'Лёгкие танки',
    15 => 'Средние танки', 
    16 => 'Тяжелые танки',
    18 => 'ПТ-САУ'
];

$page_title = $tank_type_names[$tank_type];

// Получаем параметры фильтрации из GET
$filter_country = isset($_GET['country']) ? intval($_GET['country']) : 0;
$filter_service = isset($_GET['service']) ? $_GET['service'] : '';
$filter_year_from = isset($_GET['year_from']) ? intval($_GET['year_from']) : 0;
$filter_year_to = isset($_GET['year_to']) ? intval($_GET['year_to']) : 0;
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'name';

// Получаем список стран для фильтра
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

// Формируем SQL запрос с фильтрами
$sql = "
    SELECT v.*, c.Name as CountryName, t.Name as TypeName, w.Name as WeaponName, 
           w.Type as WeaponType, w.Calibre as WeaponCalibre,
           s.Year_of_commissioning, s.Year_of_decommissioning, s.In_service,
           vi.ImagePath, vi.ImageType
    FROM Vehicle v
    LEFT JOIN Country c ON v.Country_ID = c.ID
    LEFT JOIN Type t ON v.Type_ID = t.ID
    LEFT JOIN Weapon w ON v.Weapon_ID = w.ID
    LEFT JOIN Service s ON v.Service_ID = s.ID
    LEFT JOIN vehicle_images vi ON v.ID = vi.Vehicle_ID
    WHERE v.Class_ID = 1 AND v.Generation_ID = ?
";

$params = [$tank_type];
$types = "i";

// Добавляем фильтр по стране
if ($filter_country > 0) {
    $sql .= " AND v.Country_ID = ?";
    $params[] = $filter_country;
    $types .= "i";
}

// Добавляем фильтр по статусу службы
if ($filter_service === 'active') {
    $sql .= " AND s.In_service = 1";
} elseif ($filter_service === 'inactive') {
    $sql .= " AND s.In_service = 0";
}

// Добавляем фильтр по году ввода в эксплуатацию
if ($filter_year_from > 0) {
    $sql .= " AND s.Year_of_commissioning >= ?";
    $params[] = $filter_year_from;
    $types .= "i";
}

if ($filter_year_to > 0) {
    $sql .= " AND s.Year_of_commissioning <= ?";
    $params[] = $filter_year_to;
    $types .= "i";
}

// Добавляем сортировку
switch ($sort_by) {
    case 'year_asc':
        $sql .= " ORDER BY s.Year_of_commissioning ASC";
        break;
    case 'year_desc':
        $sql .= " ORDER BY s.Year_of_commissioning DESC";
        break;
    case 'country':
        $sql .= " ORDER BY c.Name ASC, v.Name ASC";
        break;
    case 'name':
    default:
        $sql .= " ORDER BY v.Name ASC";
        break;
}

// Выполняем запрос
$tanks = [];
$stmt = $mysqli->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $tanks[] = $row;
    }
    $stmt->close();
}

// Проверяем права доступа для кнопки добавления
$can_add_article = false;
$can_delete_article = false;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $mysqli->prepare("SELECT Role_ID FROM Users WHERE ID = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if ($user && in_array($user['Role_ID'], [2, 3, 4])) {
            $can_add_article = true;
            $can_delete_article = true;
        }
    }
}

// Обработка удаления танка
if (isset($_POST['delete_tank']) && $can_delete_article) {
    $tank_id = intval($_POST['tank_id']);
    
    // Получаем информацию о танке для лога
    $tank_info_stmt = $mysqli->prepare("SELECT Name FROM Vehicle WHERE ID = ?");
    $tank_info_stmt->bind_param("i", $tank_id);
    $tank_info_stmt->execute();
    $tank_info_result = $tank_info_stmt->get_result();
    $tank_info = $tank_info_result->fetch_assoc();
    $tank_info_stmt->close();
    
    // Начинаем транзакцию для безопасного удаления
    $mysqli->begin_transaction();
    
    try {
        // Удаляем связанные изображения
        $delete_images_stmt = $mysqli->prepare("DELETE FROM vehicle_images WHERE Vehicle_ID = ?");
        $delete_images_stmt->bind_param("i", $tank_id);
        $delete_images_stmt->execute();
        $delete_images_stmt->close();
        
        // Удаляем сам танк
        $delete_tank_stmt = $mysqli->prepare("DELETE FROM Vehicle WHERE ID = ?");
        $delete_tank_stmt->bind_param("i", $tank_id);
        $delete_tank_stmt->execute();
        $delete_tank_stmt->close();
        
        // Подтверждаем транзакцию
        $mysqli->commit();
        
        // Логируем удаление
        if ($tank_info) {
            $logger->logDelete('Vehicle', $tank_id, "Удален танк: " . $tank_info['Name']);
        }
        
        // Редирект для обновления страницы
        header("Location: tanks.php?type=" . $tank_type . "&deleted=1");
        exit();
        
    } catch (Exception $e) {
        // Откатываем транзакцию в случае ошибки
        $mysqli->rollback();
        $delete_error = "Ошибка при удалении танка: " . $e->getMessage();
    }
}

// Получаем количество танков для отображения
$tanks_count = is_array($tanks) ? count($tanks) : 0;
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Военный справочник</title>
    <link rel="stylesheet" href="vehicle.css">
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
                        <a href="account.php" class="user-item">Перейти в профиль</a>
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
            <!-- Фильтры слева -->
            <div class="filter-toggle-container">
                <button type="button" class="filter-toggle-btn" id="filterToggle">
                    Фильтры и сортировка
                </button>
            </div>
            
            <!-- Добавить справа -->
            <?php if ($can_add_article): ?>
                <div class="add-btn-container">
                    <a href="add_tank.php?type=<?php echo $tank_type; ?>" class="add-btn">+ Добавить танк</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Секция фильтров (изначально скрыта) -->
        <div class="filter-section" id="filterSection">
            <form method="GET" class="filter-form" id="filterForm">
                <input type="hidden" name="type" value="<?php echo $tank_type; ?>">
                
                <div class="filter-group">
                    <label class="filter-label">Страна</label>
                    <select name="country" class="filter-select">
                        <option value="0">Все страны</option>
                        <?php foreach ($countries as $country): ?>
                            <option value="<?php echo $country['ID']; ?>" 
                                <?php echo $filter_country == $country['ID'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($country['Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Статус службы</label>
                    <select name="service" class="filter-select">
                        <option value="">Все статусы</option>
                        <option value="active" <?php echo $filter_service === 'active' ? 'selected' : ''; ?>>В эксплуатации</option>
                        <option value="inactive" <?php echo $filter_service === 'inactive' ? 'selected' : ''; ?>>Снят с эксплуатации</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Год ввода в эксплуатацию</label>
                    <div class="filter-row">
                        <input type="number" name="year_from" class="filter-input" 
                               placeholder="От" value="<?php echo $filter_year_from > 0 ? $filter_year_from : ''; ?>" min="1900" max="2030">
                        <input type="number" name="year_to" class="filter-input" 
                               placeholder="До" value="<?php echo $filter_year_to > 0 ? $filter_year_to : ''; ?>" min="1900" max="2030">
                    </div>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Сортировка</label>
                    <select name="sort" class="filter-select">
                        <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>По названию (А-Я)</option>
                        <option value="year_asc" <?php echo $sort_by === 'year_asc' ? 'selected' : ''; ?>>По году (возрастание)</option>
                        <option value="year_desc" <?php echo $sort_by === 'year_desc' ? 'selected' : ''; ?>>По году (убывание)</option>
                        <option value="country" <?php echo $sort_by === 'country' ? 'selected' : ''; ?>>По стране</option>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="filter-btn">Применить фильтры</button>
                    <button type="button" class="filter-btn reset" onclick="resetFilters()">Сбросить фильтры</button>
                </div>
            </form>
            
            <!-- Активные фильтры -->
            <?php if ($filter_country > 0 || $filter_service || $filter_year_from > 0 || $filter_year_to > 0): ?>
                <div class="active-filters">
                    <strong>Активные фильтры:</strong>
                    <?php if ($filter_country > 0): 
                        $country_name = '';
                        foreach ($countries as $c) {
                            if ($c['ID'] == $filter_country) {
                                $country_name = $c['Name'];
                                break;
                            }
                        }
                    ?>
                        <div class="filter-tag">
                            Страна: <?php echo htmlspecialchars($country_name); ?>
                            <button type="button" class="filter-tag-remove" onclick="removeFilter('country')">×</button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($filter_service === 'active'): ?>
                        <div class="filter-tag">
                            В эксплуатации
                            <button type="button" class="filter-tag-remove" onclick="removeFilter('service')">×</button>
                        </div>
                    <?php elseif ($filter_service === 'inactive'): ?>
                        <div class="filter-tag">
                            Снят с эксплуатации
                            <button type="button" class="filter-tag-remove" onclick="removeFilter('service')">×</button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($filter_year_from > 0): ?>
                        <div class="filter-tag">
                            Год от: <?php echo $filter_year_from; ?>
                            <button type="button" class="filter-tag-remove" onclick="removeFilter('year_from')">×</button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($filter_year_to > 0): ?>
                        <div class="filter-tag">
                            Год до: <?php echo $filter_year_to; ?>
                            <button type="button" class="filter-tag-remove" onclick="removeFilter('year_to')">×</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Сообщение об успешном удалении -->
        <?php if (isset($_GET['deleted']) && $_GET['deleted'] == 1): ?>
            <div class="success-message">
                Танк успешно удален!
            </div>
        <?php endif; ?>

        <?php if (isset($delete_error)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($delete_error); ?>
            </div>
        <?php endif; ?>

        <!-- Количество результатов -->
        <div class="results-count">
            Найдено танков: <?php echo $tanks_count; ?>
        </div>

        <?php if ($tanks_count === 0): ?>
            <div class="no-vehicles">
                <p><?php echo $tank_type_names[$tank_type]; ?> не найдены</p>
                <?php if ($filter_country > 0 || $filter_service || $filter_year_from > 0 || $filter_year_to > 0): ?>
                    <p>Попробуйте изменить параметры фильтрации</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="vehicle-list">
                <?php foreach ($tanks as $tank): ?>
                    <div class="vehicle-item">
                        <?php if (!empty($tank['ImagePath'])): ?>
                            <img src="../<?php echo htmlspecialchars($tank['ImagePath']); ?>"
                                 alt="<?php echo htmlspecialchars($tank['Name']); ?>" 
                                 class="vehicle-image">
                        <?php else: ?>
                            <div class="vehicle-image-placeholder">
                                <span>Нет изображения</span>
                            </div>
                        <?php endif; ?>
                        
                        <div class="vehicle-content">
                            <a href="tank_detail.php?id=<?php echo $tank['ID']; ?>&from=tanks&type=<?php echo $tank_type; ?>" class="vehicle-name-link">
                                <h3 class="vehicle-name"><?php echo htmlspecialchars($tank['Name']); ?></h3>
                            </a>
                            
                            <div class="vehicle-details">
                                <?php if (!empty($tank['CountryName'])): ?>
                                    <div class="detail-group">
                                        <span class="detail-label">Страна:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($tank['CountryName']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($tank['TypeName'])): ?>
                                    <div class="detail-group">
                                        <span class="detail-label">Тип:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($tank['TypeName']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($tank['WeaponName'])): ?>
                                    <div class="detail-group">
                                        <span class="detail-label">Вооружение:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($tank['WeaponName']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($tank['Year_of_commissioning'])): ?>
                                    <div class="detail-group">
                                        <span class="detail-label">Начало службы:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($tank['Year_of_commissioning']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <span class="service-status <?php echo ($tank['In_service'] == 1) ? 'service-active' : 'service-inactive'; ?>">
                                    <?php echo ($tank['In_service'] == 1) ? 'В эксплуатации' : 'Снят с эксплуатации'; ?>
                                </span>
                            </div>
                            
                            <?php if ($can_delete_article): ?>
                                <div class="vehicle-actions">
                                    <button type="button" class="delete-btn" 
                                            onclick="showDeleteModal(<?php echo $tank['ID']; ?>, '<?php echo htmlspecialchars(addslashes($tank['Name'])); ?>')">
                                        Удалить
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно подтверждения удаления -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal-content">
            <h3 class="modal-title">Подтверждение удаления</h3>
            <p class="modal-text" id="deleteModalText">
                Вы действительно хотите удалить танк?
            </p>
            <div class="modal-actions">
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="tank_id" id="deleteTankId">
                    <input type="hidden" name="delete_tank" value="1">
                    <button type="submit" class="modal-btn confirm">Удалить</button>
                </form>
                <button type="button" class="modal-btn cancel" onclick="hideDeleteModal()">Отмена</button>
            </div>
        </div>
    </div>

    <script>
        // Плавная прокрутка к элементу при загрузке страницы, если есть якорь
        document.addEventListener('DOMContentLoaded', function() {
            if (window.location.hash) {
                const element = document.querySelector(window.location.hash);
                if (element) {
                    setTimeout(() => {
                        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 100);
                }
            }

            // Управление открытием/закрытием фильтров
            const filterToggle = document.getElementById('filterToggle');
            const filterSection = document.getElementById('filterSection');

            // Автоматически открываем фильтры если есть активные
            const hasActiveFilters = <?php echo ($filter_country > 0 || $filter_service || $filter_year_from > 0 || $filter_year_to > 0) ? 'true' : 'false'; ?>;
            
            if (hasActiveFilters) {
                filterSection.classList.add('active');
                filterToggle.classList.add('active');
            }

            filterToggle.addEventListener('click', function() {
                filterSection.classList.toggle('active');
                filterToggle.classList.toggle('active');
            });

            // Авто-сабмит при изменении некоторых полей
            const autoSubmitFields = document.querySelectorAll('select[name="sort"]');
            autoSubmitFields.forEach(field => {
                field.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            });
        });

        // Сброс фильтров
        function resetFilters() {
            const url = new URL(window.location.href);
            url.search = '?type=' + <?php echo $tank_type; ?>;
            window.location.href = url.toString();
        }

        // Удаление конкретного фильтра
        function removeFilter(filterName) {
            const url = new URL(window.location.href);
            url.searchParams.delete(filterName);
            window.location.href = url.toString();
        }

        // Функции для модального окна удаления
        function showDeleteModal(tankId, tankName) {
            const modal = document.getElementById('deleteModal');
            const modalText = document.getElementById('deleteModalText');
            const deleteForm = document.getElementById('deleteForm');
            const deleteTankId = document.getElementById('deleteTankId');
            
            modalText.textContent = `Вы действительно хотите удалить танк "${tankName}"? Это действие нельзя отменить.`;
            deleteTankId.value = tankId;
            
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function hideDeleteModal() {
            const modal = document.getElementById('deleteModal');
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Закрытие модального окна при клике вне его
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideDeleteModal();
            }
        });

        // Закрытие модального окна при нажатии Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideDeleteModal();
            }
        });
    </script>
</body>
</html>