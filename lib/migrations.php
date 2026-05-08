<?php

function run_migrations(): void {
    $db = db();

    // Tracks which migration files have already been applied
    $db->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL UNIQUE,
            applied_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $files = glob(__DIR__ . '/../migrations/*.sql');
    if (!$files) {
        return;
    }
    sort($files); // apply in filename order (001, 002, ...)

    foreach ($files as $file) {
        $filename = basename($file);

        $stmt = $db->prepare('SELECT id FROM schema_migrations WHERE filename = ?');
        $stmt->execute([$filename]);
        if ($stmt->fetch()) {
            continue; // already applied
        }

        $db->exec(file_get_contents($file));

        $db->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$filename]);
    }
}
