<?php

declare(strict_types=1);

namespace CiscoPhone\Provisioning;

use RuntimeException;

final class Provisioner
{
    public const MAX_LINES = 6;

    public function __construct(
        private readonly string $tftpRoot,
        private readonly int $fileMode = 0644,
    ) {
    }

    /**
     * Convert request data into the application's small, predictable shape.
     *
     * @param array<string, mixed> $input
     * @return array{mac: string, label: string, logo_url: string, lines: list<array{extension: string, display_name: string, auth_name: string, password: string}>}
     */
    public function normalizeInput(array $input): array
    {
        $lines = [];
        $submittedLines = $input['lines'] ?? [];

        if (is_array($submittedLines)) {
            foreach (array_slice(array_values($submittedLines), 0, self::MAX_LINES) as $line) {
                if (!is_array($line)) {
                    continue;
                }

                $lines[] = [
                    'extension' => $this->stringValue($line['extension'] ?? ''),
                    'display_name' => $this->stringValue($line['display_name'] ?? ''),
                    'auth_name' => $this->stringValue($line['auth_name'] ?? ''),
                    'password' => $this->stringValue($line['password'] ?? '', false),
                ];
            }
        }

        return [
            'mac' => $this->normalizeMac($this->stringValue($input['mac'] ?? '')),
            'label' => $this->stringValue($input['label'] ?? ''),
            'logo_url' => $this->stringValue($input['logo_url'] ?? ''),
            'lines' => $lines,
        ];
    }

    public function normalizeMac(string $value): string
    {
        $normalized = preg_replace('/[^0-9A-F]/', '', strtoupper($value));
        return substr($normalized ?? '', 0, 12);
    }

    public function formatMac(string $value): string
    {
        $normalized = $this->normalizeMac($value);
        return implode(':', str_split($normalized, 2));
    }

