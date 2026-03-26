<?php
/**
 * pages/bug_report.php — Player Bug Report Submission
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();

$userId = Session::userId();

// =========================================================================
// HANDLE POST
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();

    $subject  = trim($_POST['subject']   ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $pageUrl  = trim($_POST['page_url']  ?? '');
    $severity = $_POST['severity'] ?? 'medium';

    if (!in_array($severity, ['low', 'medium', 'high'])) {
        $severity = 'medium';
    }

    $errors = [];
    if (strlen($subject) < 5) {
        $errors[] = 'Please provide a short subject (at least 5 characters).';
    }
    if (strlen($desc) < 20) {
        $errors[] = 'Please describe the bug in more detail (at least 20 characters).';
    }

    if (empty($errors)) {
        $db->run(
            "INSERT INTO bug_reports (user_id, subject, description, page_url, severity)
             VALUES (?, ?, ?, ?, ?)",
            [$userId, $subject, $desc, $pageUrl ?: null, $severity]
        );
        Session::setFlash('success', 'Your bug report has been dispatched to the realm administrators. Thank you!');
        redirect('/pages/bug_report.php');
    }
}

$pageTitle = 'Report a Bug';
$bodyClass = 'page-bug-report';
$extraCss  = ['form.css'];

ob_start();
?>

<div class="form-wrap" style="max-width:640px">

    <div class="form-header">
        <h1>🐛 Report a Bug</h1>
        <p class="text-muted">
            Spotted something broken? Let us know. Your report goes directly to the admin.
            Include as much detail as you can — what you were doing, what you expected, what happened instead.
        </p>
    </div>

    <?= renderFlash() ?>

    <?php if (!empty($errors)): ?>
    <div class="flash flash-error">
        <?php foreach ($errors as $err): ?>
        <div><?= e($err) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="card form-card" style="padding:1.75rem">

        <?= Session::csrfField() ?>

        <div class="form-group">
            <label class="form-label" for="subject">Subject</label>
            <input type="text" id="subject" name="subject" class="form-input"
                   placeholder="e.g. Second Chance Scroll disappeared after use"
                   value="<?= e($_POST['subject'] ?? '') ?>"
                   maxlength="200" required>
        </div>

        <div class="form-group">
            <label class="form-label" for="page_url">Where did it happen? <span class="text-muted">(optional)</span></label>
            <input type="text" id="page_url" name="page_url" class="form-input"
                   placeholder="e.g. /pages/adventure.php, the Store page, etc."
                   value="<?= e($_POST['page_url'] ?? '') ?>"
                   maxlength="500">
        </div>

        <div class="form-group">
            <label class="form-label" for="severity">Severity</label>
            <select id="severity" name="severity" class="form-input">
                <option value="low"    <?= (($_POST['severity'] ?? '') === 'low')    ? 'selected' : '' ?>>Low — Minor visual glitch or confusion</option>
                <option value="medium" <?= (($_POST['severity'] ?? 'medium') === 'medium') ? 'selected' : '' ?>>Medium — Something doesn't work correctly</option>
                <option value="high"   <?= (($_POST['severity'] ?? '') === 'high')   ? 'selected' : '' ?>>High — Lost gold/XP, broken feature, can't play</option>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="description">Describe the bug</label>
            <textarea id="description" name="description" class="form-input" rows="5"
                      placeholder="What were you doing? What did you expect to happen? What happened instead?"
                      maxlength="5000" required><?= e($_POST['description'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary btn-full">📤 Submit Bug Report</button>
        <a href="<?= BASE_URL ?>/pages/dashboard.php"
           class="btn btn-secondary btn-full" style="margin-top:0.5rem">Cancel</a>

    </form>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
