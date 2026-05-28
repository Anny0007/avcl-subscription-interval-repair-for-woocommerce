# AVCL Subscription Interval Repair for WooCommerce

A WordPress plugin that detects and repairs subscription interval mismatches in WooCommerce Subscriptions.

## Plugin Details

- **Plugin Name:** AVCL Subscription Interval Repair for WooCommerce
- **Version:** 1.2.0
- **WordPress.org Slug:** avcl-subscription-interval-repair-for-woocommerce
- **Requires:** WordPress 5.0+, WooCommerce, WooCommerce Subscriptions

## Repository Structure

```
trunk/          All plugin source files (deployed to WordPress.org)
assets/         WordPress.org store page images (banners, icons, screenshots)
.github/        GitHub Actions workflows
```

## Deployment

Deployments to WordPress.org happen automatically via GitHub Actions.

To release a new version:
1. Update `Version:` in the main plugin PHP file
2. Update `Stable tag:` in `trunk/readme.txt`
3. Commit and push changes
4. Create and push a version tag:

```bash
git tag v1.2.0
git push origin v1.2.0
```

The GitHub Action will automatically deploy to WordPress.org SVN.

## Required GitHub Secrets

| Secret | Description |
|--------|-------------|
| `SVN_USERNAME` | Your WordPress.org username |
| `SVN_PASSWORD` | Your WordPress.org password |