    /**
     * @param array{mac: string, label: string, logo_url: string, lines: list<array{extension: string, display_name: string, auth_name: string, password: string}>} $form
     * @return list<string>
     */
    public function validate(array $form): array
    {
        $errors = [];

        if (!preg_match('/^[0-9A-F]{12}$/', $form['mac'])) {
            $errors[] = 'Enter a complete 12-character MAC address.';
        }

        if ($form['label'] === '' || strlen($form['label']) > 11) {
            $errors[] = 'Phone label must be 1–11 characters.';
        } elseif ($this->containsUnsafeCharacters($form['label'])) {
            $errors[] = 'Phone label contains unsupported characters.';
        }

        if ($form['logo_url'] !== '') {
            $scheme = strtolower((string) parse_url($form['logo_url'], PHP_URL_SCHEME));
            if (
                $this->containsUnsafeCharacters($form['logo_url'])
                || filter_var($form['logo_url'], FILTER_VALIDATE_URL) === false
                || !in_array($scheme, ['http', 'https'], true)
            ) {
                $errors[] = 'Logo URL must be a valid http:// or https:// address without quotes or control characters.';
            }
        }

        if (count($form['lines']) < 1) {
            $errors[] = 'Add at least one phone line.';
        }

        foreach ($form['lines'] as $index => $line) {
            $number = $index + 1;

            if (!preg_match('/^[0-9]{2,10}$/', $line['extension'])) {
                $errors[] = "Line {$number}: extension must contain 2–10 digits.";
            }

            if (strlen($line['password']) < 8) {
                $errors[] = "Line {$number}: SIP secret must contain at least 8 characters.";
            }

            foreach (['extension', 'display_name', 'auth_name', 'password'] as $field) {
                if ($this->containsUnsafeCharacters($line[$field])) {
                    $errors[] = "Line {$number}: quotes, control characters, and line breaks are not allowed.";
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * @param array{mac: string, label: string, logo_url: string, lines: list<array{extension: string, display_name: string, auth_name: string, password: string}>} $form
     */
    public function filename(array $form): string
    {
        return 'SIP' . $form['mac'] . '.cnf';
    }

    /**
     * @param array{mac: string, label: string, logo_url: string, lines: list<array{extension: string, display_name: string, auth_name: string, password: string}>} $form
     */
    public function buildConfig(array $form): string
    {
        $output = [
            '# Cisco SIP Configuration',
            '',
            'phone_label: "' . $form['label'] . '"',
            'logo_url: "' . $form['logo_url'] . '"',
        ];

        for ($index = 0; $index < self::MAX_LINES; $index++) {
            $line = $form['lines'][$index] ?? null;
            $number = $index + 1;

            if ($line === null) {
                $extension = 'UNPROVISIONED';
                $displayName = 'UNPROVISIONED';
                $password = 'UNPROVISIONED';
                $authName = 'UNPROVISIONED';
            } else {
                $extension = $line['extension'];
                $displayName = $line['display_name'] !== '' ? $line['display_name'] : $extension;
                $password = $line['password'];
                $authName = $line['auth_name'] !== '' ? $line['auth_name'] : $extension;
            }

            $output[] = "line{$number}_name: \"{$extension}\"";
            $output[] = "line{$number}_shortname: \"{$extension}\"";
            $output[] = "line{$number}_displayname: \"{$displayName}\"";
            $output[] = "line{$number}_password: \"{$password}\"";
            $output[] = "line{$number}_authname: \"{$authName}\"";
        }

        return implode("\n", $output) . "\n";
    }

    /**
     * @param array{mac: string, label: string, logo_url: string, lines: list<array{extension: string, display_name: string, auth_name: string, password: string}>} $form
     */
    public function writeConfig(array $form, bool $overwrite = false): string
    {
        $errors = $this->validate($form);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $root = rtrim($this->tftpRoot, DIRECTORY_SEPARATOR);
        if (!is_dir($root)) {
            throw new RuntimeException("TFTP directory does not exist: {$root}");
        }
        if (!is_writable($root)) {
            throw new RuntimeException("TFTP directory is not writable by PHP: {$root}");
        }

        $filename = $this->filename($form);
        $target = $root . DIRECTORY_SEPARATOR . $filename;
        $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cisco-phone-provisioner-' . sha1($root) . '.lock';
        $lock = fopen($lockPath, 'c');

        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Could not obtain the provisioning write lock.');
        }

        $temporary = null;

        try {
            clearstatcache(true, $target);
            if (file_exists($target) && !$overwrite) {
                throw new RuntimeException("{$filename} already exists. Select overwrite to replace it.");
            }

            $temporary = tempnam($root, '.phone-provision-');
            if ($temporary === false) {
                throw new RuntimeException('Could not create a temporary file in the TFTP directory.');
            }

            $contents = $this->buildConfig($form);
            $bytes = file_put_contents($temporary, $contents, LOCK_EX);
            if ($bytes === false || $bytes !== strlen($contents)) {
                throw new RuntimeException('Could not write the complete configuration file.');
            }

            if (!chmod($temporary, $this->fileMode)) {
                throw new RuntimeException('Could not set configuration file permissions.');
            }

            if (!rename($temporary, $target)) {
                throw new RuntimeException('Could not move the configuration into the TFTP directory.');
            }
            $temporary = null;

            return $target;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function tftpRoot(): string
    {
        return rtrim($this->tftpRoot, DIRECTORY_SEPARATOR);
    }

    public function isReady(): bool
    {
        return is_dir($this->tftpRoot()) && is_writable($this->tftpRoot());
    }

    /**
     * @return list<array{name: string, size: int, modified: int}>
     */
    public function listEndpointFiles(): array
    {
        $root = $this->tftpRoot();
        if (!is_dir($root) || !is_readable($root)) {
            return [];
        }

        $files = [];
        foreach (scandir($root) ?: [] as $name) {
            if (!$this->isEndpointFilename($name)) {
                continue;
            }

            $path = $root . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $files[] = [
                'name' => $name,
                'size' => (int) (filesize($path) ?: 0),
                'modified' => (int) (filemtime($path) ?: 0),
            ];
        }

        usort($files, static fn (array $left, array $right): int => $right['modified'] <=> $left['modified']);
        return $files;
    }

    public function readEndpointFile(string $name): string
    {
        if (!$this->isEndpointFilename($name)) {
            throw new RuntimeException('Invalid endpoint filename.');
        }

        $path = $this->tftpRoot() . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("{$name} could not be read.");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("{$name} could not be read.");
        }

        return $contents;
    }

    private function isEndpointFilename(string $name): bool
    {
        return preg_match('/^SIP[0-9A-F]{12}\.cnf$/D', $name) === 1;
    }

    private function containsUnsafeCharacters(string $value): bool
    {
        return preg_match('/["\r\n\x00-\x1F\x7F]/', $value) === 1;
    }

    private function stringValue(mixed $value, bool $trim = true): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $string = (string) $value;
        return $trim ? trim($string) : $string;
    }
}
