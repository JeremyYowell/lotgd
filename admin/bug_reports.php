<?php
/**
 * admin/bug_reports.php — Bug Report Management
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireAdmin();

// =========================================================================
// HANDLE ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();

    $reportId = (int)($_POST['report_id'] ?? 0);
    $action   = $_POST['action'] ?? '';

    if ($reportId && in_array($action, ['mark_in_progress', 'mark_closed', 'mark_open', 'delete'])) {
        if ($action === 'delete') {
            $db->run("DELETE FROM bug_reports WHERE id = ?", [$reportId]);
            Session::setFlash('success', 'Report deleted.');
        } elseif ($action === 'mark_closed') {
            $db->run(
                "UPDATE bug_reports SET status = 'closed', resolved_at = NOW() WHERE id = ?",
                [$reportId]
            );
            Session::setFlash('success', 'Report marked as closed.');
        } elseif ($action === 'mark_in_progress') {
            $db->run(
                "UPDATE bug_reports SET status = 'in_progress', resolved_at = NULL WHERE id = ?",
                [$reportId]
            );
            Session::setFlash('success', 'Report marked as in progress.');
        } elseif ($action === 'mark_open') {
            $db->run(
                "UPDATE bug_reports SET status = 'open', resolved_at = NULL WHERE id = ?",
                [$reportId]
            );
            Session::setFlash('success', 'Report reopened.');
        }

        // Save admin note if provided
        $note = trim($_POST['admin_note'] ?? '');
        if ($note && $action !== 'delete') {
            $db->run("UPDATE bug_reports SET admin_note = ? WHERE id = ?", [$note, $reportId]);
        }
    }

    redirect('/admin/bug_reports.php');
}

// =========================================================================
// DATA
// =========================================================================
$filterStatus = $_GET['status'] ?? 'open';
if (!in_array($filterStatus, ['open', 'in_progress', 'closed', 'all'])) {
    $filterStatus = 'open';
}

$where = $filterStatus !== 'all' ? "WHERE br.status = ?" : "WHERE 1=1";
$params = $filterStatus !== 'all' ? [$filterStatus] : [];

$reports = $db->fetchAll(
    "SELECT br.*, u.username, u.class AS user_class
     FROM bug_reports br
     JOIN users u ON u.id = br.user_id
     {$where}
     ORDER BY br.created_at DESC
     LIMIT 100",
    $params
);

$counts = $db->fetchOne(
    "SELECT
        SUM(status = 'open')        AS open_count,
        SUM(status = 'in_progress') AS progress_count,
        SUM(status = 'closed')      AS closed_count
     FROM bug_reports"
);

$severityConfig = [
    'high'   => ['label' => 'High',   'color' => '#ef4444'],
    'medium' => ['label' => 'Medium', 'color' => '#f59e0b'],
    'low'    => ['label' => 'Low',    'color' => '#6b82a0'],
];
$statusConfig = [
    'open'        => ['label' => 'Open',        'color' => '#ef4444'],
    'in_progress' => ['label' => 'In Progress', 'color' => '#f59e0b'],
    'closed'      => ['label' => 'Closed',      'color' => '#22c55e'],
];

$pageTitle = 'Bug Reports';
$bodyClass = 'page-admin';
$extraCss  = ['admin.css'];

ob_start();
?>

<div class="admin-wrap">
    <div class="admin-header">
        <h1>🐛 Bug Reports</h1>
        <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-secondary">← Admin Panel</a>
    </div>

    <?= renderFlash() ?>

    <!-- COUNTS -->
    <div class="admin-stat-grid" style="margin-bottom:1.25rem">
        <div class="admin-stat">
            <span class="as-val text-red"><?= (int)($counts['open_count'] ?? 0) ?></span>
            <span class="as-label">Open</span>
        </div>
        <div class="admin-stat">
            <span class="as-val" style="color:#f59e0b"><?= (int)($counts['progress_count'] ?? 0) ?></span>
            <span class="as-label">In Progress</span>
        </div>
        <div class="admin-stat">
            <span class="as-val text-green"><?= (int)($counts['closed_count'] ?? 0) ?></span>
            <span class="as-label">Closed</span>
        </div>
    </div>

    <!-- FILTER TABS -->
    <div class="admin-filter-tabs" style="margin-bottom:1.25rem;display:flex;gap:0.5rem;flex-wrap:wrap">
        <?php foreach (['open' => '🔴 Open', 'in_progress' => '🟡 In Progress', 'closed' => '🟢 Closed', 'all' => 'All'] as $val => $label): ?>
        <a href="?status=<?= $val ?>"
           class="btn <?= $filterStatus === $val ? 'btn-primary' : 'btn-secondary' ?>"
           style="font-size:0.82rem;padding:0.4rem 0.9rem">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- REPORTS LIST -->
    <?php if (empty($reports)): ?>
    <div class="card" style="text-align:center;padding:2rem;color:var(--color-text-muted)">
        No bug reports with status "<?= e($filterStatus) ?>".
    </div>
    <?php else: ?>
    <div class="admin-report-list">
        <?php foreach ($reports as $report):
            $sev = $severityConfig[$report['severity']] ?? $severityConfig['medium'];
            $st  = $statusConfig[$report['status']]    ?? $statusConfig['open'];
        ?>
        <div class="card admin-report-card" style="margin-bottom:1rem;padding:1.25rem 1.5rem">
            <div class="report-meta" style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.65rem">
                <span style="color:<?= $sev['color'] ?>;font-family:var(--font-heading);font-size:0.7rem;
                             letter-spacing:0.1em;text-transform:uppercase;border:1px solid <?= $sev['color'] ?>;
                             border-radius:3px;padding:0.15rem 0.5rem">
                    <?= $sev['label'] ?>
                </span>
                <span style="color:<?= $st['color'] ?>;font-family:var(--font-heading);font-size:0.7rem;
                             letter-spacing:0.1em;text-transform:uppercase">
                    <?= $st['label'] ?>
                </span>
                <span class="text-muted" style="font-size:0.8rem">
                    <strong><?= e($report['username']) ?></strong>
                    (<?= e($report['user_class']) ?>)
                    · <?= date('M j, Y g:ia', strtotime($report['created_at'])) ?>
                    <?php if ($report['page_url']): ?>
                        · <span style="color:var(--color-text-dim)"><?= e($report['page_url']) ?></span>
                    <?php endif; ?>
                </span>
            </div>

            <h3 style="font-size:1rem;margin-bottom:0.5rem;color:var(--color-text)">
                #<?= (int)$report['id'] ?> — <?= e($report['subject']) ?>
            </h3>

            <p style="font-size:0.88rem;color:var(--color-text-muted);line-height:1.6;white-space:pre-wrap;margin-bottom:0.75rem"><?= e($report['description']) ?></p>

            <?php if ($report['admin_note']): ?>
            <div style="background:rgba(212,160,23,0.08);border:1px solid #8a6a1a;border-radius:var(--radius);
                        padding:0.65rem 0.9rem;margin-bottom:0.75rem;font-size:0.85rem">
                <span class="text-gold" style="font-family:var(--font-heading);font-size:0.65rem;
                             letter-spacing:0.1em;text-transform:uppercase;display:block;margin-bottom:0.25rem">
                    Admin Note
                </span>
                <?= e($report['admin_note']) ?>
            </div>
            <?php endif; ?>

            <!-- ACTIONS -->
            <form method="POST" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end">
                <?= Session::csrfField() ?>
                <input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
                <input type="text" name="admin_note" class="form-input"
                       placeholder="Add / update admin note…"
                       style="flex:1;min-width:180px;font-size:0.82rem;padding:0.35rem 0.65rem"
                       value="<?= e($report['admin_note'] ?? '') ?>">
                <?php if ($report['status'] !== 'in_progress'): ?>
                <button name="action" value="mark_in_progress" class="btn btn-secondary" style="font-size:0.8rem;padding:0.35rem 0.75rem">
                    🟡 In Progress
                </button>
                <?php endif; ?>
                <?php if ($report['status'] !== 'closed'): ?>
                <button name="action" value="mark_closed" class="btn btn-secondary" style="font-size:0.8rem;padding:0.35rem 0.75rem;color:#22c55e;border-color:#22c55e">
                    ✓ Close
                </button>
                <?php endif; ?>
                <?php if ($report['status'] !== 'open'): ?>
                <button name="action" value="mark_open" class="btn btn-secondary" style="font-size:0.8rem;padding:0.35rem 0.75rem">
                    🔴 Reopen
                </button>
                <?php endif; ?>
                <button name="action" value="delete"
                        class="btn btn-secondary" style="font-size:0.8rem;padding:0.35rem 0.75rem;color:#ef4444;border-color:#ef4444"
                        onclick="return confirm('Delete this report permanently?')">
                    🗑 Delete
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
