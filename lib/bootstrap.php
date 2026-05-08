<?php

date_default_timezone_set('America/Chicago');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function generate_readable_id(string $title): string {
    // First word of title, letters only, lowercased — e.g. "Welcome Packet" → "welcome"
    $word = strtolower(preg_replace('/[^a-zA-Z]/', '', strtok(trim($title), ' ')));
    if ($word === '') {
        $word = 'doc';
    }

    // Omit visually ambiguous chars: 0/O, 1/I/L
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $len   = strlen($chars);

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $suffix = '';
        for ($i = 0; $i < 4; $i++) {
            $suffix .= $chars[random_int(0, $len - 1)];
        }
        $candidate = $word . '-' . $suffix;

        $stmt = db()->prepare('SELECT id FROM documents WHERE readable_id = ?');
        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) {
            return $candidate; // no collision, use it
        }
    }

    // Extremely unlikely fallback — just use random hex
    return $word . '-' . strtoupper(bin2hex(random_bytes(2)));
}
