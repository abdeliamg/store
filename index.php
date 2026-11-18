<?php
/**
 * CStore — Main Entry Point
 * Routes requests and serves the SPA shell
 */
require_once __DIR__ . '/config.php';

// Check if setup is needed
if (needs_setup()) {
    require_once __DIR__ . '/setup.php';
    exit;
}

// Get site settings for the shell
$siteName = DB::setting('site_name', 'CStore');
$themeColor = (string) DB::setting('theme_color', '#4F46E5');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $themeColor)) {
    $themeColor = '#4F46E5';
}
$logoPath = (string) DB::setting('company_logo', '');
if (!preg_match('#^uploads/[A-Za-z0-9_\-/]+\.(png|jpe?g|gif|webp)$#i', $logoPath) || strpos($logoPath, '..') !== false) {
    $logoPath = '';
}
$user = auth_user();
$csrfToken = csrf_token();
$userKycStatus = $user ? get_user_kyc_status($user['id']) : 'none';
// v6: Load active currencies for frontend currency selector (refresh API rates if stale)
refresh_currency_rates();
$currenciesStmt = DB::get()->query("SELECT id, code, name, symbol, rate FROM currencies WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
$currencies = $currenciesStmt->fetchAll(PDO::FETCH_ASSOC);
// Safe JSON for embedding inside <script>: escapes < > & ' " so values cannot break out of the tag
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> — متجرك الإلكتروني">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        :root {
            --primary: <?= htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8') ?>;
            --primary-hover: color-mix(in srgb, var(--primary) 80%, black);
            --primary-light: color-mix(in srgb, var(--primary) 12%, white);
            --primary-lighter: color-mix(in srgb, var(--primary) 6%, white);
        }
    </style>
</head>
<body>
    <!-- App Shell -->
    <div id="app">
        <!-- Navbar (shown when logged in) -->
        <nav id="navbar" class="navbar" style="display:none;">
            <div class="navbar-inner">
                <div class="navbar-brand">
                    <a href="#/" onclick="location.hash='#/';if(typeof navigate==='function')navigate();" style="display:flex;align-items:center;text-decoration:none;color:inherit;cursor:pointer;">
                    <?php if ($logoPath): ?>
                    <img src="/<?= htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>" class="navbar-logo">
                    <?php else: ?>
                    <span class="navbar-title"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    </a>
                </div>
                <div class="navbar-links" id="navLinks">
                    <a href="#/" class="nav-link active" data-page="store">🛒 المتجر</a>
                    <a href="#/orders" class="nav-link auth-required" data-page="orders">📦 طلباتي</a>
                    <a href="#/wallet" class="nav-link auth-required" data-page="wallet">💰 المحفظة</a>
                    <a href="#/contact" class="nav-link" data-page="contact">📞 تواصل معنا</a>
                </div>
                <div class="navbar-actions">
                    <span class="wallet-badge" id="walletBadge" title="رصيد المحفظة">$0.00</span>
                    <div class="user-menu" id="userMenu">
                        <button class="user-menu-btn" id="userMenuBtn">
                            <span id="userMenuName"></span>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </button>
                        <div class="user-menu-dropdown" id="userMenuDropdown">
                            <a href="#/profile" class="dropdown-item">👤 الملف الشخصي</a>
                            <a href="#/admin" class="dropdown-item admin-only" style="display:none;">⚙️ لوحة التحكم</a>
                            <a href="#" class="dropdown-item" id="logoutBtn">🚪 تسجيل خروج</a>
                        </div>
                    </div>
                    <button class="hamburger" id="hamburgerBtn" aria-label="القائمة">
                        <span></span><span></span><span></span>
                    </button>
                </div>
            </div>
            <!-- Mobile nav (legacy, hidden by bottom bar on mobile) -->
            <div class="mobile-nav" id="mobileNav">
                <a href="#/" class="mobile-nav-link active" data-page="store">🛒 المتجر</a>
                <a href="#/orders" class="mobile-nav-link auth-required" data-page="orders">📦 طلباتي</a>
                <a href="#/wallet" class="mobile-nav-link auth-required" data-page="wallet">💰 المحفظة</a>
                <a href="#/contact" class="mobile-nav-link" data-page="contact">📞 تواصل معنا</a>
                <a href="#/admin" class="mobile-nav-link admin-only" style="display:none;">⚙️ لوحة التحكم</a>
            </div>
        </nav>

        <!-- Main Content Area -->
        <main id="mainContent" class="main-content">
            <!-- Pages loaded here -->
        </main>

        <!-- Bottom Tab Bar (mobile) -->
        <nav class="bottom-bar" id="bottomBar">
            <a href="#/" class="bottom-bar-item active" data-page="store">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>المتجر</span>
            </a>
            <a href="#/orders" class="bottom-bar-item auth-required" data-page="orders">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                <span>طلباتي</span>
            </a>
            <a href="#/wallet" class="bottom-bar-item auth-required" data-page="wallet">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                <span>المحفظة</span>
            </a>
            <a href="#/contact" class="bottom-bar-item" data-page="contact">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span>تواصل</span>
            </a>
            <a href="#/login" class="bottom-bar-item guest-only" data-page="login">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                <span>دخول</span>
            </a>
            <div class="back-peek" id="backPeek">
                <svg class="back-peek-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
        </nav>
    </div>

    <script>
        window.APP = {
            csrf: <?= json_encode((string) $csrfToken, $jsonFlags) ?>,
            user: <?= $user ? json_encode([
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
                'wallet_balance' => $user['wallet_balance'],
                'first_name' => $user['first_name'] ?? null,
                'last_name' => $user['last_name'] ?? null,
                'profile_image' => $user['profile_image'] ?? null,
                'kyc_status' => $userKycStatus,
            ], $jsonFlags) : 'null' ?>,
            siteName: <?= json_encode($siteName, $jsonFlags) ?>,
            currencies: <?= json_encode($currencies, $jsonFlags) ?>,
        };
    </script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
    <script src="/assets/js/app.js"></script>
</body>
</html>
