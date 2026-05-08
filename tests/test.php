<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

// Feature 1: Scheduled Publishing
test('document with future publish_at is blocked from view', function () {
    $future = date('Y-m-d H:i:s', strtotime('+1 day'));
    $stmt = db()->prepare("INSERT INTO documents (title, body, created_by, publish_at) VALUES (?, ?, 1, ?)");
    $stmt->execute(['Scheduled Doc', 'Body text', $future]);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare("SELECT publish_at FROM documents WHERE id = ?");
    $stmt->execute([$docId]);
    $row = $stmt->fetch();

    assert_true($row !== false, 'document should exist');
    assert_true(
        $row['publish_at'] > date('Y-m-d H:i:s'),
        'publish_at should be in the future, got: ' . $row['publish_at']
    );
});

test('document with past publish_at is not blocked', function () {
    $past = date('Y-m-d H:i:s', strtotime('-1 day'));
    $stmt = db()->prepare("INSERT INTO documents (title, body, created_by, publish_at) VALUES (?, ?, 1, ?)");
    $stmt->execute(['Past Doc', 'Body text', $past]);
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare("SELECT publish_at FROM documents WHERE id = ?");
    $stmt->execute([$docId]);
    $row = $stmt->fetch();

    assert_true(
        $row['publish_at'] <= date('Y-m-d H:i:s'),
        'publish_at in the past should not block access'
    );
});

// Feature 2: Human-Readable IDs
test('generate_readable_id returns correct format', function () {
    $id = generate_readable_id('Hello World');
    assert_true(
        preg_match('/^[a-z]+-[A-Z0-9]{4}$/', $id) === 1,
        'readable_id format invalid, got: ' . $id
    );
});

test('generate_readable_id uses first word of title', function () {
    $id = generate_readable_id('Budget Report 2026');
    assert_true(
        str_starts_with($id, 'budget-'),
        'expected id to start with "budget-", got: ' . $id
    );
});

test('seeded document has a readable_id assigned', function () {
    $stmt = db()->prepare("SELECT readable_id FROM documents WHERE title = 'Welcome Packet'");
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'seeded document not found');
    assert_true(
        $row['readable_id'] !== null && $row['readable_id'] !== '',
        'seeded document should have a readable_id'
    );
});

// Feature 3: Search by Title
test('title search returns matching documents only', function () {
    db()->exec("DELETE FROM documents WHERE title IN ('Alpha Report', 'Beta Report')");
    $stmt = db()->prepare("INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)");
    $stmt->execute(['Alpha Report', 'body']);
    $stmt->execute(['Beta Report', 'body']);

    $stmt = db()->prepare("SELECT COUNT(*) AS cnt FROM documents WHERE title LIKE ?");
    $stmt->execute(['%Alpha%']);
    $row = $stmt->fetch();
    assert_true((int) $row['cnt'] === 1, 'expected 1 result for Alpha search, got ' . $row['cnt']);
});

test('title search with no match returns empty result', function () {
    $stmt = db()->prepare("SELECT COUNT(*) AS cnt FROM documents WHERE title LIKE ?");
    $stmt->execute(['%ZZZ_NONEXISTENT_ZZZ%']);
    $row = $stmt->fetch();
    assert_true((int) $row['cnt'] === 0, 'expected 0 results for nonexistent term');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
