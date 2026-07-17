<?php

declare(strict_types=1);

use CiscoPhone\Provisioning\Provisioner;

require dirname(__DIR__) . '/src/Provisioner.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$temporaryRoot = sys_get_temp_dir() . '/phone-provisioner-test-' . bin2hex(random_bytes(5));
expect(mkdir($temporaryRoot, 0700), 'Could not create the test TFTP directory.');

try {
    $provisioner = new Provisioner($temporaryRoot, 0644);
    $form = $provisioner->normalizeInput([
        'mac' => '12:34:56:78:90:ab',
        'label' => 'Test Phone',
        'logo_url' => 'http://192.0.2.123/cisco/bmp/example-logo.bmp',
        'lines' => [
            [
                'extension' => '1234',
                'display_name' => '1234',
                'auth_name' => '1234',
                'password' => 'example-1234',
            ],
            [
                'extension' => '1235',
                'display_name' => 'Support',
                'auth_name' => '',
                'password' => 'example-1235',
            ],
        ],
    ]);

    expect($form['mac'] === '1234567890AB', 'MAC normalization failed.');
    expect($provisioner->validate($form) === [], 'A valid form failed validation.');
    expect($provisioner->filename($form) === 'SIP1234567890AB.cnf', 'Filename generation failed.');

    $config = $provisioner->buildConfig($form);
    expect(str_contains($config, 'line1_password: "example-1234"'), 'Line 1 secret is missing.');
    expect(str_contains($config, 'line2_displayname: "Support"'), 'Line 2 display name is missing.');
    expect(str_contains($config, 'line2_authname: "1235"'), 'Auth name fallback failed.');
    expect(str_contains($config, 'line3_name: "UNPROVISIONED"'), 'Unused lines were not marked unprovisioned.');
    expect(
        strpos($config, 'line1_authname: "1234"') < strpos($config, 'line2_name: "1235"'),
        'Line 1 auth name was not grouped with the rest of line 1.',
    );

    $target = $provisioner->writeConfig($form);
    expect(is_file($target), 'Configuration file was not created.');
    expect(file_get_contents($target) === $config, 'Written configuration does not match generated content.');

    file_put_contents($temporaryRoot . '/SIPDefault.cnf', 'not an endpoint file');
    $endpointFiles = $provisioner->listEndpointFiles();
    expect(count($endpointFiles) === 1, 'Endpoint file listing included an unrelated file.');
    expect($endpointFiles[0]['name'] === 'SIP1234567890AB.cnf', 'Endpoint file listing returned the wrong file.');
    expect($provisioner->readEndpointFile($endpointFiles[0]['name']) === $config, 'Endpoint file could not be viewed.');

    $traversalProtected = false;
    try {
        $provisioner->readEndpointFile('../SIP1234567890AB.cnf');
    } catch (RuntimeException $exception) {
        $traversalProtected = str_contains($exception->getMessage(), 'Invalid endpoint filename');
    }
    expect($traversalProtected, 'Endpoint file viewer accepted an unsafe filename.');

    $protected = false;
    try {
        $provisioner->writeConfig($form);
    } catch (RuntimeException $exception) {
        $protected = str_contains($exception->getMessage(), 'already exists');
    }
    expect($protected, 'Existing-file protection failed.');

    $provisioner->writeConfig($form, true);
    expect(file_get_contents($target) === $config, 'Explicit overwrite failed.');

    $invalid = $form;
    $invalid['lines'][0]['password'] = "bad\nsecret";
    expect($provisioner->validate($invalid) !== [], 'Unsafe configuration characters were accepted.');

    echo "All provisioner tests passed.\n";
} finally {
    foreach (glob($temporaryRoot . '/*') ?: [] as $file) {
        if (is_file($file) || is_link($file)) {
            unlink($file);
        }
    }
    rmdir($temporaryRoot);
}
