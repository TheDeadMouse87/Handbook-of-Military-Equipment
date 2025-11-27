<?php
session_start();

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php");
    exit();
}

// Подключаем базу данных
include 'connect.php';

// Проверяем данные пользователя
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

// Определяем права доступа
$is_main_admin = ($user['Role_ID'] == 4); // Главный администратор
$is_admin = ($user['Role_ID'] == 2); // Обычный администратор

class DataEmulator {
    private $invalidEndChars = ['-', '|', '^', '\\', '/', '*', '+', '=', '~', '`', '@', '#', '$', '%', '&', '(', ')', '[', ']', '{', '}', '<', '>', '?', '!', ';', ':', '"', ',', '.'];
    
    /**
     * Получает данные с удаленного сервера
     */
    public function fetchDataFromRemote() {
        $url = 'http://prb.sylas.ru/TransferSimulator/fullName';
        
        try {
            // Используем cURL для получения данных
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_error($ch)) {
                throw new Exception('cURL Error: ' . curl_error($ch));
            }
            
            curl_close($ch);
            
            if ($httpCode !== 200) {
                throw new Exception('HTTP Error: ' . $httpCode);
            }
            
            // Декодируем JSON ответ
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON: ' . json_last_error_msg());
            }
            
            return $data;
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Ошибка при получении данных: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Проверяет наличие английских букв в строке
     */
    public function hasEnglishLetters($text) {
        return preg_match('/[a-zA-Z]/', $text);
    }
    
    /**
     * Проверяет корректность ФИО
     */
    public function checkFullName($data) {
        // Если данные уже содержат статус ошибки (от fetchDataFromRemote)
        if (isset($data['status']) && $data['status'] === 'error') {
            return $data;
        }
        
        // Проверяем структуру данных
        if (!isset($data['value'])) {
            return [
                'status' => 'error',
                'message' => 'Отсутствует поле value в данных'
            ];
        }
        
        if (empty(trim($data['value']))) {
            return [
                'status' => 'error',
                'message' => 'Значение value пустое'
            ];
        }
        
        $fullName = trim($data['value']);
        
        // Разделяем ФИО на части
        $nameParts = array_filter(explode(' ', $fullName)); // Убираем пустые элементы
        
        // Проверяем, что есть как минимум фамилия и имя
        if (count($nameParts) < 2) {
            return [
                'status' => 'error',
                'message' => 'Недостаточно данных. Требуется Фамилия Имя Отчество',
                'received_data' => $fullName
            ];
        }
        
        // Извлекаем фамилию и имя (первые два элемента)
        $lastName = trim($nameParts[0]);
        $firstName = trim($nameParts[1]);
        
        // Проверяем фамилию
        $lastNameCheck = $this->checkNamePart($lastName, 'Фамилия');
        if ($lastNameCheck['status'] === 'error') {
            return $lastNameCheck;
        }
        
        // Проверяем имя
        $firstNameCheck = $this->checkNamePart($firstName, 'Имя');
        if ($firstNameCheck['status'] === 'error') {
            return $firstNameCheck;
        }
        
        // Проверяем отчество, если есть
        $middleName = '';
        if (count($nameParts) >= 3) {
            $middleName = trim($nameParts[2]);
            $middleNameCheck = $this->checkNamePart($middleName, 'Отчество');
            if ($middleNameCheck['status'] === 'error') {
                return $middleNameCheck;
            }
        }
        
        // Проверяем наличие английских букв
        $englishLettersCheck = $this->checkEnglishLetters($lastName, $firstName, $middleName);
        
        return [
            'status' => 'success',
            'message' => 'Данные корректны',
            'data' => [
                'full_name' => $fullName,
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_name' => $middleName
            ],
            'english_letters_check' => $englishLettersCheck
        ];
    }
    
