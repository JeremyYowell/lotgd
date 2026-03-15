<?php
/**
 * admin/settings.php — Settings Editor
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();

    $keys   = $_POST['key']   ?? [];
    $values = $_POST['value'] ?? [];

    $updated = 0;
    foreach ($keys as $i => $key) {
        $key = trim($key);
        if (empty($key)) continue;
        $value = $values[$i] ?? '';
        $db->setSetting($key, $value);
        $updated++;
    }

    Session::setFlash('success', "{$updated} setting(s) saved.");
    redirect('/admin/settings.php');
}

// Fetch all settings grouped by rough category
$settings = $db->fetchAll(
    "SELECT setting_key, setting_value, description FROM settings ORDER BY setting_key ASC"
);

$pageTitle = 'Settings Editor';
$bodyClass = 'page-admin';
$extraCss  = ['admin.css'];

ob_start();
?>

<div class="admin-wrap">
    <div class="admin-header">
        <div>
            <a href="<?= BASE_URL ?>/admin/index.php" class="admin-back">← Admin</a>
            <h1>⚙ Settings Editor</h1>
        </div>
    </div>

    <?= renderFlash() ?>

    <form method="POST">
        <?= Session::csrfField() ?>
        <div class="card" style="padding:0;overflow:hidden">
            <table class="admin-table settings-table">
                <thead>
                    <tr>
                        <th style="width:260px">Key</th>
                        <th>Value</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($settings as $i => $s): ?>
                    <tr>
                        <td>
                            <code class="setting-key"><?= e($s['setting_key']) ?></code>
                            <input type="hidden" name="key[]" value="<?= e($s['setting_key']) ?>">
                        </td>
                        <td>
                            <input type="text"
                                   name="value[]"
                                   value="<?= e($s['setting_value']) ?>"
                                   class="setting-input"
                                   <?= in_array($s['setting_key'], ['finnhub_api_key']) ? 'type="password"' : '' ?>>
                        </td>
                        <td><small class="text-muted"><?= e($s['description'] ?? '') ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <button type="submit" class="btn btn-primary">Save All Settings</button>
        </div>
    </form>
</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
