<?php

declare(strict_types=1);

use CiscoPhone\Provisioning\Provisioner;

require dirname(__DIR__) . '/src/Provisioner.php';

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_start();

header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/** @param scalar|null $value */
function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$tftpRoot = getenv('PHONE_PROVISIONER_TFTP_ROOT') ?: '/tftpboot';
$provisioner = new Provisioner($tftpRoot, 0644);

$form = $provisioner->normalizeInput([
    'mac' => '',
    'label' => '',
    'logo_url' => '',
    'lines' => [[
        'extension' => '',
        'display_name' => '',
        'auth_name' => '',
        'password' => '',
    ]],
]);

$errors = [];
$success = isset($_SESSION['provisioner_flash']) ? (string) $_SESSION['provisioner_flash'] : '';
unset($_SESSION['provisioner_flash']);

if (!isset($_SESSION['provisioner_csrf'])) {
    $_SESSION['provisioner_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['provisioner_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';

    if (!hash_equals($csrfToken, $submittedToken)) {
        $errors[] = 'The form expired. Refresh the page and try again.';
    } else {
        $form = $provisioner->normalizeInput($_POST);
        $errors = $provisioner->validate($form);

        if ($errors === []) {
            try {
                $overwrite = ($_POST['overwrite'] ?? '') === '1';
                $path = $provisioner->writeConfig($form, $overwrite);

                $authenticatedUser = (string) ($_SERVER['REMOTE_USER'] ?? 'unknown');
                $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
                error_log(sprintf(
                    'Cisco phone provisioner wrote %s user=%s remote=%s',
                    basename($path),
                    $authenticatedUser,
                    $remoteAddress,
                ));

                $_SESSION['provisioner_flash'] = basename($path) . ' was created successfully.';
                $_SESSION['provisioner_csrf'] = bin2hex(random_bytes(32));
                header('Location: ./?file=' . rawurlencode(basename($path)), true, 303);
                exit;
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

    }
}

$endpointFiles = $provisioner->listEndpointFiles();
$selectedEndpoint = is_string($_GET['file'] ?? null) ? $_GET['file'] : '';
if ($selectedEndpoint === '' && $endpointFiles !== []) {
    $selectedEndpoint = $endpointFiles[0]['name'];
}

$selectedContents = '';
$fileError = '';
if ($selectedEndpoint !== '') {
    try {
        $selectedContents = $provisioner->readEndpointFile($selectedEndpoint);
    } catch (RuntimeException $exception) {
        $fileError = $exception->getMessage();
        $selectedEndpoint = '';
    }
}

$filename = preg_match('/^[0-9A-F]{12}$/', $form['mac'])
    ? $provisioner->filename($form)
    : 'SIPMACADDRESS.cnf';
$preview = $provisioner->buildConfig($form);
$isReady = $provisioner->isReady();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= h($csrfToken) ?>">
    <title>Cisco Phone Provisioner</title>
    <link rel="stylesheet" href="assets/app.css">
    <script src="assets/app.js" defer></script>
</head>
<body>
<header class="topbar">
    <div class="brand">
        <span class="brand-mark">CP</span>
        <span>
            <strong>Cisco Phone</strong>
            <small>Endpoint Provisioner</small>
        </span>
    </div>
    <span class="server-pill <?= $isReady ? 'server-ready' : 'server-error' ?>">
        <?= $isReady ? 'TFTP ready' : 'TFTP not writable' ?>
    </span>
</header>

<main class="page-shell" data-tftp-root="<?= h($provisioner->tftpRoot()) ?>">
    <div class="page-heading">
        <div>
            <h1>Cisco SIP endpoint</h1>
            <p>Create a phone configuration directly in <?= h($provisioner->tftpRoot()) ?>.</p>
        </div>
        <p class="page-status" aria-live="polite"><span></span><span id="page-message">Ready</span></p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="notice success-notice" role="status"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="notice error-notice" role="alert">
            <strong>Configuration was not created.</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="workspace">
        <form class="panel details-panel" id="provision-form" method="post" action="" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <section class="form-section" aria-labelledby="phone-details-title">
                <div class="section-heading">
                    <h2 id="phone-details-title">Phone details</h2>
                </div>

                <div class="phone-grid">
                    <label class="field full-field">
                        <span>MAC address</span>
                        <input
                            id="mac"
                            name="mac"
                            value="<?= h($provisioner->formatMac($form['mac'])) ?>"
                            placeholder="1234567890ab"
                            autocomplete="off"
                            spellcheck="false"
                            required
                        >
                        <small>Colons and uppercase are added when you leave the field.</small>
                    </label>

                    <label class="field">
                        <span>Phone label</span>
                        <input
                            id="phone-label"
                            name="label"
                            value="<?= h($form['label']) ?>"
                            maxlength="11"
                            placeholder="Example"
                            autocomplete="off"
                            required
                        >
                        <small><span id="label-count"><?= strlen($form['label']) ?></span>/11</small>
                    </label>

                    <label class="field">
                        <span>Logo URL <em>optional</em></span>
                        <input
                            id="logo-url"
                            name="logo_url"
                            value="<?= h($form['logo_url']) ?>"
                            placeholder="http://server/cisco/logo.bmp"
                            inputmode="url"
                            autocomplete="off"
                            spellcheck="false"
                        >
                    </label>
                </div>
            </section>

            <section class="form-section" aria-labelledby="lines-title">
                <div class="section-heading">
                    <div>
                        <h2 id="lines-title">Phone lines</h2>
                        <p><span id="line-count"><?= count($form['lines']) ?></span> of 6 configured</p>
                    </div>
                    <button class="secondary-button" id="add-line" type="button" <?= count($form['lines']) >= 6 ? 'disabled' : '' ?>>
                        + Add line
                    </button>
                </div>

                <div class="line-list" id="line-list">
                    <?php foreach ($form['lines'] as $index => $line): ?>
                        <fieldset class="line-card" data-line-card>
                            <legend>Line <?= $index + 1 ?></legend>
                            <button
                                class="remove-button"
                                type="button"
                                data-remove-line
                                aria-label="Remove line <?= $index + 1 ?>"
                                <?= count($form['lines']) === 1 ? 'hidden' : '' ?>
                            >Remove</button>
                            <div class="line-grid">
                                <label class="field">
                                    <span>Extension</span>
                                    <input
                                        data-field="extension"
                                        name="lines[<?= $index ?>][extension]"
                                        value="<?= h($line['extension']) ?>"
                                        inputmode="numeric"
                                        placeholder="1234"
                                        autocomplete="off"
                                        required
                                    >
                                </label>
                                <label class="field">
                                    <span>Display name <em>optional</em></span>
                                    <input
                                        data-field="display_name"
                                        name="lines[<?= $index ?>][display_name]"
                                        value="<?= h($line['display_name']) ?>"
                                        placeholder="Defaults to extension"
                                        autocomplete="off"
                                    >
                                </label>
                                <label class="field">
                                    <span>Auth name <em>optional</em></span>
                                    <input
                                        data-field="auth_name"
                                        name="lines[<?= $index ?>][auth_name]"
                                        value="<?= h($line['auth_name']) ?>"
                                        placeholder="Defaults to extension"
                                        autocomplete="off"
                                    >
                                </label>
                                <label class="field">
                                    <span>SIP secret</span>
                                    <input
                                        data-field="password"
                                        type="text"
                                        name="lines[<?= $index ?>][password]"
                                        value="<?= h($line['password']) ?>"
                                        placeholder="Extension secret"
                                        autocomplete="off"
                                        spellcheck="false"
                                        required
                                    >
                                </label>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                </div>

                <p class="unprovisioned-note" id="unprovisioned-note"></p>
            </section>

            <label class="overwrite-control">
                <input type="checkbox" name="overwrite" value="1">
                <span>Replace the file if this MAC address already exists</span>
            </label>

            <div class="form-actions">
                <div>
                    <span>Target file</span>
                    <code id="target-path"><?= h($provisioner->tftpRoot() . '/' . $filename) ?></code>
                </div>
                <button class="primary-button" type="submit" <?= $isReady ? '' : 'disabled' ?>>
                    Create config file
                </button>
            </div>
        </form>

        <section class="panel preview-panel" aria-labelledby="preview-title">
            <div class="preview-heading">
                <div>
                    <h2 id="preview-title">Config preview</h2>
                    <code id="preview-filename"><?= h($filename) ?></code>
                </div>
            </div>
            <pre class="config-preview" tabindex="0" aria-label="Generated configuration file"><code id="config-output"><?= h($preview) ?></code></pre>
        </section>
    </div>

    <section class="panel endpoint-browser" aria-labelledby="endpoint-files-title">
        <div class="endpoint-browser-heading">
            <div>
                <h2 id="endpoint-files-title">Endpoint files</h2>
                <p><?= count($endpointFiles) ?> file<?= count($endpointFiles) === 1 ? '' : 's' ?> in <?= h($provisioner->tftpRoot()) ?></p>
            </div>
        </div>

        <?php if ($fileError !== ''): ?>
            <div class="endpoint-empty" role="alert"><?= h($fileError) ?></div>
        <?php elseif ($endpointFiles === []): ?>
            <div class="endpoint-empty">No endpoint files have been created yet.</div>
        <?php else: ?>
            <div class="endpoint-browser-grid">
                <nav class="endpoint-file-list" aria-label="Endpoint configuration files">
                    <?php foreach ($endpointFiles as $endpointFile): ?>
                        <a
                            href="?file=<?= rawurlencode($endpointFile['name']) ?>#endpoint-files-title"
                            class="endpoint-file-link <?= $selectedEndpoint === $endpointFile['name'] ? 'selected' : '' ?>"
                            <?= $selectedEndpoint === $endpointFile['name'] ? 'aria-current="page"' : '' ?>
                        >
                            <strong><?= h($endpointFile['name']) ?></strong>
                            <span><?= h(date('M j, Y g:i A', $endpointFile['modified'])) ?> · <?= number_format($endpointFile['size']) ?> bytes</span>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="endpoint-file-viewer">
                    <div class="endpoint-file-viewer-heading">
                        <span>File contents</span>
                        <code><?= h($selectedEndpoint) ?></code>
                    </div>
                    <pre tabindex="0" aria-label="Contents of <?= h($selectedEndpoint) ?>"><code><?= h($selectedContents) ?></code></pre>
                </div>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
