<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        // datetime-local gives "YYYY-MM-DDTHH:MM", SQLite wants "YYYY-MM-DD HH:MM:SS"
        $publish_at = null;
        $raw_pa = trim($_POST['publish_at'] ?? '');
        if ($raw_pa !== '') {
            $publish_at = str_replace('T', ' ', $raw_pa) . ':00';
        }

        $readable_id = generate_readable_id($title);

        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, publish_at, readable_id)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$title, $body, $staff['id'], $publish_at, $readable_id]);
        $docId = (int) db()->lastInsertId();

        audit_log('create', 'document', $docId, [
            'title'       => $title,
            'readable_id' => $readable_id,
            'publish_at'  => $publish_at,
        ]);

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

$docs = db()->query('
    SELECT d.*, s.name AS creator_name
    FROM documents d
    JOIN staff s ON s.id = d.created_by
    ORDER BY d.created_at DESC
')->fetchAll();

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at <span style="font-weight:400;color:var(--text-muted)">(optional — leave blank to publish immediately)</span></label>
            <input type="datetime-local" id="publish_at" name="publish_at">
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <?php if (empty($docs)): ?>
        <p class="empty">No documents yet.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Readable ID</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th>Publish at</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id">#<?= (int) $d['id'] ?></td>
                        <td><code style="font-size:0.82rem"><?= $d['readable_id'] ? h($d['readable_id']) : '—' ?></code></td>
                        <td><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><?= $d['publish_at'] ? h($d['publish_at']) : '<span style="color:var(--text-muted)">immediate</span>' ?></td>
                        <td style="white-space:nowrap">
                            <a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Share →</a>
                            &nbsp;
                            <a href="/schedule.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Schedule →</a>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
