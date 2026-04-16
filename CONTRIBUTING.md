# Contributing

Thanks for your interest in magento-ucp.

## Quick start

```bash
git clone https://github.com/shopwalk-inc/magento-ucp
cd magento-ucp
composer install
```

Install into a Magento 2 development environment via Composer path repository, or symlink into `app/code/Shopwalk/Ucp`.

## Testing

```bash
vendor/bin/phpcs --standard=Magento2 .
vendor/bin/phpstan analyse
php -l $(find . -name "*.php" -not -path "./vendor/*")
```

## Pull requests

- Keep changes focused — one feature or fix per PR
- Update [CHANGELOG.md](CHANGELOG.md) under "Unreleased"
- Do not modify the release version in `composer.json`; releases are cut from tags
- Run the lint checks locally before opening the PR

## Reporting issues

- UCP protocol questions: [ucp.dev](https://ucp.dev)
- Plugin bugs: open an issue with Magento version, PHP version, and reproduction steps
- Security issues: email security@shopwalk.com, do not open a public issue
