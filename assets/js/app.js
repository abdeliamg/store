/**
 * CStore — SPA Router & Core App Logic
 */

/**
 * Compress image to JPEG if >500KB. Returns a File (or original if no compression needed).
 */
window.compressImage = function (file, quality = 0.8, maxSize = 500 * 1024) {
    return new Promise((resolve) => {
        if (!file || !file.type.startsWith('image/') || file.size <= maxSize) {
            return resolve(file);
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                canvas.width = img.width;
                canvas.height = img.height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0);
                canvas.toBlob((blob) => {
                    if (blob) {
                        const name = file.name.replace(/\.[^.]+$/, '.jpg');
                        resolve(new File([blob], name, { type: 'image/jpeg' }));
                    } else {
                        resolve(file);
                    }
                }, 'image/jpeg', quality);
            };
            img.onerror = () => resolve(file);
            img.src = e.target.result;
        };
        reader.onerror = () => resolve(file);
        reader.readAsDataURL(file);
    });
};

/**
 * Compress all image File entries in a FormData. Returns a new FormData.
 */
window.compressFormDataImages = async function (fd) {
    const newFd = new FormData();
    for (const [key, value] of fd.entries()) {
        if (value instanceof File && value.type.startsWith('image/')) {
            newFd.append(key, await compressImage(value));
        } else {
            newFd.append(key, value);
        }
    }
    return newFd;
};

