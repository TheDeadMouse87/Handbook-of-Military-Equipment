import mysql.connector
from telegram import Update
from telegram.ext import Application, CommandHandler, ContextTypes, MessageHandler, filters
from datetime import datetime, timedelta
import asyncio

# Конфигурация базы данных
DB_CONFIG = {
    'host': '134.90.167.42',
    'port': 10306,
    'user': 'Agapova',
    'password': 'JV4kK_',
    'database': 'project_Agapova'
}

# Словарь для отслеживания неудачных попыток входа
failed_attempts = {}

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text(
        'Привет! Используй:\n'
        '/users - чтобы увидеть список пользователей\n'
        '/ban <ID> - чтобы забанить или разбанить пользователя\n'
        '/logs - чтобы посмотреть последние 10 логов\n'
        '/help - показать список всех команд\n'
        'Например: /ban 5'
    )

async def help_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Показывает список всех доступных команд"""
    help_text = (
        "📋 Доступные команды:\n\n"
        "/start - начать работу с ботом\n"
        "/help - показать этот список команд\n"
        "/users - показать список всех пользователей\n"
        "/ban <ID> - забанить/разбанить пользователя по ID\n"
        "/logs - показать последние 10 записей из логов\n"
        "/count - показать статистику пользователей\n"
        "/roles - показать список всех ролей\n"
        "/monitor - запустить мониторинг логов\n"
        "/stop_monitor - остановить мониторинг логов\n\n"
        "Примеры:\n"
        "/ban 5 - забанить пользователя с ID 5\n"
        "/ban 5 - разбанить пользователя с ID 5 (если он уже забанен)"
    )
    await update.message.reply_text(help_text)

async def show_users(update: Update, context: ContextTypes.DEFAULT_TYPE):
    try:
        # Подключаемся к базе данных
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()

        # Выполняем запрос с JOIN для получения названия роли
        query = """
        SELECT 
            u.ID, 
            u.Login, 
            u.Name, 
            u.Date_of_reg, 
            r.name as Role_Name, 
            u.Email,
            u.Ban
        FROM Users u
        LEFT JOIN Role r ON u.Role_ID = r.ID
        """
        cursor.execute(query)
        users = cursor.fetchall()

        # Форматируем результат
        if users:
            response = "Список пользователей:\n\n"
            for user in users:
                user_id, login, name, date_of_reg, role_name, email, ban_status = user
                
                # Форматируем дату, если она есть
                if date_of_reg:
                    if isinstance(date_of_reg, str):
                        formatted_date = date_of_reg
                    else:
                        formatted_date = date_of_reg.strftime("%Y-%m-%d %H:%M:%S")
                else:
                    formatted_date = "Не указана"
                
                # Обрабатываем случай, когда роль не найдена
                if not role_name:
                    role_name = "Не назначена"
                
                # Определяем статус бана
                ban_text = "Забанен" if ban_status == 1 else "Активен"
                
                response += f"ID: {user_id}\n"
                response += f"Login: {login}\n"
                response += f"Name: {name}\n"
                response += f"Date of registration: {formatted_date}\n"
                response += f"Role: {role_name}\n"
                response += f"Email: {email}\n"
                response += f"Status: {ban_text}\n"
                response += "─" * 30 + "\n"
        else:
            response = "В таблице нет данных"

        # Если сообщение слишком длинное, разбиваем на части
        if len(response) > 4096:
            for i in range(0, len(response), 4096):
                await update.message.reply_text(response[i:i+4096])
        else:
            await update.message.reply_text(response)

    except mysql.connector.Error as e:
        await update.message.reply_text(f"Ошибка базы данных: {e}")
    except Exception as e:
        await update.message.reply_text(f"Произошла ошибка: {e}")
    finally:
        if 'conn' in locals() and conn.is_connected():
            cursor.close()
            conn.close()

async def show_logs(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Показывает последние 10 записей из таблицы Logs"""
    try:
        # Подключаемся к базе данных
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()

        # Выполняем запрос для получения последних 10 логов
        query = """
        SELECT * FROM Logs 
        ORDER BY ID DESC 
        LIMIT 10
        """
        cursor.execute(query)
        logs = cursor.fetchall()

        # Получаем названия столбцов для красивого вывода
        cursor.execute("SHOW COLUMNS FROM Logs")
        columns = [column[0] for column in cursor.fetchall()]

        # Форматируем результат
        if logs:
            response = "Последние 10 логов:\n\n"
            for log in logs:
                response += f"Запись #{log[0]}\n"  # Предполагаем, что первый столбец - ID
                for i, value in enumerate(log):
                    # Форматируем дату, если это datetime объект
                    if isinstance(value, datetime):
                        formatted_value = value.strftime("%Y-%m-%d %H:%M:%S")
                    elif value is None:
                        formatted_value = "NULL"
                    else:
                        formatted_value = str(value)
                    
                    response += f"{columns[i]}: {formatted_value}\n"
                
                response += "─" * 30 + "\n"
        else:
            response = "В таблице логов нет данных"

        # Если сообщение слишком длинное, разбиваем на части
        if len(response) > 4096:
            for i in range(0, len(response), 4096):
                await update.message.reply_text(response[i:i+4096])
        else:
            await update.message.reply_text(response)

    except mysql.connector.Error as e:
        await update.message.reply_text(f"Ошибка базы данных: {e}")
    except Exception as e:
        await update.message.reply_text(f"Произошла ошибка: {e}")
    finally:
        if 'conn' in locals() and conn.is_connected():
            cursor.close()
            conn.close()

