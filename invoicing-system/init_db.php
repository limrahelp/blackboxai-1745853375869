<?php
require 'config.php';

try {
    $schema = file_get_contents(__DIR__ . '/db/schema.sql');
    $pdo->exec($schema);
    echo "Database initialized successfully.";
} catch (PDOException $e) {
    die("Database initialization failed: " . $e->getMessage());
}
?>