(function () {
    'use strict';

    const $ = (sel, ctx = document) => ctx.querySelector(sel);
    const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];
    const main = $('#mainContent');

    // ==========================================
    // Currency Helper — v6
    // ==========================================
    function getSelectedCurrency() {
        const code = localStorage.getItem('cstore_currency') || 'USD';
        const currencies = APP.currencies || [];
        return currencies.find(c => c.code === code) || currencies.find(c => c.code === 'USD') || { code: 'USD', symbol: '$', rate: 1 };
    }

    // Plain-text price (use with textContent only)
    function formatPriceText(usdAmount) {
        const cur = getSelectedCurrency();
        const converted = parseFloat(usdAmount) * parseFloat(cur.rate);
        if (cur.code === 'USD') return `$${converted.toFixed(2)}`;
        return `${converted.toFixed(2)} ${cur.symbol ?? ''}`;
    }

    // HTML-safe price (use when interpolating into innerHTML templates)
    function formatPrice(usdAmount) {
        return esc(formatPriceText(usdAmount));
    }

    function renderCurrencySelector() {
        const currencies = APP.currencies || [];
        if (currencies.length <= 1) return;
        const cur = getSelectedCurrency();
        // Clean up previous
        document.getElementById('currencySelector')?.remove();
        document.getElementById('currencyDropdownPortal')?.remove();
        document.getElementById('currencyBackdrop')?.remove();

        // Button — placed in navbar
        const container = document.createElement('div');
        container.id = 'currencySelector';
        container.className = 'currency-selector';
        container.innerHTML = `<button class="currency-btn" id="currencyToggleBtn">
            <i class="fa-solid fa-globe"></i><span>${esc(cur.code)}</span>
        </button>`;

        const navbar = document.querySelector('.navbar-actions') || document.querySelector('.navbar-inner');
        if (navbar) navbar.insertBefore(container, navbar.firstChild);

        // Dropdown — appended to body (escapes navbar stacking context)
        const portal = document.createElement('div');
        portal.id = 'currencyDropdownPortal';
        portal.className = 'currency-dropdown';
        portal.innerHTML = `<div class="currency-dropdown-header">اختر العملة</div>
            ${currencies.map(c => `<div class="currency-option ${c.code === cur.code ? 'active' : ''}" data-code="${esc(c.code)}">
                <span class="currency-option-info"><span class="currency-option-symbol">${esc(c.symbol)}</span> ${esc(c.code)} <span class="currency-option-name">— ${esc(c.name)}</span></span>
                ${c.code === cur.code ? '<i class="fa-solid fa-check"></i>' : ''}
            </div>`).join('')}`;
        document.body.appendChild(portal);

        // Backdrop
        const bdEl = document.createElement('div');
        bdEl.id = 'currencyBackdrop';
        bdEl.className = 'currency-backdrop';
        document.body.appendChild(bdEl);

        function positionDropdown() {
            const btn = document.getElementById('currencyToggleBtn');
            if (!btn || window.innerWidth <= 768) return; // mobile uses fixed bottom-sheet
            const rect = btn.getBoundingClientRect();
            portal.style.top = (rect.bottom + 8) + 'px';
            portal.style.right = (window.innerWidth - rect.right) + 'px';
            portal.style.left = 'auto';
        }

        const toggle = () => {
            portal.classList.toggle('show');
            bdEl.classList.toggle('show');
            if (portal.classList.contains('show')) positionDropdown();
        };

        document.getElementById('currencyToggleBtn')?.addEventListener('click', (e) => {
            e.stopPropagation();
            toggle();
        });
        bdEl.addEventListener('click', toggle);
        portal.querySelectorAll('.currency-option').forEach(opt => {
            opt.addEventListener('click', (e) => {
                e.stopPropagation();
                localStorage.setItem('cstore_currency', opt.dataset.code);
                toggle();
                navigate();
            });
        });
    }

    // ==========================================
    // API Helper
    // ==========================================
    async function api(url, opts = {}) {
        const defaults = {
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': APP.csrf },
        };
        if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
            opts.body = JSON.stringify(opts.body);
        }
        if (opts.body instanceof FormData) {
            delete defaults.headers['Content-Type'];
        }
        const res = await fetch(url, { credentials: 'same-origin', ...defaults, ...opts, headers: { ...defaults.headers, ...(opts.headers || {}) } });
        const data = await res.json();
        // Server may rotate the CSRF token (e.g. after login/logout session regeneration)
        if (data && typeof data.csrf === 'string' && data.csrf) APP.csrf = data.csrf;
        else if (data && typeof data.csrf_token === 'string' && data.csrf_token) APP.csrf = data.csrf_token;
        // Stale CSRF token (session expired while the tab was open): retry once with the fresh token
        if (res.status === 403 && data && data.csrf_retry && !opts._csrfRetried) {
            const { headers: _h, ...rest } = opts;
            return api(url, { ...rest, headers: { ...(opts.headers || {}), 'X-CSRF-TOKEN': APP.csrf }, _csrfRetried: true });
        }
        if (!res.ok || data.success === false) {
            throw new Error(data.error || 'حدث خطأ غير متوقع');
        }
        return data;
    }

    // ==========================================
    // Toast Notifications
    // ==========================================
    function showToast(message, type = 'success') {
        let container = $('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }

    // ==========================================
    // Auth State
    // ==========================================
    function updateAuthUI() {
        const navbar = $('#navbar');
        const user = APP.user;
        const bottomBar = $('#bottomBar');

        navbar.style.display = 'block';

        if (user) {
            // Logged-in state
            $('#walletBadge').textContent = formatPriceText(user.wallet_balance);
            $('#walletBadge').style.display = '';
            const verifiedBadge = user.kyc_status === 'approved' ? '<span class="verified-badge" title="حساب موثق">✅</span>' : '';
            $('#userMenuName').innerHTML = esc(user.username) + verifiedBadge;
            $('#userMenu').style.display = '';
            $$('.auth-required').forEach(el => el.style.display = '');
            $$('.guest-only').forEach(el => el.style.display = 'none');

            // Remove guest login link from desktop nav if exists
            $('#loginNavLink')?.remove();

            if (user.role === 'admin') {
                $$('.admin-only').forEach(el => el.style.display = '');
            }
        } else {
            // Guest state
            $('#walletBadge').style.display = 'none';
            $('#userMenu').style.display = 'none';
            $$('.auth-required').forEach(el => el.style.display = 'none');
            $$('.admin-only').forEach(el => el.style.display = 'none');
            $$('.guest-only').forEach(el => el.style.display = '');

            // Add login link to desktop navbar if not exists
            if (!$('#loginNavLink')) {
                const navLinks = $('#navLinks');
                if (navLinks) {
                    navLinks.insertAdjacentHTML('beforeend',
                        '<a href="#/login" class="nav-link guest-only" id="loginNavLink" data-page="login">🔑 تسجيل الدخول</a>');
                }
            }
        }
    }

    // ==========================================
    // Router
    // ==========================================
    const routes = {
        '': renderStore,
        'login': renderLogin,
        'orders': renderOrders,
        'order': renderOrderDetail,
        'wallet': renderWallet,
        'contact': renderContact,
        'item': renderItemDetail,
        'category': renderCategoryDetail,
        'profile': renderProfile,
        'admin': renderAdmin,
        'admin/categories': () => renderAdmin('categories'),
        'admin/items': () => renderAdmin('items'),
        'admin/orders': () => renderAdmin('orders'),
        'admin/users': () => renderAdmin('users'),
        'admin/topup-methods': () => renderAdmin('topup-methods'),
        'admin/topup-requests': () => renderAdmin('topup-requests'),
        'admin/carousel': () => renderAdmin('carousel'),
        'admin/settings': () => renderAdmin('settings'),
        'admin/alerts': () => renderAdmin('alerts'),
        'admin/currencies': () => renderAdmin('currencies'),
        'admin/kyc': () => renderAdmin('kyc'),
    };

    // Public routes accessible without authentication
    const publicRoutes = ['', 'item', 'category', 'contact', 'login'];

    function navigate() {
        const hash = location.hash.slice(2) || '';
        // Strip query string for route matching (e.g. login?return=...)
        const [hashPath] = hash.split('?');
        const [path, ...paramParts] = hashPath.split('/');
        const fullPath = paramParts.length ? `${path}/${paramParts.join('/')}` : path;

        // Auth check — only redirect for protected routes
        if (!APP.user && !publicRoutes.includes(path)) {
            location.hash = `#/login?return=/${hashPath}`;
            return;
        }

        updateAuthUI();
        updateActiveNav(path);

        // Refresh wallet balance on every navigation (if logged in)
        if (APP.user) {
            api('/api/auth.php?action=me').then(res => {
                if (res.user && res.user.wallet_balance !== undefined) {
                    APP.user.wallet_balance = res.user.wallet_balance;
                    const badge = document.querySelector('#walletBadge');
                    if (badge) badge.textContent = formatPriceText(res.user.wallet_balance);
                }
            }).catch(() => {});
        }

        // v6: Render currency selector
        renderCurrencySelector();

        // Show/hide bottom bar on login page
        const bottomBar = $('#bottomBar');
        if (bottomBar) {
            bottomBar.style.display = '';
            if (path === 'login') {
                bottomBar.style.display = 'none';
            }
        }

        // Show/hide back-peek button (hidden on main store page, login, admin)
        const backPeek = $('#backPeek');
        if (backPeek) {
            const isMainPage = !path || path === 'store';
            const hideBack = isMainPage || path === 'login' || path === 'admin';
            backPeek.style.display = hideBack ? 'none' : 'flex';
        }

        // Show/hide contact FAB (not on login/admin pages)
        const isLoginOrAdmin = path === 'login' || path === 'admin';
        renderContactFAB(!isLoginOrAdmin);

        // Show/hide admin edit toggle (only on store-facing pages for admins)
        const isStorePage = ['', 'category', 'item'].includes(path);
        // Track page context for the admin add button
        if (isStorePage) {
            if (path === '' || path === undefined) _adminContext = { page: 'store' };
            else if (path === 'category') _adminContext = { page: 'category', categoryId: paramParts[0] };
            else if (path === 'item') _adminContext = { page: 'item', itemId: paramParts[0] };
        }
        renderAdminEditToggle(isStorePage && APP.user?.role === 'admin');

        const routeFn = routes[fullPath] || routes[path];
        if (routeFn) {
            routeFn(paramParts.join('/'));
        } else {
            main.innerHTML = '<div class="empty-state"><div class="empty-state-icon">🔍</div><div class="empty-state-text">الصفحة غير موجودة</div></div>';
        }
    }

    function updateActiveNav(page) {
        $$('.nav-link, .mobile-nav-link, .bottom-bar-item').forEach(link => {
            const linkPage = link.dataset.page;
            link.classList.toggle('active', linkPage === (page || 'store'));
        });
    }

    // ==========================================
    // Back Peek — Edge Swipe Gesture
    // ==========================================
    (function initBackPeek() {
        const el = document.getElementById('backPeek');
        if (!el) return;
        let startX = 0, currentDelta = 0, isDragging = false;

        el.addEventListener('touchstart', (e) => {
            e.preventDefault();
            isDragging = true;
            startX = e.touches[0].clientX;
            currentDelta = 0;
            el.classList.add('dragging');
            el.classList.remove('triggered');
        }, { passive: false });

        document.addEventListener('touchmove', (e) => {
            if (!isDragging) return;
            const dx = startX - e.touches[0].clientX; // positive = dragged left
            currentDelta = Math.max(0, Math.min(dx, 80)); // clamp 0-80px
            el.style.transform = `translateY(-50%) translateX(-${currentDelta}px)`;
        });

        document.addEventListener('touchend', () => {
            if (!isDragging) return;
            isDragging = false;
            el.classList.remove('dragging');

            if (currentDelta >= 30) {
                // Trigger: animate out then go back
                el.style.transform = `translateY(-50%) translateX(-${currentDelta + 60}px) scale(0.5)`;
                el.classList.add('triggered');
                setTimeout(() => {
                    history.back();
                    // Reset after navigation
                    setTimeout(() => {
                        el.style.transform = 'translateY(-50%)';
                        el.classList.remove('triggered');
                    }, 100);
                }, 250);
            } else {
                // Snap back to peek position
                el.style.transform = 'translateY(-50%)';
            }
            currentDelta = 0;
        });

        // Also support simple click/tap (for non-drag interactions)
        el.addEventListener('click', () => {
            if (currentDelta > 5) return; // was dragging, ignore click
            el.style.transform = `translateY(-50%) translateX(-60px) scale(0.5)`;
            el.classList.add('triggered');
            setTimeout(() => {
                history.back();
                setTimeout(() => {
                    el.style.transform = 'translateY(-50%)';
                    el.classList.remove('triggered');
                }, 100);
            }, 250);
        });
    })();

    // ==========================================
    // Login / Register Page
    // ==========================================
    function renderLogin() {
        $('#navbar').style.display = 'none';
        const bottomBar = $('#bottomBar');
        if (bottomBar) bottomBar.style.display = 'none';

        // Parse return URL from hash (e.g. #/login?return=/item/5)
        const fullHash = location.hash.slice(2) || '';
        const qIdx = fullHash.indexOf('?');
        let returnUrl = '';
        if (qIdx !== -1) {
            const params = new URLSearchParams(fullHash.slice(qIdx));
            returnUrl = params.get('return') || '';
        }

        main.innerHTML = `
        <div class="auth-container">
            <div class="auth-card">
                <h1>🏪 ${esc(APP.siteName)}</h1>
                <p class="subtitle">مرحباً بك! سجّل دخولك أو أنشئ حساباً جديداً</p>
                <div class="auth-tabs">
                    <button class="auth-tab active" data-tab="login">تسجيل الدخول</button>
                    <button class="auth-tab" data-tab="register">حساب جديد</button>
                </div>
                <div class="auth-error" id="authError"></div>

                <form class="auth-form active" id="loginForm">
                    <div class="form-group">
                        <label class="form-label">اسم المستخدم أو البريد الإلكتروني</label>
                        <input type="text" class="form-input" name="username" required autocomplete="username">
                    </div>
                    <div class="form-group">
                        <label class="form-label">كلمة المرور</label>
                        <input type="password" class="form-input" name="password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">تسجيل الدخول</button>
                </form>

                <form class="auth-form" id="registerForm">
                    <div style="display:flex;gap:0.75rem;">
                        <div class="form-group" style="flex:1;">
                            <label class="form-label">الاسم الأول</label>
                            <input type="text" class="form-input" name="first_name" autocomplete="given-name">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label class="form-label">الاسم الأخير</label>
                            <input type="text" class="form-input" name="last_name" autocomplete="family-name">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">اسم المستخدم</label>
                        <input type="text" class="form-input" name="username" required minlength="3" autocomplete="username">
                    </div>
                    <div class="form-group">
                        <label class="form-label">البريد الإلكتروني</label>
                        <input type="email" class="form-input" name="email" required autocomplete="email">
                    </div>
                    <div class="form-group">
                        <label class="form-label">كلمة المرور</label>
                        <input type="password" class="form-input" name="password" required minlength="6" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">إنشاء حساب</button>
                </form>
                <div style="text-align:center;margin-top:1.25rem;">
                    <a href="#/" style="font-size:0.9rem;color:var(--text-secondary);">← تصفّح المتجر بدون حساب</a>
                </div>
            </div>
        </div>`;

        // Tab switching
        $$('.auth-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                $$('.auth-tab').forEach(t => t.classList.remove('active'));
                $$('.auth-form').forEach(f => f.classList.remove('active'));
                tab.classList.add('active');
                $(`#${tab.dataset.tab}Form`).classList.add('active');
                $('#authError').classList.remove('show');
            });
        });

        // Login
        $('#loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            try {
                const res = await api('/api/auth.php?action=login', {
                    method: 'POST',
                    body: { username: fd.get('username'), password: fd.get('password') },
                });
                if (res.requires_2fa) {
                    // Show 2FA input
                    e.target.innerHTML = `
                        <div class="form-group">
                            <label class="form-label">🔐 رمز التحقق بخطوتين</label>
                            <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:0.75rem;">أدخل الرمز من تطبيق المصادقة</p>
                            <input type="text" class="form-input" name="totp_code" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" style="text-align:center;font-size:1.5rem;letter-spacing:0.5rem;font-weight:700;">
                        </div>
                        <div class="form-group" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="checkbox" id="remember2fa" name="remember" style="width:auto;">
                            <label for="remember2fa" class="form-label" style="margin:0;font-size:0.85rem;">تذكّر هذا الجهاز (30 يوم)</label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">تأكيد</button>
                    `;
                    // Rebind for 2FA submission
                    e.target.addEventListener('submit', async (e2) => {
                        e2.preventDefault();
                        const fd2 = new FormData(e2.target);
                        try {
                            const res2 = await api('/api/auth.php?action=verify_2fa', {
                                method: 'POST',
                                body: { code: fd2.get('totp_code'), remember: fd2.get('remember') === 'on' },
                            });
                            APP.user = res2.user;
                            showToast('مرحباً بك!');
                            location.hash = returnUrl ? '#' + returnUrl : '#/';
                            navigate();
                        } catch (err) {
                            showAuthError(err.message);
                        }
                    });
                    e.target.querySelector('input[name="totp_code"]').focus();
                    return;
                }
                APP.user = res.user;
                showToast('مرحباً بك!');
                if (returnUrl) {
                    location.hash = '#' + returnUrl;
                } else {
                    location.hash = '#/';
                }
                navigate();
            } catch (err) {
                showAuthError(err.message);
            }
        });

        // Register
        $('#registerForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            try {
                const res = await api('/api/auth.php?action=register', {
                    method: 'POST',
                    body: {
                        username: fd.get('username'),
                        email: fd.get('email'),
                        password: fd.get('password'),
                        first_name: fd.get('first_name') || '',
                        last_name: fd.get('last_name') || '',
                    },
                });
                APP.user = res.user;
                showToast('تم إنشاء الحساب بنجاح!');
                if (returnUrl) {
                    location.hash = '#' + returnUrl;
                } else {
                    location.hash = '#/';
                }
                navigate();
            } catch (err) {
                showAuthError(err.message);
            }
        });
    }

    function showAuthError(msg) {
        const el = $('#authError');
        el.textContent = msg;
        el.classList.add('show');
    }

    // ==========================================
    // Store Page — Shows categories grid with search
    // ==========================================
    async function renderStore() {
        main.innerHTML = '<div class="page-loading"><div class="spinner"></div></div>';
        try {
            const [catData, carouselData] = await Promise.all([
                api('/api/categories.php?action=list_with_items'),
                api('/api/carousel.php?action=list'),
            ]);

            let carouselHtml = '';
            if (carouselData.slides && carouselData.slides.length > 0) {
                carouselHtml = renderCarousel(carouselData.slides);
            }

            const isAdminUser = APP.user?.role === 'admin';
            const allCats = (catData.categories || []).filter(c => c.items.length > 0 || (c.subcategory_item_count || 0) > 0 || isAdminUser);

            main.innerHTML = `
                ${breadcrumb([{label: '🏠 المتجر'}])}
                ${carouselHtml}
                <div style="margin-bottom:1.25rem;">
                    <div style="position:relative;">
                        <input type="text" id="storeSearch" class="form-input" placeholder="🔍 ابحث في التصنيفات والمنتجات..." style="padding-right:2.75rem;font-size:1rem;">
                        <span id="searchClear" style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);cursor:pointer;font-size:1.1rem;color:var(--text-muted);display:none;">✕</span>
                    </div>
                </div>
                <div id="categoriesGrid"></div>
            `;

            initCarousel();

            function renderCatsGrid(cats, searchItems = []) {
                const grid = document.getElementById('categoriesGrid');
                const isAdmin = APP.user?.role === 'admin';

                let html = '';

                // Categories
                if (cats.length > 0) {
                    html += '<div class="products-grid">';
                    for (const cat of cats) {
                        const imgSrc = safeImg(cat.image, '/assets/img/placeholder.svg');
                        const adminIcons = isAdmin ? `<div class="admin-card-actions">
                            <button class="admin-card-btn edit-btn admin-edit-cat" data-id="${esc(cat.id)}" title="تعديل">✏️</button>
                            <button class="admin-card-btn del-btn admin-del-cat" data-id="${esc(cat.id)}" title="حذف">🗑</button>
                        </div>` : '';
                        html += `<a href="#/category/${encodeURIComponent(cat.id)}" class="card">
                            <div class="card-img-wrap">
                                ${adminIcons}
                                <img src="${esc(imgSrc)}" alt="${esc(cat.name)}" class="card-img" loading="lazy">
                            </div>
                            <div class="card-body">
                                <div class="card-title">${esc(cat.name)}</div>
                                ${cat.description ? `<div class="card-text">${esc(cat.description.substring(0, 60))}</div>` : ''}
                                <div style="font-size:0.8rem;color:var(--text-muted);margin-top:0.35rem;">${cat.items?.length || 0} منتج</div>
                            </div>
                        </a>`;
                    }
                    html += '</div>';
                }

                // Matching items (from search)
                if (searchItems.length > 0) {
                    html += '<div style="margin-top:1.5rem;"><div style="font-weight:600;font-size:1.1rem;margin-bottom:0.75rem;">📦 منتجات مطابقة</div>';
                    html += '<div class="products-grid">';
                    for (const item of searchItems) {
                        html += renderProductCard(item);
                    }
                    html += '</div></div>';
                }

                if (!html) {
                    html = '<div class="empty-state"><div class="empty-state-icon">🔍</div><div class="empty-state-text">لا توجد نتائج لبحثك</div></div>';
                }

                grid.innerHTML = html;
                if (isAdmin) bindAdminCardActions(grid, () => performSearch(searchInput.value));
            }

            renderCatsGrid(allCats);

            const searchInput = document.getElementById('storeSearch');
            const clearBtn = document.getElementById('searchClear');

            async function performSearch(query) {
                const q = query.trim();
                if (!q) {
                    renderCatsGrid(allCats);
                    return;
                }
                const grid = document.getElementById('categoriesGrid');
                grid.innerHTML = '<div class="page-loading"><div class="spinner"></div></div>';
                try {
                    const data = await api(`/api/categories.php?action=search&q=${encodeURIComponent(q)}`);
                    const cats = (data.categories || []).filter(c => c.items?.length > 0 || (c.subcategory_item_count || 0) > 0 || APP.user?.role === 'admin' || true);
                    renderCatsGrid(cats, data.items || []);
                } catch (err) {
                    grid.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
                }
            }

            // Show/hide clear button on input
            searchInput.addEventListener('input', () => {
                clearBtn.style.display = searchInput.value ? '' : 'none';
            });

            // Search on Enter
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    performSearch(searchInput.value);
                }
            });

            clearBtn.addEventListener('click', () => {
                searchInput.value = '';
                clearBtn.style.display = 'none';
                renderCatsGrid(allCats);
                searchInput.focus();
            });

            // Show alerts on store page
            showPendingAlerts();
        } catch (err) {
            main.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
        }
    }

    function renderProductCard(item) {
        const imgSrc = safeImg(item.image, '/assets/img/placeholder.svg');
        const desc = item.description ? item.description.substring(0, 80) : '';
        const isAuto = item.delivery_type === 'auto_stock' || item.delivery_type === 'api_json';
        const isAdmin = APP.user?.role === 'admin';
        const adminIcons = isAdmin ? `<div class="admin-card-actions">
            <button class="admin-card-btn edit-btn admin-edit-item" data-id="${esc(item.id)}" title="تعديل">✏️</button>
            <button class="admin-card-btn del-btn admin-del-item" data-id="${esc(item.id)}" title="حذف">🗑</button>
        </div>` : '';
        return `<a href="#/item/${encodeURIComponent(item.id)}" class="card">
            <div class="card-img-wrap">
                ${adminIcons}
                <img src="${esc(imgSrc)}" alt="${esc(item.name)}" class="card-img" loading="lazy">
                <span class="card-delivery-pill ${isAuto ? 'auto' : 'manual'}">${isAuto ? '⚡ تسليم فوري' : '⏳ دقائق لتجهيزه'}</span>
            </div>
            <div class="card-body">
                <div class="card-title">${esc(item.name)}</div>
                ${desc ? `<div class="card-text">${esc(desc)}</div>` : ''}
                ${item.contact_only
                ? '<span class="contact-only-badge">📞 للتواصل فقط</span>'
                : `<div class="card-price">${(item.pricing_type === 'options' || item.pricing_type === 'calculated') ? '<span class="price-prefix">ابتداءً من </span>' : ''}${formatPrice(item.price)}</div>`
            }
            </div>
        </a>`;
    }

    // ==========================================
    // Category Detail Page
    // ==========================================
    async function renderCategoryDetail(id) {
        main.innerHTML = '<div class="page-loading"><div class="spinner"></div></div>';
        try {
            const data = await api(`/api/categories.php?action=get&id=${encodeURIComponent(id)}`);
            const cat = data.category;
            const items = cat.items || [];
            const subcats = cat.subcategories || [];

            // Subcategory cards
            let subcatsHtml = '';
            if (subcats.length > 0) {
                subcatsHtml = '<div class="products-grid" style="margin-bottom:1.5rem;">';
                for (const sc of subcats) {
                    const scImg = safeImg(sc.image, '/assets/img/placeholder.svg');
                    subcatsHtml += `<a href="#/category/${encodeURIComponent(sc.id)}" class="card">
                        <div class="card-img-wrap">
                            <img src="${esc(scImg)}" alt="${esc(sc.name)}" class="card-img" loading="lazy">
                        </div>
                        <div class="card-body">
                            <div class="card-title">${esc(sc.name)}</div>
                            ${sc.description ? `<div class="card-text">${esc(sc.description.substring(0, 60))}</div>` : ''}
                        </div>
                    </a>`;
                }
                subcatsHtml += '</div>';
            }

            // Items grid
            let itemsHtml = '';
            if (items.length > 0) {
                itemsHtml = '<div class="products-grid">';
                for (const item of items) {
                    itemsHtml += renderProductCard(item);
                }
                itemsHtml += '</div>';
            } else if (subcats.length === 0) {
                itemsHtml = '<div class="empty-state"><div class="empty-state-icon">📦</div><div class="empty-state-text">لا توجد منتجات في هذا التصنيف</div></div>';
            }

            // Breadcrumb: if this is a child category, show parent
            const crumbs = [{label: '🏠 المتجر', href: '#/'}];
            if (cat.parent_id_resolved) {
                crumbs.push({label: cat.parent_name, href: `#/category/${cat.parent_id_resolved}`});
            }
            crumbs.push({label: cat.name});

            main.innerHTML = `
            <div>
                ${breadcrumb(crumbs)}
                ${safeImg(cat.image) ? `<div style="margin-bottom:1.25rem;border-radius:var(--radius-lg);overflow:hidden;"><img src="${esc(safeImg(cat.image))}" alt="${esc(cat.name)}" style="width:100%;max-height:250px;object-fit:cover;"></div>` : ''}
                <div style="margin-bottom:1.25rem;">
                    <h1 style="font-size:1.5rem;font-weight:700;margin-bottom:0.35rem;">${esc(cat.name)}</h1>
                    ${cat.description ? `<p style="color:var(--text-secondary);">${esc(cat.description)}</p>` : ''}
                    <div style="font-size:0.85rem;color:var(--text-muted);">${items.length} منتج${subcats.length > 0 ? ` · ${subcats.length} تصنيف فرعي` : ''}</div>
                </div>
                ${subcatsHtml}
                ${itemsHtml}
            </div>`;

            // Bind admin inline actions on item cards
            if (APP.user?.role === 'admin') {
                bindAdminCardActions(main, () => renderCategoryDetail(id));
            }

            // Show alerts on category page
            showPendingAlerts();
        } catch (err) {
            main.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
        }
    }

    function renderCarousel(slides) {
        let dotsHtml = slides.map((_, i) => `<div class="carousel-dot${i === 0 ? ' active' : ''}" data-index="${i}"></div>`).join('');
        let slidesHtml = slides.map(s => `
            <div class="carousel-slide">
                ${s.link ? `<a href="${esc(safeUrl(s.link))}" rel="noopener noreferrer">` : ''}
                <img src="${esc(safeImg(s.image))}" alt="${esc(s.title || '')}" loading="lazy">
                ${s.title ? `<div class="slide-title">${esc(s.title)}</div>` : ''}
                ${s.link ? '</a>' : ''}
            </div>`).join('');
        return `<div class="hero-carousel" id="heroCarousel">
            <div class="carousel-track" id="carouselTrack">${slidesHtml}</div>
            <div class="carousel-dots">${dotsHtml}</div>
        </div>`;
    }

    function initCarousel() {
        const carousel = $('#heroCarousel');
        if (!carousel) return;
        const track = $('#carouselTrack');
        const dots = $$('.carousel-dot');
        let current = 0;
        const count = dots.length;
        if (count <= 1) return;

        function goTo(index) {
            current = ((index % count) + count) % count;
            track.style.transform = `translateX(${current * 100}%)`;
            dots.forEach((d, i) => d.classList.toggle('active', i === current));
        }

        dots.forEach(d => d.addEventListener('click', () => goTo(parseInt(d.dataset.index))));
        const carouselTimer = setInterval(() => goTo(current + 1), 5000);
        window.addEventListener('hashchange', () => clearInterval(carouselTimer), { once: true });

        // Touch swipe
        let startX = 0;
        carousel.addEventListener('touchstart', e => startX = e.touches[0].clientX, { passive: true });
        carousel.addEventListener('touchend', e => {
            const diff = startX - e.changedTouches[0].clientX;
            if (Math.abs(diff) > 50) goTo(current + (diff > 0 ? 1 : -1));
        }, { passive: true });
    }

    // ==========================================
    // Item Detail Page
    // ==========================================
    async function renderItemDetail(id) {
        main.innerHTML = '<div class="page-loading"><div class="spinner"></div></div>';
        try {
            // Fetch item data (+ fresh user balance if logged in)
            const data = await api(`/api/items.php?action=get&id=${encodeURIComponent(id)}`);
            const item = data.item;
            const fields = data.fields || [];
            const imgSrc = safeImg(item.image, '/assets/img/placeholder.svg');

            let fieldsHtml = '';
            if (fields.length > 0 && !item.contact_only) {
                fieldsHtml = '<div class="section-title" style="margin-top:1.5rem;font-weight:600;margin-bottom:0.75rem;">📝 معلومات مطلوبة</div>';
                for (const f of fields) {
                    const req = f.is_required ? '<span class="required">*</span>' : '<span class="optional">(اختياري)</span>';
                    if (f.type === 'image') {
                        fieldsHtml += `<div class="form-group"><label class="form-label">${esc(f.label)} ${req}</label><input type="file" class="form-input" name="field_${f.id}" accept="image/*" ${f.is_required ? 'required' : ''}></div>`;
                    } else if (f.type === 'textarea') {
                        fieldsHtml += `<div class="form-group"><label class="form-label">${esc(f.label)} ${req}</label><textarea class="form-textarea" name="field_${f.id}" ${f.is_required ? 'required' : ''}></textarea></div>`;
                    } else {
                        const inputType = f.type === 'email' ? 'email' : f.type === 'phone' ? 'tel' : 'text';
                        const hasAddon = (f.prefix || f.suffix);
                        if (hasAddon) {
                            fieldsHtml += `<div class="form-group"><label class="form-label">${esc(f.label)} ${req}</label><div class="input-group">${f.prefix ? `<span class="input-addon">${esc(f.prefix)}</span>` : ''}<input type="${inputType}" class="form-input" name="field_${f.id}" ${f.is_required ? 'required' : ''}>${f.suffix ? `<span class="input-addon">${esc(f.suffix)}</span>` : ''}</div></div>`;
                        } else {
                            fieldsHtml += `<div class="form-group"><label class="form-label">${esc(f.label)} ${req}</label><input type="${inputType}" class="form-input" name="field_${f.id}" ${f.is_required ? 'required' : ''}></div>`;
                        }
                    }
                }
            }

            // v6: Price options or calculated pricing UI
            let priceOptionsHtml = '';
            const pricingType = item.pricing_type || 'fixed';
            const priceOptions = data.price_options || [];

            if (pricingType === 'options' && priceOptions.length > 0 && !item.contact_only) {
                priceOptionsHtml = '<div class="section-title" style="margin-top:1.5rem;font-weight:600;margin-bottom:0.75rem;">💲 اختر الخيار</div>';
                priceOptionsHtml += '<div class="price-options-list">';
                for (let i = 0; i < priceOptions.length; i++) {
                    const po = priceOptions[i];
                    priceOptionsHtml += `<label class="price-option-card" style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem;border:1.5px solid var(--border);border-radius:var(--radius);cursor:pointer;margin-bottom:0.5rem;transition:border-color 0.2s;">
                        <input type="radio" name="price_option_id" value="${esc(po.id)}" ${i === 0 ? 'checked' : ''} style="width:18px;height:18px;accent-color:var(--primary);">
                        <span style="flex:1;font-weight:500;">${esc(po.label)}</span>
                        <span style="font-weight:700;color:var(--primary);">${formatPrice(po.price)}</span>
                    </label>`;
                }
                priceOptionsHtml += '</div>';
            }

            let calcPreviewHtml = '';
            if (pricingType === 'calculated' && !item.contact_only) {
                calcPreviewHtml = '<div id="calcPricePreview" style="margin-top:0.75rem;padding:0.75rem;background:var(--primary-light);border-radius:var(--radius);font-weight:600;color:var(--primary);display:none;">💰 السعر: —</div>';
            }

            let actionHtml = '';
            if (item.contact_only) {
                const phone = data.contact_phone || '';
                actionHtml = `<div style="margin-top:1.5rem;">
                    <div class="contact-only-badge" style="font-size:1rem;padding:0.75rem 1.25rem;">📞 للطلب تواصل معنا: <a href="${esc(safeUrl('tel:' + telDigits(phone)))}" style="color:inherit;font-weight:700;margin-right:0.5rem;">${esc(phone)}</a></div>
                </div>`;
            } else if (!APP.user) {
                // Guest — show login to order button
                const displayPrice = pricingType === 'options' && priceOptions.length > 0 ? priceOptions[0].price : item.price;
                actionHtml = `<div style="margin-top:1.5rem;">
                    ${priceOptionsHtml}
                    <button class="btn btn-primary btn-block" id="guestOrderBtn">🔑 سجّل دخولك لإتمام الطلب — ${formatPrice(displayPrice)}</button>
                </div>`;
            } else {
                const basePrice = pricingType === 'options' && priceOptions.length > 0 ? priceOptions[0].price : item.price;
                const canAfford = parseFloat(APP.user.wallet_balance) >= parseFloat(basePrice);
                // Check stock for auto_stock
                const isAutoStock = item.delivery_type === 'auto_stock';
                const outOfStock = isAutoStock && data.stock_count <= 0;
                const stockInfo = isAutoStock ? `<div style="font-size:0.9rem;margin-bottom:0.5rem;color:${data.stock_count > 0 ? 'var(--success)' : 'var(--danger)'}">${data.stock_count > 0 ? `✅ متوفر: ${esc(data.stock_count)}` : '❌ نفذ المخزون'}</div>` : '';
                actionHtml = `<div style="margin-top:1.5rem;">
                    ${stockInfo}
                    ${!canAfford ? '<div class="alert alert-warning">⚠️ رصيدك غير كافٍ. <a href="#/wallet">اشحن محفظتك</a></div>' : ''}
                    ${outOfStock ? '<div class="alert alert-warning">⚠️ المنتج غير متوفر حالياً</div>' : ''}
                    ${priceOptionsHtml}
                    ${fieldsHtml}
                    ${calcPreviewHtml}
                    <button class="btn btn-primary btn-block" id="orderBtn" ${(!canAfford || outOfStock) ? 'disabled' : ''}>🛒 اطلب الآن — ${formatPrice(basePrice)}</button>
                </div>`;
            }

            main.innerHTML = `
            <div style="max-width:700px;margin:0 auto;">
                ${(() => {
                    const crumbs = [{label: '🏠 المتجر', href: '#/'}];
                    if (data.category_parent_id) {
                        crumbs.push({label: data.category_parent_name, href: `#/category/${data.category_parent_id}`});
                    }
                    crumbs.push({label: data.category_name || 'التصنيف', href: `#/category/${item.category_id}`});
                    crumbs.push({label: item.name});
                    return breadcrumb(crumbs);
                })()}
                <div class="card card-no-hover">
                    <img src="${esc(imgSrc)}" alt="${esc(item.name)}" style="width:100%;max-height:400px;object-fit:cover;">
                    <div class="card-body" style="padding:1.5rem;">
                        <h1 style="font-size:1.5rem;font-weight:700;margin-bottom:0.5rem;">${esc(item.name)}</h1>
                        ${item.description ? `<p style="color:var(--text-secondary);margin-bottom:1rem;">${esc(item.description)}</p>` : ''}
                        ${!item.contact_only ? `<div style="font-size:1.5rem;font-weight:700;color:var(--primary);">${pricingType !== 'fixed' ? '<span style="font-size:0.85rem;font-weight:400;color:var(--text-muted);">ابتداءً من </span>' : ''}${formatPrice(item.price)}</div>` : ''}
                        ${item.cancel_window_seconds > 0 && !item.contact_only ? `<div style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;">🛡️ فترة حماية ${esc(item.cancel_window_seconds)} ثانية قبل إمكانية الإلغاء</div>` : ''}
                        ${actionHtml}
                    </div>
                </div>
            </div>`;

            // Order button handler
            const orderBtn = $('#orderBtn');
            if (orderBtn) {
                orderBtn.addEventListener('click', async () => {
                    orderBtn.disabled = true;
                    orderBtn.textContent = 'جارٍ الطلب...';
                    try {
                        const formData = new FormData();
                        formData.append('item_id', item.id);
                        // v6: Send price_option_id for options pricing
                        if (pricingType === 'options') {
                            const selected = document.querySelector('input[name="price_option_id"]:checked');
                            if (selected) formData.append('price_option_id', selected.value);
                        }
                        // Collect custom fields
                        for (const f of fields) {
                            const input = $(`[name="field_${f.id}"]`);
                            if (input) {
                                if (f.type === 'image' && input.files[0]) {
                                    formData.append(`field_${f.id}`, await compressImage(input.files[0]));
                                } else if (input.value) {
                                    formData.append(`field_${f.id}`, input.value);
                                }
                            }
                        }
                        const res = await fetch('/api/orders.php?action=create', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': APP.csrf },
                            body: formData,
                        });
                        const data = await res.json();
                        if (!data.success) throw new Error(data.error);
                        APP.user.wallet_balance = data.new_balance;
                        updateAuthUI();
                        showToast(data.auto_delivered ? 'تم تسليم المنتج تلقائياً! ✅' : 'تم إنشاء الطلب بنجاح!');
                        location.hash = `#/order/${data.order_id}`;
                    } catch (err) {
                        showToast(err.message, 'error');
                        orderBtn.disabled = false;
                        orderBtn.textContent = `🛒 اطلب الآن — ${formatPriceText(item.price)}`;
                    }
                });
            }

            // v6: Price option selection updates order button price
            if (pricingType === 'options') {
                document.querySelectorAll('input[name="price_option_id"]').forEach(radio => {
                    radio.addEventListener('change', () => {
                        const selected = priceOptions.find(po => po.id == radio.value);
                        if (selected && orderBtn) {
                            orderBtn.textContent = `🛒 اطلب الآن — ${formatPriceText(selected.price)}`;
                        }
                    });
                });
            }

            // v6: Calculated pricing live preview
            if (pricingType === 'calculated' && item.calc_field_id) {
                const calcInput = $(`[name="field_${item.calc_field_id}"]`);
                const previewEl = document.getElementById('calcPricePreview');
                if (calcInput && previewEl) {
                    calcInput.addEventListener('input', () => {
                        const val = parseFloat(calcInput.value);
                        if (isNaN(val) || val <= 0) { previewEl.style.display = 'none'; return; }
                        let computed = 0;
                        const op = item.calc_operator || '*';
                        const cv = parseFloat(item.calc_value) || 0;
                        switch (op) {
                            case '+': computed = val + cv; break;
                            case '-': computed = val - cv; break;
                            case '*': computed = val * cv; break;
                            case '/': computed = cv !== 0 ? val / cv : 0; break;
                        }
                        if (computed > 0) {
                            previewEl.style.display = '';
                            previewEl.textContent = `💰 السعر: ${formatPriceText(computed)}`;
                            if (orderBtn) orderBtn.textContent = `🛒 اطلب الآن — ${formatPriceText(computed)}`;
                        } else {
                            previewEl.style.display = 'none';
                        }
                    });
                }
            }

            // Guest order button — redirect to login with return URL
            const guestOrderBtn = $('#guestOrderBtn');
            if (guestOrderBtn) {
                guestOrderBtn.addEventListener('click', () => {
                    location.hash = `#/login?return=/item/${id}`;
                });
            }
        } catch (err) {
            main.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
        }
    }

    // ==========================================
    // Orders Page
    // ==========================================
    async function renderOrders() {
        main.innerHTML = '<div class="page-loading"><div class="spinner"></div></div>';
        const perPage = 10;
        let offset = 0;
        let container;

        async function loadOrders() {
            const data = await api(`/api/orders.php?action=my_orders&limit=${perPage}&offset=${offset}`);
            const orders = data.orders || [];
            if (offset === 0 && orders.length === 0) {
                main.innerHTML = '<div class="empty-state"><div class="empty-state-icon">📦</div><div class="empty-state-text">لا توجد طلبات بعد</div></div>';
                return;
            }
            if (offset === 0) {
                main.innerHTML = `${breadcrumb([{label: '🏠 المتجر', href: '#/'}, {label: 'طلباتي'}])}<h1 style="font-size:1.35rem;font-weight:700;margin-bottom:1rem;">📦 طلباتي</h1><div id="ordersContainer"></div><div id="ordersLoadMore"></div>`;
                container = $('#ordersContainer');
            }
            let html = '';
            for (const order of orders) {
                html += `<a href="#/order/${encodeURIComponent(order.id)}" class="order-card">
                    <div class="order-info">
                        <span class="order-id">#${esc(order.id)}</span>
                        <span class="order-item-name">${esc(order.item_name)}</span>
                        <div class="order-meta">${esc(order.created_at)}</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <span class="order-price">$${parseFloat(order.total_price).toFixed(2)}</span>
                        <span class="badge badge-${statusClass(order.status)}">${esc(statusLabel(order.status))}</span>
                    </div>
                </a>`;
            }
            container.insertAdjacentHTML('beforeend', html);
            offset += orders.length;

            const loadMoreDiv = $('#ordersLoadMore');
            if (data.has_more) {
                loadMoreDiv.innerHTML = '<button class="btn btn-outline btn-block" id="loadMoreOrdersBtn" style="margin-top:1rem;">⬇️ تحميل المزيد</button>';
                $('#loadMoreOrdersBtn').addEventListener('click', async () => {
                    const btn = $('#loadMoreOrdersBtn');
                    btn.disabled = true;
                    btn.textContent = '...';
                    try { await loadOrders(); } catch (e) { showToast(e.message, 'error'); btn.disabled = false; btn.textContent = '⬇️ تحميل المزيد'; }
                });
            } else {
                loadMoreDiv.innerHTML = '';
            }
        }

        try { await loadOrders(); } catch (err) {
            main.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
        }
    }

    // ==========================================
    // Order Detail Page
    // ==========================================
    async function renderOrderDetail(id) {
        main.innerHTML = '<div class="page-loading"><div class="spinner"></div></div>';
        try {
            const data = await api(`/api/orders.php?action=get&id=${encodeURIComponent(id)}`);
            const order = data.order;
            const deliveries = data.deliveries || [];
            const fieldValues = data.field_values || [];
            const report = data.report;
            const reportWindowSecs = data.report_window_seconds || 180;

            let cancelHtml = '';
            if (order.status === 'pending' && order.cancel_deadline) {
                const deadline = new Date(order.cancel_deadline + 'Z').getTime();
                const now = Date.now();
                if (deadline > now) {
                    // Cooldown active — show countdown, button disabled
                    cancelHtml = `<div style="margin-top:1rem;display:flex;align-items:center;gap:0.75rem;">
                        <button class="btn btn-danger" id="cancelOrderBtn" disabled>❌ إلغاء الطلب</button>
                        <span class="countdown" id="cancelCountdown">🛡️ فترة الحماية...</span>
                    </div>`;
                } else {
                    // Cooldown expired — cancel allowed
                    cancelHtml = `<div style="margin-top:1rem;">
                        <button class="btn btn-danger" id="cancelOrderBtn">❌ إلغاء الطلب</button>
                    </div>`;
                }
            } else if (order.status === 'pending' && !order.cancel_deadline) {
                // No cooldown — cancel always allowed
                cancelHtml = `<div style="margin-top:1rem;">
                    <button class="btn btn-danger" id="cancelOrderBtn">❌ إلغاء الطلب</button>
                </div>`;
            }

            let deliveryHtml = '';
            if (deliveries.length > 0) {
                deliveryHtml = '<div class="delivery-content"><h3 style="font-weight:600;margin-bottom:0.75rem;">📬 محتوى التسليم</h3>';
                for (const d of deliveries) {
                    deliveryHtml += '<div class="delivery-item">';
                    if (d.type === 'text') {
                        deliveryHtml += `<div style="white-space:pre-wrap;word-break:break-word;">${esc(d.content)}</div>`;
                    } else if (d.type === 'image') {
                        deliveryHtml += `<img src="${esc(safeImg(d.content))}" alt="صورة التسليم">`;
                    } else if (d.type === 'video') {
                        deliveryHtml += `<video controls src="${esc(safeImg(d.content))}"></video>`;
                    } else if (d.type === 'audio') {
                        deliveryHtml += `<audio controls src="${esc(safeImg(d.content))}"></audio>`;
                    } else if (d.type === 'document') {
                        deliveryHtml += `<a href="${esc(safeImg(d.content, '#'))}" class="doc-link" download>📄 ${esc(d.file_name || 'مستند')}</a>`;
                    }
                    deliveryHtml += '</div>';
                }
                deliveryHtml += '</div>';
            }

            let fieldValuesHtml = '';
            if (fieldValues.length > 0) {
                fieldValuesHtml = '<div style="margin-top:1rem;"><h3 style="font-weight:600;margin-bottom:0.5rem;font-size:0.95rem;">📝 المعلومات المُدخلة</h3>';
                for (const fv of fieldValues) {
                    if (fv.field_type === 'image') {
                        fieldValuesHtml += `<div style="margin-bottom:0.5rem;"><strong>${esc(fv.label)}:</strong><br><img src="${esc(safeImg(fv.value))}" style="max-width:200px;border-radius:8px;margin-top:0.25rem;"></div>`;
                    } else {
                        fieldValuesHtml += `<div style="margin-bottom:0.35rem;"><strong>${esc(fv.label)}:</strong> ${esc(fv.value)}</div>`;
                    }
                }
                fieldValuesHtml += '</div>';
            }

            main.innerHTML = `
            <div style="max-width:700px;margin:0 auto;">
                ${breadcrumb([{label: '🏠 المتجر', href: '#/'}, {label: 'طلباتي', href: '#/orders'}, {label: `طلب #${order.id}`}])}
                <div class="card card-no-hover">
                    <div class="card-body" style="padding:1.5rem;">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
                            <h1 style="font-size:1.3rem;font-weight:700;">طلب #${esc(order.id)}</h1>
                            <span class="badge badge-${statusClass(order.status)}" style="font-size:0.85rem;padding:0.35rem 0.85rem;">${esc(statusLabel(order.status))}</span>
                        </div>
                        <div style="margin-top:1rem;font-size:0.95rem;color:var(--text-secondary);">
                            <div>🛒 المنتج: <strong>${esc(order.item_name)}</strong></div>
                            ${order.price_option_label ? `<div>📋 الخيار: <strong>${esc(order.price_option_label)}</strong></div>` : ''}
                            <div>💰 السعر: <strong>${formatPrice(order.total_price)}</strong></div>
                            <div>📅 التاريخ: ${esc(order.created_at)}</div>
                            ${order.cancel_reason ? `<div style="color:var(--danger);">❌ سبب الإلغاء: ${esc(order.cancel_reason)}</div>` : ''}
                        </div>
                        ${fieldValuesHtml}
                        ${data.queue_position > 0 ? `<div class="alert alert-info" style="margin-top:1rem;" id="queuePositionAlert">
                            <div style="font-weight:600;">⏳ طلبك في الطابور — الموقع: <span id="queuePosNum">#${esc(data.queue_position)}</span></div>
                            <div style="font-size:0.8rem;margin-top:0.25rem;">سيتم تنفيذ طلبك تلقائياً عند وصول دورك</div>
                        </div>` : ''}
                        ${cancelHtml}
                        ${order.status === 'delivering' ? '<div class="alert alert-info" style="margin-top:1rem;">⏳ المشرف يُجهز محتوى التسليم...</div>' : ''}
                        ${deliveryHtml}
                        <div id="reportSection"></div>
                    </div>
                </div>
            </div>`;

            // Cancel cooldown countdown
            if (order.status === 'pending') {
                const cancelBtn = $('#cancelOrderBtn');
                const countdownEl = $('#cancelCountdown');

                if (order.cancel_deadline) {
                    const deadline = new Date(order.cancel_deadline + 'Z').getTime();

                    if (countdownEl && deadline > Date.now()) {
                        // Cooldown active — countdown until cancel becomes available
                        const timer = setInterval(() => {
                            const remaining = Math.max(0, deadline - Date.now());
                            if (remaining === 0) {
                                clearInterval(timer);
                                if (cancelBtn) cancelBtn.disabled = false;
                                if (countdownEl) countdownEl.textContent = '✅ يمكنك الآن إلغاء الطلب';
                                return;
                            }
                            const secs = Math.ceil(remaining / 1000);
                            countdownEl.textContent = `🛡️ فترة حماية — ${secs} ثانية متبقية`;
                        }, 1000);
                    }
                }

                if (cancelBtn) {
                    cancelBtn.addEventListener('click', async () => {
                        if (!confirm('هل أنت متأكد من إلغاء الطلب؟')) return;
                        cancelBtn.disabled = true;
                        try {
                            const res = await api('/api/orders.php?action=cancel', {
                                method: 'POST',
                                body: { order_id: order.id },
                            });
                            APP.user.wallet_balance = res.new_balance;
                            updateAuthUI();
                            showToast('تم إلغاء الطلب وإرجاع المبلغ');
                            renderOrderDetail(id);
                        } catch (err) {
                            showToast(err.message, 'error');
                            cancelBtn.disabled = false;
                        }
                    });
                }
            }
            // Auto-poll for status changes when order is pending or delivering
            let _orderPollTimer;
            let _lastQueuePos = data.queue_position || 0;
            // Disable cancel immediately if already at position #1 (processing)
            if (_lastQueuePos >= 1 && _lastQueuePos <= 1) {
                const cancelBtn = $('#cancelOrderBtn');
                if (cancelBtn) {
                    cancelBtn.disabled = true;
                    cancelBtn.title = 'الطلب قيد المعالجة';
                    cancelBtn.textContent = '⏳ جارٍ المعالجة...';
                }
            }
            if (['pending', 'delivering', 'accepted'].includes(order.status)) {
                _orderPollTimer = setInterval(async () => {
                    try {
                        const fresh = await api(`/api/orders.php?action=check_status&id=${encodeURIComponent(id)}`);
                        if (fresh.status !== order.status || fresh.delivery_count !== deliveries.length) {
                            clearInterval(_orderPollTimer);
                            renderOrderDetail(id);
                        } else if (fresh.queue_position !== _lastQueuePos) {
                            // Update queue position display without full re-render
                            _lastQueuePos = fresh.queue_position;
                            const posEl = $('#queuePosNum');
                            if (posEl) posEl.textContent = `#${fresh.queue_position}`;
                            const alertEl = $('#queuePositionAlert');
                            if (alertEl && fresh.queue_position <= 0) alertEl.style.display = 'none';
                            // Disable cancel when position is #1 (processing)
                            if (fresh.queue_position <= 1) {
                                const cancelBtn = $('#cancelOrderBtn');
                                if (cancelBtn) {
                                    cancelBtn.disabled = true;
                                    cancelBtn.title = 'الطلب قيد المعالجة';
                                    cancelBtn.textContent = '⏳ جارٍ المعالجة...';
                                }
                            }
                        }
                    } catch (e) { /* ignore poll errors */ }
                }, 10000);
                // Clean up on navigation
                window.addEventListener('hashchange', () => clearInterval(_orderPollTimer), { once: true });
            }

            // === Report Section ===
            const reportSection = $('#reportSection');
            if (order.status === 'delivered' && reportSection) {
                const deliveredAt = order.delivered_at || order.resolved_at;
                const deliveredTime = deliveredAt ? new Date(deliveredAt + 'Z').getTime() : 0;
                const reportDeadline = deliveredTime + (reportWindowSecs * 1000);
                const now = Date.now();

                if (report && ['pending', 'alternative_sent'].includes(report.status)) {
                    // Build messages thread
                    const messages = data.report_messages || [];
                    let chatHtml = '<div class="report-chat" id="reportChat">';

                    // Initial report as first bubble
                    chatHtml += `<div class="chat-bubble user">
                        <div class="chat-bubble-text">${esc(report.details)}</div>
                        ${report.screenshot ? `<img src="${esc(safeImg(report.screenshot))}" class="chat-bubble-img" alt="لقطة شاشة">` : ''}
                        <div class="chat-bubble-time">${esc(report.created_at)}</div>
                    </div>`;

                    // Follow-up messages
                    for (const m of messages) {
                        chatHtml += `<div class="chat-bubble ${m.sender === 'admin' ? 'admin' : 'user'}">
                            ${m.message ? `<div class="chat-bubble-text">${esc(m.message)}</div>` : ''}
                            ${m.image ? `<img src="${esc(safeImg(m.image))}" class="chat-bubble-img" alt="صورة">` : ''}
                            <div class="chat-bubble-time">${esc(m.created_at)}</div>
                        </div>`;
                    }
                    chatHtml += '</div>';

                    let resolveBtn = '';
                    if (report.status === 'alternative_sent') {
                        resolveBtn = `<div class="alert alert-info" style="margin-bottom:0.75rem;">📨 تم إرسال بديل — تحقق من محتوى التسليم أعلاه</div>
                            <button class="btn btn-primary btn-block" id="resolveReportBtn" style="margin-bottom:0.75rem;">✅ تأكيد الحل</button>`;
                    }

                    reportSection.innerHTML = `<div style="margin-top:1.5rem;border-top:1px solid var(--border);padding-top:1rem;">
                        <h3 style="font-weight:600;font-size:0.95rem;margin-bottom:0.75rem;">💬 محادثة البلاغ</h3>
                        ${resolveBtn}
                        ${chatHtml}
                        <form id="reportMsgForm" class="report-msg-form" style="margin-top:0.75rem;">
                            <div style="display:flex;gap:0.5rem;align-items:flex-end;">
                                <div style="flex:1;">
                                    <textarea class="form-textarea" id="reportMsgText" rows="2" placeholder="اكتب رسالة..." style="min-height:44px;resize:none;"></textarea>
                                </div>
                                <div style="display:flex;gap:0.35rem;">
                                    <label class="btn btn-outline btn-icon" style="cursor:pointer;" title="إرفاق صورة">
                                        🖼️
                                        <input type="file" id="reportMsgImage" accept="image/*" style="display:none;">
                                    </label>
                                    <button type="submit" class="btn btn-primary btn-icon" id="reportMsgSendBtn" title="إرسال">➤</button>
                                </div>
                            </div>
                            <div id="reportMsgImagePreview" style="display:none;margin-top:0.5rem;font-size:0.8rem;color:var(--text-muted);"></div>
                        </form>
                    </div>`;

                    // Scroll chat to bottom
                    const chatEl = $('#reportChat');
                    if (chatEl) chatEl.scrollTop = chatEl.scrollHeight;

                    // Image preview
                    const imgInput = $('#reportMsgImage');
                    imgInput?.addEventListener('change', () => {
                        const preview = $('#reportMsgImagePreview');
                        if (imgInput.files[0]) {
                            preview.style.display = '';
                            preview.textContent = `📎 ${imgInput.files[0].name}`;
                        } else {
                            preview.style.display = 'none';
                        }
                    });

                    // Send message
                    $('#reportMsgForm')?.addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const msgText = $('#reportMsgText').value.trim();
                        const imgFile = $('#reportMsgImage').files[0];
                        if (!msgText && !imgFile) { showToast('اكتب رسالة أو أرفق صورة', 'error'); return; }
                        const btn = $('#reportMsgSendBtn');
                        btn.disabled = true;
                        try {
                            const fd = new FormData();
                            fd.append('report_id', report.id);
                            fd.append('csrf_token', APP.csrf);
                            if (msgText) fd.append('message', msgText);
                            if (imgFile) fd.append('image', await compressImage(imgFile));
                            const res = await fetch('/api/orders.php?action=report_send_message', {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': APP.csrf },
                                body: fd,
                            });
                            const d = await res.json();
                            if (!d.success) throw new Error(d.error);
                            // Append the new message to chat
                            const m = d.message;
                            const bubbleHtml = `<div class="chat-bubble user">
                                ${m.message ? `<div class="chat-bubble-text">${esc(m.message)}</div>` : ''}
                                ${m.image ? `<img src="${esc(safeImg(m.image))}" class="chat-bubble-img" alt="صورة">` : ''}
                                <div class="chat-bubble-time">${esc(m.created_at)}</div>
                            </div>`;
                            chatEl.insertAdjacentHTML('beforeend', bubbleHtml);
                            chatEl.scrollTop = chatEl.scrollHeight;
                            $('#reportMsgText').value = '';
                            $('#reportMsgImage').value = '';
                            $('#reportMsgImagePreview').style.display = 'none';
                        } catch (err) {
                            showToast(err.message, 'error');
                        }
                        btn.disabled = false;
                    });

                    // Resolve button handler
                    $('#resolveReportBtn')?.addEventListener('click', async () => {
                        const btn = $('#resolveReportBtn');
                        btn.disabled = true;
                        btn.textContent = 'جارٍ التأكيد...';
                        try {
                            await api('/api/orders.php?action=report_resolve', {
                                method: 'POST',
                                body: { order_id: order.id },
                            });
                            showToast('تم تأكيد الحل');
                            renderOrderDetail(id);
                        } catch (err) {
                            showToast(err.message, 'error');
                            btn.disabled = false;
                            btn.textContent = '✅ تأكيد الحل';
                        }
                    });

                    // Poll for new messages + status changes
                    let lastMsgCount = messages.length;
                    const reportPoll = setInterval(async () => {
                        try {
                            const fresh = await api(`/api/orders.php?action=get&id=${encodeURIComponent(id)}`);
                            // Re-render on status change
                            if (fresh.report?.status !== report.status || fresh.order.status !== order.status || (fresh.deliveries || []).length !== deliveries.length) {
                                clearInterval(reportPoll);
                                renderOrderDetail(id);
                                return;
                            }
                            // Append new messages without re-render
                            const freshMsgs = fresh.report_messages || [];
                            if (freshMsgs.length > lastMsgCount) {
                                const newMsgs = freshMsgs.slice(lastMsgCount);
                                for (const m of newMsgs) {
                                    const bubbleHtml = `<div class="chat-bubble ${m.sender === 'admin' ? 'admin' : 'user'}">
                                        ${m.message ? `<div class="chat-bubble-text">${esc(m.message)}</div>` : ''}
                                        ${m.image ? `<img src="${esc(safeImg(m.image))}" class="chat-bubble-img" alt="صورة">` : ''}
                                        <div class="chat-bubble-time">${esc(m.created_at)}</div>
                                    </div>`;
                                    chatEl.insertAdjacentHTML('beforeend', bubbleHtml);
                                }
                                chatEl.scrollTop = chatEl.scrollHeight;
                                lastMsgCount = freshMsgs.length;
                            }
                        } catch (e) { }
                    }, 10000);
                    window.addEventListener('hashchange', () => clearInterval(reportPoll), { once: true });
                } else if (report && report.status === 'denied') {
                    reportSection.innerHTML = `<div class="alert alert-error" style="margin-top:1rem;">❌ تم رفض البلاغ من قبل المشرف</div>`;
                } else if (report && report.status === 'resolved') {
                    reportSection.innerHTML = `<div class="alert alert-info" style="margin-top:1rem;">✅ تم حل البلاغ بنجاح</div>`;
                } else if (report && report.status === 'canceled') {
                    reportSection.innerHTML = `<div class="alert alert-info" style="margin-top:1rem;">💰 تم إلغاء الطلب واسترداد المبلغ</div>`;
                } else if (!report && reportDeadline > now) {
                    // Can still report
                    reportSection.innerHTML = `<div style="margin-top:1.5rem;border-top:1px solid var(--border);padding-top:1rem;">
                        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;">
                            <span style="font-weight:600;font-size:0.95rem;">⚠️ هل يوجد مشكلة؟</span>
                            <span id="reportCountdown" style="font-size:0.85rem;color:var(--text-muted);"></span>
                        </div>
                        <div class="form-group"><textarea class="form-textarea" id="reportDetails" rows="3" placeholder="اشرح المشكلة بالتفصيل..."></textarea></div>
                        <div class="form-group"><label class="form-label">لقطة شاشة (اختياري)</label><input type="file" class="form-input" id="reportScreenshot" accept="image/*"></div>
                        <button class="btn btn-danger" id="submitReportBtn">📩 إرسال بلاغ</button>
                    </div>`;

                    // Countdown
                    const countdownEl = $('#reportCountdown');
                    const reportTimer = setInterval(() => {
                        const remaining = Math.max(0, reportDeadline - Date.now());
                        if (remaining === 0) {
                            clearInterval(reportTimer);
                            reportSection.innerHTML = '<div style="margin-top:1rem;color:var(--text-muted);font-size:0.85rem;">⏰ انتهت فترة الإبلاغ</div>';
                            return;
                        }
                        const secs = Math.ceil(remaining / 1000);
                        const mins = Math.floor(secs / 60);
                        const s = secs % 60;
                        countdownEl.textContent = `⏰ متبقي: ${mins}:${String(s).padStart(2, '0')}`;
                    }, 1000);
                    window.addEventListener('hashchange', () => clearInterval(reportTimer), { once: true });

                    // Submit report
                    $('#submitReportBtn')?.addEventListener('click', async () => {
                        const details = $('#reportDetails').value.trim();
                        if (!details) { showToast('يرجى كتابة تفاصيل المشكلة', 'error'); return; }
                        const btn = $('#submitReportBtn');
                        btn.disabled = true;
                        btn.textContent = 'جارٍ الإرسال...';
                        try {
                            const fd = new FormData();
                            fd.append('order_id', order.id);
                            fd.append('details', details);
                            fd.append('csrf_token', APP.csrf);
                            const ssFile = $('#reportScreenshot').files[0];
                            if (ssFile) fd.append('screenshot', await compressImage(ssFile));
                            const res = await fetch('/api/orders.php?action=report', {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': APP.csrf },
                                body: fd,
                            });
                            const d = await res.json();
                            if (!d.success) throw new Error(d.error);
                            showToast('تم إرسال البلاغ بنجاح');
                            renderOrderDetail(id);
                        } catch (err) {
                            showToast(err.message, 'error');
                            btn.disabled = false;
                            btn.textContent = '📩 إرسال بلاغ';
                        }
                    });
                } else if (!report && reportDeadline <= now) {
                    reportSection.innerHTML = '<div style="margin-top:1rem;color:var(--text-muted);font-size:0.85rem;">⏰ انتهت فترة الإبلاغ</div>';
                }
            }
        } catch (err) {
            main.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
        }
    }

    // ==========================================
    // Wallet Page
    // ==========================================
    async function renderWallet() {
        main.innerHTML = '<div class="page-loading"><div class="spinner"></div></div>';
        try {
            const methodsData = await api('/api/topup_methods.php?action=list');
            const methods = methodsData.methods || [];
            const userKycApproved = methodsData.user_kyc_approved || false;

            let methodsHtml = methods.map(m => {
                const isLocked = m.require_kyc == 1 && !userKycApproved;
                return `<option value="${esc(m.id)}" data-instructions="${esc(m.instructions || '')}" data-image="${esc(m.image || '')}" data-kyc="${esc(m.require_kyc)}" ${isLocked ? 'data-locked="1"' : ''}>${isLocked ? '🔒 ' : ''}${esc(m.name)}${isLocked ? ' (يتطلب توثيق)' : ''}</option>`;
            }).join('');

            main.innerHTML = `
            <div style="max-width:600px;margin:0 auto;">
                ${breadcrumb([{label: '🏠 المتجر', href: '#/'}, {label: 'المحفظة'}])}
                <div class="wallet-header">
                    <div class="wallet-label">رصيدك الحالي</div>
                    <div class="wallet-balance">${formatPrice(APP.user.wallet_balance)}</div>
                </div>

                ${methods.length > 0 ? `
                <div class="card card-no-hover" style="margin-bottom:1.5rem;">
                    <div class="card-body" style="padding:1.5rem;">
                        <h2 style="font-size:1.1rem;font-weight:600;margin-bottom:1rem;">💳 شحن الرصيد</h2>
                        <div id="kycRequiredBanner" class="kyc-required-banner" style="display:none;">
                            <span class="kyc-required-icon">🔒</span>
                            <div>
                                <strong>هذه الطريقة تتطلب توثيق الحساب (KYC)</strong>
                                <p style="margin:0.25rem 0 0;font-size:0.85rem;color:var(--text-secondary);">يرجى إكمال التحقق من الهوية أولاً</p>
                            </div>
                            <a href="#/profile" class="btn btn-primary btn-sm" onclick="setTimeout(()=>{document.querySelector('[data-tab=kyc]')?.click()},100)">التحقق الآن</a>
                        </div>
                        <form id="topupForm" enctype="multipart/form-data">
                            <div class="form-group">
                                <label class="form-label">طريقة الدفع</label>
                                <select class="form-select" name="method_id" id="topupMethod" required>
                                    <option value="">اختر طريقة الدفع</option>
                                    ${methodsHtml}
                                </select>
                            </div>
                            <div class="form-group" id="methodDetails" style="display:none;">
                                <div id="methodImage" style="display:none;text-align:center;margin-bottom:0.75rem;">
                                    <img id="methodImageTag" src="" style="max-width:100%;max-height:300px;border-radius:8px;border:1px solid var(--border);cursor:pointer;" title="اضغط للتكبير">
                                </div>
                                <div id="methodInstructionsText"></div>
                            </div>
                            <div class="form-group" id="topupAmountGroup">
                                <label class="form-label">المبلغ ($)</label>
                                <input type="number" class="form-input" name="amount" id="topupAmount" min="0.01" step="0.01" required placeholder="100.00">
                                <div id="currencyConversionHint" style="display:none;margin-top:0.4rem;padding:0.4rem 0.75rem;background:var(--primary-light);border-radius:var(--radius-sm);font-size:0.82rem;font-weight:600;color:var(--primary);"></div>
                            </div>
                            <div class="form-group" id="topupProofGroup">
                                <label class="form-label">إثبات الدفع (صورة) <span class="required">*</span></label>
                                <input type="file" class="form-input" name="proof_image" accept="image/*" required>
                            </div>
                            <button type="submit" class="btn btn-success btn-block" id="topupSubmitBtn">📤 إرسال طلب الشحن</button>
                        </form>
                    </div>
                </div>` : '<div class="alert alert-warning">لا توجد طرق شحن متاحة حالياً</div>'}

                <h2 style="font-size:1.1rem;font-weight:600;margin:1.5rem 0 0.75rem;">📋 سجل الشحن</h2>
                <div id="topupHistoryContainer"></div>
                <div id="topupHistoryLoadMore"></div>
            </div>`;

            // Load topup history with load-more
            const historyPerPage = 10;
            let historyOffset = 0;
            const histContainer = $('#topupHistoryContainer');

            async function loadHistory() {
                const historyData = await api(`/api/wallet.php?action=history&limit=${historyPerPage}&offset=${historyOffset}`);
                const history = historyData.requests || [];
                if (historyOffset === 0 && history.length === 0) {
                    histContainer.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:1rem;">لا يوجد سجل شحن</div>';
                    return;
                }
                let html = '';
                for (const r of history) {
                    html += `<div class="order-card" style="cursor:default;">
                        <div class="order-info">
                            <span class="order-id">#${esc(r.id)}</span>
                            <span>${esc(r.method_name)}</span>
                            <div class="order-meta">${esc(r.created_at)}</div>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.75rem;">
                            <span class="order-price">$${parseFloat(r.accepted_amount || r.amount).toFixed(2)}</span>
                            <span class="badge badge-${r.status === 'accepted' ? 'accepted' : r.status === 'denied' ? 'denied' : 'pending'}">${esc(topupStatusLabel(r.status))}</span>
                        </div>
                    </div>`;
                }
                histContainer.insertAdjacentHTML('beforeend', html);
                historyOffset += history.length;

                const loadMoreDiv = $('#topupHistoryLoadMore');
                if (historyData.has_more) {
                    loadMoreDiv.innerHTML = '<button class="btn btn-outline btn-block" id="loadMoreHistoryBtn" style="margin-top:1rem;">⬇️ تحميل المزيد</button>';
                    $('#loadMoreHistoryBtn').addEventListener('click', async () => {
                        const btn = $('#loadMoreHistoryBtn');
                        btn.disabled = true;
                        btn.textContent = '...';
                        try { await loadHistory(); } catch (e) { showToast(e.message, 'error'); btn.disabled = false; btn.textContent = '⬇️ تحميل المزيد'; }
                    });
                } else {
                    loadMoreDiv.innerHTML = '';
                }
            }
            await loadHistory();

            // Method image + instructions toggle + KYC lock
            const methodSelect = $('#topupMethod');
            if (methodSelect) {
                methodSelect.addEventListener('change', () => {
                    const opt = methodSelect.selectedOptions[0];
                    const inst = opt?.dataset.instructions;
                    const img = opt?.dataset.image;
                    const isLocked = opt?.dataset.locked === '1';
                    const detailsDiv = $('#methodDetails');
                    const imgDiv = $('#methodImage');
                    const imgTag = $('#methodImageTag');
                    const instText = $('#methodInstructionsText');
                    const kycBanner = $('#kycRequiredBanner');
                    const amountGroup = $('#topupAmountGroup');
                    const proofGroup = $('#topupProofGroup');
                    const submitBtn = $('#topupSubmitBtn');

                    // KYC lock handling
                    if (isLocked) {
                        kycBanner.style.display = '';
                        detailsDiv.style.display = 'none';
                        amountGroup.style.display = 'none';
                        proofGroup.style.display = 'none';
                        submitBtn.style.display = 'none';
                        return;
                    }
                    kycBanner.style.display = 'none';
                    amountGroup.style.display = '';
                    proofGroup.style.display = '';
                    submitBtn.style.display = '';

                    const hasContent = (inst || img);
                    detailsDiv.style.display = hasContent ? '' : 'none';

                    if (img && safeImg(img)) {
                        imgTag.src = safeImg(img);
                        imgDiv.style.display = '';
                    } else {
                        imgDiv.style.display = 'none';
                    }

                    if (inst) {
                        instText.innerHTML = formatInstructions(inst);
                        instText.style.display = '';
                    } else {
                        instText.style.display = 'none';
                    }
                });
            }

            // Lightbox for method image
            const methodImgTag = $('#methodImageTag');
            if (methodImgTag) {
                methodImgTag.addEventListener('click', () => {
                    const src = methodImgTag.src;
                    if (!src) return;
                    const overlay = document.createElement('div');
                    overlay.className = 'lightbox-overlay';
                    overlay.innerHTML = `
                        <div class="lightbox-content">
                            <img src="${esc(src)}" class="lightbox-img">
                            <div class="lightbox-actions">
                                <a href="${esc(src)}" download class="btn btn-primary btn-sm">⬇️ تحميل</a>
                                <button class="btn btn-outline btn-sm lightbox-close">✕ إغلاق</button>
                            </div>
                        </div>`;
                    document.body.appendChild(overlay);
                    requestAnimationFrame(() => overlay.classList.add('active'));
                    overlay.addEventListener('click', (ev) => {
                        if (ev.target === overlay || ev.target.closest('.lightbox-close')) {
                            overlay.classList.remove('active');
                            setTimeout(() => overlay.remove(), 200);
                        }
                    });
                });
            }

            // Copy-to-clipboard for {text} blocks (one-time delegation via document)
            if (!window._copyBtnHandlerSet) {
                window._copyBtnHandlerSet = true;
                document.addEventListener('click', (ev) => {
                    const copyBtn = ev.target.closest('.copy-value-btn');
                    if (!copyBtn) return;
                    const val = copyBtn.dataset.value;
                    navigator.clipboard.writeText(val).then(() => {
                        const origText = copyBtn.innerHTML;
                        copyBtn.innerHTML = '✅ تم النسخ';
                        copyBtn.classList.add('copied');
                        setTimeout(() => { copyBtn.innerHTML = origText; copyBtn.classList.remove('copied'); }, 1500);
                    });
                });
            }

            // Live currency conversion hint
            const amountInput = $('#topupAmount');
            const convHint = $('#currencyConversionHint');
            if (amountInput && convHint) {
                const updateHint = () => {
                    const cur = getSelectedCurrency();
                    const val = parseFloat(amountInput.value);
                    if (!val || val <= 0 || cur.code === 'USD') {
                        convHint.style.display = 'none';
                        return;
                    }
                    const converted = (val * parseFloat(cur.rate)).toFixed(2);
                    convHint.innerHTML = `<i class="fa-solid fa-arrow-right-arrow-left" style="font-size:0.7rem;margin-left:0.35rem;"></i> $${val.toFixed(2)} = <strong>${esc(converted)} ${esc(cur.symbol)}</strong>`;
                    convHint.style.display = '';
                };
                amountInput.addEventListener('input', updateHint);
                updateHint();
            }

            // Topup form
            const topupForm = $('#topupForm');
            if (topupForm) {
                topupForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    let fd = new FormData(e.target);
                    fd = await compressFormDataImages(fd);
                    const btn = topupForm.querySelector('button[type="submit"]');
                    btn.disabled = true;
                    btn.textContent = 'جارٍ الإرسال...';
                    try {
                        await fetch('/api/wallet.php?action=topup', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': APP.csrf },
                            body: fd,
                        }).then(r => r.json()).then(d => { if (!d.success) throw new Error(d.error); });
                        showToast('تم إرسال طلب الشحن بنجاح! بانتظار موافقة المشرف');
                        renderWallet();
                    } catch (err) {
                        showToast(err.message, 'error');
                        btn.disabled = false;
                        btn.textContent = '📤 إرسال طلب الشحن';
                    }
                });
            }
        } catch (err) {
            main.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
        }
    }

    // ==========================================
    // Contact Page
    // ==========================================
    async function renderContact() {
        try {
            const data = await api('/api/settings.php?action=contact');
            main.innerHTML = `
            <div style="max-width:500px;margin:2rem auto;text-align:center;">
                ${breadcrumb([{label: '🏠 المتجر', href: '#/'}, {label: 'تواصل معنا'}])}
                <h1 style="font-size:1.5rem;font-weight:700;margin-bottom:1.5rem;">📞 تواصل معنا</h1>
                <div class="card card-no-hover">
                    <div class="card-body" style="padding:2rem;">
                        ${data.phone ? `<div style="margin-bottom:1rem;font-size:1.1rem;">📱 الهاتف: <a href="${esc(safeUrl('tel:' + telDigits(data.phone)))}" style="font-weight:600;">${esc(data.phone)}</a></div>` : ''}
                        ${data.email ? `<div style="margin-bottom:1rem;font-size:1.1rem;">📧 البريد: <a href="${esc(safeUrl('mailto:' + String(data.email).trim()))}" style="font-weight:600;">${esc(data.email)}</a></div>` : ''}
                        ${data.whatsapp ? `<div style="font-size:1.1rem;">💬 واتساب: <a href="https://wa.me/${String(data.whatsapp).replace(/\D/g, '')}" target="_blank" rel="noopener noreferrer" style="font-weight:600;">${esc(data.whatsapp)}</a></div>` : ''}
                        ${!data.phone && !data.email && !data.whatsapp ? '<div class="empty-state-text">لا توجد معلومات تواصل متاحة</div>' : ''}
                    </div>
                </div>
            </div>`;
        } catch (err) {
            main.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
        }
    }

    // ==========================================
    // Admin Panel (loaded from admin.js)
    // ==========================================
    let adminJsLoaded = false;
    async function renderAdmin(section) {
        if (APP.user?.role !== 'admin') {
            main.innerHTML = '<div class="alert alert-error">صلاحيات غير كافية</div>';
            return;
        }
        if (!adminJsLoaded) {
            await loadScript('/assets/js/admin.js');
            adminJsLoaded = true;
        }
        if (window.renderAdminPanel) {
            window.renderAdminPanel(section || 'dashboard');
        }
    }

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            if (document.querySelector(`script[src="${src}"]`)) { resolve(); return; }
            const s = document.createElement('script');
            s.src = src;
            s.onload = resolve;
            s.onerror = reject;
            document.body.appendChild(s);
        });
    }

    // ==========================================
    // Utility Functions
    // ==========================================
    // HTML-escape for both text content and attribute values. null/undefined → '', 0 → '0'.
    function esc(v) {
        return String(v ?? '').replace(/[&<>"'`]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' }[c]));
    }

    // Allow only safe URL schemes for href / window.open. Returns '#' otherwise. (Raw value — still esc() it in HTML.)
    function safeUrl(u) {
        const s = String(u ?? '').trim();
        if (!s || /[\u0000-\u001F\u007F]/.test(s)) return '#';
        return /^(https?:\/\/|mailto:|tel:|\/(?!\/)|#)/i.test(s) ? s : '#';
    }

    // Phone number for tel: links — keep a leading + and digits only
    function telDigits(x) {
        const s = String(x ?? '').trim();
        return (s.startsWith('+') ? '+' : '') + s.replace(/\D/g, '');
    }

    // Server-stored upload paths / images: allow only uploads/... (relative or rooted) or https:// URLs.
    // Returns a URL usable as src/href (rooted for uploads), or fallback. (Raw value — still esc() it in HTML.)
    function safeImg(p, fallback = '') {
        const s = String(p ?? '').trim();
        if (!s || /[\u0000-\u001F\u007F\\]/.test(s) || s.includes('..')) return fallback;
        if (/^uploads\//i.test(s)) return '/' + s;
        if (/^\/uploads\//i.test(s)) return s;
        if (/^https:\/\//i.test(s)) return s;
        return fallback;
    }

    /**
     * Parse instruction text: {value} → copyable block, rest → pre-wrap text
     */
    function formatInstructions(text) {
        if (!text) return '';
        // Split by {value} patterns
        const parts = text.split(/\{([^}]+)\}/g);
        let html = '';
        for (let i = 0; i < parts.length; i++) {
            if (i % 2 === 0) {
                // Regular text
                const trimmed = parts[i].trim();
                if (trimmed) {
                    html += `<div class="inst-text">${esc(trimmed)}</div>`;
                }
            } else {
                // Copyable value (was inside {})
                const val = parts[i].trim();
                html += `<div class="inst-copy-block">
                    <div class="inst-copy-value" dir="ltr">${esc(val)}</div>
                    <button type="button" class="btn btn-outline btn-sm copy-value-btn" data-value="${esc(val)}">📋 نسخ</button>
                </div>`;
            }
        }
        return html;
    }

    function breadcrumb(items) {
        return '<nav class="breadcrumb">' + items.map((item, i) => {
            const isLast = i === items.length - 1;
            const sep = i > 0 ? '<span class="breadcrumb-sep">‹</span>' : '';
            if (isLast) return `${sep}<span class="breadcrumb-item active">${esc(item.label)}</span>`;
            return `${sep}<span class="breadcrumb-item"><a href="${esc(safeUrl(item.href))}">${esc(item.label)}</a></span>`;
        }).join('') + '</nav>';
    }

    function statusClass(status) {
        const map = { pending: 'pending', accepted: 'accepted', delivering: 'delivering', delivered: 'delivered', canceled_by_admin: 'canceled', canceled_by_user: 'canceled' };
        return map[status] || 'pending';
    }

    function statusLabel(status) {
        const map = { pending: 'قيد الانتظار', accepted: 'مقبول', delivering: 'جارٍ التسليم', delivered: 'تم التسليم', canceled_by_admin: 'ملغي من المشرف', canceled_by_user: 'ملغي من المستخدم' };
        return map[status] || status;
    }

    function topupStatusLabel(status) {
        const map = { pending: 'قيد الانتظار', accepted: 'مقبول', denied: 'مرفوض' };
        return map[status] || status;
    }

    // ==========================================
    // Navbar Events
    // ==========================================
    document.addEventListener('click', (e) => {
        // User menu toggle
        if (e.target.closest('#userMenuBtn')) {
            $('#userMenuDropdown').classList.toggle('show');
            return;
        }
        // Close dropdown on outside click
        if (!e.target.closest('.user-menu')) {
            const dropdown = $('#userMenuDropdown');
            if (dropdown) dropdown.classList.remove('show');
        }

        // Logout
        if (e.target.closest('#logoutBtn')) {
            e.preventDefault();
            api('/api/auth.php?action=logout', { method: 'POST' }).then(() => {
                APP.user = null;
                location.hash = '#/';
                navigate();
            });
            return;
        }

        // Hamburger
        if (e.target.closest('#hamburgerBtn')) {
            $('#hamburgerBtn').classList.toggle('open');
            $('#mobileNav').classList.toggle('show');
            return;
        }

        // Mobile nav links close menu
        if (e.target.closest('.mobile-nav-link')) {
            $('#hamburgerBtn').classList.remove('open');
            $('#mobileNav').classList.remove('show');
        }

        // Wallet badge link
        if (e.target.closest('#walletBadge')) {
            location.hash = '#/wallet';
        }
    });

    // ==========================================
    // Floating Contact FAB
    // ==========================================
    let _contactData = null;
    let _contactFabRendered = false;

    async function renderContactFAB(show) {
        const existing = document.getElementById('contactFabWrapper');
        if (!show) {
            if (existing) existing.style.display = 'none';
            return;
        }
        if (existing) {
            existing.style.display = '';
            return;
        }

        // Fetch contact data once
        if (!_contactData) {
            try {
                _contactData = await api('/api/settings.php?action=contact');
            } catch (e) { return; }
        }

        const d = _contactData;
        const items = [];
        if (d.phone) items.push(`<a href="${esc(safeUrl('tel:' + telDigits(d.phone)))}" class="contact-fab-circle" title="${esc(d.phone)}"><i class="fas fa-phone"></i></a>`);
        if (d.email) items.push(`<a href="${esc(safeUrl('mailto:' + String(d.email).trim()))}" class="contact-fab-circle" title="${esc(d.email)}"><i class="fas fa-envelope"></i></a>`);
        if (d.whatsapp) items.push(`<a href="https://wa.me/${String(d.whatsapp).replace(/\D/g, '')}" target="_blank" rel="noopener noreferrer" class="contact-fab-circle" title="واتساب"><i class="fab fa-whatsapp"></i></a>`);

        if (items.length === 0) return;

        const wrapper = document.createElement('div');
        wrapper.id = 'contactFabWrapper';
        wrapper.className = 'contact-fab-wrapper';
        wrapper.innerHTML = `
            <div class="contact-fab-panel" id="contactFabPanel">${items.join('')}</div>
            <button class="contact-fab-btn" id="contactFabBtn" title="تواصل معنا"><i class="fas fa-headset"></i></button>
        `;
        document.body.appendChild(wrapper);

        const btn = document.getElementById('contactFabBtn');
        const panel = document.getElementById('contactFabPanel');
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = panel.classList.contains('show');
            panel.classList.toggle('show');
            btn.classList.toggle('active');
            btn.innerHTML = isOpen ? '<i class="fas fa-headset"></i>' : '<i class="fas fa-xmark"></i>';
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.contact-fab-wrapper')) {
                panel.classList.remove('show');
                btn.classList.remove('active');
                btn.innerHTML = '<i class="fas fa-headset"></i>';
            }
        });

        _contactFabRendered = true;
    }

    // ==========================================
    // Admin Edit Toggle + Add Button
    // ==========================================
    let _adminContext = { page: 'store' };

    function renderAdminEditToggle(show) {
        const existing = document.getElementById('adminEditToggle');
        const existingAdd = document.getElementById('adminAddBtn');
        if (!show) {
            if (existing) existing.style.display = 'none';
            if (existingAdd) existingAdd.style.display = 'none';
            document.body.classList.remove('admin-edit-mode');
            return;
        }
        if (existing) {
            existing.style.display = '';
            if (existingAdd) existingAdd.style.display = '';
            return;
        }

        // Create toggle button
        const btn = document.createElement('button');
        btn.id = 'adminEditToggle';
        btn.className = 'admin-edit-toggle';
        btn.title = 'وضع التحرير';
        btn.textContent = '🔧';
        document.body.appendChild(btn);

        // Create add button
        const addBtn = document.createElement('button');
        addBtn.id = 'adminAddBtn';
        addBtn.className = 'admin-add-btn';
        addBtn.title = 'إضافة جديد';
        addBtn.textContent = '+';
        document.body.appendChild(addBtn);

        // Restore state from sessionStorage
        if (sessionStorage.getItem('cstore_edit_mode') === '1') {
            document.body.classList.add('admin-edit-mode');
        }

        btn.addEventListener('click', () => {
            document.body.classList.toggle('admin-edit-mode');
            const active = document.body.classList.contains('admin-edit-mode');
            sessionStorage.setItem('cstore_edit_mode', active ? '1' : '0');
        });

        addBtn.addEventListener('click', async () => {
            // Ensure admin.js is loaded
            if (!window.renderAdminPanel) {
                await loadScript('/assets/js/admin.js');
            }
            try {
                if (_adminContext.page === 'store') {
                    // Add new category
                    if (window.ADMIN_SHOW_CATEGORY_MODAL) {
                        window.ADMIN_SHOW_CATEGORY_MODAL(null, () => { location.reload(); });
                    }
                } else if (_adminContext.page === 'category') {
                    // Add new item in this category
                    const catsData = await api('/api/categories.php?action=list');
                    const cats = catsData.categories || [];
                    const preselect = { category_id: _adminContext.categoryId };
                    if (window.ADMIN_SHOW_ITEM_MODAL) {
                        window.ADMIN_SHOW_ITEM_MODAL(preselect, cats, () => {
                            renderCategoryDetail(_adminContext.categoryId);
                        });
                    }
                } else if (_adminContext.page === 'item') {
                    // Edit current item
                    const [d, f, catsData] = await Promise.all([
                        api(`/api/items.php?action=get&id=${encodeURIComponent(_adminContext.itemId)}`),
                        api(`/api/items.php?action=fields&id=${encodeURIComponent(_adminContext.itemId)}`),
                        api('/api/categories.php?action=list'),
                    ]);
                    if (window.ADMIN_SHOW_ITEM_MODAL) {
                        window.ADMIN_SHOW_ITEM_MODAL({ ...d.item, fields: f.fields, price_options: d.price_options || [] }, catsData.categories || [], () => {
                            renderItemDetail(_adminContext.itemId);
                        });
                    }
                }
            } catch (err) { showToast(err.message, 'error'); }
        });
    }

    // ==========================================
    // Admin Inline Card Actions (edit/delete)
    // ==========================================
    function bindAdminCardActions(container, refreshFn) {
        // Category edit
        container.querySelectorAll('.admin-edit-cat').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                // Ensure admin.js is loaded
                if (!window.renderAdminPanel) {
                    await loadScript('/assets/js/admin.js');
                }
                try {
                    const d = await api(`/api/categories.php?action=get&id=${encodeURIComponent(btn.dataset.id)}`);
                    if (window.ADMIN_SHOW_CATEGORY_MODAL) {
                        window.ADMIN_SHOW_CATEGORY_MODAL(d.category, refreshFn);
                    }
                } catch (err) { showToast(err.message, 'error'); }
            });
        });

        // Category delete
        container.querySelectorAll('.admin-del-cat').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (!confirm('هل أنت متأكد من حذف هذا التصنيف؟')) return;
                try {
                    await api('/api/categories.php?action=delete', { method: 'POST', body: { id: btn.dataset.id } });
                    showToast('تم حذف التصنيف');
                    if (refreshFn) refreshFn();
                    else location.reload();
                } catch (err) { showToast(err.message, 'error'); }
            });
        });

        // Item edit
        container.querySelectorAll('.admin-edit-item').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (!window.renderAdminPanel) {
                    await loadScript('/assets/js/admin.js');
                }
                try {
                    const [d, f, catsData] = await Promise.all([
                        api(`/api/items.php?action=get&id=${encodeURIComponent(btn.dataset.id)}`),
                        api(`/api/items.php?action=fields&id=${encodeURIComponent(btn.dataset.id)}`),
                        api('/api/categories.php?action=list'),
                    ]);
                    if (window.ADMIN_SHOW_ITEM_MODAL) {
                        window.ADMIN_SHOW_ITEM_MODAL({ ...d.item, fields: f.fields, price_options: d.price_options || [] }, catsData.categories || [], refreshFn);
                    }
                } catch (err) { showToast(err.message, 'error'); }
            });
        });

        // Item delete
        container.querySelectorAll('.admin-del-item').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (!confirm('هل أنت متأكد من حذف هذا المنتج؟')) return;
                try {
                    await api('/api/items.php?action=delete', { method: 'POST', body: { id: btn.dataset.id } });
                    showToast('تم حذف المنتج');
                    if (refreshFn) refreshFn();
                    else location.reload();
                } catch (err) { showToast(err.message, 'error'); }
            });
        });
    }

    // ==========================================
    // SweetAlert Alerts System
    // ==========================================
    async function showPendingAlerts() {
        if (typeof Swal === 'undefined') return;
        try {
            const data = await api('/api/alerts.php?action=list');
            const alerts = data.alerts || [];
            if (alerts.length === 0) return;

            const today = new Date().toISOString().slice(0, 10);
            const pending = alerts.filter(a => {
                if (a.type === 'one_time') {
                    return !localStorage.getItem(`cstore_alert_seen_${a.id}`);
                } else if (a.type === 'always') {
                    return localStorage.getItem(`cstore_alert_day_${a.id}`) !== today;
                }
                return false;
            });

            for (const alert of pending) {
                const options = {
                    titleText: String(alert.title ?? ''),
                    html: alert.description ? `<div style="text-align:right;direction:rtl;">${esc(alert.description)}</div>` : '',
                    allowOutsideClick: true,
                    confirmButtonColor: 'var(--primary, #4F46E5)',
                };

                if (alert.url) {
                    options.showConfirmButton = true;
                    options.confirmButtonText = '🔗 عرض';
                    options.showCancelButton = true;
                    options.cancelButtonText = 'إغلاق';
                } else {
                    options.confirmButtonText = 'حسناً';
                }

                const result = await Swal.fire(options);

                // Mark as seen
                if (alert.type === 'one_time') {
                    localStorage.setItem(`cstore_alert_seen_${alert.id}`, '1');
                } else if (alert.type === 'always') {
                    localStorage.setItem(`cstore_alert_day_${alert.id}`, today);
                }

                // Handle URL redirect
                if (alert.url && result.isConfirmed) {
                    const target = safeUrl(alert.url);
                    if (target !== '#') window.open(target, '_blank', 'noopener,noreferrer');
                }
            }
        } catch (e) { /* silently fail */ }
    }

    // ==========================================
    // Profile Page — Personal Info, Security, KYC
    // ==========================================
    async function renderProfile() {
        main.innerHTML = '<div class="page-loading"><div class="spinner"></div></div>';
        try {
            const [profileData, kycData] = await Promise.all([
                api('/api/profile.php?action=get'),
                api('/api/kyc.php?action=status'),
            ]);
            const p = profileData.profile;
            const kyc = kycData;

            const avatarSrc = safeImg(p.profile_image);
            const avatarHtml = avatarSrc
                ? `<img src="${esc(avatarSrc)}" alt="avatar" class="profile-avatar-img">`
                : `<div class="profile-avatar-placeholder">${esc(p.username?.charAt(0)?.toUpperCase() || '?')}</div>`;

            // KYC tab content
            let kycContent = '';
            if (kyc.status === 'approved') {
                kycContent = `<div class="kyc-status-card kyc-approved"><div class="kyc-status-icon">✅</div><h3>حسابك موثق</h3><p>تم التحقق من هويتك بنجاح</p></div>`;
            } else if (kyc.status === 'pending') {
                kycContent = `<div class="kyc-status-card kyc-pending"><div class="kyc-status-icon">⏳</div><h3>قيد المراجعة</h3><p>طلبك قيد المراجعة من قبل الإدارة</p></div>`;
            } else if (kyc.status === 'denied' && !kyc.can_reapply) {
                const hours = Math.ceil(kyc.cooldown_remaining / 3600);
                kycContent = `<div class="kyc-status-card kyc-denied"><div class="kyc-status-icon">❌</div><h3>تم الرفض</h3>
                    ${kyc.application?.deny_reason ? `<p>السبب: ${esc(kyc.application.deny_reason)}</p>` : ''}
                    <p>يمكنك إعادة التقديم بعد <strong id="kycCooldownTimer">${esc(hours)} ساعة</strong></p></div>`;
            } else {
                // Can apply (none or denied+cooldown over)
                const showDeniedMsg = kyc.status === 'denied';
                kycContent = `
                    ${showDeniedMsg ? `<div class="alert alert-warning" style="margin-bottom:1rem;">تم رفض طلبك السابق${kyc.application?.deny_reason ? ': ' + esc(kyc.application.deny_reason) : ''}. يمكنك التقديم مجدداً.</div>` : ''}
                    <form id="kycForm" enctype="multipart/form-data">
                        <div style="display:flex;gap:0.75rem;">
                            <div class="form-group" style="flex:1;"><label class="form-label">الاسم الأول <span class="required">*</span></label><input type="text" class="form-input" name="first_name" required value="${esc(p.first_name || '')}"></div>
                            <div class="form-group" style="flex:1;"><label class="form-label">الاسم الأخير <span class="required">*</span></label><input type="text" class="form-input" name="last_name" required value="${esc(p.last_name || '')}"></div>
                        </div>
                        <div class="form-group"><label class="form-label">العنوان <span class="required">*</span></label><input type="text" class="form-input" name="address" required placeholder="المدينة، البلد"></div>
                        <div class="form-group"><label class="form-label">رقم الهاتف <span class="required">*</span></label><input type="tel" class="form-input" name="phone" required placeholder="+966..."></div>
                        <div style="display:flex;gap:0.75rem;">
                            <div class="form-group" style="flex:1;"><label class="form-label">نوع الهوية <span class="required">*</span></label>
                                <select class="form-select" name="id_type" required><option value="">اختر</option><option value="citizen_id">بطاقة هوية</option><option value="passport">جواز سفر</option></select>
                            </div>
                            <div class="form-group" style="flex:1;"><label class="form-label">رقم الهوية <span class="required">*</span></label><input type="text" class="form-input" name="id_number" required></div>
                        </div>
                        <div class="form-group"><label class="form-label">صورة الوجه الأمامي <span class="required">*</span></label><input type="file" class="form-input" name="front_image" accept="image/*" required></div>
                        <div class="form-group"><label class="form-label">صورة الوجه الخلفي <span class="required">*</span></label><input type="file" class="form-input" name="back_image" accept="image/*" required></div>
                        <div class="form-group"><label class="form-label">سيلفي وأنت تحمل الهوية <span class="required">*</span></label><input type="file" class="form-input" name="selfie_image" accept="image/*" required></div>
                        <button type="submit" class="btn btn-primary btn-block">📤 تقديم طلب التحقق</button>
                    </form>`;
            }

            main.innerHTML = `
            <div style="max-width:600px;margin:0 auto;">
                ${breadcrumb([{label: '🏠 المتجر', href: '#/'}, {label: 'الملف الشخصي'}])}
                <div class="profile-header">
                    <div class="profile-avatar">${avatarHtml}</div>
                    <h2>${esc(p.username)} ${p.kyc_status === 'approved' ? '<span class="verified-badge">✅</span>' : ''}</h2>
                    <p style="color:var(--text-secondary);font-size:0.9rem;">${esc(p.email)}</p>
                </div>
                <div class="profile-tabs">
                    <button class="profile-tab active" data-tab="personal">👤 البيانات</button>
                    <button class="profile-tab" data-tab="security">🔒 الأمان</button>
                    <button class="profile-tab" data-tab="kyc">🪪 التحقق</button>
                </div>
                <div class="profile-tab-content active" id="tab-personal">
                    <form id="profileForm" enctype="multipart/form-data">
                        <div class="form-group">
                            <label class="form-label">صورة الملف الشخصي</label>
                            <input type="file" class="form-input" name="profile_image" accept="image/*">
                        </div>
                        <div style="display:flex;gap:0.75rem;">
                            <div class="form-group" style="flex:1;"><label class="form-label">الاسم الأول</label><input type="text" class="form-input" name="first_name" value="${esc(p.first_name || '')}"></div>
                            <div class="form-group" style="flex:1;"><label class="form-label">الاسم الأخير</label><input type="text" class="form-input" name="last_name" value="${esc(p.last_name || '')}"></div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">💾 حفظ التغييرات</button>
                    </form>
                </div>
                <div class="profile-tab-content" id="tab-security">
                    <h3 style="font-size:1rem;font-weight:600;margin-bottom:1rem;">🔑 تغيير كلمة المرور</h3>
                    <form id="passwordForm">
                        <div class="form-group"><label class="form-label">كلمة المرور الحالية</label><input type="password" class="form-input" name="current_password" required autocomplete="current-password"></div>
                        <div class="form-group"><label class="form-label">كلمة المرور الجديدة</label><input type="password" class="form-input" name="new_password" required minlength="6" autocomplete="new-password"></div>
                        <div class="form-group"><label class="form-label">تأكيد كلمة المرور</label><input type="password" class="form-input" name="confirm_password" required minlength="6" autocomplete="new-password"></div>
                        <button type="submit" class="btn btn-primary btn-block">تغيير كلمة المرور</button>
                    </form>
                    <hr style="margin:1.5rem 0;border-color:var(--border);">
                    <h3 style="font-size:1rem;font-weight:600;margin-bottom:1rem;">🔐 التحقق بخطوتين (2FA)</h3>
                    <div id="twoFaSection">
                        ${p.has_2fa
                            ? `<div class="alert alert-success" style="margin-bottom:1rem;">✅ التحقق بخطوتين مفعّل</div>
                               <form id="disable2faForm">
                                   <div class="form-group"><label class="form-label">كلمة المرور لإلغاء التفعيل</label><input type="password" class="form-input" name="password" required></div>
                                   <div class="form-group"><label class="form-label">رمز التحقق من تطبيق المصادقة</label><input type="text" class="form-input" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></div>
                                   <button type="submit" class="btn btn-danger btn-block">إلغاء التحقق بخطوتين</button>
                               </form>`
                            : `<p style="color:var(--text-secondary);margin-bottom:1rem;font-size:0.9rem;">قم بتفعيل التحقق بخطوتين لحماية إضافية لحسابك</p>
                               <button class="btn btn-primary btn-block" id="enable2faBtn">تفعيل التحقق بخطوتين</button>
                               <div id="twoFaSetup" style="display:none;margin-top:1rem;"></div>`}
                    </div>
                </div>
                <div class="profile-tab-content" id="tab-kyc">
                    ${kycContent}
                </div>
            </div>`;

            // Tab switching
            $$('.profile-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    $$('.profile-tab').forEach(t => t.classList.remove('active'));
                    $$('.profile-tab-content').forEach(c => c.classList.remove('active'));
                    tab.classList.add('active');
                    $(`#tab-${tab.dataset.tab}`).classList.add('active');
                });
            });

            // Profile update form
            const profileForm = $('#profileForm');
            if (profileForm) {
                profileForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    let fd = new FormData(e.target);
                    fd = await compressFormDataImages(fd);
                    const btn = profileForm.querySelector('button[type="submit"]');
                    btn.disabled = true;
                    try {
                        await fetch('/api/profile.php?action=update', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': APP.csrf },
                            body: fd,
                        }).then(r => r.json()).then(d => { if (!d.success) throw new Error(d.error); });
                        APP.user.first_name = fd.get('first_name');
                        APP.user.last_name = fd.get('last_name');
                        showToast('تم تحديث الملف الشخصي');
                        renderProfile();
                    } catch (err) { showToast(err.message, 'error'); btn.disabled = false; }
                });
            }

            // Password change form
            const pwForm = $('#passwordForm');
            if (pwForm) {
                pwForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const fd = new FormData(e.target);
                    if (fd.get('new_password') !== fd.get('confirm_password')) {
                        showToast('كلمات المرور غير متطابقة', 'error');
                        return;
                    }
                    try {
                        await api('/api/profile.php?action=change_password', {
                            method: 'POST',
                            body: { current_password: fd.get('current_password'), new_password: fd.get('new_password') },
                        });
                        showToast('تم تغيير كلمة المرور');
                        e.target.reset();
                    } catch (err) { showToast(err.message, 'error'); }
                });
            }

            // Enable 2FA
            const enable2faBtn = $('#enable2faBtn');
            if (enable2faBtn) {
                enable2faBtn.addEventListener('click', async () => {
                    enable2faBtn.disabled = true;
                    try {
                        const res = await api('/api/profile.php?action=enable_2fa', { method: 'POST', body: {} });
                        const setupDiv = $('#twoFaSetup');
                        setupDiv.style.display = '';
                        setupDiv.innerHTML = `
                            <div class="twofa-setup-card">
                                <p style="font-size:0.9rem;color:var(--text-secondary);margin-bottom:1rem;">امسح رمز QR بتطبيق المصادقة (Google Authenticator, Authy, ...)</p>
                                <div id="qrcodeContainer" style="display:flex;justify-content:center;margin-bottom:1rem;"></div>
                                <div class="form-group" style="margin-bottom:0.75rem;">
                                    <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);">أو أدخل الرمز يدوياً:</label>
                                    <input type="text" class="form-input" value="${esc(res.secret)}" readonly onclick="this.select()" style="text-align:center;font-family:monospace;letter-spacing:2px;font-size:0.9rem;">
                                </div>
                                <form id="confirm2faForm">
                                    <div class="form-group"><label class="form-label">رمز التحقق</label>
                                    <input type="text" class="form-input" name="code" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" style="text-align:center;font-size:1.3rem;letter-spacing:0.4rem;font-weight:700;" placeholder="000000"></div>
                                    <button type="submit" class="btn btn-success btn-block">✅ تأكيد التفعيل</button>
                                </form>
                            </div>`;
                        // Load QR code library dynamically
                        if (!window.QRCode) {
                            const script = document.createElement('script');
                            // 1.4.4 is the last release that ships a browser bundle at build/qrcode.min.js (1.5.x 404s)
                            script.src = 'https://cdn.jsdelivr.net/npm/qrcode@1.4.4/build/qrcode.min.js';
                            script.onload = () => {
                                QRCode.toCanvas(document.createElement('canvas'), res.otpauth_url, { width: 200, margin: 2 }, (err, canvas) => {
                                    if (!err) $('#qrcodeContainer').appendChild(canvas);
                                });
                            };
                            document.head.appendChild(script);
                        } else {
                            QRCode.toCanvas(document.createElement('canvas'), res.otpauth_url, { width: 200, margin: 2 }, (err, canvas) => {
                                if (!err) $('#qrcodeContainer').appendChild(canvas);
                            });
                        }
                        // Confirm 2FA
                        $('#confirm2faForm').addEventListener('submit', async (e2) => {
                            e2.preventDefault();
                            const code = new FormData(e2.target).get('code');
                            try {
                                await api('/api/profile.php?action=confirm_2fa', { method: 'POST', body: { code } });
                                showToast('تم تفعيل التحقق بخطوتين');
                                renderProfile();
                            } catch (err) { showToast(err.message, 'error'); }
                        });
                    } catch (err) { showToast(err.message, 'error'); enable2faBtn.disabled = false; }
                });
            }

            // Disable 2FA
            const disable2faForm = $('#disable2faForm');
            if (disable2faForm) {
                disable2faForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const fdDis = new FormData(e.target);
                    const password = fdDis.get('password');
                    const code = String(fdDis.get('code') || '').trim();
                    try {
                        await api('/api/profile.php?action=disable_2fa', { method: 'POST', body: { password, code } });
                        showToast('تم إلغاء التحقق بخطوتين');
                        renderProfile();
                    } catch (err) { showToast(err.message, 'error'); }
                });
            }

            // KYC form
            const kycForm = $('#kycForm');
            if (kycForm) {
                kycForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    let fd = new FormData(e.target);
                    fd = await compressFormDataImages(fd);
                    const btn = kycForm.querySelector('button[type="submit"]');
                    btn.disabled = true;
                    btn.textContent = 'جارٍ الإرسال...';
                    try {
                        await fetch('/api/kyc.php?action=submit', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': APP.csrf },
                            body: fd,
                        }).then(r => r.json()).then(d => { if (!d.success) throw new Error(d.error); });
                        showToast('تم تقديم طلب التحقق بنجاح!');
                        APP.user.kyc_status = 'pending';
                        renderProfile();
                    } catch (err) { showToast(err.message, 'error'); btn.disabled = false; btn.textContent = '📤 تقديم طلب التحقق'; }
                });
            }

            // Update me data
            api('/api/auth.php?action=me').then(res => {
                if (res.user) {
                    APP.user = { ...APP.user, ...res.user };
                    updateAuthUI();
                }
            }).catch(() => {});

        } catch (err) {
            main.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
        }
    }

    // ==========================================
    // Init
    // ==========================================
    window.addEventListener('hashchange', navigate);
    window.APP_API = api;
    window.APP_TOAST = showToast;
    window.APP_ESC = esc;
    window.APP_SAFE_URL = safeUrl;
    window.APP_SAFE_IMG = safeImg;
    window.APP_UPDATE_AUTH = updateAuthUI;
    navigate();
})();
