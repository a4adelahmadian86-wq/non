#!/usr/bin/env bash
set -euo pipefail

required_extensions=(
  mbstring
  dom
  fileinfo
  pdo_sqlite
  sqlite3
  gd
  zip
  curl
  xml
)

echo "PHP: $(php -r 'echo PHP_VERSION;')"
if ! php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'; then
  echo "PHP 8.2 or newer is required." >&2
  exit 1
fi
echo "Composer: $(composer --version --no-ansi)"

for extension in "${required_extensions[@]}"; do
  if ! php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension"; then
    echo "Missing required PHP extension: $extension" >&2
    exit 1
  fi
done

if [[ ! -f vendor/autoload.php ]]; then
  echo "Composer dependencies are missing. Run: composer install" >&2
  exit 1
fi

if [[ ! -x vendor/bin/phpunit ]]; then
  echo "PHPUnit is missing. Install development dependencies with: composer install (do not use --no-dev)" >&2
  exit 1
fi

composer dump-autoload --optimize --strict-psr
php -r 'require "vendor/autoload.php"; if (!class_exists("Tests\\TestCase")) { fwrite(STDERR, "Tests\\TestCase is not autoloadable. Ensure require-dev dependencies are installed.\n"); exit(1); }'

echo "Test environment and Tests\\TestCase autoload are valid."
