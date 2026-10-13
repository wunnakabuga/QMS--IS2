<?php
/** Shared page chrome. */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

function base_url(): string
{
    // Depth-aware relative root so the app works in any subdirectory.
    return defined('QMS_ROOT') ? QMS_ROOT : './';
}

function page_head(string $title, string $active = '', array $opts = []): void
{
    $root = base_url();
    $user = auth_user();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> &middot; <?= e(CENTRE_NAME) ?></title>
<link rel="stylesheet" href="<?= e($root) ?>assets/css/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="<?= e($root) ?>index.php">
      <span class="brand-mark">QMS</span>
      <span>
        <span class="brand-name"><?= e($opts['heading'] ?? 'Queue Management System') ?></span><br>
        <span class="brand-sub"><?= e($opts['subheading'] ?? CENTRE_NAME) ?></span>
      </span>
    </a>
    <nav class="nav">
      <?php if ($user && $user['role'] === 'staff'): ?>
        <a href="<?= e($root) ?>staff/counter.php" class="<?= $active === 'counter' ? 'on' : '' ?>">My counter</a>
        <a href="<?= e($root) ?>account.php" class="<?= $active === 'account' ? 'on' : '' ?>">My account</a>
        <a href="<?= e($root) ?>index.php">Public site</a>
        <a href="<?= e($root) ?>logout.php">Sign out</a>
      <?php elseif ($user && $user['role'] === 'admin'): ?>
        <a href="<?= e($root) ?>admin/dashboard.php" class="<?= $active === 'dashboard' ? 'on' : '' ?>">Dashboard</a>
        <a href="<?= e($root) ?>admin/reports.php" class="<?= $active === 'reports' ? 'on' : '' ?>">Reports</a>
        <a href="<?= e($root) ?>account.php" class="<?= $active === 'account' ? 'on' : '' ?>">My account</a>
        <a href="<?= e($root) ?>logout.php">Sign out</a>
      <?php else: ?>
        <a href="<?= e($root) ?>index.php" class="<?= $active === 'home' ? 'on' : '' ?>">Home</a>
        <a href="<?= e($root) ?>join.php" class="<?= $active === 'join' ? 'on' : '' ?>">Join queue</a>
        <a href="<?= e($root) ?>track.php" class="<?= $active === 'track' ? 'on' : '' ?>">Track ticket</a>
        <a href="<?= e($root) ?>login.php" class="<?= $active === 'login' ? 'on' : '' ?>">Staff login</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="wrap <?= !empty($opts['narrow']) ? 'wrap-narrow' : '' ?>">
<?php
    $f = flash();
    if ($f) {
        echo '<div class="alert ' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
}

function page_foot(): void
{
    ?>
</main>
<footer class="foot">
  <?= e(CENTRE_NAME) ?> &middot; Web-Based Queue Management System with Predictive
  Waiting Time &middot; Prototype build
</footer>
</body>
</html>
<?php
}

/** Render a status badge. */
function badge(string $status): string
{
    return '<span class="badge ' . e($status) . '">'
        . e(strtoupper(str_replace('_', ' ', $status))) . '</span>';
}
