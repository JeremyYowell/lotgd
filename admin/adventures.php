<?php
/**
 * admin/adventures.php — Adventure Scenario Manager
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();
    $action     = $_POST['action']      ?? '';
    $scenarioId = (int)($_POST['scenario_id'] ?? 0);

    if ($action === 'toggle' && $scenarioId) {
        $db->run(
            "UPDATE adventure_scenarios SET is_active = NOT is_active WHERE id = ?",
            [$scenarioId]
        );
        Session::setFlash('success', 'Scenario status toggled.');
    }

    redirect('/admin/adventures.php');
}

$scenarios = $db->fetchAll(
    "SELECT ads.*,
            COUNT(DISTINCT ac.id) AS choice_count,
            COUNT(DISTINCT al.id) AS play_count
     FROM adventure_scenarios ads
     LEFT JOIN adventure_choices ac ON ac.scenario_id = ads.id
     LEFT JOIN adventure_log al     ON al.scenario_id = ads.id
     GROUP BY ads.id
     ORDER BY ads.category, ads.title"
);

$categoryMeta = [
    'shopping'   => ['icon' => '🛒', 'label' => 'Shopping'],
    'work'       => ['icon' => '💼', 'label' => 'Work'],
    'banking'    => ['icon' => '🏦', 'label' => 'Banking'],
    'investing'  => ['icon' => '📈', 'label' => 'Investing'],
    'housing'    => ['icon' => '🏠', 'label' => 'Housing'],
    'daily_life' => ['icon' => '☀️', 'label' => 'Daily Life'],
];

$pageTitle = 'Adventure Manager';
$bodyClass = 'page-admin';
$extraCss  = ['admin.css'];

ob_start();
?>

<div class="admin-wrap">
    <div class="admin-header">
        <div>
            <a href="<?= BASE_URL ?>/admin/index.php" class="admin-back">← Admin</a>
            <h1>⚔ Adventure Manager</h1>
        </div>
        <span class="text-muted" style="font-size:0.85rem"><?= count($scenarios) ?> scenarios</span>
    </div>

    <?= renderFlash() ?>

    <div class="card" style="padding:0;overflow:hidden">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Scenario</th>
                    <th>Category</th>
                    <th>Level Range</th>
                    <th>Choices</th>
                    <th>Times Played</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scenarios as $s):
                    $cat = $categoryMeta[$s['category']] ?? ['icon' => '⚔', 'label' => $s['category']];
                ?>
                <tr class="<?= !$s['is_active'] ? 'row-inactive' : '' ?>">
                    <td>
                        <strong><?= e($s['title']) ?></strong>
                        <?php if ($s['flavor_text']): ?>
                        <br><small class="text-muted"><?= e(substr($s['flavor_text'], 0, 60)) ?>…</small>
                        <?php endif; ?>
                    </td>
                    <td><?= $cat['icon'] ?> <?= $cat['label'] ?></td>
                    <td class="text-muted">Lvl <?= $s['min_level'] ?>–<?= $s['max_level'] ?></td>
                    <td><?= $s['choice_count'] ?></td>
                    <td><?= num($s['play_count']) ?></td>
                    <td>
                        <?php if ($s['is_active']): ?>
                            <span class="status-tag tag-ok">Active</span>
                        <?php else: ?>
                            <span class="status-tag tag-bad">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action"      value="toggle">
                            <input type="hidden" name="scenario_id" value="<?= $s['id'] ?>">
                            <button class="btn-admin-action">
                                <?= $s['is_active'] ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card mt-3" style="padding:1.25rem 1.5rem">
        <h3 class="mb-2">📝 Adding New Scenarios</h3>
        <p class="text-muted" style="font-size:0.9rem">
            New scenarios and choices are added directly via SQL INSERT statements.
            Use the existing seed data in <code>sql/adventure_schema.sql</code> as a template.
            Each scenario needs at least 2 choices with all four narrative fields filled in.
        </p>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
