<?php
// install.php - Обработчик установки приложения Bitrix24
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

// --- ОТВЕТ НА HEAD-ЗАПРОС ДЛЯ ВАЛИДАЦИИ BITRIX24 ---
// Отправляем 200 OK и выходим, если это HEAD-запрос для проверки доступности
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'HEAD') {
    if (!headers_sent()) { // Дополнительная проверка
        header("HTTP/1.1 200 OK");
    }
    exit;
}
// --- КОНЕЦ БЛОКА ДЛЯ HEAD ---

// --- НАСТРОЙКА ЛОГИРОВАНИЯ ---
ini_set('display_errors', '1'); // Показывать ошибки при установке для отладки
error_reporting(E_ALL);
ini_set('log_errors', '1');

// Базовый путь к директории данных приложения
define('APP_DATA_PATH', '/var/www/www-root/data/bitrix24/draft');

// Проверка существования директории APP_DATA_PATH
if (!is_dir(APP_DATA_PATH)) {
    $errorMsg = "FATAL ERROR: APP_DATA_PATH directory does not exist: " . APP_DATA_PATH;
    error_log($errorMsg);
    die($errorMsg);
}

// Путь к лог-файлу установки
define('INSTALL_LOG_FILE', APP_DATA_PATH . '/logs/install.log');
ini_set('error_log', INSTALL_LOG_FILE);

// Путь к файлу конфигурации
define('CONFIG_PATH', APP_DATA_PATH . '/config.php');

// Проверка существования config.php
if (!file_exists(CONFIG_PATH)) {
    $errorMsg = "FATAL ERROR: config.php does NOT exist at path: " . CONFIG_PATH;
    error_log($errorMsg);
    die($errorMsg);
}
if (!is_readable(CONFIG_PATH)) {
    $errorMsg = "FATAL ERROR: config.php is NOT readable at path: " . CONFIG_PATH;
    error_log($errorMsg);
    die($errorMsg);
}

// Подключаем конфигурацию
$config = require_once CONFIG_PATH;

// Путь для хранения токенов
define('TOKEN_DIR', APP_DATA_PATH . '/tokens');

// Создаем директорию для токенов, если она не существует
if (!is_dir(TOKEN_DIR)) {
    mkdir(TOKEN_DIR, 0775, true);
}

// Проверка доступа на запись в директорию токенов
if (!is_writable(TOKEN_DIR)) {
    $errorMsg = "FATAL ERROR: TOKEN_DIR is not writable: " . TOKEN_DIR;
    error_log($errorMsg);
    die($errorMsg);
}

// Логируем запрос для отладки
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'N/A';
$requestUri = $_SERVER['REQUEST_URI'] ?? 'N/A';
$logEntry = date('Y-m-d H:i:s') . " Install Request Received:\n";
$logEntry .= "METHOD: {$requestMethod}\n";
$logEntry .= "URI: {$requestUri}\n";
$logEntry .= "_REQUEST Data:\n" . print_r($_REQUEST, true) . "\n---\n";
@file_put_contents(APP_DATA_PATH . '/logs/install_requests.log', $logEntry, FILE_APPEND);
error_log("--- install.php execution started ---");

