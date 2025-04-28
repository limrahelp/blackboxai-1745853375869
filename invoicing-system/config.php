<?php
// SQLite database configuration
$db_file = __DIR__ . '/invoicing_system.sqlite';

try {
    $pdo = new PDO("sqlite:" . $db_file);
    // Set error mode
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Enable foreign keys support
    $pdo->exec('PRAGMA foreign_keys = ON;');
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
