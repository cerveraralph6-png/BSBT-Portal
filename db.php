<?php
// db.php - Dedicated Authentication Database
$host    = "localhost";
$db_user = "root";
$db_pass = "";
$dbname  = "bsbt_portal_db"; // <-- AUTH DATABASE ONLY

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $pdo->exec("USE `$dbname`;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `role` ENUM('admin', 'academics', 'registrar') NOT NULL,
        `fullname` VARCHAR(100) DEFAULT 'Master Administrator',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    $check = $pdo->prepare("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
    $check->execute();
    if (!$check->fetch()) {
        $hashed = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password, role, fullname) VALUES ('admin', ?, 'admin', 'Master Administrator')")->execute([$hashed]);
    }
} catch (PDOException $e) {
    die("Auth Database Connection Failed: " . $e->getMessage());
}
?>