    /**
     * Проверяет наличие английских букв в ФИО
     */
    private function checkEnglishLetters($lastName, $firstName, $middleName) {
        $hasEnglish = false;
        $details = [];
        
        if ($this->hasEnglishLetters($lastName)) {
            $hasEnglish = true;
            $details[] = [
                'part' => 'Фамилия',
                'value' => $lastName,
                'message' => 'Содержит английские буквы'
            ];
        }
        
        if ($this->hasEnglishLetters($firstName)) {
            $hasEnglish = true;
            $details[] = [
                'part' => 'Имя',
                'value' => $firstName,
                'message' => 'Содержит английские буквы'
            ];
        }
        
        if (!empty($middleName) && $this->hasEnglishLetters($middleName)) {
            $hasEnglish = true;
            $details[] = [
                'part' => 'Отчество',
                'value' => $middleName,
                'message' => 'Содержит английские буквы'
            ];
        }
        
        return [
            'has_english_letters' => $hasEnglish,
            'details' => $details
        ];
    }
    
    /**
     * Проверяет отдельную часть имени (фамилию, имя или отчество)
     */
    private function checkNamePart($namePart, $partName) {
        // Проверяем, что строка не пустая
        if (empty($namePart)) {
            return [
                'status' => 'error',
                'message' => "$partName не может быть пустым"
            ];
        }
        
        // Проверяем последний символ
        $lastChar = substr($namePart, -1);
        
        if (in_array($lastChar, $this->invalidEndChars)) {
            return [
                'status' => 'error',
                'message' => "Некорректные данные: $partName '$namePart' содержит недопустимый символ в конце ('$lastChar')"
            ];
        }
        
        return [
            'status' => 'success'
        ];
    }
    
    /**
     * Основной метод выполнения проверки
     */
    public function executeCheck() {
        // Получаем данные с удаленного сервера
        $remoteData = $this->fetchDataFromRemote();
        
        // Проверяем полученные данные
        return $this->checkFullName($remoteData);
    }
    
    /**
     * Получает список недопустимых символов (для информации)
     */
    public function getInvalidChars() {
        return $this->invalidEndChars;
    }
}

