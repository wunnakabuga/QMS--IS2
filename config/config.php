<?php
/**
 * Web-Based Queue Management System with Predictive Waiting Time
 * Central configuration.
 *
 * On XAMPP the defaults below normally work unchanged: user "root",
 * empty password. Adjust DB_PASS if you have set a MySQL root password.
 */

// ----------------------------------------------------------- database
define('DB_HOST', getenv('QMS_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('QMS_DB_NAME') ?: 'qms');
define('DB_USER', getenv('QMS_DB_USER') ?: 'root');
define('DB_PASS', getenv('QMS_DB_PASS') !== false ? getenv('QMS_DB_PASS') : '');

// ----------------------------------------------------------- centre
define('CENTRE_NAME', 'Huduma Centre');
define('OPENING_HOUR', 8);
define('CLOSING_HOUR', 17);

// Services offered. Each must have at least one counter configured.
$SERVICE_TYPES = [
    'ID Registration',
    'Birth Certificate',
    'Business Permit',
    'Licence Renewal',
];

// ----------------------------------------------------------- queue rules
define('GRACE_PERIOD_MINUTES', 10);   // FR-12: ticket expiry after being called
define('REMINDER_THRESHOLD_MINUTES', 5);  // FR-10: when to send the reminder
define('QUEUE_PREFIX', 'A');

// ----------------------------------------------------------- prediction
// On Windows the interpreter is normally "python"; on macOS and Linux it is
// "python3". If PHP cannot find it on the system PATH, replace this with the
// full path to the executable, for example:
//   define('PYTHON_BIN', 'C:\\Users\\YourName\\AppData\\Local\\Programs\\Python\\Python312\\python.exe');
define('PYTHON_BIN', getenv('QMS_PYTHON')
    ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3'));
define('ML_DIR', dirname(__DIR__) . '/ml');
define('MODEL_FILE', ML_DIR . '/model/rf_model.joblib');
define('PREDICT_SCRIPT', ML_DIR . '/predict.py');
define('PREDICTION_TIMEOUT', 5);      // seconds before falling back (NFR-02)

// ----------------------------------------------------------- SMS gateway
// Left in simulation mode for the prototype. Set SMS_MODE to 'live' and
// supply credentials to dispatch through Africa's Talking.
define('SMS_MODE', getenv('QMS_SMS_MODE') ?: 'simulate');
define('SMS_API_URL', 'https://api.africastalking.com/version1/messaging');
define('SMS_API_KEY', getenv('QMS_SMS_KEY') ?: '');
define('SMS_USERNAME', getenv('QMS_SMS_USER') ?: 'sandbox');
define('SMS_SENDER_ID', 'HUDUMA');

// ----------------------------------------------------------- environment
date_default_timezone_set('Africa/Nairobi');
define('APP_DEBUG', true);
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
