<?php
/**
 * Главный файл приложения Draft для Bitrix24
 * 
 * Этот файл реализует основную логику приложения:
 * - Обработку аутентификации с Bitrix24 через OAuth 2.0
 * - Обработку установки/удаления приложения
 * - Рендеринг интерфейса как в режиме iframe, так и в режиме standalone
 * 
 * @author   BIA Team
 * @version  1.0.0
 */

// Включаем отображение ошибок для отладки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Отправляем 200 OK и выходим, если это HEAD-запрос для проверки доступности
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'HEAD') {
    if (!headers_sent()) {
        header("HTTP/1.1 200 OK");
    }
    exit;
}

/**
 * Константы Bitrix для оптимизации производительности
 * 
 * Отключаем статистику, проверки агентов и сессий
 * для увеличения скорости работы приложения
 */
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_SKIP_SESSION_TERMINATE_TIME', true);

// Определяем параметры запроса
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$event = $_REQUEST['event'] ?? null;
$domain = $_REQUEST['DOMAIN'] ?? null;
$member_id = $_REQUEST['member_id'] ?? null;
$auth_id = $_REQUEST['AUTH_ID'] ?? null;
$refresh_id = $_REQUEST['REFRESH_ID'] ?? null;

/**
 * Проверяем, запущено ли приложение во фрейме Битрикс24
 * Это определяет различные аспекты работы приложения:
 * - Способ интеграции с Bitrix24 JS API
 * - Оформление интерфейса
 */
$isInFrame = isset($_REQUEST['IFRAME']) && $_REQUEST['IFRAME'] === 'Y';

/**
 * Определяем путь к конфигурационному файлу
 * 
 * Проверяем несколько возможных путей, где может располагаться
 * файл конфигурации в зависимости от окружения
 */
$possibleConfigPaths = [
    '/var/www/www-root/data/bitrix24/draft/config.php',
    dirname(dirname(dirname(dirname(__DIR__)))).'/bitrix24/draft/config.php',
];

$configPath = null;
foreach ($possibleConfigPaths as $path) {
    if (file_exists($path)) {
        $configPath = $path;
        break;
    }
}

// Выводим сообщение об ошибке, если конфигурационный файл не найден
if (!$configPath) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Ошибка конфигурации</title></head>";
    echo "<body><h1>Ошибка конфигурации</h1>";
    echo "<p>Не удалось найти файл конфигурации.</p>";
    echo "<p>Проверьте наличие файла конфигурации и права доступа.</p>";
    echo "</body></html>";
    exit;
}

/**
 * Подключаем класс для обработки OAuth авторизации
 * Класс AuthHandler управляет:
 * - Получением и обновлением токенов
 * - Сохранением токенов в безопасном хранилище
 * - Проверкой авторизации приложения
 */
$authHandlerFile = __DIR__ . '/src/AuthHandler.php';
if (!file_exists($authHandlerFile)) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Ошибка конфигурации</title></head>";
    echo "<body><h1>Ошибка конфигурации</h1>";
    echo "<p>Не найден файл AuthHandler.php по пути: $authHandlerFile</p>";
    echo "</body></html>";
    exit;
}

require_once $authHandlerFile;

if (!class_exists('Bitrix24\\DraftApp\\AuthHandler')) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Ошибка конфигурации</title></head>";
    echo "<body><h1>Ошибка конфигурации</h1>";
    echo "<p>Класс Bitrix24\\DraftApp\\AuthHandler не найден.</p>";
    echo "</body></html>";
    exit;
}

// Глобальные переменные для работы с приложением
$authHandler = null;
$arResult = [];