// Обработка запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emulator = new DataEmulator();
    $result = $emulator->executeCheck();
    
    header('Content-Type: application/json');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} else {
    // GET запрос - показываем интерфейс и результаты проверки
    $emulator = new DataEmulator();
    
    // Получаем исходные данные
    $sourceData = $emulator->fetchDataFromRemote();
    
    // Выполняем проверку
    $result = $emulator->checkFullName($sourceData);
    
    echo "<!DOCTYPE html>
    <html lang='ru'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Эмулятор - Проверка ФИО</title>
        <link rel='stylesheet' href='admin_panel.css'>
    </head>
    <body>
        <!-- Верхняя панель -->
        <div class='top-bar'>
            <div class='nav-menu'>
                <div class='nav-item'>
                    <a href='main.php' class='nav-link'>Главная</a>
                </div>
            </div>
            
            <div class='admin-title'>
                <h1>Эмулятор</h1>
            </div>
            
            <div class='auth-section'>";
    
    if (isset($_SESSION['user_id'])) {
        echo "<div class='user-menu'>
                <div class='user-btn'>
                    " . htmlspecialchars($_SESSION['user_login']) . "
                </div>
                <div class='user-dropdown'>
                    <a href='account.php' class='user-item'>Перейти в профиль</a>
                    <a href='auth.php?logout=true' class='user-item'>Выйти из аккаунта</a>
                </div>
            </div>";
    } else {
        echo "<a href='auth.php?register=true' class='auth-btn'>Регистрация</a>
              <a href='auth.php' class='auth-btn'>Авторизация</a>";
    }
    
    echo "</div>
        </div>

        <!-- Основной контент -->
        <div class='main-content'>
            <div class='section-title'>
                Проверка корректности ФИО
            </div>

            <!-- Информация об источнике данных -->
            <div class='data-section'>
                <div class='section-header'>Источник данных</div>
                <div class='info'>
                    <strong>URL:</strong> http://prb.sylas.ru/TransferSimulator/fullName
                </div>
                
                <div class='section-header'>Полученные данные</div>";
    
    if (isset($sourceData['status']) && $sourceData['status'] === 'error') {
        echo "<div class='error'><strong>Ошибка:</strong> " . $sourceData['message'] . "</div>";
    } else {
        echo "<pre>" . json_encode($sourceData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "</pre>";
        
        if (isset($sourceData['value'])) {
            echo "<div class='info'>
                    <strong>Текст для проверки:</strong> \"" . htmlspecialchars($sourceData['value']) . "\"
                  </div>";
        }
    }
    
    echo "</div>

            <!-- Результаты проверки -->
            <div class='data-section'>
                <div class='section-header'>Результат проверки</div>";
    
    if ($result['status'] === 'success') {
        echo "<div class='success'>
                <strong>✓ " . $result['message'] . "</strong>
              </div>";
        
        // Проверка на английские буквы
        if (isset($result['english_letters_check']) && $result['english_letters_check']['has_english_letters']) {
            echo "<div class='english-warning'>
                    <strong>⚠ Обнаружены английские буквы</strong>
                    <div class='english-details'>";
            
            foreach ($result['english_letters_check']['details'] as $detail) {
                echo "<div class='english-detail-item'>
                        <strong>" . $detail['part'] . ":</strong> \"" . htmlspecialchars($detail['value']) . "\" - " . $detail['message'] . "
                      </div>";
            }
            
            echo "</div></div>";
        } else {
            echo "<div class='english-info'>
                    <strong>✓ Английские буквы не обнаружены</strong>
                  </div>";
        }
        
        echo "<div class='data-grid'>
                <div class='data-card'>
                    <h3>Полное ФИО</h3>
                    <p>" . htmlspecialchars($result['data']['full_name']) . "</p>
                </div>
                <div class='data-card'>
                    <h3>Фамилия</h3>
                    <p>" . htmlspecialchars($result['data']['last_name']) . "</p>
                </div>
                <div class='data-card'>
                    <h3>Имя</h3>
                    <p>" . htmlspecialchars($result['data']['first_name']) . "</p>
                </div>";
        
        if (!empty($result['data']['middle_name'])) {
            echo "<div class='data-card'>
                    <h3>Отчество</h3>
                    <p>" . htmlspecialchars($result['data']['middle_name']) . "</p>
                  </div>";
        }
        
        echo "</div>";
    } else {
        echo "<div class='error'>
                <strong>✗ " . $result['message'] . "</strong>
              </div>";
    }
    
    echo "</div>

            <!-- Недопустимые символы -->
            <div class='data-section'>
                <div class='section-header'>Недопустимые символы</div>
                <div class='warning'>
                    Не допускаются в конце фамилии, имени или отчества:
                </div>
                <div class='invalid-chars'>";
    
    $invalidChars = $emulator->getInvalidChars();
    foreach ($invalidChars as $char) {
        echo "<span class='char-badge'>" . htmlspecialchars($char) . "</span>";
    }
    
    echo "</div>
            </div>

            <!-- Отладочная информация -->
            <div class='data-section'>
                <div class='section-header'>Отладочная информация</div>
                <div class='debug-info'>
                    <p><strong>Время проверки:</strong> " . date('Y-m-d H:i:s') . "</p>";
    
    if (isset($sourceData['value'])) {
        $nameParts = array_filter(explode(' ', $sourceData['value']));
        $invalidChars = $emulator->getInvalidChars();
        
        // Фамилия
        if (count($nameParts) >= 1) {
            $lastName = trim($nameParts[0]);
            $lastChar = substr($lastName, -1);
            $hasEnglish = $emulator->hasEnglishLetters($lastName);
            $statusClass = in_array($lastChar, $invalidChars) ? 'status-incorrect' : ($hasEnglish ? 'status-warning' : 'status-correct');
            $statusText = in_array($lastChar, $invalidChars) ? 'Некорректный символ' : ($hasEnglish ? 'Есть английские буквы' : 'Корректный символ');
            $displayChar = in_array($lastChar, $invalidChars) ? htmlspecialchars($lastChar) : ' ';
            
            echo "<p><strong>Фамилия:</strong> \"" . htmlspecialchars($lastName) . "\"</p>";
            echo "<p><strong>Последний символ фамилии:</strong> \"" . $displayChar . "\"</p>";
            echo "<p><strong>Английские буквы в фамилии:</strong> " . ($hasEnglish ? 'Да' : 'Нет') . "</p>";
            echo "<p><strong>Статус фамилии:</strong> <span class='status-badge $statusClass'>$statusText</span></p>";
            echo "<hr style='border: none; border-top: 1px solid #3d4630; margin: 0.5rem 0;'>";
        }
        
        // Имя
        if (count($nameParts) >= 2) {
            $firstName = trim($nameParts[1]);
            $firstChar = substr($firstName, -1);
            $hasEnglish = $emulator->hasEnglishLetters($firstName);
            $statusClass = in_array($firstChar, $invalidChars) ? 'status-incorrect' : ($hasEnglish ? 'status-warning' : 'status-correct');
            $statusText = in_array($firstChar, $invalidChars) ? 'Некорректный символ' : ($hasEnglish ? 'Есть английские буквы' : 'Корректный символ');
            $displayChar = in_array($firstChar, $invalidChars) ? htmlspecialchars($firstChar) : ' ';
            
            echo "<p><strong>Имя:</strong> \"" . htmlspecialchars($firstName) . "\"</p>";
            echo "<p><strong>Последний символ имени:</strong> \"" . $displayChar . "\"</p>";
            echo "<p><strong>Английские буквы в имени:</strong> " . ($hasEnglish ? 'Да' : 'Нет') . "</p>";
            echo "<p><strong>Статус имени:</strong> <span class='status-badge $statusClass'>$statusText</span></p>";
            
            if (count($nameParts) >= 3) {
                echo "<hr style='border: none; border-top: 1px solid #3d4630; margin: 0.5rem 0;'>";
            }
        }
        
        // Отчество
        if (count($nameParts) >= 3) {
            $middleName = trim($nameParts[2]);
            $middleChar = substr($middleName, -1);
            $hasEnglish = $emulator->hasEnglishLetters($middleName);
            $statusClass = in_array($middleChar, $invalidChars) ? 'status-incorrect' : ($hasEnglish ? 'status-warning' : 'status-correct');
            $statusText = in_array($middleChar, $invalidChars) ? 'Некорректный символ' : ($hasEnglish ? 'Есть английские буквы' : 'Корректный символ');
            $displayChar = in_array($middleChar, $invalidChars) ? htmlspecialchars($middleChar) : ' ';
            
            echo "<p><strong>Отчество:</strong> \"" . htmlspecialchars($middleName) . "\"</p>";
            echo "<p><strong>Последний символ отчества:</strong> \"" . $displayChar . "\"</p>";
            echo "<p><strong>Английские буквы в отчестве:</strong> " . ($hasEnglish ? 'Да' : 'Нет') . "</p>";
            echo "<p><strong>Статус отчества:</strong> <span class='status-badge $statusClass'>$statusText</span></p>";
        }
        
        // Общий статус
        echo "<hr style='border: none; border-top: 1px solid #3d4630; margin: 0.5rem 0;'>";
        $overallStatus = ($result['status'] === 'success') ? 'Все данные корректны' : 'Обнаружены ошибки в данных';
        $overallStatusClass = ($result['status'] === 'success') ? 'status-correct' : 'status-incorrect';
        echo "<p><strong>Общий статус проверки:</strong> <span class='status-badge $overallStatusClass'>$overallStatus</span></p>";
        
        // Статус английских букв
        $hasEnglishOverall = isset($result['english_letters_check']) && $result['english_letters_check']['has_english_letters'];
        $englishStatus = $hasEnglishOverall ? 'Обнаружены английские буквы' : 'Английские буквы не обнаружены';
        $englishStatusClass = $hasEnglishOverall ? 'status-warning' : 'status-correct';
        echo "<p><strong>Статус английских букв:</strong> <span class='status-badge $englishStatusClass'>$englishStatus</span></p>";
    }
    
    echo "    </div>
            </div>

            <!-- Кнопки действий -->
            <div class='action-buttons'>
                <button onclick='location.reload()'>Обновить проверку</button>
            </div>
        </div>
    </body>
    </html>";
}
?>