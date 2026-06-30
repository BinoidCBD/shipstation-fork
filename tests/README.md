# Running the test suite

PHPUnit tests for the GH-9 shipment queue. They run on the standard WordPress core
test framework with WooCommerce loaded.

## Prerequisites

- PHP 7.4+ CLI with the `mysqli` extension.
- A MySQL/MariaDB you can create a throwaway database on.
- WooCommerce available to the test install (loaded from `wp-content/plugins/woocommerce`
  by default; override with `WC_PLUGIN_DIR`).
- PHPUnit 9 + the Yoast PHPUnit Polyfills, installed **outside** this plugin — the
  plugin's `vendor/` is committed (Jetpack autoloader) and must not be overwritten.

## One-time setup

1. Install PHPUnit + polyfills in a separate directory (keeps the plugin's `vendor/` intact):

   ```bash
   mkdir -p ~/wp-test-tools && cd ~/wp-test-tools
   composer require --dev phpunit/phpunit:^9 yoast/phpunit-polyfills:^2
   ```

2. Install the WP test suite and create a **throwaway** test database. The script
   downloads WordPress + the test library over HTTPS (curl/tar — no svn needed):

   ```bash
   cd /path/to/plugins/shipstation-fork
   bin/install-wp-tests.sh <test_db_name> <db_user> <db_pass> <db_host> latest
   ```

   - `<test_db_name>` must be a database that is safe to wipe — the framework drops
     and recreates it on every run. **Never point it at a real site database.**
   - `<db_host>` accepts `host`, `host:port`, or `host:/path/to/socket`.

## Run

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
export WP_TESTS_PHPUNIT_POLYFILLS_PATH="$HOME/wp-test-tools/vendor/yoast/phpunit-polyfills"
export WC_PLUGIN_DIR="/path/to/plugins/woocommerce"
~/wp-test-tools/vendor/bin/phpunit -c phpunit.xml.dist
```

Override `WP_TESTS_DIR` / `WP_CORE_DIR` if the suite was installed elsewhere.

## What's covered

- `test-shipment-notification-parity.php` — the synchronous path and the queue
  worker produce identical order state (meta, status, notes).
- `test-shipment-queue.php` — enqueue dedupe; claim/attempt accounting; the M1
  poison-row quarantine in `reclaim_stale()`; the M2 `failed_depth()` / `depth()`
  counts.
