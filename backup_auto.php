<?php
/**
 * Автоматический скрипт бэкапа
 * Запускать через cron: 0 2 * * * /usr/bin/php /path/to/backup_auto.php
 */

include 'connect.php';

function autoBackup($mysqli) {
    $backupDir = 'backups/auto/';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    // Удаляем старые бэкапы (старше 7 дней)
    $files = glob($backupDir . '*.sql');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file)) {
            if ($now - filemtime($file) >= 7 * 24 * 60 * 60) {
                unlink($file);
            }
        }
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = $backupDir . 'auto_backup_' . $timestamp . '.sql';
    
    // Создаем бэкап базы данных
    $tables = [];
    $result = $mysqli->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
    
    $sqlScript = "-- Auto backup created on " . date('Y-m-d H:i:s') . "\n\n";
    
    foreach ($tables as $table) {
        $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n";
        $createTable = $mysqli->query("SHOW CREATE TABLE `$table`");
        $row = $createTable->fetch_row();
        $sqlScript .= $row[1] . ";\n\n";
        
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
    
    if (file_put_contents($backupFile, $sqlScript)) {
        // Логируем успешное создание бэкапа
        error_log("Auto backup created: " . $backupFile);
        return true;
    } else {
        error_log("Auto backup failed: " . $backupFile);
        return false;
    }
}

// Запускаем автоматический бэкап
autoBackup($mysqli);
?>