async def ban_user(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Бан/разбан пользователя по ID"""
    try:
        # Проверяем, передан ли ID пользователя
        if not context.args:
            await update.message.reply_text("Пожалуйста, укажите ID пользователя. Например: /ban 5")
            return
        
        user_id = context.args[0]
        
        # Проверяем, что ID - это число
        if not user_id.isdigit():
            await update.message.reply_text("ID пользователя должен быть числом.")
            return
        
        user_id = int(user_id)
        
        # Подключаемся к базе данных
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        # Проверяем существование пользователя
        cursor.execute("SELECT ID, Name, Ban FROM Users WHERE ID = %s", (user_id,))
        user = cursor.fetchone()
        
        if not user:
            await update.message.reply_text(f"Пользователь с ID {user_id} не найден.")
            return
        
        current_ban_status = user[2]
        new_ban_status = 0 if current_ban_status == 1 else 1
        
        # Обновляем статус бана
        cursor.execute("UPDATE Users SET Ban = %s WHERE ID = %s", (new_ban_status, user_id))
        conn.commit()
        
        # Получаем обновленные данные пользователя
        cursor.execute("""
            SELECT u.ID, u.Name, u.Ban, r.name 
            FROM Users u 
            LEFT JOIN Role r ON u.Role_ID = r.ID 
            WHERE u.ID = %s
        """, (user_id,))
        updated_user = cursor.fetchone()
        
        action = "забанен" if new_ban_status == 1 else "разбанен"
        await update.message.reply_text(
            f"Пользователь {updated_user[1]} (ID: {updated_user[0]}) {action}.\n"
            f"Роль: {updated_user[3] if updated_user[3] else 'Не назначена'}\n"
            f"Текущий статус: {'Забанен' if new_ban_status == 1 else 'Активен'}"
        )
        
    except mysql.connector.Error as e:
        await update.message.reply_text(f"Ошибка базы данных: {e}")
    except Exception as e:
        await update.message.reply_text(f"Произошла ошибка: {e}")
    finally:
        if 'conn' in locals() and conn.is_connected():
            cursor.close()
            conn.close()

async def show_user_count(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Дополнительная команда для показа количества пользователей"""
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        cursor.execute("SELECT COUNT(*) FROM Users")
        total_count = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM Users WHERE Ban = 1")
        banned_count = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM Users WHERE Ban = 0")
        active_count = cursor.fetchone()[0]
        
        await update.message.reply_text(
            f"Статистика пользователей:\n"
            f"Всего: {total_count}\n"
            f"Активных: {active_count}\n"
            f"Забаненных: {banned_count}"
        )
        
    except mysql.connector.Error as e:
        await update.message.reply_text(f"Ошибка базы данных: {e}")
    finally:
        if 'conn' in locals() and conn.is_connected():
            cursor.close()
            conn.close()

async def show_roles(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Команда для просмотра всех доступных ролей"""
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        cursor.execute("SELECT ID, name FROM Role")
        roles = cursor.fetchall()
        
        if roles:
            response = "Доступные роли:\n\n"
            for role in roles:
                role_id, role_name = role
                response += f"ID: {role_id}, Название: {role_name}\n"
        else:
            response = "В таблице ролей нет данных"
            
        await update.message.reply_text(response)
        
    except mysql.connector.Error as e:
        await update.message.reply_text(f"Ошибка базы данных: {e}")
    finally:
        if 'conn' in locals() and conn.is_connected():
            cursor.close()
            conn.close()

async def check_ban_logs(context: ContextTypes.DEFAULT_TYPE):
    """Периодическая проверка логов на наличие трех неудачных попыток входа подряд"""
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()

        # Ищем логи с описанием "Неудачная попытка входа" за последний час
        query = """
        SELECT ID, User_ID, Description, Timestamp 
        FROM Logs 
        WHERE Description LIKE '%Неудачная попытка входа%' 
        AND Timestamp >= %s
        ORDER BY User_ID, Timestamp
        """
        
        one_hour_ago = datetime.now() - timedelta(hours=1)
        cursor.execute(query, (one_hour_ago,))
        failed_logins = cursor.fetchall()

        # Группируем по пользователям и проверяем количество попыток
        user_attempts = {}
        for log in failed_logins:
            log_id, user_id, description, timestamp = log
            if user_id not in user_attempts:
                user_attempts[user_id] = []
            user_attempts[user_id].append((timestamp, log_id))

        # Проверяем для каждого пользователя, есть ли 3 попытки подряд
        for user_id, attempts in user_attempts.items():
            if len(attempts) >= 3:
                # Сортируем по времени
                attempts.sort(key=lambda x: x[0])
                
                # Проверяем, что все три попытки идут подряд (в течение короткого времени)
                time_diffs = []
                for i in range(1, len(attempts)):
                    time_diff = attempts[i][0] - attempts[i-1][0]
                    time_diffs.append(time_diff.total_seconds())
                
                # Если все три попытки в течение 5 минут
                if len(attempts) >= 3 and all(diff <= 300 for diff in time_diffs[:2]):
                    
                    # Проверяем, не забанен ли уже пользователь
                    cursor.execute("SELECT Login, Ban FROM Users WHERE ID = %s", (user_id,))
                    user_data = cursor.fetchone()
                    
                    if user_data and user_data[1] == 0:  # Если пользователь не забанен
                        # Баним пользователя
                        cursor.execute("UPDATE Users SET Ban = 1 WHERE ID = %s", (user_id,))
                        conn.commit()
                        
                        # Получаем информацию о пользователе для сообщения
                        cursor.execute("SELECT Login, Name FROM Users WHERE ID = %s", (user_id,))
                        user_info = cursor.fetchone()
                        
                        if user_info:
                            login, name = user_info
                            message = f"🚨 Автоматическое оповещение о бане:\nПользователь {user_id} с именем {login} был забанен для авторизации.\nПричина: 3 неудачные попытки входа подряд."
                            
                            # Отправляем сообщение в тот же чат, откуда была запущена команда /start
                            # В реальном сценарии лучше хранить chat_id администраторов
                            await context.bot.send_message(
                                chat_id=context.job.chat_id, 
                                text=message
                            )
                            
                            # Также добавляем запись в логи о автоматическом бане
                            cursor.execute(
                                "INSERT INTO Logs (User_ID, Description, Timestamp) VALUES (%s, %s, %s)",
                                (user_id, f"Автоматический бан: 3 неудачные попытки входа подряд", datetime.now())
                            )
                            conn.commit()

    except mysql.connector.Error as e:
        print(f"Ошибка базы данных при проверке логов: {e}")
    except Exception as e:
        print(f"Произошла ошибка при проверке логов: {e}")
    finally:
        if 'conn' in locals() and conn.is_connected():
            cursor.close()
            conn.close()

async def monitor_logs(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Команда для запуска мониторинга логов на неудачные попытки входа"""
    chat_id = update.effective_chat.id
    
    # Проверяем, не запущен ли уже мониторинг
    current_jobs = context.job_queue.get_jobs_by_name("ban_monitor")
    if current_jobs:
        await update.message.reply_text("Мониторинг логов уже запущен!")
        return
    
    # Запускаем периодическую проверку каждые 30 секунд
    context.job_queue.run_repeating(
        check_ban_logs, 
        interval=30, 
        first=10, 
        chat_id=chat_id,
        name="ban_monitor"
    )
    
    await update.message.reply_text("Мониторинг логов запущен! Бот будет проверять неудачные попытки входа каждые 30 секунд.")

async def stop_monitor(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Команда для остановки мониторинга логов"""
    current_jobs = context.job_queue.get_jobs_by_name("ban_monitor")
    if current_jobs:
        for job in current_jobs:
            job.schedule_removal()
        await update.message.reply_text("Мониторинг логов остановлен!")
    else:
        await update.message.reply_text("Мониторинг логов не был запущен.")

async def handle_unknown(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Обработчик неизвестных команд"""
    await update.message.reply_text(
        "❌ Такой команды не существует.\n\n"
        "Для просмотра списка команд используйте /help"
    )

def main():
    # Инициализируем бот
    application = Application.builder().token("8006644117:AAEA-8_Tm47oMq0bAv3gZQOn06IdCstaOa4").build()

    # Добавляем обработчики
    application.add_handler(CommandHandler("start", start))
    application.add_handler(CommandHandler("help", help_command))
    application.add_handler(CommandHandler("users", show_users))
    application.add_handler(CommandHandler("logs", show_logs))
    application.add_handler(CommandHandler("ban", ban_user))
    application.add_handler(CommandHandler("count", show_user_count))
    application.add_handler(CommandHandler("roles", show_roles))
    application.add_handler(CommandHandler("monitor", monitor_logs))
    application.add_handler(CommandHandler("stop_monitor", stop_monitor))
    
    # Обработчик неизвестных команд - должен быть добавлен ПОСЛЕДНИМ
    application.add_handler(MessageHandler(filters.COMMAND, handle_unknown))

    # Запускаем бот
    print("Бот запущен...")
    application.run_polling()

if __name__ == '__main__':
    main()