<?php
/**
 * admin/adventures.php — Adventure Scenario Manager
 * Full CRUD: list, toggle, add, edit scenario + choices
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireAdmin();

$categoryMeta = [
    'shopping'   => ['icon' => '🛒', 'label' => 'Shopping'],
    'work'       => ['icon' => '💼', 'label' => 'Work'],
    'banking'    => ['icon' => '🏦', 'label' => 'Banking'],
    'investing'  => ['icon' => '📈', 'label' => 'Investing'],
    'housing'    => ['icon' => '🏠', 'label' => 'Housing'],
    'daily_life' => ['icon' => '☀️', 'label' => 'Daily Life'],
];

$showAddForm  = false;
$editScenario = null;
$editChoices  = [];

// =========================================================================
// HANDLE POSTS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();
    $action = $_POST['action'] ?? '';

    // --- Toggle ---
    if ($action === 'toggle') {
        $sid = (int)$_POST['scenario_id'];
        $db->run("UPDATE adventure_scenarios SET is_active = NOT is_active WHERE id = ?", [$sid]);
        Session::setFlash('success', 'Scenario status updated.');
        redirect('/admin/adventures.php');
    }

    // --- Delete scenario + choices ---
    if ($action === 'delete') {
        $sid = (int)$_POST['scenario_id'];
        $plays = (int)$db->fetchValue("SELECT COUNT(*) FROM adventure_log WHERE scenario_id = ?", [$sid]);
        if ($plays > 0) {
            Session::setFlash('error', "Cannot delete — this scenario has been played {$plays} times. Disable it instead.");
        } else {
            $db->beginTransaction();
            try {
                $db->run("DELETE FROM adventure_choices WHERE scenario_id = ?", [$sid]);
                $db->run("DELETE FROM adventure_scenarios WHERE id = ?", [$sid]);
                $db->commit();
                Session::setFlash('success', 'Scenario deleted.');
            } catch (Exception $e) {
                $db->rollBack();
                Session::setFlash('error', 'Delete failed: ' . $e->getMessage());
            }
        }
        redirect('/admin/adventures.php');
    }

    // --- Add new scenario ---
    if ($action === 'add_scenario') {
        $title    = trim($_POST['title'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $flavor   = trim($_POST['flavor_text'] ?? '');
        $category = $_POST['category'] ?? 'daily_life';
        $minLevel = max(1,  (int)($_POST['min_level'] ?? 1));
        $maxLevel = min(50, (int)($_POST['max_level'] ?? 50));
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        $errors = [];
        if (empty($title)) $errors[] = 'Title is required.';
        if (empty($desc))  $errors[] = 'Description is required.';
        if (!array_key_exists($category, $categoryMeta)) $errors[] = 'Invalid category.';

        $choices = parseChoicesFromPost((int)($_POST['choice_count'] ?? 0), $errors);
        if (count($choices) < 2) $errors[] = 'At least 2 choices with all narrative fields are required.';

        if (!empty($errors)) {
            foreach ($errors as $err) Session::setFlash('error', $err);
            $showAddForm = true;
        } else {
            $db->beginTransaction();
            try {
                $db->run(
                    "INSERT INTO adventure_scenarios
                     (title, description, flavor_text, category, min_level, max_level, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$title, $desc, $flavor ?: null, $category, $minLevel, $maxLevel, $isActive]
                );
                $scenarioId = (int)$db->lastInsertId();
                insertChoices($scenarioId, $choices);
                $db->commit();
                Session::setFlash('success', "Scenario \"{$title}\" added with " . count($choices) . " choices.");
                redirect('/admin/adventures.php');
            } catch (Exception $e) {
                $db->rollBack();
                Session::setFlash('error', 'Database error: ' . $e->getMessage());
                $showAddForm = true;
            }
        }
    }

    // --- Save edited scenario ---
    if ($action === 'edit_scenario') {
        $sid      = (int)$_POST['scenario_id'];
        $title    = trim($_POST['title'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $flavor   = trim($_POST['flavor_text'] ?? '');
        $category = $_POST['category'] ?? 'daily_life';
        $minLevel = max(1,  (int)($_POST['min_level'] ?? 1));
        $maxLevel = min(50, (int)($_POST['max_level'] ?? 50));
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        $errors = [];
        if (empty($title)) $errors[] = 'Title is required.';
        if (empty($desc))  $errors[] = 'Description is required.';
        if (!array_key_exists($category, $categoryMeta)) $errors[] = 'Invalid category.';

        $choices = parseChoicesFromPost((int)($_POST['choice_count'] ?? 0), $errors);
        if (count($choices) < 2) $errors[] = 'At least 2 choices with all narrative fields are required.';

        if (!empty($errors)) {
            foreach ($errors as $err) Session::setFlash('error', $err);
            redirect('/admin/adventures.php?edit=' . $sid);
        } else {
            $db->beginTransaction();
            try {
                $db->run(
                    "UPDATE adventure_scenarios
                     SET title=?, description=?, flavor_text=?, category=?,
                         min_level=?, max_level=?, is_active=?
                     WHERE id=?",
                    [$title, $desc, $flavor ?: null, $category, $minLevel, $maxLevel, $isActive, $sid]
                );
                // Replace all choices
                $db->run("DELETE FROM adventure_choices WHERE scenario_id = ?", [$sid]);
                insertChoices($sid, $choices);
                $db->commit();
                Session::setFlash('success', "Scenario \"{$title}\" updated.");
                redirect('/admin/adventures.php');
            } catch (Exception $e) {
                $db->rollBack();
                Session::setFlash('error', 'Save failed: ' . $e->getMessage());
                redirect('/admin/adventures.php?edit=' . $sid);
            }
        }
    }
}

// =========================================================================
// HELPERS
// =========================================================================
function parseChoicesFromPost(int $count, array &$errors): array {
    $choices = [];
    for ($i = 0; $i < $count; $i++) {
        $ct  = trim($_POST["choice_text_{$i}"]         ?? '');
        $ch  = trim($_POST["choice_hint_{$i}"]         ?? '');
        $cd  = (int)($_POST["choice_dc_{$i}"]          ?? 10);
        $cx  = (int)($_POST["choice_xp_{$i}"]          ?? 100);
        $cg  = (int)($_POST["choice_gold_{$i}"]        ?? 25);
        $cs  = trim($_POST["choice_success_{$i}"]      ?? '');
        $cf  = trim($_POST["choice_failure_{$i}"]      ?? '');
        $ccs = trim($_POST["choice_critsuccess_{$i}"]  ?? '');
        $ccf = trim($_POST["choice_critfailure_{$i}"]  ?? '');
        if (empty($ct)) continue;
        if (empty($cs) || empty($cf) || empty($ccs) || empty($ccf)) {
            $errors[] = "Choice " . ($i + 1) . ": all four narrative fields are required.";
            continue;
        }
        $choices[] = compact('ct','ch','cd','cx','cg','cs','cf','ccs','ccf');
    }
    return $choices;
}

function insertChoices(int $scenarioId, array $choices): void {
    global $db;
    foreach ($choices as $ord => $c) {
        $db->run(
            "INSERT INTO adventure_choices
             (scenario_id, choice_text, hint_text, difficulty, base_xp, base_gold,
              sort_order, success_narrative, failure_narrative,
              crit_success_narrative, crit_failure_narrative)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$scenarioId, $c['ct'], $c['ch'] ?: null, $c['cd'], $c['cx'], $c['cg'], $ord,
             $c['cs'], $c['cf'], $c['ccs'], $c['ccf']]
        );
    }
}

// =========================================================================
// LOAD EDIT SCENARIO IF REQUESTED
// =========================================================================
$editId = (int)($_GET['edit'] ?? 0);
if ($editId) {
    $editScenario = $db->fetchOne("SELECT * FROM adventure_scenarios WHERE id = ?", [$editId]);
    if ($editScenario) {
        $editChoices = $db->fetchAll(
            "SELECT * FROM adventure_choices WHERE scenario_id = ? ORDER BY sort_order ASC",
            [$editId]
        );
    }
}

// =========================================================================
// DATA
// =========================================================================
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

// =========================================================================
// RENDER HELPERS — reusable choice block HTML
// =========================================================================
function renderChoiceBlock(int $i, array $choice = [], bool $removable = true): void { ?>
<div class="choice-block" id="choice-block-<?= $i ?>"
     style="background:var(--color-bg-input);border:1px solid var(--color-border);
            border-radius:var(--radius);padding:1.25rem;margin-bottom:1rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <strong style="font-family:var(--font-heading);font-size:0.82rem;
                       letter-spacing:0.06em;color:var(--color-gold-light)">
            Choice <?= $i + 1 ?>
        </strong>
        <?php if ($removable): ?>
        <button type="button" onclick="removeChoice(<?= $i ?>)"
                style="background:none;border:1px solid var(--color-red);color:var(--color-red);
                       border-radius:var(--radius);padding:0.2rem 0.55rem;
                       font-size:0.72rem;cursor:pointer">Remove</button>
        <?php endif; ?>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:0.75rem">
        <div class="form-group" style="margin:0">
            <label>Choice Text</label>
            <input type="text" name="choice_text_<?= $i ?>" required
                   placeholder="What the player does"
                   value="<?= e($choice['choice_text'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin:0">
            <label>Hint Text</label>
            <input type="text" name="choice_hint_<?= $i ?>"
                   placeholder="Visible hint"
                   value="<?= e($choice['hint_text'] ?? '') ?>">
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;margin-bottom:0.75rem">
        <div class="form-group" style="margin:0">
            <label>DC (5–18)</label>
            <input type="number" name="choice_dc_<?= $i ?>" min="5" max="18"
                   value="<?= (int)($choice['difficulty'] ?? 10) ?>">
        </div>
        <div class="form-group" style="margin:0">
            <label>Base XP</label>
            <input type="number" name="choice_xp_<?= $i ?>" min="10" max="500"
                   value="<?= (int)($choice['base_xp'] ?? 100) ?>">
        </div>
        <div class="form-group" style="margin:0">
            <label>Base Gold</label>
            <input type="number" name="choice_gold_<?= $i ?>" min="5" max="200"
                   value="<?= (int)($choice['base_gold'] ?? 25) ?>">
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
        <div class="form-group" style="margin:0">
            <label style="color:var(--color-green)">✔ Success</label>
            <textarea name="choice_success_<?= $i ?>" rows="2" required
                      placeholder="You succeeded..."><?= e($choice['success_narrative'] ?? '') ?></textarea>
        </div>
        <div class="form-group" style="margin:0">
            <label style="color:var(--color-red)">✘ Failure</label>
            <textarea name="choice_failure_<?= $i ?>" rows="2" required
                      placeholder="You failed..."><?= e($choice['failure_narrative'] ?? '') ?></textarea>
        </div>
        <div class="form-group" style="margin:0">
            <label style="color:#fbbf24">⚡ Crit Success</label>
            <textarea name="choice_critsuccess_<?= $i ?>" rows="2" required
                      placeholder="Exceptional outcome..."><?= e($choice['crit_success_narrative'] ?? '') ?></textarea>
        </div>
        <div class="form-group" style="margin:0">
            <label style="color:#f97316">💀 Crit Failure</label>
            <textarea name="choice_critfailure_<?= $i ?>" rows="2" required
                      placeholder="Catastrophic outcome..."><?= e($choice['crit_failure_narrative'] ?? '') ?></textarea>
        </div>
    </div>
</div>
<?php }

// =========================================================================
// RENDER
// =========================================================================
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
        <div style="display:flex;gap:0.75rem;align-items:center">
            <span class="text-muted" style="font-size:0.85rem"><?= count($scenarios) ?> scenarios</span>
            <?php if (!$editScenario): ?>
            <button class="btn btn-primary" id="toggle-add-btn"
                    onclick="toggleAddForm()">+ Add Scenario</button>
            <?php endif; ?>
        </div>
    </div>

    <?= renderFlash() ?>

    <?php if ($editScenario): ?>
    <!-- =====================================================================
         EDIT SCENARIO FORM
    ===================================================================== -->
    <div class="card mb-3" style="padding:1.5rem">
        <h3 class="mb-3">✏️ Edit Scenario: <?= e($editScenario['title']) ?></h3>
        <form method="POST" id="edit-scenario-form">
            <?= Session::csrfField() ?>
            <input type="hidden" name="action"      value="edit_scenario">
            <input type="hidden" name="scenario_id" value="<?= $editScenario['id'] ?>">
            <input type="hidden" name="choice_count" id="choice_count" value="<?= count($editChoices) ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
                <div class="form-group" style="margin:0">
                    <label>Title</label>
                    <input type="text" name="title" required maxlength="150"
                           value="<?= e($editScenario['title']) ?>">
                </div>
                <div class="form-group" style="margin:0">
                    <label>Category</label>
                    <select name="category">
                        <?php foreach ($categoryMeta as $key => $cat): ?>
                        <option value="<?= $key ?>" <?= $editScenario['category'] === $key ? 'selected' : '' ?>>
                            <?= $cat['icon'] ?> <?= $cat['label'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="3" required><?= e($editScenario['description']) ?></textarea>
            </div>

            <div class="form-group">
                <label>Flavor Text <span class="text-muted">(optional)</span></label>
                <input type="text" name="flavor_text" maxlength="255"
                       value="<?= e($editScenario['flavor_text'] ?? '') ?>">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.5rem">
                <div class="form-group" style="margin:0">
                    <label>Min Level</label>
                    <input type="number" name="min_level" min="1" max="50"
                           value="<?= (int)$editScenario['min_level'] ?>">
                </div>
                <div class="form-group" style="margin:0">
                    <label>Max Level</label>
                    <input type="number" name="max_level" min="1" max="50"
                           value="<?= (int)$editScenario['max_level'] ?>">
                </div>
                <div class="form-group" style="margin:0;display:flex;align-items:flex-end;padding-bottom:0.1rem">
                    <label class="checkbox-label" style="padding:0.6rem 0.75rem">
                        <input type="checkbox" name="is_active" value="1"
                               <?= $editScenario['is_active'] ? 'checked' : '' ?>>
                        <span>Active</span>
                    </label>
                </div>
            </div>

            <h4 style="font-family:var(--font-heading);font-size:0.88rem;letter-spacing:0.06em;
                       text-transform:uppercase;color:var(--color-text-muted);
                       margin-bottom:1rem;border-top:1px solid var(--color-border);padding-top:1rem">
                Choices
            </h4>

            <div id="choices-container">
                <?php foreach ($editChoices as $i => $choice):
                    renderChoiceBlock($i, $choice, $i >= 2);
                endforeach; ?>
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:0.5rem">
                <button type="button" onclick="addChoice()" class="btn btn-secondary">+ Add Choice</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="<?= BASE_URL ?>/admin/adventures.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <?php else: ?>
    <!-- =====================================================================
         ADD SCENARIO FORM
    ===================================================================== -->
    <div id="add-form-wrap" style="display:<?= $showAddForm ? 'block' : 'none' ?>">
    <div class="card mb-3" style="padding:1.5rem">
        <h3 class="mb-3">📝 Add New Scenario</h3>
        <form method="POST" id="add-scenario-form">
            <?= Session::csrfField() ?>
            <input type="hidden" name="action" value="add_scenario">
            <input type="hidden" name="choice_count" id="choice_count" value="2">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
                <div class="form-group" style="margin:0">
                    <label>Title</label>
                    <input type="text" name="title" required maxlength="150"
                           placeholder="The Used Car Dealership of Despair"
                           value="<?= e($_POST['title'] ?? '') ?>">
                </div>
                <div class="form-group" style="margin:0">
                    <label>Category</label>
                    <select name="category">
                        <?php foreach ($categoryMeta as $key => $cat): ?>
                        <option value="<?= $key ?>" <?= (($_POST['category'] ?? '') === $key) ? 'selected' : '' ?>>
                            <?= $cat['icon'] ?> <?= $cat['label'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="3" required
                          placeholder="You step onto the lot..."><?= e($_POST['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>Flavor Text <span class="text-muted">(optional)</span></label>
                <input type="text" name="flavor_text" maxlength="255"
                       placeholder="Not all that glitters is a good deal."
                       value="<?= e($_POST['flavor_text'] ?? '') ?>">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.5rem">
                <div class="form-group" style="margin:0">
                    <label>Min Level</label>
                    <input type="number" name="min_level" min="1" max="50" value="1">
                </div>
                <div class="form-group" style="margin:0">
                    <label>Max Level</label>
                    <input type="number" name="max_level" min="1" max="50" value="50">
                </div>
                <div class="form-group" style="margin:0;display:flex;align-items:flex-end;padding-bottom:0.1rem">
                    <label class="checkbox-label" style="padding:0.6rem 0.75rem">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <span>Active immediately</span>
                    </label>
                </div>
            </div>

            <h4 style="font-family:var(--font-heading);font-size:0.88rem;letter-spacing:0.06em;
                       text-transform:uppercase;color:var(--color-text-muted);
                       margin-bottom:1rem;border-top:1px solid var(--color-border);padding-top:1rem">
                Choices (minimum 2)
            </h4>

            <div id="choices-container">
                <?php
                $choiceCount = max(2, (int)($_POST['choice_count'] ?? 2));
                for ($i = 0; $i < $choiceCount; $i++):
                    renderChoiceBlock($i, [], $i >= 2);
                endfor; ?>
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:0.5rem">
                <button type="button" onclick="addChoice()" class="btn btn-secondary">+ Add Another Choice</button>
                <button type="submit" class="btn btn-primary">Save Scenario</button>
                <button type="button" class="btn btn-secondary" onclick="toggleAddForm()">Cancel</button>
            </div>
        </form>
    </div>
    </div>
    <?php endif; ?>

    <!-- =====================================================================
         SCENARIO LIST
    ===================================================================== -->
    <div class="card" style="padding:0;overflow:hidden">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Scenario</th>
                    <th>Category</th>
                    <th>Level Range</th>
                    <th>Choices</th>
                    <th>Played</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scenarios as $s):
                    $cat = $categoryMeta[$s['category']] ?? ['icon' => '⚔', 'label' => $s['category']];
                    $isEditing = $editScenario && $editScenario['id'] == $s['id'];
                ?>
                <tr class="<?= !$s['is_active'] ? 'row-inactive' : '' ?> <?= $isEditing ? 'row-editing' : '' ?>">
                    <td>
                        <strong><?= e($s['title']) ?></strong>
                        <?php if ($s['flavor_text']): ?>
                        <br><small class="text-muted">
                            "<?= e(mb_substr($s['flavor_text'], 0, 55)) ?><?= mb_strlen($s['flavor_text']) > 55 ? '…' : '' ?>"
                        </small>
                        <?php endif; ?>
                    </td>
                    <td><?= $cat['icon'] ?> <?= $cat['label'] ?></td>
                    <td class="text-muted">Lvl <?= $s['min_level'] ?>–<?= $s['max_level'] ?></td>
                    <td><?= $s['choice_count'] ?></td>
                    <td><?= num($s['play_count']) ?></td>
                    <td>
                        <span class="status-tag <?= $s['is_active'] ? 'tag-ok' : 'tag-bad' ?>">
                            <?= $s['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap">
                        <!-- Edit -->
                        <a href="<?= BASE_URL ?>/admin/adventures.php?edit=<?= $s['id'] ?>"
                           class="btn-admin-action" style="text-decoration:none;margin-right:0.25rem">
                            Edit
                        </a>
                        <!-- Toggle -->
                        <form method="POST" style="display:inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action"      value="toggle">
                            <input type="hidden" name="scenario_id" value="<?= $s['id'] ?>">
                            <button class="btn-admin-action">
                                <?= $s['is_active'] ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                        <!-- Delete (only if never played) -->
                        <?php if ((int)$s['play_count'] === 0): ?>
                        <form method="POST" style="display:inline">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action"      value="delete">
                            <input type="hidden" name="scenario_id" value="<?= $s['id'] ?>">
                            <button class="btn-admin-action"
                                    style="color:var(--color-red);border-color:var(--color-red)"
                                    onclick="return confirm('Delete this scenario permanently?')">
                                Delete
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

<?php
$pageContent = ob_get_clean();

$extraScripts = <<<'JS'
<script>
let choiceCount = parseInt(document.getElementById('choice_count')?.value || '2');

function toggleAddForm() {
    var wrap = document.getElementById('add-form-wrap');
    var btn  = document.getElementById('toggle-add-btn');
    if (!wrap) return;
    var showing = wrap.style.display !== 'none';
    wrap.style.display = showing ? 'none' : '';
    if (btn) btn.textContent = showing ? '+ Add Scenario' : '✕ Cancel';
}

function addChoice() {
    var idx = choiceCount++;
    document.getElementById('choice_count').value = choiceCount;
    var container = document.getElementById('choices-container');
    var block = document.createElement('div');
    block.className = 'choice-block';
    block.id = 'choice-block-' + idx;
    block.style.cssText = 'background:var(--color-bg-input);border:1px solid var(--color-border);border-radius:var(--radius);padding:1.25rem;margin-bottom:1rem';
    block.innerHTML =
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">'
        + '<strong style="font-family:var(--font-heading);font-size:0.82rem;letter-spacing:0.06em;color:var(--color-gold-light)">Choice ' + (idx+1) + '</strong>'
        + '<button type="button" onclick="removeChoice(' + idx + ')" style="background:none;border:1px solid var(--color-red);color:var(--color-red);border-radius:var(--radius);padding:0.2rem 0.55rem;font-size:0.72rem;cursor:pointer">Remove</button>'
        + '</div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:0.75rem">'
        + '<div class="form-group" style="margin:0"><label>Choice Text</label><input type="text" name="choice_text_' + idx + '" required placeholder="What the player does"></div>'
        + '<div class="form-group" style="margin:0"><label>Hint Text</label><input type="text" name="choice_hint_' + idx + '" placeholder="Visible hint"></div>'
        + '</div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;margin-bottom:0.75rem">'
        + '<div class="form-group" style="margin:0"><label>DC (5–18)</label><input type="number" name="choice_dc_' + idx + '" min="5" max="18" value="10"></div>'
        + '<div class="form-group" style="margin:0"><label>Base XP</label><input type="number" name="choice_xp_' + idx + '" min="10" max="500" value="100"></div>'
        + '<div class="form-group" style="margin:0"><label>Base Gold</label><input type="number" name="choice_gold_' + idx + '" min="5" max="200" value="25"></div>'
        + '</div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">'
        + '<div class="form-group" style="margin:0"><label style="color:var(--color-green)">✔ Success</label><textarea name="choice_success_' + idx + '" rows="2" required placeholder="You succeeded..."></textarea></div>'
        + '<div class="form-group" style="margin:0"><label style="color:var(--color-red)">✘ Failure</label><textarea name="choice_failure_' + idx + '" rows="2" required placeholder="You failed..."></textarea></div>'
        + '<div class="form-group" style="margin:0"><label style="color:#fbbf24">⚡ Crit Success</label><textarea name="choice_critsuccess_' + idx + '" rows="2" required placeholder="Exceptional..."></textarea></div>'
        + '<div class="form-group" style="margin:0"><label style="color:#f97316">💀 Crit Failure</label><textarea name="choice_critfailure_' + idx + '" rows="2" required placeholder="Catastrophic..."></textarea></div>'
        + '</div>';
    container.appendChild(block);
}

function removeChoice(idx) {
    var block = document.getElementById('choice-block-' + idx);
    if (block) block.remove();
}
</script>
JS;

require TPL_PATH . '/layout.php';
?>
