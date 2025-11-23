<?php
class Logger {
    private $mysqli;
    
    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
    }
    
    private function getUserIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }
    
    private function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    public function log($action_type, $table_name = null, $record_id = null, $description = null, $old_values = null, $new_values = null) {
        $user_id = $_SESSION['user_id'] ?? null;
        $ip_address = $this->getUserIP();
        $user_agent = $this->getUserAgent();
        
        // Не логируем системные события или события без пользователя
        if (!$user_id) {
            return false;
        }
        
        // Преобразуем массивы в JSON
        if (is_array($old_values)) {
            $old_values = json_encode($old_values, JSON_UNESCAPED_UNICODE);
        }
        
        if (is_array($new_values)) {
            $new_values = json_encode($new_values, JSON_UNESCAPED_UNICODE);
        }
        
        $stmt = $this->mysqli->prepare("
            INSERT INTO Logs (User_ID, Action_Type, Table_Name, Record_ID, Description, Old_Values, New_Values, IP_Address, User_Agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param(
            "ississsss", 
            $user_id, 
            $action_type, 
            $table_name, 
            $record_id, 
            $description, 
            $old_values, 
            $new_values, 
            $ip_address, 
            $user_agent
        );
        
        return $stmt->execute();
    }
    
    // Специальные методы для разных типов действий
    public function logLogin($user_id, $success = true) {
        // Для логина используем переданный user_id, а не из сессии
        $ip_address = $this->getUserIP();
        $user_agent = $this->getUserAgent();
        
        $stmt = $this->mysqli->prepare("
            INSERT INTO Logs (User_ID, Action_Type, Table_Name, Record_ID, Description, IP_Address, User_Agent) 
            VALUES (?, 'login', 'Users', ?, ?, ?, ?)
        ");
        
        $description = $success ? 'Успешный вход в систему' : 'Неудачная попытка входа';
        $stmt->bind_param("issss", $user_id, $user_id, $description, $ip_address, $user_agent);
        
        return $stmt->execute();
    }
    
    public function logLogout($user_id) {
        // Для логаута используем переданный user_id
        $ip_address = $this->getUserIP();
        $user_agent = $this->getUserAgent();
        
        $stmt = $this->mysqli->prepare("
            INSERT INTO Logs (User_ID, Action_Type, Table_Name, Record_ID, Description, IP_Address, User_Agent) 
            VALUES (?, 'logout', 'Users', ?, ?, ?, ?)
        ");
        
        $description = 'Выход из системы';
        $stmt->bind_param("issss", $user_id, $user_id, $description, $ip_address, $user_agent);
        
        return $stmt->execute();
    }
    
    public function logUserBan($target_user_id, $banned_by) {
        return $this->log('ban', 'Users', $target_user_id, "Пользователь заблокирован администратором ID: $banned_by");
    }
    
    public function logUserUnban($target_user_id, $unbanned_by) {
        return $this->log('unban', 'Users', $target_user_id, "Пользователь разблокирован администратором ID: $unbanned_by");
    }
    
    public function logUserDelete($target_user_id, $deleted_by) {
        return $this->log('delete', 'Users', $target_user_id, "Пользователь удален администратором ID: $deleted_by");
    }
    
    public function logRoleChange($target_user_id, $old_role, $new_role, $changed_by) {
        $description = "Роль пользователя изменена с $old_role на $new_role администратором ID: $changed_by";
        return $this->log('role_change', 'Users', $target_user_id, $description);
    }
    
    // Методы для работы с техникой
    public function logVehicleCreate($vehicle_id, $vehicle_name) {
        return $this->log('create', 'Vehicle', $vehicle_id, "Добавлена техника: $vehicle_name");
    }
    
    public function logVehicleUpdate($vehicle_id, $vehicle_name, $old_values = null, $new_values = null) {
        return $this->log('update', 'Vehicle', $vehicle_id, "Обновлена техника: $vehicle_name", $old_values, $new_values);
    }
    
    public function logVehicleDelete($vehicle_id, $vehicle_name) {
        return $this->log('delete', 'Vehicle', $vehicle_id, "Удалена техника: $vehicle_name");
    }
    
    public function logVehicleImageUpdate($vehicle_id, $vehicle_name) {
        return $this->log('update', 'Vehicle', $vehicle_id, "Обновлено изображение для техники: $vehicle_name");
    }
    
    // Методы для избранного
    public function logFavoriteAdd($vehicle_id, $vehicle_name) {
        return $this->log('favorite', 'user_favorites', $vehicle_id, "Добавлено в избранное: $vehicle_name");
    }
    
    public function logFavoriteRemove($vehicle_id, $vehicle_name) {
        return $this->log('favorite', 'user_favorites', $vehicle_id, "Удалено из избранного: $vehicle_name");
    }
    
    // Общие методы для других таблиц
    public function logCreate($table_name, $record_id, $description = null) {
        return $this->log('create', $table_name, $record_id, $description);
    }
    
    public function logUpdate($table_name, $record_id, $old_values, $new_values, $description = null) {
        return $this->log('update', $table_name, $record_id, $description, $old_values, $new_values);
    }
    
    public function logDelete($table_name, $record_id, $description = null) {
        return $this->log('delete', $table_name, $record_id, $description);
    }
    
    // Метод для принудительного логирования системных событий (если нужно)
    public function logSystemEvent($action_type, $table_name = null, $record_id = null, $description = null) {
        $ip_address = $this->getUserIP();
        $user_agent = $this->getUserAgent();
        
        $stmt = $this->mysqli->prepare("
            INSERT INTO Logs (User_ID, Action_Type, Table_Name, Record_ID, Description, IP_Address, User_Agent) 
            VALUES (NULL, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("ssisss", $action_type, $table_name, $record_id, $description, $ip_address, $user_agent);
        
        return $stmt->execute();
    }
}
?>