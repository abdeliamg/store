/**
 * CStore — Admin Panel
 * Uses DataTables with server-side processing for all management tables
 */
(function () {
    'use strict';
    const api = window.APP_API;
    const toast = window.APP_TOAST;
    const esc = window.APP_ESC;
    const safeUrl = window.APP_SAFE_URL;
    const safeImg = window.APP_SAFE_IMG;
    const main = document.getElementById('mainContent');

    // Track active DataTable so we can destroy it on page change
    let activeTable = null;

    const sections = [
        { id: 'dashboard', icon: '📊', label: 'لوحة التحكم' },
        { id: 'categories', icon: '📁', label: 'التصنيفات' },
        { id: 'items', icon: '📦', label: 'المنتجات' },
        { id: 'orders', icon: '🛒', label: 'الطلبات' },
        { id: 'users', icon: '👥', label: 'المستخدمون' },
        { id: 'topup-methods', icon: '💳', label: 'طرق الشحن' },
        { id: 'topup-requests', icon: '💰', label: 'طلبات الشحن' },
        { id: 'kyc', icon: '🪪', label: 'التحقق (KYC)' },
        { id: 'carousel', icon: '🖼', label: 'السلايدر' },
        { id: 'alerts', icon: '🔔', label: 'التنبيهات' },
        { id: 'currencies', icon: '💱', label: 'العملات' },
        { id: 'settings', icon: '⚙️', label: 'الإعدادات' },
    ];

    window.renderAdminPanel = function (section = 'dashboard') {
        // Destroy previous DataTable
        if (activeTable) { activeTable.destroy(); activeTable = null; }

        const sidebarHtml = sections.map(s =>
            `<a href="#/admin/${s.id}" class="admin-nav-item ${s.id === section ? 'active' : ''}">
                <span class="admin-nav-icon">${s.icon}</span>
                <span class="admin-nav-label">${s.label}</span>
            </a>`
        ).join('');

        main.innerHTML = `
        <div class="admin-layout">
            <nav class="admin-sidebar" id="adminSidebar">${sidebarHtml}</nav>
            <div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>
            <div class="admin-content" id="adminContent">
                <div class="page-loading"><div class="spinner"></div></div>
            </div>
        </div>`;

        // Mobile sidebar expand/collapse logic
        const sidebar = document.getElementById('adminSidebar');
        const backdrop = document.getElementById('adminSidebarBackdrop');
        if (sidebar && backdrop && window.innerWidth <= 768) {
            const collapse = () => {
                sidebar.classList.remove('expanded');
                backdrop.classList.remove('show');
            };

            sidebar.addEventListener('click', (e) => {
                if (!sidebar.classList.contains('expanded')) {
                    // First tap: expand to show labels
                    e.preventDefault();
                    e.stopPropagation();
                    sidebar.classList.add('expanded');
                    backdrop.classList.add('show');
                }
                // Second tap: default <a> navigation fires, then collapse
            });

            // Collapse after navigation
            sidebar.querySelectorAll('.admin-nav-item').forEach(item => {
                item.addEventListener('click', () => {
                    if (sidebar.classList.contains('expanded')) {
                        setTimeout(collapse, 100);
                    }
                });
            });

            backdrop.addEventListener('click', collapse);

            // Scroll active item into view
            const activeItem = sidebar.querySelector('.admin-nav-item.active');
            if (activeItem) activeItem.scrollIntoView({ block: 'nearest' });
        }

        const content = document.getElementById('adminContent');
        const renderer = renderers[section];
        if (renderer) renderer(content);
        else content.innerHTML = '<div class="empty-state"><div class="empty-state-icon">🔍</div><div class="empty-state-text">الصفحة غير موجودة</div></div>';
    };

    const renderers = {};

    /** Helper: init DataTable with SSR */
    function initSSRTable(tableId, ajaxUrl, dtColumns, opts = {}) {
        activeTable = $(`#${tableId}`).DataTable({
            processing: true,
            serverSide: true,
            ajax: ajaxUrl,
            columns: dtColumns,
            order: opts.order || [[0, 'desc']],
            pageLength: opts.pageLength || 15,
            language: {
                processing: 'جارٍ التحميل...',
                lengthMenu: 'عرض _MENU_ سجل',
                zeroRecords: 'لا توجد نتائج',
                info: 'عرض _START_ إلى _END_ من _TOTAL_ سجل',
                infoEmpty: 'لا توجد بيانات',
                infoFiltered: '(تصفية من _MAX_ سجل)',
                search: '🔍 بحث:',
                paginate: { first: 'الأول', last: 'الأخير', next: 'التالي ←', previous: '→ السابق' },
            },
            dom: '<"dt-top"lf>rt<"dt-bottom"ip>',
            drawCallback: opts.drawCallback || null,
        });
        return activeTable;
    }

    // ==========================================
    // Dashboard
    // ==========================================
    renderers.dashboard = async (el) => {
        try {
            const data = await api('/api/users.php?action=dashboard');
            const s = data.stats;
            el.innerHTML = `
            <div class="admin-page-title">📊 لوحة التحكم</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;">
                ${statCard('👥', 'المستخدمون', s.total_users)}
                ${statCard('🛒', 'إجمالي الطلبات', s.total_orders)}
                ${statCard('📋', 'طلبات معلقة', s.pending_orders, 'var(--warning)')}
                ${statCard('💰', 'شحن معلق', s.pending_topups, 'var(--warning)')}
                ${statCard('📅', 'طلبات اليوم', s.today_orders)}
                ${statCard('💵', 'إجمالي الإيرادات', '$' + parseFloat(s.total_revenue).toFixed(2), 'var(--success)')}
            </div>`;
        } catch (err) {
            el.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
        }
    };

    function statCard(icon, label, value, color = 'var(--primary)') {
        return `<div class="card card-no-hover"><div class="card-body" style="text-align:center;padding:1.5rem;">
            <div style="font-size:1.5rem;">${icon}</div>
            <div style="font-size:2rem;font-weight:700;color:${color};margin:0.5rem 0;">${esc(value)}</div>
            <div style="font-size:0.9rem;color:var(--text-secondary);">${label}</div>
        </div></div>`;
    }

    // ==========================================
    // Categories — DataTables SSR
    // ==========================================
    renderers.categories = (el) => {
        el.innerHTML = `
        <div class="admin-page-title">📁 التصنيفات <button class="btn btn-primary btn-sm" id="addCatBtn">+ إضافة تصنيف</button></div>
        <div class="card card-no-hover"><div class="card-body" style="overflow-x:auto;">
            <table id="catsTable" class="display" style="width:100%">
                <thead><tr><th>#</th><th>صورة</th><th>الاسم</th><th>الترتيب</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            </table>
        </div></div>`;

        initSSRTable('catsTable', '/api/categories.php?action=list', [
            { title: '#' },
            { title: 'صورة', orderable: false, searchable: false },
            { title: 'الاسم' },
            { title: 'الترتيب' },
            { title: 'الحالة', orderable: false },
            { title: 'إجراءات', orderable: false, searchable: false },
        ], {
            order: [[3, 'asc']],
            drawCallback: () => {
                el.querySelectorAll('.edit-cat').forEach(btn => btn.addEventListener('click', async () => {
                    const d = await api(`/api/categories.php?action=get&id=${encodeURIComponent(btn.dataset.id)}`);
                    showCategoryModal(d.category);
                }));
                el.querySelectorAll('.del-cat').forEach(btn => btn.addEventListener('click', async () => {
                    if (!confirm('هل أنت متأكد من حذف هذا التصنيف؟')) return;
                    await api('/api/categories.php?action=delete', { method: 'POST', body: { id: btn.dataset.id } });
                    toast('تم حذف التصنيف');
                    activeTable.ajax.reload(null, false);
                }));
            },
        });

        el.querySelector('#addCatBtn')?.addEventListener('click', () => showCategoryModal());
    };

    async function showCategoryModal(cat = null, externalRefresh = null) {
        // Fetch all categories for parent dropdown (only top-level ones as options)
        const catsData = await api('/api/categories.php?action=list');
        const allCats = catsData.categories || [];
        // Only allow top-level categories as parents (prevent >1 level nesting) and exclude self
        const parentOptions = allCats
            .filter(c => (!c.parent_id || c.parent_id == 0) && (!cat || c.id != cat.id))
            .map(c => `<option value="${esc(c.id)}" ${cat?.parent_id == c.id ? 'selected' : ''}>${esc(c.name)}</option>`)
            .join('');

        showModal('التصنيف', `
            <input type="hidden" name="id" value="${esc(cat?.id || '')}">
            <div class="form-group"><label class="form-label">الاسم</label><input type="text" class="form-input" name="name" value="${esc(cat?.name || '')}" required></div>
            <div class="form-group"><label class="form-label">الوصف</label><textarea class="form-textarea" name="description">${esc(cat?.description || '')}</textarea></div>
            <div class="form-group"><label class="form-label">صورة</label><input type="file" class="form-input" name="image" accept="image/*"></div>
            <div class="form-group"><label class="form-label">التصنيف الأب</label><select class="form-select" name="parent_id"><option value="0">بدون (تصنيف رئيسي)</option>${parentOptions}</select></div>
            <div class="form-group"><label class="form-label">الترتيب</label><input type="number" class="form-input" name="sort_order" value="${esc(cat?.sort_order || 0)}"></div>
            ${cat?.id ? `<div class="form-group"><label class="form-label">الحالة</label><select class="form-select" name="is_active"><option value="1" ${cat.is_active ? 'selected' : ''}>مفعّل</option><option value="0" ${!cat.is_active ? 'selected' : ''}>معطّل</option></select></div>` : ''}
        `, async (fd) => {
            fd.append('csrf_token', APP.csrf);
            const action = cat?.id ? 'update' : 'create';
            const res = await fetch(`/api/categories.php?action=${action}`, { method: 'POST', headers: { 'X-CSRF-TOKEN': APP.csrf }, body: fd });
            const d = await res.json();
            if (!d.success) throw new Error(d.error);
            toast(cat?.id ? 'تم تحديث التصنيف' : 'تم إنشاء التصنيف');
            if (externalRefresh) externalRefresh();
            else if (activeTable) activeTable.ajax.reload(null, false);
        }, true);
    }

    // ==========================================
    // Items — DataTables SSR
    // ==========================================
    renderers.items = async (el) => {
        // Fetch categories for the dropdown (small data set, fine to fetch all)
        const catsData = await api('/api/categories.php?action=list');
        const cats = catsData.categories || [];

        el.innerHTML = `
        <div class="admin-page-title">📦 المنتجات <button class="btn btn-primary btn-sm" id="addItemBtn">+ إضافة منتج</button></div>
        <div class="card card-no-hover"><div class="card-body" style="overflow-x:auto;">
            <table id="itemsTable" class="display" style="width:100%">
                <thead><tr><th>#</th><th>صورة</th><th>الاسم</th><th>التصنيف</th><th>السعر</th><th>التسليم</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            </table>
        </div></div>`;

        initSSRTable('itemsTable', '/api/items.php?action=list', [
            { title: '#' },
            { title: 'صورة', orderable: false, searchable: false },
            { title: 'الاسم' },
            { title: 'التصنيف' },
            { title: 'السعر' },
            { title: 'التسليم', orderable: false },
            { title: 'الحالة', orderable: false },
            { title: 'إجراءات', orderable: false, searchable: false },
        ], {
            drawCallback: () => {
                el.querySelectorAll('.edit-item').forEach(btn => btn.addEventListener('click', async () => {
                    const [d, f] = await Promise.all([
                        api(`/api/items.php?action=get&id=${encodeURIComponent(btn.dataset.id)}`),
                        api(`/api/items.php?action=fields&id=${encodeURIComponent(btn.dataset.id)}`),
                    ]);
                    showItemModal({ ...d.item, fields: f.fields, price_options: d.price_options || [] }, cats);
                }));
                el.querySelectorAll('.stock-item').forEach(btn => btn.addEventListener('click', () => {
                    showStockModal(btn.dataset.id);
                }));
                el.querySelectorAll('.del-item').forEach(btn => btn.addEventListener('click', async () => {
                    if (!confirm('هل أنت متأكد من حذف هذا المنتج؟')) return;
                    await api('/api/items.php?action=delete', { method: 'POST', body: { id: btn.dataset.id } });
                    toast('تم حذف المنتج');
                    activeTable.ajax.reload(null, false);
                }));
            },
        });

        el.querySelector('#addItemBtn')?.addEventListener('click', () => showItemModal(null, cats));
    };

    function showItemModal(item = null, cats = [], externalRefresh = null) {
        const fields = item?.fields || [];
        const priceOptions = item?.price_options || [];
        const catOptions = cats.map(c => `<option value="${esc(c.id)}" ${item?.category_id == c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('');

        let fieldsHtml = '';
        fields.forEach((f, i) => {
            fieldsHtml += fieldRowHtml(i, f);
        });

        let priceOptionsHtml = '';
        priceOptions.forEach((po, i) => {
            priceOptionsHtml += priceOptionRowHtml(i, po);
        });

        const dtypes = [['manual', 'يدوي'], ['auto_stock', 'مخزون تلقائي'], ['api_json', 'ربط API']];
        const dtypeOptions = dtypes.map(([v, l]) => `<option value="${v}" ${item?.delivery_type === v ? 'selected' : ''}>${l}</option>`).join('');

        showModal('المنتج', `
            <input type="hidden" name="id" value="${esc(item?.id || '')}">
            <div class="form-group"><label class="form-label">التصنيف</label><select class="form-select" name="category_id" required><option value="">اختر</option>${catOptions}</select></div>
            <div class="form-group"><label class="form-label">الاسم</label><input type="text" class="form-input" name="name" value="${esc(item?.name || '')}" required></div>
            <div class="form-group"><label class="form-label">الوصف</label><textarea class="form-textarea" name="description">${esc(item?.description || '')}</textarea></div>

            <div style="margin:1rem 0;border:1px solid var(--border);border-radius:10px;padding:1rem;background:var(--bg-secondary);">
                <label class="form-label" style="font-weight:600;margin-bottom:0.75rem;">💲 نوع التسعير</label>
                <div style="display:flex;gap:0.5rem;margin-bottom:1rem;" id="pricingTypeToggle">
                    <button type="button" class="btn btn-sm pricing-type-btn ${(!item?.pricing_type || item.pricing_type === 'fixed') ? 'btn-primary' : 'btn-outline'}" data-pt="fixed">ثابت</button>
                    <button type="button" class="btn btn-sm pricing-type-btn ${item?.pricing_type === 'calculated' ? 'btn-primary' : 'btn-outline'}" data-pt="calculated">محسوب</button>
                    <button type="button" class="btn btn-sm pricing-type-btn ${item?.pricing_type === 'options' ? 'btn-primary' : 'btn-outline'}" data-pt="options">خيارات</button>
                </div>
                <input type="hidden" name="pricing_type" id="pricingTypeInput" value="${esc(item?.pricing_type || 'fixed')}">

                <div id="pricingFixed" style="display:${(!item?.pricing_type || item.pricing_type === 'fixed') ? 'block' : 'none'}">
                    <div class="form-group" style="margin-bottom:0;"><label class="form-label">السعر ($)</label><input type="number" class="form-input" name="price" step="0.01" value="${esc(item?.price || 0)}"></div>
                </div>

                <div id="pricingCalculated" style="display:${item?.pricing_type === 'calculated' ? 'block' : 'none'}">
                    <div class="form-group"><label class="form-label">السعر المرجعي ($)</label><input type="number" class="form-input" name="calc_base_price" step="0.01" value="${esc(item?.price || 0)}"></div>
                    <div class="form-group"><label class="form-label">الحقل المرتبط</label><select class="form-select" name="calc_field_id" id="calcFieldSelect"><option value="">اختر حقل</option></select></div>
                    <div style="display:flex;gap:0.5rem;">
                        <div class="form-group" style="flex:1;"><label class="form-label">العملية</label><select class="form-select" name="calc_operator">${['+','-','*','/'].map(op => '<option value="' + op + '" ' + (item?.calc_operator === op ? 'selected' : '') + '>' + op + '</option>').join('')}</select></div>
                        <div class="form-group" style="flex:1;"><label class="form-label">القيمة</label><input type="number" class="form-input" name="calc_value" step="0.01" value="${esc(item?.calc_value || 0)}"></div>
                    </div>
                    <div style="font-size:0.8rem;color:var(--text-muted);padding:0.5rem;background:var(--bg);border-radius:6px;">🔢 معادلة: قيمة_الحقل <span id="calcPreviewOp">${esc(item?.calc_operator||'*')}</span> <span id="calcPreviewVal">${esc(item?.calc_value||0)}</span> = السعر النهائي</div>
                </div>

                <div id="pricingOptions" style="display:${item?.pricing_type === 'options' ? 'block' : 'none'}">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                        <label class="form-label" style="margin:0;">خيارات السعر</label>
                        <button type="button" class="btn btn-outline btn-sm" id="addPriceOptionBtn">+ خيار جديد</button>
                    </div>
                    <div id="priceOptionsContainer">${priceOptionsHtml}</div>
                </div>
            </div>

            <div class="form-group"><label class="form-label">صورة</label><input type="file" class="form-input" name="image" accept="image/*"></div>
            <div class="form-group"><label class="form-label">نوع التسليم</label><select class="form-select" name="delivery_type" id="deliveryTypeSelect">${dtypeOptions}</select></div>
            <div class="form-group" id="apiUrlGroup" style="display:${item?.delivery_type === 'api_json' ? 'block' : 'none'}"><label class="form-label">رابط API</label><input type="url" class="form-input" name="api_url" value="${esc(item?.api_url || '')}" placeholder="https://example.com/api/get?param={اسم_الحقل}"><div id="apiUrlHint" class="api-url-hint" style="font-size:0.75rem;color:var(--text-muted);margin-top:0.35rem;display:none;"></div></div>
            <div class="form-group"><label class="form-label">مدة إلغاء الطلب (ثواني)</label><input type="number" class="form-input" name="cancel_window_seconds" value="${esc(item?.cancel_window_seconds || 0)}"></div>
            <div class="form-group"><label class="form-label">للتواصل فقط</label><select class="form-select" name="contact_only"><option value="0" ${!item?.contact_only ? 'selected' : ''}>لا</option><option value="1" ${item?.contact_only ? 'selected' : ''}>نعم</option></select></div>
            <div class="form-group"><label class="form-label">الترتيب</label><input type="number" class="form-input" name="sort_order" value="${esc(item?.sort_order || 0)}"></div>
            ${item?.id ? `<div class="form-group"><label class="form-label">الحالة</label><select class="form-select" name="is_active"><option value="1" ${item.is_active ? 'selected' : ''}>مفعّل</option><option value="0" ${!item.is_active ? 'selected' : ''}>معطّل</option></select></div>` : ''}
            <div style="margin-top:1.5rem;border-top:1px solid var(--border);padding-top:1rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <label class="form-label" style="margin:0;font-weight:600;">📝 حقول مخصصة</label>
                    <button type="button" class="btn btn-outline btn-sm" id="addFieldBtn">+ حقل جديد</button>
                </div>
                <div id="fieldsContainer">${fieldsHtml}</div>
            </div>
        `, async (fd) => {
            const fieldEls = document.querySelectorAll('.custom-field-row');
            const fieldsArr = [];
            fieldEls.forEach(row => {
                fieldsArr.push({
                    id: row.dataset.fieldId || '',
                    label: row.querySelector('[data-field="label"]').value,
                    type: row.querySelector('[data-field="type"]').value,
                    is_required: row.querySelector('[data-field="required"]').value,
                    prefix: row.querySelector('[data-field="prefix"]')?.value || '',
                    suffix: row.querySelector('[data-field="suffix"]')?.value || '',
                });
            });
            // Serialize price_options for 'options' pricing type
            const ptVal = document.getElementById('pricingTypeInput')?.value || 'fixed';
            if (ptVal === 'options') {
                const optEls = document.querySelectorAll('.price-option-row');
                const optsArr = [];
                optEls.forEach(row => {
                    optsArr.push({
                        label: row.querySelector('[data-opt="label"]').value,
                        price: parseFloat(row.querySelector('[data-opt="price"]').value) || 0,
                        api_url: row.querySelector('[data-opt="api_url"]')?.value || '',
                    });
                });
                fd.append('price_options', JSON.stringify(optsArr));
            }
            // Ensure correct price is sent based on pricing type
            if (ptVal === 'calculated') {
                const calcPrice = document.querySelector('[name="calc_base_price"]')?.value || '0';
                fd.delete('price');
                fd.set('calc_base_price', calcPrice);
            } else {
                const fixedPrice = document.querySelector('#pricingFixed [name="price"]')?.value || '0';
                fd.delete('price');
                fd.set('price', fixedPrice);
            }
            fd.append('fields', JSON.stringify(fieldsArr));
            fd.append('csrf_token', APP.csrf);
            const action = item?.id ? 'update' : 'create';
            const res = await fetch(`/api/items.php?action=${action}`, { method: 'POST', headers: { 'X-CSRF-TOKEN': APP.csrf }, body: fd });
            const d = await res.json();
            if (!d.success) throw new Error(d.error);
            toast(item?.id ? 'تم تحديث المنتج' : 'تم إنشاء المنتج');
            if (externalRefresh) externalRefresh();
            else if (activeTable) activeTable.ajax.reload(null, false);
        }, true);

        // Toggle api_url visibility and per-option URL inputs
        function toggleOptApiUrls() {
            const isApi = document.getElementById('deliveryTypeSelect')?.value === 'api_json';
            document.querySelectorAll('.opt-api-url').forEach(el => {
                el.style.display = isApi ? 'block' : 'none';
            });
            document.querySelectorAll('.opt-api-url-hint').forEach(el => {
                el.style.display = isApi ? 'block' : 'none';
            });
            refreshApiUrlHints();
        }
        // Build and display available {field_label} placeholders for API URL inputs
        function refreshApiUrlHints() {
            const isApi = document.getElementById('deliveryTypeSelect')?.value === 'api_json';
            const hintEl = document.getElementById('apiUrlHint');
            if (!isApi) {
                if (hintEl) hintEl.style.display = 'none';
                return;
            }
            const labels = [];
            document.querySelectorAll('.custom-field-row [data-field="label"]').forEach(el => {
                const lbl = el.value.trim();
                if (lbl) labels.push(lbl);
            });
            const hintText = labels.length
                ? '💡 متغيرات متاحة: ' + labels.map(l => `<code style="background:var(--bg);padding:0.1rem 0.35rem;border-radius:4px;font-size:0.7rem;">{${esc(l)}}</code>`).join(' ')
                : '💡 أضف حقول مخصصة لاستخدامها كمتغيرات في الرابط';
            if (hintEl) {
                hintEl.innerHTML = hintText;
                hintEl.style.display = 'block';
            }
            document.querySelectorAll('.opt-api-url-hint').forEach(el => {
                el.innerHTML = hintText;
            });
        }
        document.getElementById('deliveryTypeSelect')?.addEventListener('change', (e) => {
            document.getElementById('apiUrlGroup').style.display = e.target.value === 'api_json' ? 'block' : 'none';
            toggleOptApiUrls();
        });
        // Set initial visibility
        toggleOptApiUrls();

        let fieldIndex = fields.length;
        document.getElementById('addFieldBtn')?.addEventListener('click', () => {
            const container = document.getElementById('fieldsContainer');
            container.insertAdjacentHTML('beforeend', fieldRowHtml(fieldIndex++));
            refreshCalcFieldDropdown();
            refreshApiUrlHints();
        });
        document.getElementById('fieldsContainer')?.addEventListener('click', (e) => {
            if (e.target.closest('.remove-field-btn')) {
                e.target.closest('.custom-field-row').remove();
                refreshCalcFieldDropdown();
                refreshApiUrlHints();
            }
        });

        // v6: Refresh calc field dropdown from current custom fields in DOM
        function refreshCalcFieldDropdown() {
            const sel = document.getElementById('calcFieldSelect');
            if (!sel) return;
            const currentVal = sel.value;
            let html = '<option value="">اختر حقل</option>';
            // Calc field is referenced by position; saveItemFields maps _pos_N to the field saved at index N
            const domRows = document.querySelectorAll('.custom-field-row');
            domRows.forEach((row, i) => {
                const label = row.querySelector('[data-field="label"]')?.value?.trim();
                const type = row.querySelector('[data-field="type"]')?.value || 'text';
                if (label) {
                    html += `<option value="_pos_${i}">${esc(label)} (${esc(type)})</option>`;
                }
            });
            sel.innerHTML = html;
            // Restore selection
            if (currentVal) sel.value = currentVal;
        }
        refreshCalcFieldDropdown();

        // Pre-select the correct position for existing calc_field_id
        if (item?.calc_field_id && fields.length > 0) {
            const posIdx = fields.findIndex(f => f.id == item.calc_field_id);
            if (posIdx >= 0) {
                const sel = document.getElementById('calcFieldSelect');
                if (sel) sel.value = `_pos_${posIdx}`;
            }
        }

        // Update dropdown + API URL hints when field labels change
        document.getElementById('fieldsContainer')?.addEventListener('input', (e) => {
            if (e.target.matches('[data-field="label"]')) { refreshCalcFieldDropdown(); refreshApiUrlHints(); }
        });

        // v6: Pricing type toggle
        document.getElementById('pricingTypeToggle')?.addEventListener('click', (e) => {
            const btn = e.target.closest('.pricing-type-btn');
            if (!btn) return;
            const pt = btn.dataset.pt;
            document.getElementById('pricingTypeInput').value = pt;
            document.querySelectorAll('.pricing-type-btn').forEach(b => { b.classList.remove('btn-primary'); b.classList.add('btn-outline'); });
            btn.classList.remove('btn-outline'); btn.classList.add('btn-primary');
            document.getElementById('pricingFixed').style.display = pt === 'fixed' ? 'block' : 'none';
            document.getElementById('pricingCalculated').style.display = pt === 'calculated' ? 'block' : 'none';
            document.getElementById('pricingOptions').style.display = pt === 'options' ? 'block' : 'none';
        });

        // Calc formula preview
        document.querySelector('[name="calc_operator"]')?.addEventListener('change', (e) => {
            const prev = document.getElementById('calcPreviewOp');
            if (prev) prev.textContent = e.target.value;
        });
        document.querySelector('[name="calc_value"]')?.addEventListener('input', (e) => {
            const prev = document.getElementById('calcPreviewVal');
            if (prev) prev.textContent = e.target.value;
        });

        // Price options repeater
        let optIndex = (item?.price_options || []).length;
        document.getElementById('addPriceOptionBtn')?.addEventListener('click', () => {
            const c = document.getElementById('priceOptionsContainer');
            c.insertAdjacentHTML('beforeend', priceOptionRowHtml(optIndex++));
            toggleOptApiUrls();
        });
        document.getElementById('priceOptionsContainer')?.addEventListener('click', (e) => {
            if (e.target.closest('.remove-opt-btn')) {
                e.target.closest('.price-option-row').remove();
            }
        });
    }

    async function showStockModal(itemId) {
        const data = await api(`/api/items.php?action=stock_list&item_id=${encodeURIComponent(itemId)}`);
        const stock = data.stock || [];
        const now = new Date().toISOString();

        let stockListHtml = '';
        for (const s of stock) {
            const expired = s.expires_at && s.expires_at < now;
            let statusBadge;
            if (s.is_sold) statusBadge = '<span class="badge badge-canceled">مباع</span>';
            else if (expired) statusBadge = '<span class="badge badge-canceled">منتهي</span>';
            else statusBadge = '<span class="badge badge-accepted">متوفر</span>';

            const typeBadge = s.image_path ? '<span class="badge badge-delivering" style="font-size:0.7rem;">🖼️</span>' : '';
            const delBtn = !s.is_sold ? `<button class="btn btn-danger btn-sm del-stock" data-id="${esc(s.id)}" style="padding:0.15rem 0.5rem;">✕</button>` : '';

            let preview = '';
            if (s.content) preview += `<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(s.content.substring(0, 80))}${s.content.length > 80 ? '...' : ''}</span>`;
            if (safeImg(s.image_path)) preview += `<img src="${esc(safeImg(s.image_path))}" style="width:32px;height:32px;object-fit:cover;border-radius:4px;">`;

            let expiryInfo = s.expires_at ? `<span style="font-size:0.7rem;color:var(--text-muted);">${esc(String(s.expires_at).substring(0, 10))}</span>` : '';

            stockListHtml += `<div style="display:flex;align-items:center;gap:0.5rem;padding:0.4rem 0;border-bottom:1px solid var(--border);font-size:0.85rem;">
                ${statusBadge} ${typeBadge}
                ${preview || '<span style="color:var(--text-muted);">-</span>'}
                ${expiryInfo}
                ${delBtn}
            </div>`;
        }

        showModal(`📦 المخزون (${data.available} متوفر / ${data.total} إجمالي)`, `
            <div style="border-bottom:1px solid var(--border);padding-bottom:1rem;margin-bottom:1rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <label class="form-label" style="margin:0;font-weight:600;">➕ إضافة عناصر جديدة</label>
                    <button type="button" class="btn btn-outline btn-sm" id="addStockRowBtn">+ عنصر</button>
                </div>
                <div id="stockRepeater"></div>
            </div>
            <div style="max-height:300px;overflow-y:auto;">
                <label class="form-label" style="font-weight:600;margin-bottom:0.5rem;">العناصر الحالية</label>
                ${stockListHtml || '<div style="color:var(--text-muted);text-align:center;padding:1rem;">لا توجد عناصر في المخزون</div>'}
            </div>
        `, async (fd) => {
            const rows = document.querySelectorAll('.stock-repeater-row');
            if (rows.length === 0) return;

            const submitFd = new FormData();
            submitFd.append('item_id', itemId);
            submitFd.append('stock_count', rows.length);
            submitFd.append('csrf_token', APP.csrf);

            let i = 0;
            for (const row of rows) {
                submitFd.append(`stock_content_${i}`, row.querySelector('[data-stock="content"]').value);
                submitFd.append(`stock_expires_${i}`, row.querySelector('[data-stock="expires"]').value);
                const imgFile = row.querySelector('[data-stock="image"]').files[0];
                if (imgFile) submitFd.append(`stock_image_${i}`, await compressImage(imgFile));
                i++;
            }

            const res = await fetch('/api/items.php?action=stock_add', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': APP.csrf },
                body: submitFd,
            });
            const d = await res.json();
            if (!d.success) throw new Error(d.error);
            toast(d.message || 'تم إضافة العناصر');
            if (activeTable) activeTable.ajax.reload(null, false);
        });

        let stockRowIndex = 0;

        function addStockRow() {
            const container = document.getElementById('stockRepeater');
            const idx = stockRowIndex++;
            container.insertAdjacentHTML('beforeend', `
                <div class="stock-repeater-row" style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:8px;padding:0.75rem;margin-bottom:0.5rem;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
                        <span style="font-weight:500;font-size:0.85rem;">عنصر #${idx + 1}</span>
                        <button type="button" class="btn btn-danger btn-sm btn-icon remove-stock-row" style="padding:0.15rem 0.4rem;">✕</button>
                    </div>
                    <textarea data-stock="content" class="form-textarea" rows="3" placeholder="المحتوى (نص، رابط، كود... يمكن أكثر من سطر)" style="margin-bottom:0.5rem;"></textarea>
                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                        <div style="flex:1;min-width:140px;">
                            <label style="font-size:0.75rem;color:var(--text-muted);">🖼️ صورة (اختياري)</label>
                            <input type="file" data-stock="image" accept="image/*" class="form-input" style="font-size:0.8rem;">
                        </div>
                        <div style="flex:1;min-width:140px;">
                            <label style="font-size:0.75rem;color:var(--text-muted);">⏰ تاريخ الانتهاء (اختياري)</label>
                            <input type="datetime-local" data-stock="expires" class="form-input" style="font-size:0.8rem;">
                        </div>
                    </div>
                </div>
            `);
        }

        // Add first row
        addStockRow();

        document.getElementById('addStockRowBtn')?.addEventListener('click', addStockRow);

        // Remove rows
        document.getElementById('stockRepeater')?.addEventListener('click', (e) => {
            if (e.target.closest('.remove-stock-row')) {
                e.target.closest('.stock-repeater-row').remove();
            }
        });

        // Delete existing stock items
        document.querySelector('.modal')?.addEventListener('click', async (e) => {
            const btn = e.target.closest('.del-stock');
            if (!btn) return;
            await api('/api/items.php?action=stock_delete', { method: 'POST', body: { id: btn.dataset.id } });
            btn.closest('div').remove();
            toast('تم حذف العنصر');
        });
    }

    function fieldRowHtml(index, field = null) {
        const types = [['text', 'نص'], ['email', 'بريد إلكتروني'], ['phone', 'هاتف'], ['textarea', 'نص طويل'], ['image', 'صورة']];
        const typeOptions = types.map(([v, l]) => `<option value="${v}" ${field?.type === v ? 'selected' : ''}>${l}</option>`).join('');
        return `<div class="custom-field-row" data-field-id="${esc(field?.id || '')}" style="display:flex;gap:0.5rem;margin-bottom:0.5rem;align-items:center;flex-wrap:wrap;">
            <input type="text" class="form-input" data-field="label" placeholder="عنوان الحقل" value="${esc(field?.label || '')}" style="flex:2;min-width:120px;">
            <select class="form-select" data-field="type" style="flex:1;min-width:100px;">${typeOptions}</select>
            <select class="form-select" data-field="required" style="flex:0.7;min-width:80px;"><option value="1" ${field?.is_required !== 0 ? 'selected' : ''}>مطلوب</option><option value="0" ${field?.is_required === 0 ? 'selected' : ''}>اختياري</option></select>
            <input type="text" class="form-input" data-field="prefix" placeholder="بادئة" value="${esc(field?.prefix || '')}" style="flex:0.6;min-width:60px;max-width:80px;">
            <input type="text" class="form-input" data-field="suffix" placeholder="لاحقة" value="${esc(field?.suffix || '')}" style="flex:0.6;min-width:60px;max-width:80px;">
            <button type="button" class="btn btn-danger btn-sm btn-icon remove-field-btn" title="حذف">✕</button>
        </div>`;
    }

    function priceOptionRowHtml(index, opt = null) {
        return `<div class="price-option-row" style="margin-bottom:0.5rem;">
            <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                <input type="text" class="form-input" data-opt="label" placeholder="التسمية (مثلاً: 1 شهر)" value="${esc(opt?.label || '')}" style="flex:2;min-width:120px;">
                <input type="number" class="form-input" data-opt="price" placeholder="السعر ($)" step="0.01" min="0.01" value="${esc(opt?.price || '')}" style="flex:1;min-width:80px;">
                <input type="url" class="form-input opt-api-url" data-opt="api_url" placeholder="رابط API (اختياري) — يدعم {اسم_الحقل}" value="${esc(opt?.api_url || '')}" style="flex:2;min-width:150px;display:none;">
                <button type="button" class="btn btn-danger btn-sm btn-icon remove-opt-btn" title="حذف">✕</button>
            </div>
            <div class="opt-api-url-hint" style="font-size:0.75rem;color:var(--text-muted);margin-top:0.2rem;display:none;"></div>
        </div>`;
    }

    // ==========================================
    // Orders — DataTables SSR
    // ==========================================
    renderers.orders = (el) => {
        el.innerHTML = `
        <div class="admin-page-title">🛒 الطلبات</div>
        <div class="card card-no-hover"><div class="card-body" style="overflow-x:auto;">
            <table id="ordersTable" class="display" style="width:100%">
                <thead><tr><th>#</th><th>المستخدم</th><th>المنتج</th><th>السعر</th><th>الحالة</th><th>التاريخ</th><th>إجراءات</th></tr></thead>
            </table>
        </div></div>`;

        initSSRTable('ordersTable', '/api/orders.php?action=admin_list', [
            { title: '#' },
            { title: 'المستخدم' },
            { title: 'المنتج' },
            { title: 'السعر' },
            { title: 'الحالة' },
            { title: 'التاريخ' },
            { title: 'إجراءات', orderable: false, searchable: false },
        ], {
            drawCallback: () => {
                el.querySelectorAll('.accept-order').forEach(btn => btn.addEventListener('click', async () => {
                    await api('/api/orders.php?action=admin_accept', { method: 'POST', body: { order_id: btn.dataset.id } });
                    toast('تم قبول الطلب');
                    activeTable.ajax.reload(null, false);
                }));
                el.querySelectorAll('.cancel-order').forEach(btn => btn.addEventListener('click', async () => {
                    if (!confirm('هل أنت متأكد من إلغاء الطلب وإرجاع المبلغ؟')) return;
                    await api('/api/orders.php?action=admin_cancel', { method: 'POST', body: { order_id: btn.dataset.id } });
                    toast('تم إلغاء الطلب');
                    activeTable.ajax.reload(null, false);
                }));
            },
        });
    };

    // ==========================================
    // Users — DataTables SSR
    // ==========================================
    renderers.users = (el) => {
        el.innerHTML = `
        <div class="admin-page-title">👥 المستخدمون</div>
        <div class="card card-no-hover"><div class="card-body" style="overflow-x:auto;">
            <table id="usersTable" class="display" style="width:100%">
                <thead><tr><th>#</th><th>المستخدم</th><th>البريد</th><th>الدور</th><th>الرصيد</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            </table>
        </div></div>`;

        initSSRTable('usersTable', '/api/users.php?action=list', [
            { title: '#' },
            { title: 'المستخدم' },
            { title: 'البريد' },
            { title: 'الدور' },
            { title: 'الرصيد' },
            { title: 'الحالة', orderable: false },
            { title: 'إجراءات', orderable: false, searchable: false },
        ], {
            drawCallback: () => {
                el.querySelectorAll('.set-balance').forEach(btn => btn.addEventListener('click', () => {
                    const uid = btn.dataset.id;
                    const username = btn.dataset.username;
                    const currentBalance = btn.dataset.balance;
                    showModal(`💰 تعديل رصيد — ${username}`, `
                        <div class="form-group">
                            <label class="form-label">الرصيد الحالي</label>
                            <div style="font-size:1.25rem;font-weight:700;color:var(--primary);margin-bottom:0.5rem;">$${parseFloat(currentBalance).toFixed(2)}</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">الرصيد الجديد ($)</label>
                            <input type="number" class="form-input" name="balance" step="0.01" min="0" value="${parseFloat(currentBalance).toFixed(2)}" required>
                        </div>
                    `, async (fd) => {
                        const balance = parseFloat(fd.get('balance'));
                        await api('/api/users.php?action=set_balance', { method: 'POST', body: { id: uid, balance } });
                        toast('تم تحديث الرصيد');
                        activeTable.ajax.reload(null, false);
                    });
                }));
                el.querySelectorAll('.ban-user').forEach(btn => btn.addEventListener('click', async () => {
                    const reason = prompt('سبب الحظر (اختياري):');
                    if (reason === null) return;
                    await api('/api/users.php?action=ban', { method: 'POST', body: { id: btn.dataset.id, ban_reason: reason } });
                    toast('تم حظر المستخدم');
                    activeTable.ajax.reload(null, false);
                }));
                el.querySelectorAll('.unban-user').forEach(btn => btn.addEventListener('click', async () => {
                    await api('/api/users.php?action=unban', { method: 'POST', body: { id: btn.dataset.id } });
                    toast('تم إلغاء الحظر');
                    activeTable.ajax.reload(null, false);
                }));
                el.querySelectorAll('.set-password').forEach(btn => btn.addEventListener('click', () => {
                    const uid = btn.dataset.id;
                    const username = btn.dataset.username;
                    showModal(`🔑 تغيير كلمة المرور — ${username}`, `
                        <div class="form-group"><label class="form-label">كلمة المرور الجديدة</label><input type="password" class="form-input" name="password" minlength="6" required></div>
                        <div class="form-group"><label class="form-label">تأكيد كلمة المرور</label><input type="password" class="form-input" name="password_confirm" minlength="6" required></div>
                    `, async (fd) => {
                        const pw = fd.get('password');
                        const confirm = fd.get('password_confirm');
                        if (pw.length < 6) throw new Error('كلمة المرور يجب أن تكون 6 أحرف على الأقل');
                        if (pw !== confirm) throw new Error('كلمتا المرور غير متطابقتين');
                        await api('/api/users.php?action=set_password', { method: 'POST', body: { id: uid, password: pw } });
                        toast('تم تحديث كلمة المرور');
                    });
                }));
            },
        });
    };

    // ==========================================
    // Top-Up Methods (small data set — no SSR needed)
    // ==========================================
    renderers['topup-methods'] = async (el) => {
        const data = await api('/api/topup_methods.php?action=admin_list');
        const methods = data.methods || [];
        el.innerHTML = `
        <div class="admin-page-title">💳 طرق الشحن <button class="btn btn-primary btn-sm" id="addMethodBtn">+ إضافة طريقة</button></div>
        <div class="card card-no-hover"><div class="card-body" style="overflow-x:auto;">
            <table class="display" id="methodsTable" style="width:100%">
                <thead><tr><th>#</th><th>صورة</th><th>الاسم</th><th>التعليمات</th><th>الترتيب</th><th>الحالة</th><th>إجراءات</th></tr></thead>
                <tbody>
                ${methods.map(m => `<tr>
                    <td>${esc(m.id)}</td>
                    <td>${safeImg(m.image) ? `<img src="${esc(safeImg(m.image))}" style="width:50px;height:40px;object-fit:cover;border-radius:6px;">` : '-'}</td>
                    <td>${esc(m.name)}</td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(m.instructions || '')}</td>
                    <td>${esc(m.sort_order)}</td>
                    <td><span class="badge ${m.is_active ? 'badge-accepted' : 'badge-canceled'}">${m.is_active ? 'مفعّل' : 'معطّل'}</span></td>
                    <td>
                        <button class="btn btn-primary btn-sm edit-method" data-id="${esc(m.id)}" data-name="${esc(m.name)}" data-instructions="${esc(m.instructions || '')}" data-image="${esc(m.image || '')}" data-sort="${esc(m.sort_order)}" data-active="${esc(m.is_active)}" data-kyc="${esc(m.require_kyc || 0)}">✏️</button>
                        <button class="btn btn-danger btn-sm del-method" data-id="${esc(m.id)}">🗑</button>
                    </td>
                </tr>`).join('')}
                </tbody>
            </table>
        </div></div>`;

        // Client-side DataTable for small dataset
        activeTable = $('#methodsTable').DataTable({
            order: [[4, 'asc']],
            pageLength: 15,
            language: { search: '🔍 بحث:', zeroRecords: 'لا توجد نتائج', info: '_TOTAL_ سجل', paginate: { next: '←', previous: '→' } },
        });

        el.querySelector('#addMethodBtn')?.addEventListener('click', () => showMethodModal());
        el.querySelectorAll('.edit-method').forEach(btn => btn.addEventListener('click', () => {
            showMethodModal({ id: btn.dataset.id, name: btn.dataset.name, instructions: btn.dataset.instructions, image: btn.dataset.image, sort_order: btn.dataset.sort, is_active: btn.dataset.active, require_kyc: btn.dataset.kyc });
        }));
        el.querySelectorAll('.del-method').forEach(btn => btn.addEventListener('click', async () => {
            if (!confirm('حذف طريقة الشحن؟')) return;
            await api('/api/topup_methods.php?action=delete', { method: 'POST', body: { id: btn.dataset.id } });
            toast('تم الحذف');
            renderers['topup-methods'](el);
        }));
    };

    function showMethodModal(m = null) {
        showModal('طريقة الشحن', `
            <div class="form-group"><label class="form-label">الاسم</label><input type="text" class="form-input" name="name" value="${esc(m?.name || '')}" required></div>
            <div class="form-group"><label class="form-label">صورة (مثلاً QR كود)</label><input type="file" class="form-input" name="image" accept="image/*">${safeImg(m?.image) ? `<div style="margin-top:0.5rem;"><img src="${esc(safeImg(m.image))}" style="max-width:150px;border-radius:8px;"></div>` : ''}</div>
            <div class="form-group"><label class="form-label">التعليمات</label><textarea class="form-textarea" name="instructions">${esc(m?.instructions || '')}</textarea></div>
            <div class="form-group"><label class="form-label">الترتيب</label><input type="number" class="form-input" name="sort_order" value="${esc(m?.sort_order || 0)}"></div>
            <div class="form-group" style="display:flex;align-items:center;gap:0.5rem;"><input type="checkbox" name="require_kyc" value="1" ${m?.require_kyc == 1 ? 'checked' : ''} style="width:auto;"><label class="form-label" style="margin:0;">يتطلب توثيق KYC</label></div>
            ${m ? `<div class="form-group"><label class="form-label">الحالة</label><select class="form-select" name="is_active"><option value="1" ${m.is_active == 1 ? 'selected' : ''}>مفعّل</option><option value="0" ${m.is_active == 0 ? 'selected' : ''}>معطّل</option></select></div>` : ''}
        `, async (fd) => {
            if (m) fd.append('id', m.id);
            fd.append('csrf_token', APP.csrf);
            const action = m ? 'update' : 'create';
            const res = await fetch(`/api/topup_methods.php?action=${action}`, { method: 'POST', headers: { 'X-CSRF-TOKEN': APP.csrf }, body: fd });
            const d = await res.json();
            if (!d.success) throw new Error(d.error);
            toast(m ? 'تم التحديث' : 'تم الإنشاء');
            location.hash = '#/admin/topup-methods';
            window.renderAdminPanel('topup-methods');
        }, true);
    }

    // ==========================================
    // Top-Up Requests — DataTables SSR
    // ==========================================
    renderers['topup-requests'] = (el) => {
        el.innerHTML = `
        <div class="admin-page-title">💰 طلبات الشحن</div>
        <div class="card card-no-hover"><div class="card-body" style="overflow-x:auto;">
            <table id="topupReqTable" class="display" style="width:100%">
                <thead><tr><th>#</th><th>المستخدم</th><th>الطريقة</th><th>المبلغ</th><th>الإثبات</th><th>الحالة</th><th>التاريخ</th><th>إجراءات</th></tr></thead>
            </table>
        </div></div>`;

        initSSRTable('topupReqTable', '/api/topup_requests.php?action=list', [
            { title: '#' },
            { title: 'المستخدم' },
            { title: 'الطريقة' },
            { title: 'المبلغ' },
            { title: 'الإثبات', orderable: false, searchable: false },
            { title: 'الحالة' },
            { title: 'التاريخ' },
            { title: 'إجراءات', orderable: false, searchable: false },
        ], {
            drawCallback: () => {
                el.querySelectorAll('.accept-topup').forEach(btn => btn.addEventListener('click', async () => {
                    await api('/api/topup_requests.php?action=accept', { method: 'POST', body: { id: btn.dataset.id } });
                    toast('تم قبول الشحن');
                    activeTable.ajax.reload(null, false);
                }));
                el.querySelectorAll('.deny-topup').forEach(btn => btn.addEventListener('click', async () => {
                    if (!confirm('رفض طلب الشحن؟')) return;
                    await api('/api/topup_requests.php?action=deny', { method: 'POST', body: { id: btn.dataset.id } });
                    toast('تم رفض الشحن');
                    activeTable.ajax.reload(null, false);
                }));
            },
        });
    };

    // ==========================================
    // Carousel (small data set — card layout)
    // ==========================================
    renderers.carousel = async (el) => {
        const data = await api('/api/carousel.php?action=admin_list');
        const slides = data.slides || [];
        el.innerHTML = `
        <div class="admin-page-title">🖼 السلايدر <button class="btn btn-primary btn-sm" id="addSlideBtn">+ إضافة شريحة</button></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:1rem;">
            ${slides.map(s => `<div class="card">
                <img src="${esc(safeImg(s.image))}" style="width:100%;height:150px;object-fit:cover;">
                <div class="card-body">
                    <div style="font-weight:600;margin-bottom:0.25rem;">${esc(s.title || 'بدون عنوان')}</div>
                    <div style="font-size:0.85rem;color:var(--text-secondary);">ترتيب: ${esc(s.sort_order)} · ${s.is_active ? '🟢 مفعّل' : '🔴 معطّل'}</div>
                    <div style="display:flex;gap:0.5rem;margin-top:0.75rem;">
                        <button class="btn btn-primary btn-sm edit-slide" data-id="${esc(s.id)}" data-title="${esc(s.title || '')}" data-link="${esc(s.link || '')}" data-sort="${esc(s.sort_order)}" data-active="${esc(s.is_active)}">✏️</button>
                        <button class="btn btn-danger btn-sm del-slide" data-id="${esc(s.id)}">🗑</button>
                    </div>
                </div>
            </div>`).join('')}
        </div>`;

        el.querySelector('#addSlideBtn')?.addEventListener('click', () => showSlideModal());
        el.querySelectorAll('.edit-slide').forEach(btn => btn.addEventListener('click', () => {
            showSlideModal({ id: btn.dataset.id, title: btn.dataset.title, link: btn.dataset.link, sort_order: btn.dataset.sort, is_active: btn.dataset.active });
        }));
        el.querySelectorAll('.del-slide').forEach(btn => btn.addEventListener('click', async () => {
            if (!confirm('حذف الشريحة؟')) return;
            await api('/api/carousel.php?action=delete', { method: 'POST', body: { id: btn.dataset.id } });
            toast('تم الحذف');
            renderers.carousel(el);
        }));
    };

    function showSlideModal(s = null) {
        showModal('شريحة السلايدر', `
            <input type="hidden" name="id" value="${esc(s?.id || '')}">
            <div class="form-group"><label class="form-label">صورة ${!s ? '<span class="required">*</span>' : ''}</label><input type="file" class="form-input" name="image" accept="image/*" ${!s ? 'required' : ''}></div>
            <div class="form-group"><label class="form-label">العنوان</label><input type="text" class="form-input" name="title" value="${esc(s?.title || '')}"></div>
            <div class="form-group"><label class="form-label">الرابط</label><input type="text" class="form-input" name="link" value="${esc(s?.link || '')}" placeholder="https://..."></div>
            <div class="form-group"><label class="form-label">الترتيب</label><input type="number" class="form-input" name="sort_order" value="${esc(s?.sort_order || 0)}"></div>
            ${s ? `<div class="form-group"><label class="form-label">الحالة</label><select class="form-select" name="is_active"><option value="1" ${s.is_active == 1 ? 'selected' : ''}>مفعّل</option><option value="0" ${s.is_active == 0 ? 'selected' : ''}>معطّل</option></select></div>` : ''}
        `, async (fd) => {
            fd.append('csrf_token', APP.csrf);
            const action = s ? 'update' : 'create';
            const res = await fetch(`/api/carousel.php?action=${action}`, { method: 'POST', headers: { 'X-CSRF-TOKEN': APP.csrf }, body: fd });
            const d = await res.json();
            if (!d.success) throw new Error(d.error);
            toast(s ? 'تم التحديث' : 'تم الإنشاء');
            location.hash = '#/admin/carousel';
            window.renderAdminPanel('carousel');
        }, true);
    }

    // ==========================================
    // Currencies — v6
    // ==========================================
    renderers.currencies = async (el) => {
        const data = await api('/api/currencies.php?action=admin_list');
        const currencies = data.currencies || [];
        el.innerHTML = `
        <div class="admin-page-title">💱 العملات <button class="btn btn-primary btn-sm" id="addCurrencyBtn">+ إضافة عملة</button></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;">
            ${currencies.map(c => {
                const isApi = c.rate_mode === 'api';
                const modeBadge = isApi ? '<span class="badge badge-pending" style="font-size:0.7rem;">API</span>' : '<span class="badge" style="font-size:0.7rem;background:var(--bg-hover);">ثابت</span>';
                const lastUpdate = isApi && c.rate_updated_at ? `<div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.25rem;">آخر تحديث: ${esc(c.rate_updated_at)}</div>` : '';
                const adjustmentInfo = isApi && c.rate_adjustment ? `<span style="font-size:0.8rem;color:var(--text-muted);"> (${c.rate_adjustment > 0 ? '+' : ''}${esc(c.rate_adjustment)})</span>` : '';
                return `<div class="card card-no-hover">
                <div class="card-body">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
                        <div style="font-weight:700;font-size:1.1rem;">${esc(c.symbol)} ${esc(c.code)}</div>
                        <div style="display:flex;gap:0.35rem;align-items:center;">
                            ${modeBadge}
                            ${c.code === 'USD' ? '<span class="badge badge-accepted">الأساسية</span>' : `<span class="badge ${c.is_active ? 'badge-accepted' : 'badge-canceled'}">${c.is_active ? 'مفعّل' : 'معطّل'}</span>`}
                        </div>
                    </div>
                    <div style="font-size:0.9rem;color:var(--text-secondary);margin-bottom:0.5rem;">${esc(c.name)}</div>
                    <div style="font-size:0.85rem;color:var(--text-muted);margin-bottom:0.25rem;">سعر الصرف: <strong>1 USD = ${esc(c.rate)} ${esc(c.code)}</strong>${adjustmentInfo}</div>
                    ${lastUpdate}
                    <div style="display:flex;gap:0.5rem;margin-top:0.75rem;">
                        <button class="btn btn-primary btn-sm edit-currency" data-id="${esc(c.id)}" data-code="${esc(c.code)}" data-name="${esc(c.name)}" data-symbol="${esc(c.symbol)}" data-rate="${esc(c.rate)}" data-sort="${esc(c.sort_order)}" data-active="${esc(c.is_active)}" data-ratemode="${esc(c.rate_mode || 'fixed')}" data-rateurl="${esc(c.rate_url || '')}" data-ratepath="${esc(c.rate_json_path || '')}" data-rateadj="${esc(c.rate_adjustment || 0)}">✏️</button>
                        ${c.code !== 'USD' ? `<button class="btn btn-danger btn-sm del-currency" data-id="${esc(c.id)}">🗑</button>` : ''}
                    </div>
                </div>
            </div>`;
            }).join('')}
            ${currencies.length === 0 ? '<div class="empty-state"><div class="empty-state-icon">💱</div><div class="empty-state-text">لا توجد عملات</div></div>' : ''}
        </div>`;

        el.querySelector('#addCurrencyBtn')?.addEventListener('click', () => showCurrencyModal(null, el));
        el.querySelectorAll('.edit-currency').forEach(btn => btn.addEventListener('click', () => {
            showCurrencyModal({
                id: btn.dataset.id, code: btn.dataset.code, name: btn.dataset.name, symbol: btn.dataset.symbol,
                rate: btn.dataset.rate, sort_order: btn.dataset.sort, is_active: btn.dataset.active,
                rate_mode: btn.dataset.ratemode, rate_url: btn.dataset.rateurl, rate_json_path: btn.dataset.ratepath, rate_adjustment: btn.dataset.rateadj,
            }, el);
        }));
        el.querySelectorAll('.del-currency').forEach(btn => btn.addEventListener('click', async () => {
            if (!confirm('حذف العملة؟')) return;
            await api('/api/currencies.php?action=delete', { method: 'POST', body: { id: btn.dataset.id } });
            toast('تم الحذف');
            renderers.currencies(el);
        }));
    };

    function showCurrencyModal(c = null, parentEl = null) {
        const isUSD = c?.code === 'USD';
        const currentMode = c?.rate_mode || 'fixed';
        showModal('عملة', `
            <div class="form-group"><label class="form-label">رمز العملة (ISO)</label><input type="text" class="form-input" name="code" value="${esc(c?.code || '')}" ${isUSD || c ? 'readonly' : ''} placeholder="IQD" maxlength="5" required></div>
            <div class="form-group"><label class="form-label">الاسم</label><input type="text" class="form-input" name="name" value="${esc(c?.name || '')}" required placeholder="دينار عراقي"></div>
            <div class="form-group"><label class="form-label">الرمز</label><input type="text" class="form-input" name="symbol" value="${esc(c?.symbol || '')}" placeholder="د.ع"></div>
            ${!isUSD ? `
            <div class="form-group"><label class="form-label">مصدر السعر</label>
                <select class="form-select" name="rate_mode" id="currRateMode">
                    <option value="fixed" ${currentMode === 'fixed' ? 'selected' : ''}>ثابت (يدوي)</option>
                    <option value="api" ${currentMode === 'api' ? 'selected' : ''}>تلقائي (API)</option>
                </select>
            </div>
            <div id="fixedRateFields" style="display:${currentMode === 'fixed' ? 'block' : 'none'};">
                <div class="form-group"><label class="form-label">سعر الصرف (1 USD = ?)</label><input type="number" class="form-input" name="rate" step="0.0001" min="0.0001" value="${esc(c?.rate || 1)}" ${currentMode === 'api' ? 'disabled' : ''}></div>
            </div>
            <div id="apiRateFields" style="display:${currentMode === 'api' ? 'block' : 'none'};">
                <div class="form-group"><label class="form-label">رابط API</label><input type="url" class="form-input" name="rate_url" value="${esc(c?.rate_url || '')}" placeholder="https://open.er-api.com/v6/latest/USD"></div>
                <div class="form-group"><label class="form-label">مسار JSON (dot notation)</label><input type="text" class="form-input" name="rate_json_path" value="${esc(c?.rate_json_path || '')}" placeholder="rates.IQD"></div>
                <div class="form-group"><label class="form-label">تعديل السعر (يُضاف للسعر من API)</label><input type="number" class="form-input" name="rate_adjustment" step="0.01" value="${esc(c?.rate_adjustment || 0)}"></div>
                <div class="form-group"><button type="button" class="btn btn-outline btn-sm" id="testRateBtn">🔄 اختبار الاتصال</button><div id="testRateResult" style="margin-top:0.5rem;"></div></div>
            </div>` : `
            <div class="form-group"><label class="form-label">سعر الصرف</label><input type="number" class="form-input" name="rate" value="1" readonly></div>
            <input type="hidden" name="rate_mode" value="fixed">
            `}
            <div class="form-group"><label class="form-label">الترتيب</label><input type="number" class="form-input" name="sort_order" value="${esc(c?.sort_order || 0)}"></div>
            ${c && !isUSD ? `<div class="form-group"><label class="form-label">الحالة</label><select class="form-select" name="is_active"><option value="1" ${c.is_active == 1 ? 'selected' : ''}>مفعّل</option><option value="0" ${c.is_active == 0 ? 'selected' : ''}>معطّل</option></select></div>` : ''}
        `, async (fd) => {
            if (c) fd.append('id', c.id);
            fd.append('csrf_token', APP.csrf);
            const action = c ? 'update' : 'create';
            const res = await fetch(`/api/currencies.php?action=${action}`, { method: 'POST', headers: { 'X-CSRF-TOKEN': APP.csrf }, body: fd });
            const d = await res.json();
            if (!d.success) throw new Error(d.error);
            toast(c ? 'تم التحديث' : 'تم الإنشاء');
            if (parentEl) renderers.currencies(parentEl);
        });

        // Rate mode toggle
        const modeSelect = document.querySelector('#currRateMode');
        if (modeSelect) {
            modeSelect.addEventListener('change', () => {
                const isApi = modeSelect.value === 'api';
                document.querySelector('#fixedRateFields').style.display = isApi ? 'none' : 'block';
                document.querySelector('#apiRateFields').style.display = isApi ? 'block' : 'none';
                const rateInput = document.querySelector('#fixedRateFields input[name="rate"]');
                if (rateInput) rateInput.disabled = isApi;
            });
        }

        // Test rate button
        const testBtn = document.querySelector('#testRateBtn');
        if (testBtn) {
            testBtn.addEventListener('click', async () => {
                const resultDiv = document.querySelector('#testRateResult');
                const url = document.querySelector('input[name="rate_url"]')?.value || '';
                const jsonPath = document.querySelector('input[name="rate_json_path"]')?.value || '';
                const adjustment = parseFloat(document.querySelector('input[name="rate_adjustment"]')?.value || '0');

                if (!url || !jsonPath) {
                    resultDiv.innerHTML = '<span style="color:var(--danger);">⚠️ الرابط ومسار JSON مطلوبان</span>';
                    return;
                }

                testBtn.disabled = true;
                testBtn.textContent = '⏳ جارٍ الاختبار...';
                resultDiv.innerHTML = '';

                try {
                    const data = await api('/api/currencies.php?action=test_rate', {
                        method: 'POST',
                        body: { url, json_path: jsonPath, adjustment }
                    });
                    resultDiv.innerHTML = `<div style="padding:0.5rem 0.75rem;background:rgba(16,185,129,0.1);border-radius:8px;font-size:0.85rem;">
                        ✅ <strong>نجح الاتصال</strong><br>
                        سعر API: <strong>${esc(data.raw_rate)}</strong>
                        ${data.adjustment ? ` | تعديل: <strong>${data.adjustment > 0 ? '+' : ''}${esc(data.adjustment)}</strong>` : ''}
                        <br>السعر النهائي: <strong>${esc(data.final_rate)}</strong>
                    </div>`;
                } catch (err) {
                    resultDiv.innerHTML = `<span style="color:var(--danger);">❌ ${esc(err.message)}</span>`;
                } finally {
                    testBtn.disabled = false;
                    testBtn.textContent = '🔄 اختبار الاتصال';
                }
            });
        }
    }

    // ==========================================
    // Settings
    // ==========================================
    renderers.settings = async (el) => {
        const data = await api('/api/settings.php?action=get_all');
        const s = data.settings || {};
        el.innerHTML = `
        <div class="admin-page-title">⚙️ الإعدادات</div>
        <form id="settingsForm" enctype="multipart/form-data">
            <div class="card card-no-hover" style="margin-bottom:1.5rem;">
                <div class="card-body" style="padding:1.5rem;">
                    <h3 style="font-weight:600;margin-bottom:1rem;">🏪 الموقع</h3>
                    <div class="form-group"><label class="form-label">اسم الموقع</label><input type="text" class="form-input" name="site_name" value="${esc(s.site_name || '')}"></div>
                    <div class="form-group"><label class="form-label">شعار الشركة</label><input type="file" class="form-input" name="company_logo" accept="image/*">${safeImg(s.company_logo) ? `<div style="margin-top:0.5rem;"><img src="${esc(safeImg(s.company_logo))}" style="height:40px;"></div>` : ''}</div>
                    <div class="form-group">
                        <label class="form-label">لون الثيم</label>
                        <div style="display:flex;gap:0.75rem;align-items:center;">
                            <input type="color" id="sThemeColor" value="${esc(s.theme_color || '#4F46E5')}" style="width:50px;height:44px;padding:4px;border:1.5px solid var(--border);border-radius:8px;cursor:pointer;">
                            <input type="text" class="form-input" name="theme_color" id="sThemeColorText" value="${esc(s.theme_color || '#4F46E5')}" style="flex:1;">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-no-hover" style="margin-bottom:1.5rem;">
                <div class="card-body" style="padding:1.5rem;">
                    <h3 style="font-weight:600;margin-bottom:1rem;">📞 التواصل</h3>
                    <div class="form-group"><label class="form-label">رقم الهاتف</label><input type="text" class="form-input" name="contact_phone" value="${esc(s.contact_phone || '')}"></div>
                    <div class="form-group"><label class="form-label">البريد الإلكتروني</label><input type="email" class="form-input" name="contact_email" value="${esc(s.contact_email || '')}"></div>
                    <div class="form-group"><label class="form-label">واتساب</label><input type="text" class="form-input" name="contact_whatsapp" value="${esc(s.contact_whatsapp || '')}" placeholder="964123456789"></div>
                </div>
            </div>

            <div class="card card-no-hover" style="margin-bottom:1.5rem;">
                <div class="card-body" style="padding:1.5rem;">
                    <h3 style="font-weight:600;margin-bottom:1rem;">🤖 تيليجرام</h3>
                    <div class="form-group"><label class="form-label">توكن البوت</label><input type="text" class="form-input" name="telegram_bot_token" value="${esc(s.telegram_bot_token || '')}"></div>
                    <div class="form-group"><label class="form-label">معرّف محادثة المشرف (Chat ID)</label><input type="text" class="form-input" name="telegram_admin_chat_id" value="${esc(s.telegram_admin_chat_id || '')}"></div>
                    <button type="button" class="btn btn-outline btn-sm" id="setWebhookBtn">🔗 تعيين الويب هوك</button>
                </div>
            </div>

            <div class="card card-no-hover" style="margin-bottom:1.5rem;">
                <div class="card-body" style="padding:1.5rem;">
                    <h3 style="font-weight:600;margin-bottom:1rem;">📦 الطلبات</h3>
                    <div class="form-group"><label class="form-label">مدة الإبلاغ بعد التسليم (ثواني)</label><input type="number" class="form-input" name="report_window_seconds" value="${esc(s.report_window_seconds || '180')}" min="0"><small style="color:var(--text-muted);">المدة التي يمكن للعميل خلالها الإبلاغ عن مشكلة بعد التسليم (الافتراضي: 180 ثانية = 3 دقائق)</small></div>
                    <div class="form-group"><label class="form-label">🔑 رمز معالج الطابور (Queue Token)</label><div style="display:flex;gap:0.5rem;"><input type="text" class="form-input" name="queue_process_token" id="queueTokenInput" value="${esc(s.queue_process_token || '')}" style="flex:1;" placeholder="اضغط توليد لإنشاء رمز عشوائي"><button type="button" class="btn btn-outline btn-sm" id="genQueueTokenBtn">🔄 توليد</button></div><small style="color:var(--text-muted);">يُستخدم لحماية رابط معالج الطابور: /api/queue_process.php?token=XXXX</small></div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block">💾 حفظ الإعدادات</button>
        </form>`;

        const cp = document.getElementById('sThemeColor');
        const ct = document.getElementById('sThemeColorText');
        cp?.addEventListener('input', () => ct.value = cp.value);
        ct?.addEventListener('input', () => { if (/^#[0-9A-Fa-f]{6}$/.test(ct.value)) cp.value = ct.value; });

        // Generate random queue token
        document.getElementById('genQueueTokenBtn')?.addEventListener('click', () => {
            const arr = new Uint8Array(16);
            crypto.getRandomValues(arr);
            document.getElementById('queueTokenInput').value = Array.from(arr, b => b.toString(16).padStart(2, '0')).join('');
        });

        document.getElementById('settingsForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            let fd = new FormData(e.target);
            fd.append('csrf_token', APP.csrf);
            fd = await compressFormDataImages(fd);
            const res = await fetch('/api/settings.php?action=update', { method: 'POST', headers: { 'X-CSRF-TOKEN': APP.csrf }, body: fd });
            const d = await res.json();
            if (d.success) {
                toast('تم حفظ الإعدادات — أعد تحميل الصفحة لتطبيق التغييرات');
            } else {
                toast(d.error, 'error');
            }
        });

        document.getElementById('setWebhookBtn')?.addEventListener('click', async () => {
            const url = prompt('أدخل رابط الويب هوك:', window.location.origin + '/telegram/webhook.php');
            if (!url) return;
            try {
                await api('/api/settings.php?action=set_webhook', { method: 'POST', body: { webhook_url: url } });
                toast('تم تعيين الويب هوك بنجاح!');
            } catch (err) {
                toast(err.message, 'error');
            }
        });
    };

    // ==========================================
    // Shared Modal Helper
    // ==========================================
    function showModal(title, bodyHtml, onSubmit, isFormData = false) {
        document.querySelector('.modal-overlay')?.remove();

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title"></h3>
                <button class="modal-close" type="button">✕</button>
            </div>
            <form class="modal-body" id="modalForm" ${isFormData ? 'enctype="multipart/form-data"' : ''}>
                ${bodyHtml}
                <div style="margin-top:1rem;display:flex;gap:0.5rem;">
                    <button type="submit" class="btn btn-primary">💾 حفظ</button>
                    <button type="button" class="btn btn-outline modal-cancel">إلغاء</button>
                </div>
            </form>
        </div>`;

        // Title is plain text (may contain user-controlled data such as usernames) — never parse as HTML
        overlay.querySelector('.modal-title').textContent = String(title ?? '');
        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('show'));

        overlay.querySelector('.modal-close')?.addEventListener('click', () => closeModal(overlay));
        overlay.querySelector('.modal-cancel')?.addEventListener('click', () => closeModal(overlay));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(overlay); });

        overlay.querySelector('#modalForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = e.target.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = '...';
            try {
                let fd = new FormData(e.target);
                fd = await compressFormDataImages(fd);
                await onSubmit(fd);
                closeModal(overlay);
            } catch (err) {
                toast(err.message, 'error');
                btn.disabled = false;
                btn.textContent = '💾 حفظ';
            }
        });
    }

    function closeModal(overlay) {
        overlay.classList.remove('show');
        setTimeout(() => overlay.remove(), 300);
    }

    // ==========================================
    // Alerts — Admin CRUD
    // ==========================================
    renderers.alerts = async (el) => {
        const data = await api('/api/alerts.php?action=list');
        const alerts = data.alerts || [];
        el.innerHTML = `
        <div class="admin-page-title">🔔 التنبيهات <button class="btn btn-primary btn-sm" id="addAlertBtn">+ إضافة تنبيه</button></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;">
            ${alerts.map(a => `<div class="card card-no-hover">
                <div class="card-body">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
                        <div style="font-weight:700;font-size:1rem;">${esc(a.title)}</div>
                        <span class="badge ${a.status === 'active' ? 'badge-accepted' : 'badge-canceled'}">${a.status === 'active' ? 'مفعّل' : 'معطّل'}</span>
                    </div>
                    ${a.description ? `<div style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:0.5rem;">${esc(a.description.substring(0, 120))}</div>` : ''}
                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.75rem;">
                        <span class="badge badge-${a.type === 'always' ? 'delivering' : 'pending'}" style="font-size:0.7rem;">${a.type === 'always' ? 'دائماً (مرة/يوم)' : 'مرة واحدة'}</span>
                        ${a.url ? `<span style="font-size:0.7rem;color:var(--info);">🔗 رابط</span>` : ''}
                        <span style="font-size:0.7rem;color:var(--text-muted);">ترتيب: ${esc(a.sort_order)}</span>
                    </div>
                    <div style="display:flex;gap:0.5rem;">
                        <button class="btn btn-primary btn-sm edit-alert" data-id="${esc(a.id)}">✏️</button>
                        <button class="btn btn-danger btn-sm del-alert" data-id="${esc(a.id)}">🗑</button>
                    </div>
                </div>
            </div>`).join('')}
            ${alerts.length === 0 ? '<div class="empty-state"><div class="empty-state-icon">🔔</div><div class="empty-state-text">لا توجد تنبيهات</div></div>' : ''}
        </div>`;

        el.querySelector('#addAlertBtn')?.addEventListener('click', () => showAlertModal(null, el));
        el.querySelectorAll('.edit-alert').forEach(btn => btn.addEventListener('click', async () => {
            const a = alerts.find(x => x.id == btn.dataset.id);
            showAlertModal(a, el);
        }));
        el.querySelectorAll('.del-alert').forEach(btn => btn.addEventListener('click', async () => {
            if (!confirm('حذف التنبيه؟')) return;
            await api('/api/alerts.php?action=delete', { method: 'POST', body: { id: btn.dataset.id } });
            toast('تم الحذف');
            renderers.alerts(el);
        }));
    };

    function showAlertModal(a = null, parentEl = null) {
        const typeOptions = [['one_time', 'مرة واحدة'], ['always', 'دائماً (مرة/يوم)']];
        const typeHtml = typeOptions.map(([v, l]) => `<option value="${v}" ${a?.type === v ? 'selected' : ''}>${l}</option>`).join('');
        showModal('تنبيه', `
            <div class="form-group"><label class="form-label">العنوان</label><input type="text" class="form-input" name="title" value="${esc(a?.title || '')}" required></div>
            <div class="form-group"><label class="form-label">الوصف</label><textarea class="form-textarea" name="description">${esc(a?.description || '')}</textarea></div>
            <div class="form-group"><label class="form-label">النوع</label><select class="form-select" name="type">${typeHtml}</select></div>
            <div class="form-group"><label class="form-label">رابط (اختياري)</label><input type="url" class="form-input" name="url" value="${esc(a?.url || '')}" placeholder="https://..."></div>
            <div class="form-group"><label class="form-label">الترتيب</label><input type="number" class="form-input" name="sort_order" value="${esc(a?.sort_order || 0)}"></div>
            ${a ? `<div class="form-group"><label class="form-label">الحالة</label><select class="form-select" name="status"><option value="active" ${a.status === 'active' ? 'selected' : ''}>مفعّل</option><option value="inactive" ${a.status === 'inactive' ? 'selected' : ''}>معطّل</option></select></div>` : ''}
        `, async (fd) => {
            const body = {
                title: fd.get('title'),
                description: fd.get('description'),
                type: fd.get('type'),
                url: fd.get('url'),
                sort_order: fd.get('sort_order'),
                status: fd.get('status') || 'active',
            };
            if (a) body.id = a.id;
            const action = a ? 'update' : 'create';
            await api(`/api/alerts.php?action=${action}`, { method: 'POST', body });
            toast(a ? 'تم التحديث' : 'تم الإنشاء');
            if (parentEl) renderers.alerts(parentEl);
        });
    }

    // ==========================================
    // Expose modal helpers for inline editing from app.js
    // ==========================================
    window.ADMIN_SHOW_CATEGORY_MODAL = showCategoryModal;
    window.ADMIN_SHOW_ITEM_MODAL = showItemModal;

    // ==========================================
    // KYC Admin Panel
    // ==========================================
    renderers.kyc = (el) => {
        el.innerHTML = `
        <div class="admin-page-title">🪪 طلبات التحقق (KYC)</div>
        <div class="card card-no-hover"><div class="card-body" style="overflow-x:auto;">
            <table id="kycTable" class="display" style="width:100%">
                <thead><tr><th>#</th><th>المستخدم</th><th>الاسم</th><th>الهاتف</th><th>نوع الهوية</th><th>رقم الهوية</th><th>الحالة</th><th>التاريخ</th><th>إجراءات</th></tr></thead>
            </table>
        </div></div>`;

        initSSRTable('kycTable', '/api/kyc.php?action=admin_list', [
            { data: '0' },
            { data: '1' },
            { data: '2' },
            { data: '3' },
            { data: '4' },
            { data: '5' },
            { data: '6' },
            { data: '7' },
            { data: '8' },
        ], {
            drawCallback: () => {
                el.querySelectorAll('.view-kyc-docs').forEach(btn => btn.addEventListener('click', () => {
                    const front = btn.dataset.front;
                    const back = btn.dataset.back;
                    const selfie = btn.dataset.selfie;
                    Swal.fire({
                        title: '📄 مستندات التحقق',
                        html: `<div style="display:flex;flex-direction:column;gap:0.75rem;max-height:60vh;overflow-y:auto;">
                            ${safeImg(front) ? `<div><strong>الوجه الأمامي</strong><br><img src="${esc(safeImg(front))}" style="max-width:100%;border-radius:8px;"></div>` : ''}
                            ${safeImg(back) ? `<div><strong>الوجه الخلفي</strong><br><img src="${esc(safeImg(back))}" style="max-width:100%;border-radius:8px;"></div>` : ''}
                            ${safeImg(selfie) ? `<div><strong>سيلفي مع الهوية</strong><br><img src="${esc(safeImg(selfie))}" style="max-width:100%;border-radius:8px;"></div>` : ''}
                        </div>`,
                        width: 500,
                        showConfirmButton: false,
                        showCloseButton: true,
                    });
                }));

                el.querySelectorAll('.approve-kyc').forEach(btn => btn.addEventListener('click', async () => {
                    const r = await Swal.fire({ title: 'تأكيد الموافقة؟', icon: 'question', showCancelButton: true, confirmButtonText: '✅ موافقة', cancelButtonText: 'إلغاء' });
                    if (!r.isConfirmed) return;
                    try {
                        await api('/api/kyc.php?action=approve', { method: 'POST', body: { id: btn.dataset.id } });
                        toast('تم الموافقة');
                        activeTable.ajax.reload();
                    } catch (err) { toast(err.message, 'error'); }
                }));

                el.querySelectorAll('.deny-kyc').forEach(btn => btn.addEventListener('click', async () => {
                    const r = await Swal.fire({
                        title: 'رفض طلب التحقق',
                        input: 'textarea',
                        inputLabel: 'سبب الرفض (اختياري)',
                        inputPlaceholder: 'اكتب سبب الرفض...',
                        showCancelButton: true,
                        confirmButtonText: '❌ رفض',
                        cancelButtonText: 'إلغاء',
                    });
                    if (!r.isConfirmed) return;
                    try {
                        await api('/api/kyc.php?action=deny', { method: 'POST', body: { id: btn.dataset.id, reason: r.value || '' } });
                        toast('تم الرفض');
                        activeTable.ajax.reload();
                    } catch (err) { toast(err.message, 'error'); }
                }));
            },
        });
    };

})();
