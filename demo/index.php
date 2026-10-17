<?php
/**
 * pint-ae playground — paste invoice JSON (or PINT AE XML), get XML + validation.
 * Upload the whole pint-ae folder to any PHP 8.1+ host and open /demo/.
 * No database, no files written, no network calls.
 */
declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use PintAE\PintAE;

const MAX_INPUT = 512 * 1024;

$examples = [];
foreach (glob(__DIR__ . '/../examples/invoices/*.json') ?: [] as $f) {
    $examples[basename($f, '.json')] = (string) file_get_contents($f);
}
$pick = $_GET['example'] ?? '01-standard';
$input = $_POST['input'] ?? ($examples[$pick] ?? reset($examples));
$mode = $_POST['mode'] ?? 'generate';
$xml = $json = $error = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (strlen($input) > MAX_INPUT) {
            throw new RuntimeException('Input is larger than 512 KB.');
        }
        if ($mode === 'validate') {
            $xml = $input;
        } else {
            $data = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
            $xml = PintAE::generate($data);
        }
        $result = PintAE::validate($xml);
        if (!$result->hasRule('PAE-000')) {
            $json = PintAE::toJson($xml);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$h = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>pint-ae playground</title>
<style>
  :root { --bg:#f7f6f2; --panel:#fff; --ink:#1d1f1e; --mute:#6b6f6c; --line:#e3e1da; --accent:#0f6b4f; --bad:#b3261e; --warn:#8a5a00; --code:#f1efe8; }
  @media (prefers-color-scheme: dark) { :root { --bg:#141615; --panel:#1c1f1d; --ink:#e9ebe8; --mute:#9aa19c; --line:#2c312e; --accent:#5ccf9f; --bad:#ff8a80; --warn:#f2c46d; --code:#232724; } }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif; }
  header { padding:20px 16px 8px; max-width:1240px; margin:auto; }
  header h1 { margin:0; font-size:22px; letter-spacing:-.01em; }
  header p { margin:4px 0 0; color:var(--mute); }
  main { max-width:1240px; margin:auto; padding:8px 16px 40px; display:grid; gap:16px; grid-template-columns:1fr 1fr; }
  @media (max-width: 900px) { main { grid-template-columns:1fr; } }
  section { background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:14px; min-width:0; }
  h2 { font-size:13px; text-transform:uppercase; letter-spacing:.06em; color:var(--mute); margin:0 0 10px; }
  textarea, pre { width:100%; font:12.5px/1.45 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; background:var(--code); color:var(--ink); border:1px solid var(--line); border-radius:8px; padding:10px; }
  textarea { height:520px; resize:vertical; }
  pre { max-height:420px; overflow:auto; margin:0; white-space:pre; }
  .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:10px; }
  select, button { font:inherit; padding:7px 12px; border-radius:7px; border:1px solid var(--line); background:var(--panel); color:var(--ink); }
  button.primary { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; cursor:pointer; }
  @media (prefers-color-scheme: dark) { button.primary { color:#08130e; } }
  .badge { display:inline-block; padding:3px 10px; border-radius:99px; font-weight:600; font-size:13px; }
  .ok { background:color-mix(in srgb,var(--accent) 15%,transparent); color:var(--accent); }
  .no { background:color-mix(in srgb,var(--bad) 15%,transparent); color:var(--bad); }
  ul.issues { list-style:none; padding:0; margin:10px 0 0; }
  ul.issues li { padding:7px 0; border-top:1px solid var(--line); font-size:14px; }
  code.rule { font-size:12px; padding:1px 6px; border-radius:5px; background:var(--code); }
  .e { color:var(--bad); font-weight:600; } .w { color:var(--warn); font-weight:600; }
  .muted { color:var(--mute); font-size:13px; }
  details { margin-top:12px; } summary { cursor:pointer; font-weight:600; }
</style>
</head>
<body>
<header>
  <h1>pint-ae playground</h1>
  <p>UAE PINT AE e-invoice generator &amp; validator — paste invoice JSON to build XML, or paste XML to check it.</p>
</header>
<main>
  <section>
    <form method="post">
      <div class="row">
        <select onchange="location='?example='+this.value" aria-label="Load example">
          <?php foreach ($examples as $k => $_): ?>
            <option value="<?= $h($k) ?>" <?= $k === $pick ? 'selected' : '' ?>><?= $h($k) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="mode" aria-label="Mode">
          <option value="generate" <?= $mode === 'generate' ? 'selected' : '' ?>>JSON → XML + validate</option>
          <option value="validate" <?= $mode === 'validate' ? 'selected' : '' ?>>Validate XML</option>
        </select>
        <button class="primary" type="submit">Run</button>
      </div>
      <textarea name="input" spellcheck="false"><?= $h($input) ?></textarea>
    </form>
    <p class="muted">Rule ids like <code class="rule">IBR-132-AE</code> mirror the official PINT AE rules; <code class="rule">PAE-xxx</code> are extra library checks. This is a pre-check — your ASP's validation is final.</p>
  </section>

  <section>
    <h2>Result</h2>
    <?php if ($error): ?>
      <span class="badge no">Error</span>
      <p><?= $h($error) ?></p>
    <?php elseif ($result): ?>
      <span class="badge <?= $result->isValid() ? 'ok' : 'no' ?>">
        <?= $result->isValid() ? 'Valid' : 'Invalid' ?> — <?= count($result->errors()) ?> error(s), <?= count($result->warnings()) ?> warning(s)
      </span>
      <?php if ($result->all()): ?>
        <ul class="issues">
          <?php foreach ($result->all() as $i): ?>
            <li><span class="<?= $i['severity'] === 'error' ? 'e' : 'w' ?>"><?= $i['severity'] === 'error' ? 'Error' : 'Warning' ?></span>
              <code class="rule"><?= $h($i['rule']) ?></code> <?= $h($i['message']) ?>
              <?php if ($i['path']): ?><br><span class="muted"><?= $h($i['path']) ?></span><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($xml && $mode === 'generate'): ?>
        <details open><summary>PINT AE XML</summary><pre><?= $h($xml) ?></pre></details>
      <?php endif; ?>
      <?php if ($json): ?>
        <details><summary>Parsed back to JSON</summary><pre><?= $h($json) ?></pre></details>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Pick an example (14 UAE scenarios) and press <strong>Run</strong>.</p>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
