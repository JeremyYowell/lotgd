<?php
/**
 * admin/audio.php — ElevenLabs Audio Management
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireAdmin();

// =========================================================================
// HELPERS
// =========================================================================
function audioDir(): string { return ROOT_PATH . '/assets/audio/adventures'; }

function audioFilename(int $scenarioId, int $choiceOrder, string $type): string {
    if ($type === 'title_desc') return "Adventure{$scenarioId}TitleDesc.mp3";
    return match($type) {
        'choice_text'  => "Adventure{$scenarioId}Choice{$choiceOrder}Text.mp3",
        'success'      => "Adventure{$scenarioId}Choice{$choiceOrder}Success.mp3",
        'failure'      => "Adventure{$scenarioId}Choice{$choiceOrder}Failure.mp3",
        'crit_success' => "Adventure{$scenarioId}Choice{$choiceOrder}CritSuccess.mp3",
        'crit_failure' => "Adventure{$scenarioId}Choice{$choiceOrder}CritFailure.mp3",
        default        => "Adventure{$scenarioId}Choice{$choiceOrder}{$type}.mp3",
    };
}

function buildText(string $type, array $scenario, array $choice = [], int $ord = 0): string {
    $ordinals = ['First','Second','Third','Fourth','Fifth'];
    $ordWord  = $ordinals[max(0,$ord-1)] ?? "Choice {$ord}";
    return match($type) {
        'title_desc'   => $scenario['title'] . '. ' . $scenario['description'],
        'choice_text'  => $ordWord . ' choice. ' . ($choice['choice_text'] ?? ''),
        'success'      => 'Success. '          . ($choice['success_narrative'] ?? ''),
        'failure'      => 'Failure. '          . ($choice['failure_narrative'] ?? ''),
        'crit_success' => 'Critical Success! ' . ($choice['crit_success_narrative'] ?? ''),
        'crit_failure' => 'Critical Failure! ' . ($choice['crit_failure_narrative'] ?? ''),
        default        => '',
    };
}

function elevenLabsGet(string $endpoint, string $key): array {
    $ch = curl_init('https://api.elevenlabs.io/v1' . $endpoint);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>['xi-api-key: '.$key]]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    return ['code'=>$code,'body'=>$raw,'error'=>$err];
}

// =========================================================================
// AJAX: TEST (quick sanity check)
// =========================================================================
if (($_GET['ajax']??'') === 'test') {
    header('Content-Type: application/json');
    $dir    = audioDir();
    $dirOk  = is_dir($dir);
    $dirW   = $dirOk && is_writable($dir);
    $curlOk = function_exists('curl_init');
    $key    = $db->getSetting('elevenlabs_api_key','');
    echo json_encode([
        'ok'         => true,
        'audio_dir'  => $dir,
        'dir_exists' => $dirOk,
        'dir_writable'=> $dirW,
        'curl'       => $curlOk,
        'key_set'    => !empty($key),
        'key_prefix' => $key ? substr($key,0,4).'...' : 'empty',
        'php_version'=> PHP_VERSION,
        'time_limit' => ini_get('max_execution_time'),
    ]);
    exit;
}

// =========================================================================
// AJAX: CREDITS
// =========================================================================
if (($_GET['ajax']??'') === 'credits') {
    header('Content-Type: application/json');
    $key = $db->getSetting('elevenlabs_api_key','');
    if (!$key){echo json_encode(['ok'=>false,'msg'=>'No API key set — save it in Voice Settings first.']);exit;}
    $res = elevenLabsGet('/user/subscription',$key);
    if ($res['error']||$res['code']!==200){echo json_encode(['ok'=>false,'msg'=>"HTTP {$res['code']}"]);exit;}
    $d = json_decode($res['body'],true);
    echo json_encode(['ok'=>true,'used'=>$d['character_count']??0,'limit'=>$d['character_limit']??0,
        'remaining'=>($d['character_limit']??0)-($d['character_count']??0)]);
    exit;
}

// =========================================================================
// AJAX: VOICES
// =========================================================================
if (($_GET['ajax']??'') === 'voices') {
    header('Content-Type: application/json');
    $key = $db->getSetting('elevenlabs_api_key','');
    if (!$key){echo json_encode(['ok'=>false,'msg'=>'No API key set.']);exit;}
    $res = elevenLabsGet('/voices',$key);
    if ($res['error']||$res['code']!==200){echo json_encode(['ok'=>false,'msg'=>"HTTP {$res['code']}"]);exit;}
    $d  = json_decode($res['body'],true);
    $vs = array_map(fn($v)=>['id'=>$v['voice_id'],'name'=>$v['name']],$d['voices']??[]);
    usort($vs,fn($a,$b)=>strcmp($a['name'],$b['name']));
    echo json_encode(['ok'=>true,'voices'=>$vs]);
    exit;
}

// =========================================================================
// AJAX: TASKLIST — returns list of files to generate for a scenario
// =========================================================================
if (($_GET['ajax']??'') === 'tasklist') {
    header('Content-Type: application/json');
    $sid = (int)($_POST['scenario_id']??0);
    if (!$sid) { echo json_encode(['ok'=>false,'msg'=>'Invalid scenario.']); exit; }

    $choices = $db->fetchAll(
        "SELECT * FROM adventure_choices WHERE scenario_id=? ORDER BY sort_order ASC", [$sid]
    );

    $tasks = [['type'=>'title_desc','cid'=>null,'ord'=>0,
                'fname'=>audioFilename($sid,0,'title_desc')]];
    foreach ($choices as $i => $c) {
        $ord = $i + 1;
        foreach (['choice_text','success','failure','crit_success','crit_failure'] as $t) {
            $tasks[] = ['type'=>$t,'cid'=>(int)$c['id'],'ord'=>$ord,
                        'fname'=>audioFilename($sid,$ord,$t)];
        }
    }
    echo json_encode(['ok'=>true,'tasks'=>$tasks]);
    exit;
}

// =========================================================================
// AJAX: GENERATE — one file per request to stay within server time limits
// =========================================================================
if (($_GET['ajax']??'') === 'generate') {
    set_time_limit(60);
    header('Content-Type: application/json');

    register_shutdown_function(function() {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['ok'=>false,'msg'=>'PHP fatal: '.$err['message'].' line '.$err['line']]);
        }
    });

    $sid   = (int)($_POST['scenario_id']??0);
    $force = !empty($_POST['force']);
    $type  = trim($_POST['audio_type'] ?? '');
    $cid   = isset($_POST['choice_id']) && $_POST['choice_id'] !== '' ? (int)$_POST['choice_id'] : null;
    $ord   = (int)($_POST['choice_order'] ?? 0);

    $key   = $db->getSetting('elevenlabs_api_key','');
    $vid   = $db->getSetting('elevenlabs_voice_id','RILOU7YmBhvwJGDGjNmP');
    $mid   = $db->getSetting('elevenlabs_model_id','eleven_multilingual_v2');
    $stab  = (float)$db->getSetting('elevenlabs_stability','0.5');
    $sim   = (float)$db->getSetting('elevenlabs_similarity_boost','0.75');

    if (!$key) { echo json_encode(['ok'=>false,'msg'=>'API key not set.']); exit; }
    if (!$sid) { echo json_encode(['ok'=>false,'msg'=>'Invalid scenario.']); exit; }
    if (!$type){ echo json_encode(['ok'=>false,'msg'=>'Missing audio_type.']); exit; }

    $scenario = $db->fetchOne("SELECT * FROM adventure_scenarios WHERE id=?", [$sid]);
    if (!$scenario) { echo json_encode(['ok'=>false,'msg'=>'Scenario not found.']); exit; }

    $choice = [];
    if ($cid) {
        $choice = $db->fetchOne("SELECT * FROM adventure_choices WHERE id=?", [$cid]) ?: [];
    }

    $dir = audioDir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $fname = audioFilename($sid, $ord, $type);
    $fpath = $dir . '/' . $fname;

    if (!$force && file_exists($fpath)) {
        echo json_encode(['ok'=>true,'skipped'=>true,'file'=>$fname,'chars'=>0]);
        exit;
    }

    $text = trim(buildText($type, $scenario, $choice, $ord));
    if (!$text) {
        echo json_encode(['ok'=>false,'msg'=>"Empty text for {$fname}"]);
        exit;
    }

    $ch = curl_init("https://api.elevenlabs.io/v1/text-to-speech/{$vid}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'text'           => $text,
            'model_id'       => $mid,
            'voice_settings' => ['stability'=>$stab,'similarity_boost'=>$sim],
        ]),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'xi-api-key: '.$key,
            'Accept: audio/mpeg',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $code !== 200) {
        echo json_encode(['ok'=>false,'msg'=>"ElevenLabs error for {$fname}: ".($err ?: "HTTP {$code}")]);
        exit;
    }

    if (file_put_contents($fpath, $raw) === false) {
        echo json_encode(['ok'=>false,'msg'=>"Cannot write {$fname} — check directory permissions."]);
        exit;
    }

    $charCount = strlen($text);
    $db->run(
        "INSERT INTO adventure_audio(scenario_id,choice_id,audio_type,file_path,char_count)
         VALUES(?,?,?,?,?)
         ON DUPLICATE KEY UPDATE file_path=VALUES(file_path),char_count=VALUES(char_count),generated_at=NOW()",
        [$sid, $cid, $type, 'assets/audio/adventures/'.$fname, $charCount]
    );

    echo json_encode(['ok'=>true,'skipped'=>false,'file'=>$fname,'chars'=>$charCount]);
    exit;
}
// =========================================================================
// POST: SAVE SETTINGS
// =========================================================================
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_settings') {
    Session::verifyCsrfPost();
    foreach (['elevenlabs_api_key','elevenlabs_voice_id','elevenlabs_model_id',
              'elevenlabs_stability','elevenlabs_similarity_boost'] as $k) {
        if (!empty($_POST[$k])) {
            $db->run("INSERT INTO settings(setting_key,setting_value)VALUES(?,?)
                      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",
                [$k, trim($_POST[$k])]);
        }
    }
    Session::setFlash('success','Audio settings saved.');
    redirect('/admin/audio.php');
}

// =========================================================================
// DATA
// =========================================================================
$apiKey   = $db->getSetting('elevenlabs_api_key','');
$voiceId  = $db->getSetting('elevenlabs_voice_id','RILOU7YmBhvwJGDGjNmP');
$modelId  = $db->getSetting('elevenlabs_model_id','eleven_multilingual_v2');
$stability= $db->getSetting('elevenlabs_stability','0.5');
$simBoost = $db->getSetting('elevenlabs_similarity_boost','0.75');

$scenarios = $db->fetchAll(
    "SELECT ads.*, COUNT(DISTINCT ac.id) AS choice_count
     FROM adventure_scenarios ads
     LEFT JOIN adventure_choices ac ON ac.scenario_id=ads.id
     GROUP BY ads.id ORDER BY ads.category, ads.title"
);

$audioByScenario = [];
foreach ($db->fetchAll("SELECT scenario_id, COUNT(*) AS cnt FROM adventure_audio GROUP BY scenario_id") as $r) {
    $audioByScenario[(int)$r['scenario_id']] = (int)$r['cnt'];
}

$pageTitle = 'Audio Manager';
$bodyClass = 'page-admin';
$extraCss  = ['admin.css'];

ob_start();
?>

<div class="admin-wrap">

    <div class="admin-header">
        <div>
            <a href="<?= BASE_URL ?>/admin/index.php" class="admin-back">← Admin</a>
            <h1>🔊 Audio Manager</h1>
        </div>
        <a href="https://elevenlabs.io" target="_blank" rel="noopener"
           style="font-family:var(--font-heading);font-size:0.72rem;letter-spacing:0.1em;
                  color:var(--color-text-muted);text-decoration:none">
            Powered by ElevenLabs ↗
        </a>
    </div>

    <?= renderFlash() ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.5rem;align-items:start">

        <!-- CREDITS -->
        <div class="card" style="padding:1.5rem">
            <h3 class="mb-3" style="font-family:var(--font-heading);font-size:0.82rem;
                letter-spacing:0.08em;text-transform:uppercase;color:var(--color-text-muted)">
                💳 Credit Balance
            </h3>
            <div id="credit-display" style="color:var(--color-text-muted);font-size:0.9rem">
                <?= $apiKey ? 'Loading...' : '⚠ Set API key to check credits.' ?>
            </div>
        </div>

        <!-- SETTINGS -->
        <div class="card" style="padding:1.5rem">
            <h3 class="mb-3" style="font-family:var(--font-heading);font-size:0.82rem;
                letter-spacing:0.08em;text-transform:uppercase;color:var(--color-text-muted)">
                ⚙ Voice Settings
            </h3>
            <form method="POST">
                <?= Session::csrfField() ?>
                <input type="hidden" name="action" value="save_settings">

                <div class="form-group">
                    <label>API Key</label>
                    <input type="password" name="elevenlabs_api_key"
                           placeholder="<?= $apiKey ? 'Key set — paste new key to change' : 'Your ElevenLabs API key' ?>">
                    <p class="hint">Leave blank to keep the current key.</p>
                </div>

                <div class="form-group">
                    <label>Voice ID</label>
                    <div style="display:flex;gap:0.5rem">
                        <input type="text" name="elevenlabs_voice_id" id="voice_id_input"
                               value="<?= e($voiceId) ?>" style="flex:1;font-family:monospace;font-size:0.82rem">
                        <button type="button" class="btn btn-secondary" onclick="fetchVoices()"
                                style="flex-shrink:0;white-space:nowrap;font-size:0.78rem">Browse</button>
                    </div>
                    <div id="voice-list" style="display:none;margin-top:0.4rem;max-height:160px;overflow-y:auto;
                         background:var(--color-bg-input);border:1px solid var(--color-border);
                         border-radius:var(--radius)"></div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.5rem">
                    <div class="form-group" style="margin:0">
                        <label>Model</label>
                        <select name="elevenlabs_model_id" style="font-size:0.8rem">
                            <option value="eleven_multilingual_v2" <?= $modelId==='eleven_multilingual_v2'?'selected':'' ?>>Multilingual v2</option>
                            <option value="eleven_v3" <?= $modelId==='eleven_v3'?'selected':'' ?>>Eleven v3</option>
                            <option value="eleven_flash_v2_5" <?= $modelId==='eleven_flash_v2_5'?'selected':'' ?>>Flash v2.5</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label>Stability</label>
                        <input type="number" name="elevenlabs_stability" min="0" max="1" step="0.05" value="<?= e($stability) ?>">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label>Similarity</label>
                        <input type="number" name="elevenlabs_similarity_boost" min="0" max="1" step="0.05" value="<?= e($simBoost) ?>">
                    </div>
                </div>

                <div style="margin-top:1rem">
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </form>
        </div>
    </div>

    <!-- SCENARIO TABLE -->
    <div class="card" style="padding:0;overflow:hidden">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Scenario</th>
                    <th>Category</th>
                    <th>Choices</th>
                    <th>Audio Files</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($scenarios as $s):
                $expected   = 1 + ((int)$s['choice_count'] * 5);
                $generated  = $audioByScenario[(int)$s['id']] ?? 0;
                $complete   = $generated >= $expected;
            ?>
            <tr>
                <td><strong><?= e($s['title']) ?></strong></td>
                <td class="text-muted"><?= e($s['category']) ?></td>
                <td><?= $s['choice_count'] ?></td>
                <td>
                    <span id="audio-count-<?= $s['id'] ?>"
                          style="font-family:var(--font-heading);font-size:0.78rem;
                                 color:<?= $complete ? 'var(--color-green)' : 'var(--color-text-muted)' ?>">
                        <?= $generated ?> / <?= $expected ?> <?= $complete ? '✓' : '' ?>
                    </span>
                </td>
                <td style="white-space:nowrap">
                    <button class="btn-admin-action" onclick="generateAudio(<?= $s['id'] ?>, false)">
                        Generate Missing
                    </button>
                    <button class="btn-admin-action" style="color:var(--color-text-dim)"
                            onclick="generateAudio(<?= $s['id'] ?>, true)"
                            title="Regenerate all files including existing ones">
                        Force Regen
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="gen-status" style="display:none;margin-top:1rem"></div>

</div>

<?php
$pageContent = ob_get_clean();

$extraScripts = <<<'JS'
<script>
document.addEventListener("DOMContentLoaded", function() {
    if (document.getElementById("credit-display").textContent.trim() === "Loading...") {
        loadCredits();
    }
});

function loadCredits() {
    fetch("?ajax=credits")
        .then(r => r.json())
        .then(d => {
            var el = document.getElementById("credit-display");
            if (!d.ok) { el.textContent = d.msg; return; }
            var pct   = d.limit > 0 ? Math.round((d.used / d.limit) * 100) : 0;
            var color = pct < 70 ? "var(--color-green)" : pct < 90 ? "#f59e0b" : "var(--color-red)";
            el.innerHTML =
                "<div style='margin-bottom:0.5rem'><strong style='color:" + color + "'>"
                + d.remaining.toLocaleString() + "</strong>"
                + " <span style='color:var(--color-text-muted);font-size:0.8rem'>remaining</span></div>"
                + "<div style='height:6px;background:var(--color-border);border-radius:3px;overflow:hidden'>"
                + "<div style='height:100%;background:" + color + ";width:" + pct + "%'></div></div>"
                + "<div style='font-size:0.75rem;color:var(--color-text-dim);margin-top:0.3rem'>"
                + d.used.toLocaleString() + " / " + d.limit.toLocaleString() + " used this month</div>";
        })
        .catch(() => { document.getElementById("credit-display").textContent = "Could not load credits."; });
}

function fetchVoices() {
    var list = document.getElementById("voice-list");
    list.style.display = "block";
    list.innerHTML = "<div style='padding:0.5rem;color:var(--color-text-muted);font-size:0.82rem'>Loading voices...</div>";
    fetch("?ajax=voices")
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { list.innerHTML = "<div style='padding:0.5rem;color:var(--color-red)'>" + d.msg + "</div>"; return; }
            list.innerHTML = d.voices.map(v =>
                "<div onclick='selectVoice(\"" + v.id + "\")' style='padding:0.45rem 0.75rem;cursor:pointer;"
                + "font-size:0.82rem;border-bottom:1px solid var(--color-border)' "
                + "onmouseover='this.style.background=\"var(--color-bg-card)\"' "
                + "onmouseout='this.style.background=\"\"'>"
                + v.name + " <span style='color:var(--color-text-dim);font-family:monospace;font-size:0.7rem'>" + v.id + "</span></div>"
            ).join("");
        })
        .catch(() => { list.innerHTML = "<div style='padding:0.5rem;color:var(--color-red)'>Network error</div>"; });
}

function selectVoice(id) {
    document.getElementById("voice_id_input").value = id;
    document.getElementById("voice-list").style.display = "none";
}

function generateAudio(sid, force) {
    var el = document.getElementById("gen-status");
    el.style.display = "block";
    el.innerHTML = "<div style='padding:0.75rem 1rem;background:var(--color-bg-card);"
        + "border:1px solid var(--color-border);border-radius:var(--radius);"
        + "color:var(--color-text-muted);font-size:0.88rem'>"
        + "⏳ Loading scenario info...</div>";
    el.scrollIntoView({behavior:"smooth",block:"nearest"});

    var infoForm = new FormData();
    infoForm.append("scenario_id", sid);
    fetch("?ajax=tasklist", {method:"POST", body:infoForm})
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { showStatus(el, false, data.msg); return; }
            runTasksSequentially(el, sid, force, data.tasks, 0, 0, 0, []);
        })
        .catch(() => showStatus(el, false, "Network error fetching task list."));
}

function runTasksSequentially(el, sid, force, tasks, idx, generated, skipped, errors) {
    if (idx >= tasks.length) {
        var msg = errors.length === 0
            ? "✓ Generated " + generated + " file(s), skipped " + skipped + " existing."
            : "Completed with errors: " + errors.join("; ");
        showStatus(el, errors.length === 0, msg);
        if (errors.length === 0) { loadCredits(); setTimeout(() => location.reload(), 2000); }
        return;
    }

    var t   = tasks[idx];
    var pct = Math.round((idx / tasks.length) * 100);
    el.innerHTML = "<div style='padding:0.75rem 1rem;background:var(--color-bg-card);"
        + "border:1px solid var(--color-border);border-radius:var(--radius);font-size:0.85rem'>"
        + "<div style='color:var(--color-text-muted);margin-bottom:0.4rem'>"
        + "Generating " + (idx+1) + " / " + tasks.length + ": <code>" + t.fname + "</code></div>"
        + "<div style='height:6px;background:var(--color-border);border-radius:3px;overflow:hidden'>"
        + "<div style='height:100%;background:var(--color-gold);width:" + pct + "%;transition:width 0.3s'></div></div>"
        + "</div>";

    var form = new FormData();
    form.append("scenario_id", sid);
    form.append("force",        force ? "1" : "0");
    form.append("audio_type",   t.type);
    form.append("choice_id",    t.cid !== null ? t.cid : "");
    form.append("choice_order", t.ord);

    fetch("?ajax=generate", {method:"POST", body:form})
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { errors.push(d.msg); }
            else if (d.skipped) { skipped++; }
            else { generated++; t.chars = d.chars || 0; }
            runTasksSequentially(el, sid, force, tasks, idx+1, generated, skipped, errors);
        })
        .catch(err => {
            errors.push("Network error on " + t.fname);
            runTasksSequentially(el, sid, force, tasks, idx+1, generated, skipped, errors);
        });
}

function showStatus(el, ok, msg) {
    var c  = ok ? "var(--color-green)" : "var(--color-red)";
    var b  = ok ? "#052e16" : "#2d0a0a";
    var br = ok ? "#166534" : "#7f1d1d";
    el.innerHTML = "<div style='padding:0.75rem 1rem;background:" + b
        + ";border:1px solid " + br + ";border-radius:var(--radius);"
        + "color:" + c + ";font-size:0.88rem'>" + msg + "</div>";
}
</script>
JS;

require TPL_PATH . '/layout.php';
?>