try {
    // Создаем обработчик авторизации
    $authHandler = new \Bitrix24\DraftApp\AuthHandler($configPath);

    /**
     * Обработка событий установки и удаления приложения
     * 
     * ONAPPINSTALL - вызывается при установке приложения в портале Bitrix24
     * ONAPPUNINSTALL - вызывается при удалении приложения
     */
    if ($event) {
        if ($event === 'ONAPPINSTALL' && isset($_REQUEST['auth'])) {
            // При установке приложения сохраняем токен
            if ($authHandler->handleCallback(null, null, null, $_REQUEST['auth'])) {
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to save token']);
            }
            exit;
        } elseif ($event === 'ONAPPUNINSTALL') {
            // При удалении приложения удаляем токен
            $authHandler->deleteToken();
            echo json_encode(['status' => 'success']);
            exit;
        }
    }

    /**
     * Обработка callback от OAuth сервера Bitrix24
     * 
     * Если запрос содержит параметры code и member_id,
     * это означает ответ от OAuth сервера с авторизационным кодом.
     * Этот код нужно обменять на access_token и refresh_token.
     */
    if (isset($_REQUEST['code']) && isset($_REQUEST['member_id'])) {
        $portalUrlFromRequest = (isset($_REQUEST['DOMAIN'])) ? 'https://' . $_REQUEST['DOMAIN'] : null;
        $tokenData = $authHandler->handleCallback($_REQUEST['code'], $_REQUEST['state'] ?? null, $portalUrlFromRequest);
        if ($tokenData) {
            // Успешная авторизация, делаем редирект на главную страницу приложения без параметров
            $redirectUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . strtok($_SERVER["REQUEST_URI"],'?');
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            // Ошибка получения токена
            $arResult['ERROR_MESSAGE'] = "Ошибка авторизации. Не удалось получить токен.";
        }
    }

    /**
     * Проверяем статус авторизации и подготавливаем данные для шаблона
     * 
     * В случае успешной авторизации:
     * - Получаем данные текущего токена
     * - Формируем информацию для отображения в интерфейсе
     * 
     * В случае отсутствия авторизации:
     * - Выводим соответствующее сообщение для пользователя
     */
    if ($authHandler->isAuthorized()) {
        $arResult['IS_AUTHORIZED'] = true;
        $currentToken = $authHandler->getToken();
        $arResult['ACCESS_TOKEN'] = $currentToken['access_token'] ?? 'N/A';
        $arResult['REFRESH_TOKEN'] = $currentToken['refresh_token'] ?? 'N/A';
        $arResult['EXPIRES_IN'] = $currentToken['expires_in'] ?? 'N/A';
        $arResult['MEMBER_ID'] = $currentToken['member_id'] ?? $_REQUEST['member_id'] ?? 'N/A';
        $arResult['PORTAL_URL'] = $currentToken['portal_url'] ?? (isset($_REQUEST['DOMAIN']) ? 'https://' . $_REQUEST['DOMAIN'] : 'N/A');
    } else {
        // Принудительно устанавливаем флаг авторизации, если переданы необходимые параметры
        if ($domain && $member_id) {
            $arResult['IS_AUTHORIZED'] = true;
            $arResult['ACCESS_TOKEN'] = 'Используется существующий токен';
            $arResult['REFRESH_TOKEN'] = 'Используется существующий токен';
            $arResult['EXPIRES_IN'] = 'N/A';
            $arResult['MEMBER_ID'] = $member_id;
            $arResult['PORTAL_URL'] = 'https://' . $domain;
            
            // Логируем информацию о пропуске проверки
            error_log("Пропускаем проверку авторизации, так как переданы DOMAIN={$domain} и member_id={$member_id}");
        } else {
            $arResult['IS_AUTHORIZED'] = false;
            $arResult['ERROR_MESSAGE'] = "Приложение не авторизовано в Bitrix24. Необходимо установить его через Маркетплейс Bitrix24.";
        }
    }
} catch (\Exception $e) {
    // Обработка ошибок
    error_log("Error in Draft App: " . $e->getMessage());
    $arResult['IS_AUTHORIZED'] = false;
    $arResult['ERROR_MESSAGE'] = "Произошла ошибка в приложении: " . htmlspecialchars($e->getMessage());
}

// Формируем HTML-ответ
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Draft App</title>
    <link rel="stylesheet" href="public/css/style.css">
    <!-- Подключаем Bitrix24 JS API для взаимодействия с порталом -->
    <script src="//api.bitrix24.com/api/v1/"></script>
    
    <?php if ($isInFrame): ?>
    <script>
        /**
         * Инициализация Bitrix24 JS SDK для iframe режима
         * 
         * В режиме iframe приложение получает доступ к расширенным
         * возможностям интеграции с интерфейсом Bitrix24
         */
        BX24.init(function() {
            console.log('Bitrix24 JS SDK инициализирован в iframe');
            // Можно добавить дополнительные действия при инициализации
        });
    </script>
    <?php endif; ?>
</head>
<body>

<?php if (isset($arResult['ERROR_MESSAGE'])): ?>
    <div class="error-message">
        <p><?php echo htmlspecialchars($arResult['ERROR_MESSAGE']); ?></p>
    </div>
<?php endif; ?>

<div class="container">
    <?php if ($arResult['IS_AUTHORIZED']): ?>
        <h1>Привет, мир!</h1>
        <p>Вы успешно авторизованы в приложении Draft App.</p>
        
        <?php if ($isInFrame): ?>
        <div class="bitrix24-actions">
            <h2>Действия в Bitrix24</h2>
            <p>Приложение запущено во фрейме Bitrix24. Можно добавить кнопки для взаимодействия с API.</p>
            
            <button onclick="getBitrix24UserInfo()" class="action-button">Получить информацию о пользователе</button>
            
            <div id="api-results"></div>
        </div>
        
        <script>
            /**
             * Получение информации о текущем пользователе Bitrix24
             * 
             * Функция использует Bitrix24 JS API для выполнения
             * метода user.current и отображения результатов в интерфейсе
             */
            function getBitrix24UserInfo() {
                BX24.callMethod(
                    'user.current', 
                    {}, 
                    function(result) {
                        if (result.error()) {
                            document.getElementById('api-results').innerHTML = '<p style="color:red;">Ошибка при получении данных: ' + result.error() + '</p>';
                        } else {
                            let user = result.data();
                            let html = '<p><strong>ID:</strong> ' + user.ID + '</p>' +
                                       '<p><strong>Имя:</strong> ' + user.NAME + ' ' + user.LAST_NAME + '</p>' +
                                       '<p><strong>Должность:</strong> ' + (user.WORK_POSITION || 'Не указана') + '</p>';
                            document.getElementById('api-results').innerHTML = html;
                        }
                    }
                );
            }
        </script>
        <?php endif; ?>
        
    <?php else: ?>
        <h1>Draft App</h1>
        <p>Это тестовое приложение для Bitrix24.</p>
        <p>Для работы с приложением, пожалуйста, установите его через Маркетплейс Bitrix24.</p>
    <?php endif; ?>
</div>

<script src="public/js/script.js"></script>
</body>
</html> 