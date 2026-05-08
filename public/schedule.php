<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$docId = (int) ($_GET['doc'] ?? 0);

$stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    render_header('Not found', $staff);
    ?>
    <div class="banner banner-error">Document not found.</div>
    <p><a href="/admin.php" class="back-link">← back to admin</a></p>
    <?php
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = trim($_POST['publish_at'] ?? '');
    // Empty = publish immediately (null); otherwise convert to SQLite format
    $publish_at = ($raw !== '') ? str_replace('T', ' ', $raw) . ':00' : null;

    $stmt = db()->prepare('UPDATE documents SET publish_at = ? WHERE id = ?');
    $stmt->execute([$publish_at, $docId]);

    audit_log('schedule', 'document', $docId, [
        'publish_at'          => $publish_at,
        'previous_publish_at' => $doc['publish_at'],
    ]);

    header('Location: /schedule.php?doc=' . $docId . '&updated=1');
    exit;
}

// Convert stored "YYYY-MM-DD HH:MM:SS" → datetime-local format "YYYY-MM-DDTHH:MM"
$current_pa = '';
if ($doc['publish_at']) {
    $current_pa = str_replace(' ', 'T', substr($doc['publish_at'], 0, 16));
}

render_header('Schedule · ' . $doc['title'], $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title">Schedule "<?= h($doc['title']) ?>"</h1>
<p class="page-subtitle">Set when this document becomes visible to recipients via share links.</p>

<?php if (!empty($_GET['updated'])): ?>
    <div class="banner banner-success">Schedule updated.</div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">Publication schedule</h2>
    <form method="post">
        <div class="form-field">
            <label for="publish_at">Publish at <span style="font-weight:400;color:var(--text-muted)">(leave blank to publish immediately)</span></label>
            <input type="datetime-local" id="publish_at" name="publish_at" value="<?= h($current_pa) ?>">
        </div>
        <button type="submit" class="btn">Save schedule</button>
    </form>
</section>

<?php render_footer(); ?>
