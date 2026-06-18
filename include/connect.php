<?php
// Load .env variables
$env_path = __DIR__ . '/../.env';
if (file_exists($env_path)) {
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

// include/connect.php – PDO & MySQLi connection for the ai-review database
$host = 'localhost';
$db   = 'ai-review';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

// PDO connection (used for future code)
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    exit('Database connection failed (PDO): ' . $e->getMessage());
}

// MySQLi connection (used by existing scripts)
$mysqli = new mysqli($host, $user, $pass, $db);
if ($mysqli->connect_error) {
    exit('Database connection failed (MySQLi): ' . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');
?>