// --- ФУНКЦИЯ СОХРАНЕНИЯ ТОКЕНА ---
function saveAuth($memberId, $authData) {
    $tokenFile = TOKEN_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $memberId) . '.json';
    
    // Сохраняем токен доступа
    if (!file_put_contents($tokenFile, json_encode($authData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        error_log("Failed to save auth data to {$tokenFile}");
        return false;
    }
    
    return true;
}

// --- ПРОВЕРКА ТИПА ЗАПРОСА ---
// Проверяем запрос установки приложения (POST с AUTH_ID)
if ($requestMethod === 'POST' && 
    isset($_REQUEST['DOMAIN'], $_REQUEST['AUTH_ID'], $_REQUEST['REFRESH_ID'], $_REQUEST['member_id'])) {
    
    error_log("Detected installation request for domain: " . $_REQUEST['DOMAIN']);
    
    // Подготавливаем данные для сохранения
    $authData = [
        'member_id'     => $_REQUEST['member_id'],
        'domain'        => $_REQUEST['DOMAIN'],
        'access_token'  => $_REQUEST['AUTH_ID'],
        'refresh_token' => $_REQUEST['REFRESH_ID'],
        'expires_at'    => time() + intval($_REQUEST['AUTH_EXPIRES'] ?? 3600),
        'install_time'  => time(),
        'client_id'     => $config['app_id'] ?? '',
        'client_endpoint' => 'https://' . $_REQUEST['DOMAIN'] . '/rest/',
    ];
    
    // Сохраняем данные авторизации
    if (saveAuth($_REQUEST['member_id'], $authData)) {
        error_log("Installation successful for member_id: " . $_REQUEST['member_id']);
        
        // Отправляем успешный ответ в Bitrix24
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        echo '<script src="//api.bitrix24.com/api/v1/"></script>';
        echo '<script>';
        echo '    console.log("Install successful, calling BX24.installFinish()...");';
        echo '    if (typeof BX24 !== "undefined") {';
        echo '        BX24.installFinish();';
        echo '    } else {';
        echo '        console.warn("BX24 object not found, attempting to initialize...");';
        echo '        setTimeout(function() {';
        echo '            if (typeof BX24 !== "undefined") {';
        echo '                BX24.init(function(){ BX24.installFinish(); });';
        echo '            } else {';
        echo '                document.body.innerHTML = "Установка завершена. Пожалуйста, закройте это окно.";';
        echo '            }';
        echo '        }, 1000);';
        echo '    }';
        echo '</script>';
        echo '</head><body>Установка успешно завершена! Окно закроется автоматически.</body></html>';
        error_log("--- install.php execution finished successfully ---");
        exit;
    } else {
        // Ошибка сохранения токена
        $errorMsg = "FATAL ERROR: Failed to save auth data for member_id: " . $_REQUEST['member_id'];
        error_log($errorMsg);
        http_response_code(500);
        die("Ошибка установки: не удалось сохранить данные авторизации. Пожалуйста, проверьте права доступа к директории токенов.");
    }
}
// --- КОНЕЦ БЛОКА УСТАНОВКИ ---

// --- ОБРАБОТКА JSON-MANIFEST ЗАПРОСА ---
// Если это GET без специфических параметров, скорее всего запрашивается JSON-манифест
$isManifestRequest = $requestMethod === 'GET' && 
                   !isset($_REQUEST['DOMAIN']) && 
                   !isset($_REQUEST['AUTH_ID']) && 
                   !isset($_REQUEST['event']);

if ($isManifestRequest) {
    // Базовая конфигурация приложения (манифест)
    $appConfig = [
        'name' => 'Draft App', // Имя приложения
        'code' => 'custom.draft.app', // Уникальный код приложения
        'version' => '1.0.0',
        'vendor' => 'BI-DATA',
        'description' => 'Заготовка для тиражного приложения Bitrix24',
        'icon' => 'images/icon.png',
        
        // Права доступа, необходимые приложению
        'scope' => ['crm', 'user', 'log'],
        
        // Размещение приложения в интерфейсе Bitrix24
        'placement' => [
            'DEFAULT' => [
                'LANG' => [
                    'ru' => ['NAME' => 'Draft App'],
                    'en' => ['NAME' => 'Draft App'],
                ]
            ]
        ],
        
        // Обработчики событий
        'handlers' => [
            'ONAPPINSTALL' => 'index.php',
            'ONAPPUNINSTALL' => 'index.php',
        ],
        
        'support_url' => 'https://bi-data.ru/support'
    ];
    
    header('Content-Type: application/json');
    echo json_encode($appConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
// --- КОНЕЦ БЛОКА MANIFEST ---

// --- РЕДИРЕКТ НА ОСНОВНОЙ ФАЙЛ ПРИЛОЖЕНИЯ ---
// Для всех других случаев - редирект на index.php
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$baseAppPath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$indexPath = $baseAppPath . '/index.php';
$queryString = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';

$redirectUrl = $protocol . $host . $indexPath . $queryString;
error_log("Redirecting from install.php to: " . $redirectUrl);

header('Location: ' . $redirectUrl, true, 302);
exit;
?> 