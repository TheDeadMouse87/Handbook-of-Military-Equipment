<?php
// Конфигурация Gmail
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'meriwin47@gmail.com'); // Замените на ваш Gmail
define('SMTP_PASSWORD', 'swbv utzs srqb kruq'); // Замените на пароль приложения
define('SMTP_FROM', 'meriwin47@gmail.com'); // Замените на ваш Gmail
define('SMTP_FROM_NAME', 'Справочник военной техники');

// Используем правильные namespace в начале файла
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function sendVerificationCode($email, $code) {
    // Используем PHPMailer для надежной отправки
    require_once 'PHPMailer/src/PHPMailer.php';
    require_once 'PHPMailer/src/SMTP.php';
    require_once 'PHPMailer/src/Exception.php';
    
    $mail = new PHPMailer(true);
    
    try {
        // Настройки сервера
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        
        // Дополнительные настройки для Gmail
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Кодировка
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        
        // Отправитель
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($email);
        $mail->addReplyTo(SMTP_FROM, SMTP_FROM_NAME);
        
        // Тема и содержимое
        $mail->isHTML(true);
        $mail->Subject = 'Код подтверждения для входа - Справочник военной техники';
        
        $message = "
        <!DOCTYPE html>
        <html lang='ru'>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { 
                    font-family: 'Arial', sans-serif; 
                    background-color: #f4f4f4; 
                    margin: 0; 
                    padding: 20px; 
                    color: #333;
                }
                .container { 
                    max-width: 600px; 
                    margin: 0 auto; 
                    background: white; 
                    border-radius: 10px; 
                    overflow: hidden; 
                    box-shadow: 0 4px 6px rgba(0,0,0,0.1); 
                }
                .header { 
                    background: #3d4630; 
                    color: white; 
                    padding: 25px; 
                    text-align: center; 
                }
                .header h1 {
                    margin: 0;
                    font-size: 24px;
                }
                .content { 
                    padding: 30px; 
                }
                .code { 
                    font-size: 42px; 
                    font-weight: bold; 
                    color: #3d4630; 
                    text-align: center; 
                    margin: 30px 0; 
                    padding: 20px; 
                    background: #f8f9fa; 
                    border: 3px dashed #8b966c; 
                    border-radius: 8px;
                    letter-spacing: 8px;
                }
                .warning { 
                    background: #fff3cd; 
                    border: 1px solid #ffeaa7; 
                    border-radius: 8px; 
                    padding: 20px; 
                    margin: 25px 0; 
                    font-size: 14px;
                }
                .footer { 
                    background: #f8f9fa; 
                    padding: 20px; 
                    text-align: center; 
                    color: #6c757d; 
                    font-size: 12px; 
                    border-top: 1px solid #dee2e6;
                }
                .info {
                    background: #d1ecf1;
                    border: 1px solid #bee5eb;
                    border-radius: 8px;
                    padding: 15px;
                    margin: 15px 0;
                    font-size: 14px;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🎯 Справочник военной техники</h1>
                </div>
                <div class='content'>
                    <h2>Код подтверждения для входа</h2>
                    <p>Здравствуйте!</p>
                    <p>Для завершения входа в систему используйте следующий код подтверждения:</p>
                    
                    <div class='code'>$code</div>
                    
                    <div class='info'>
                        <strong>📋 Инструкция:</strong><br>
                        1. Скопируйте код выше<br>
                        2. Вернитесь на страницу входа<br>
                        3. Введите код в соответствующее поле
                    </div>
                    
                    <div class='warning'>
                        <strong>⚠️ Важная информация:</strong><br>
                        • Код действителен в течение <strong>10 минут</strong><br>
                        • Никому не сообщайте этот код<br>
                        • Если вы не запрашивали вход, проигнорируйте это письмо
                    </div>
                    
                    <p>С уважением,<br>Команда Справочника военной техники</p>
                </div>
                <div class='footer'>
                    Это автоматическое письмо, пожалуйста, не отвечайте на него.<br>
                    © " . date('Y') . " Справочник военной техники
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->Body = $message;
        
        // Альтернативный текст для почтовых клиентов, которые не поддерживают HTML
        $mail->AltBody = "Код подтверждения для входа: $code\n\nИспользуйте этот код для входа в Справочник военной техники. Код действителен в течение 10 минут.\n\nЕсли вы не запрашивали вход, проигнорируйте это письмо.";
        
        // Отправка
        if ($mail->send()) {
            error_log("✅ Email успешно отправлен на: $email");
            return true;
        } else {
            error_log("❌ Ошибка отправки email: " . $mail->ErrorInfo);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("❌ Ошибка PHPMailer: " . $mail->ErrorInfo);
        return false;
    }
}

// Универсальная функция отправки
function sendVerificationUniversal($email, $code) {
    return sendVerificationCode($email, $code);
}
?>