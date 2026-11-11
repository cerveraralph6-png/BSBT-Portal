<?php
// tracker_db.php - Multi-file Versioning & Draft Workflow Database
$host    = "localhost";
$db_user = "root";
$db_pass = "";
$dbname  = "bsbt_general_tracker_db";

try {
    $tracker_pdo = new PDO("mysql:host=$host;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false
    ]);

    $tracker_pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $tracker_pdo->exec("USE `$dbname`;");

    // 1. Categories Table
    $tracker_pdo->exec("CREATE TABLE IF NOT EXISTS `categories` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(150) NOT NULL,
        `description` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    // 2. Documents Table (supports drafts & multiple attachments JSON)
    $tracker_pdo->exec("CREATE TABLE IF NOT EXISTS `documents` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `category_id` INT NOT NULL,
        `ref_no` VARCHAR(50) NOT NULL UNIQUE,
        `title` VARCHAR(255) NOT NULL,
        `flow` ENUM('incoming', 'outgoing') DEFAULT 'incoming',
        `origin_department` ENUM('Administrative', 'Academe', 'Registrars') NOT NULL,
        `current_department` ENUM('Administrative', 'Academe', 'Registrars') NOT NULL,
        `current_holder` VARCHAR(100) NOT NULL,
        `custody_route` TEXT NOT NULL,
        `deadline` DATE NOT NULL,
        `status` ENUM('draft', 'pending', 'completed', 'overdue') DEFAULT 'pending',
        `attachments` TEXT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
    );");

    // 3. Audit Logs Table (stores versioned file attachments per step)
    $tracker_pdo->exec("CREATE TABLE IF NOT EXISTS `audit_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `document_id` INT NOT NULL,
        `action_type` ENUM('created', 'forwarded', 'action_taken', 'completed') DEFAULT 'action_taken',
        `department` ENUM('Administrative', 'Academe', 'Registrars') NOT NULL,
        `acted_by` VARCHAR(100) NOT NULL,
        `action_title` VARCHAR(255) NOT NULL,
        `remarks` TEXT NULL,
        `attachments` TEXT NULL,
        `logged_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE
    );");

    // Automatic migration checks for existing tables
    try {
        $tracker_pdo->exec("ALTER TABLE `documents` MODIFY COLUMN `status` ENUM('draft', 'pending', 'completed', 'overdue') DEFAULT 'pending';");
        $tracker_pdo->exec("ALTER TABLE `documents` ADD COLUMN `attachments` TEXT NULL;");
    } catch (Exception $e) {}

    try {
        $tracker_pdo->exec("ALTER TABLE `audit_logs` ADD COLUMN `attachments` TEXT NULL;");
    } catch (Exception $e) {}

} catch (PDOException $e) {
    die("Tracker Database Error: " . $e->getMessage());
}
?>