<?php
$host = '134.90.167.42:10306';
$username = 'Agapova';
$password = 'JV4kK_';
$database = 'project_Agapova';

$mysqli = new mysqli($host, $username, $password, $database);

if ($mysqli->connect_error) {
    die("Ошибка подключения: " . $mysqli->connect_error);
}

$mysqli->set_charset("utf8");

// Установка временной зоны для Новосибирска (UTC+7)
date_default_timezone_set('Asia/Novosibirsk');
?>