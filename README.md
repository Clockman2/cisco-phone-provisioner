# Cisco Phone Provisioner

This is a project I have been working on to make it easier to create legacy
Cisco SIP phone configuration files from a small web interface.

The application is written in native PHP and creates `SIP<MAC>.cnf` files in a
TFTP directory for supported Cisco phones.

## Features

- Creates configurations for up to six SIP lines
- Marks unused lines as `UNPROVISIONED`
- Normalizes MAC addresses automatically
- Shows a live configuration preview
- Lists and displays existing endpoint files
- Protects existing files unless overwrite is selected
- Uses no database, Composer packages, or Node.js dependencies

## Requirements

- PHP 8.2 or newer
- Apache 2.4 with PHP support
- A writable TFTP directory such as `/tftpboot`
- `apache2-utils` if using the included Basic Authentication configuration

## Installation

Copy `public` and `src` to `/opt/cisco-phone-provisioner`, then install the
example Apache configuration from `deploy/cisco-phone-provisioner.conf`.

```bash
install -d -o root -g root -m 0755 /opt/cisco-phone-provisioner
cp -a public src /opt/cisco-phone-provisioner/

cp deploy/cisco-phone-provisioner.conf /etc/apache2/conf-available/
a2enconf cisco-phone-provisioner
apache2ctl configtest
systemctl reload apache2
```

By default, generated files are written to `/tftpboot`. Set
`PHONE_PROVISIONER_TFTP_ROOT` in the Apache configuration to use another directory.

Open the application at:

```text
https://your-server/provisioner/
```

## Test

```bash
php tests/run.php
```

The application creates phone configuration files only. FreePBX extensions and
SIP credentials must already exist.
