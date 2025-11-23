<?php
session_start();

// Проверка авторизации при попытке доступа к защищенным страницам
if (isset($_GET['access_denied'])) {
    $access_denied = true;
} else {
    $access_denied = false;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Военный справочник</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php if ($access_denied): ?>
        <!-- Новое уведомление (справа) -->
        <div class="notification-right" id="accessNotificationRight">
            <div class="notification-right-content">
                <span class="notification-right-icon">🔒</span>
                <span class="notification-right-message">Для просмотра статей авторизуйтесь или зарегистрируйтесь</span>
                <button class="notification-right-close" onclick="closeNotification()">×</button>
            </div>
        </div>
    <?php endif; ?>

    <div class="top-bar">
        <nav class="nav-menu">
            <!-- Истребители -->
            <div class="nav-item">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- Для авторизованных пользователей -->
                    <a href="#" class="nav-link fighters">Истребители</a>
                    <div class="dropdown-menu">
                        <a href="vehicle/istrebiteli.php?gen=1" class="dropdown-item">1 поколение</a>
                        <a href="vehicle/istrebiteli.php?gen=2" class="dropdown-item">2 поколение</a>
                        <a href="vehicle/istrebiteli.php?gen=3" class="dropdown-item">3 поколение</a>
                        <a href="vehicle/istrebiteli.php?gen=4" class="dropdown-item">4 поколение</a>
                        <a href="vehicle/istrebiteli.php?gen=5" class="dropdown-item">5 поколение</a>
                    </div>
                <?php else: ?>
                    <!-- Для неавторизованных пользователей -->
                    <a href="main.php?access_denied=true" class="nav-link fighters unauthorized">Истребители</a>
                    <div class="dropdown-menu">
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">1 поколение</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">2 поколение</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">3 поколение</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">4 поколение</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">5 поколение</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Вертолёты -->
            <div class="nav-item">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- Для авторизованных пользователей -->
                    <a href="#" class="nav-link helicopters">Вертолёты</a>
                    <div class="dropdown-menu">
                        <a href="vehicle/helicopters.php?type=6" class="dropdown-item">Боевые</a>
                        <a href="vehicle/helicopters.php?type=7" class="dropdown-item">Транспортно-боевые</a>
                        <a href="vehicle/helicopters.php?type=8" class="dropdown-item">Транспортные</a>
                        <a href="vehicle/helicopters.php?type=9" class="dropdown-item">Специальные</a>
                    </div>
                <?php else: ?>
                    <!-- Для неавторизованных пользователей -->
                    <a href="main.php?access_denied=true" class="nav-link helicopters unauthorized">Вертолёты</a>
                    <div class="dropdown-menu">
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Боевые</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Транспортно-боевые</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Транспортные</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Специальные</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Бомбардировщики -->
            <div class="nav-item">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- Для авторизованных пользователей -->
                    <a href="#" class="nav-link bombers">Бомбардировщики</a>
                    <div class="dropdown-menu">
                        <a href="vehicle/bombers.php?type=10" class="dropdown-item">Стратегические</a>
                        <a href="vehicle/bombers.php?type=11" class="dropdown-item">Тактические</a>
                        <a href="vehicle/bombers.php?type=12" class="dropdown-item">Штурмовики</a>
                        <a href="vehicle/bombers.php?type=13" class="dropdown-item">Истребители</a>
                    </div>
                <?php else: ?>
                    <!-- Для неавторизованных пользователей -->
                    <a href="main.php?access_denied=true" class="nav-link bombers unauthorized">Бомбардировщики</a>
                    <div class="dropdown-menu">
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Стратегические</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Тактические</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Штурмовики</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Истребители</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Танки -->
            <div class="nav-item">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- Для авторизованных пользователей -->
                    <a href="#" class="nav-link tanks">Танки</a>
                    <div class="dropdown-menu">
                        <a href="vehicle/tanks.php?type=14" class="dropdown-item">Лёгкий танк</a>
                        <a href="vehicle/tanks.php?type=15" class="dropdown-item">Средний танк</a>
                        <a href="vehicle/tanks.php?type=16" class="dropdown-item">Тяжелый танк</a>
                        <a href="vehicle/tanks.php?type=18" class="dropdown-item">ПТ-САУ</a>
                    </div>
                <?php else: ?>
                    <!-- Для неавторизованных пользователей -->
                    <a href="main.php?access_denied=true" class="nav-link tanks unauthorized">Танки</a>
                    <div class="dropdown-menu">
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Лёгкий танк</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Средний танк</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Тяжелый танк</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">ПТ-САУ</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ПВО -->
            <div class="nav-item">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- Для авторизованных пользователей -->
                    <a href="#" class="nav-link pvo">ПВО</a>
                    <div class="dropdown-menu">
                        <a href="vehicle/pvo.php?type=19" class="dropdown-item">ЗРК и ЗРПК</a>
                        <a href="vehicle/pvo.php?type=20" class="dropdown-item">Переносные зенитные ракетные комплексы</a>
                        <a href="vehicle/pvo.php?type=21" class="dropdown-item">Зенитная артиллерия</a>
                    </div>
                <?php else: ?>
                    <!-- Для неавторизованных пользователей -->
                    <a href="main.php?access_denied=true" class="nav-link pvo unauthorized">ПВО</a>
                    <div class="dropdown-menu">
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">ЗРК и ЗРПК</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Переносные зенитные ракетные комплексы</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Зенитная артиллерия</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Средства разведки, управления и РЭБ -->
            <div class="nav-item">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- Для авторизованных пользователей -->
                    <a href="#" class="nav-link recon">Средства разведки, управления и РЭБ</a>
                    <div class="dropdown-menu">
                        <a href="vehicle/recon.php?type=22" class="dropdown-item">Боевые разведывательные машины</a>
                        <a href="vehicle/recon.php?type=23" class="dropdown-item">Радиоэлектронная разведка и подавление</a>
                        <a href="vehicle/recon.php?type=24" class="dropdown-item">Радиолокационные станции</a>
                        <a href="vehicle/recon.php?type=25" class="dropdown-item">Системы управления</a>
                    </div>
                <?php else: ?>
                    <!-- Для неавторизованных пользователей -->
                    <a href="main.php?access_denied=true" class="nav-link recon unauthorized">Средства разведки, управления и РЭБ</a>
                    <div class="dropdown-menu">
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Боевые разведывательные машины</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Радиоэлектронная разведка и подавление</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Радиолокационные станции</a>
                        <a href="main.php?access_denied=true" class="dropdown-item unauthorized">Системы управления</a>
                    </div>
                <?php endif; ?>
            </div>
        </nav>
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

    <div class="main-content">
        <section class="welcome-section">
            <h1 class="welcome-title">ДОБРО ПОЖАЛОВАТЬ В ВОЕННЫЙ СПРАВОЧНИК</h1>
            <p class="welcome-text">Перед вами — фундаментальный военный справочник, призванный стать надежным источником знаний и практическим пособием для всех, кто связан со сферой обороны и безопасности.

Структура и содержание данного издания разработаны с целью предоставить систематизированную, точную и проверенную информацию. На этих страницах вы найдете сведения по военной истории, структуре вооруженных сил, классификации вооружения и техники, основам тактики и стратегии, а также уставам и наставлениям.

Этот справочник предназначен не только для военнослужащих, курсантов военных училищ и специалистов оборонной промышленности, но и для всех, кто интересуется военным делом, историей и геополитикой. Мы стремились создать универсальный труд, который будет одинаково полезен как для углубленного изучения, так и для получения базовых знаний.

Доверие к источнику — основа любого справочника. Мы приложили все усилия, чтобы данные, приведенные здесь, были актуальными и достоверными. Пусть эта книга станет вашим верным соратником в профессиональной деятельности и учебе.

(Навигация по справочнику происходит по верхней панеле, чтобы авторизоваться или зарегистрироваться, нажмите в верхней правой части на авторизация/регистрация)</p>
            <?php if (!isset($_SESSION['user_id'])): ?>
                <p class="welcome-text" style="margin-top: 1rem; color: #8b966c; font-style: italic;">
                    Для доступа к полному содержимому справочника необходимо авторизоваться
                </p>
            <?php endif; ?>
        </section>
    </div>

    <script>
        // Функция для закрытия уведомления
        function closeNotification() {
            const notification = document.getElementById('accessNotificationRight');
            if (notification) {
                notification.classList.add('hiding');
                setTimeout(() => {
                    notification.style.display = 'none';
                }, 500);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const notificationRight = document.getElementById('accessNotificationRight');

            // Автоматическое скрытие уведомления через 5 секунд
            if (notificationRight) {
                setTimeout(() => {
                    closeNotification();
                }, 5000);
            }

            // Удаление параметра access_denied из URL без перезагрузки страницы
            if (window.location.search.includes('access_denied')) {
                const url = new URL(window.location);
                url.searchParams.delete('access_denied');
                window.history.replaceState({}, '', url);
            }
        });
    </script>
</body>
</html>