<?php
/**
 * CStore — Database (SQLite WAL mode, singleton, migrations)
 */

class DB
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            self::$instance = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');
            self::$instance->exec('PRAGMA busy_timeout=5000');
            self::migrateIfNeeded();
        }
        return self::$instance;
    }

    private static function migrateIfNeeded(): void
    {
        $versionFile = ROOT_DIR . '/.db_version';
        $currentVersion = 12; // Increment this when schema changes
        $installedVersion = (int)@file_get_contents($versionFile);
        if ($installedVersion === $currentVersion)
            return;
        self::migrate();
        file_put_contents($versionFile, (string)$currentVersion);
    }

    private static function migrate(): void
    {
        $db = self::$instance;

        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user',
            wallet_balance REAL NOT NULL DEFAULT 0.00,
            is_active INTEGER NOT NULL DEFAULT 1,
            ban_reason TEXT,
            created_at DATETIME NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT,
            image TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            description TEXT,
            price REAL NOT NULL DEFAULT 0.00,
            image TEXT,
            cancel_window_seconds INTEGER NOT NULL DEFAULT 0,
            contact_only INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS item_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT 'text',
            is_required INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS topup_methods (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            instructions TEXT,
            image TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS topup_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            method_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            proof_image TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            accepted_amount REAL,
            admin_note TEXT,
            telegram_message_id INTEGER,
            created_at DATETIME NOT NULL DEFAULT (datetime('now')),
            resolved_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (method_id) REFERENCES topup_methods(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 1,
            total_price REAL NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            cancel_reason TEXT,
            cancel_deadline DATETIME,
            telegram_message_id INTEGER,
            created_at DATETIME NOT NULL DEFAULT (datetime('now')),
            resolved_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (item_id) REFERENCES items(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS order_field_values (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            field_id INTEGER NOT NULL,
            value TEXT,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (field_id) REFERENCES item_fields(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS order_deliveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            type TEXT NOT NULL DEFAULT 'text',
            content TEXT,
            file_name TEXT,
            telegram_file_id TEXT,
            created_at DATETIME NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS carousel_slides (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            image TEXT NOT NULL,
            title TEXT,
            link TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS site_settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS bot_state (
            key TEXT PRIMARY KEY,
            value TEXT,
            created_at DATETIME NOT NULL DEFAULT (datetime('now'))
        )");

        // Item stock for auto-delivery
        $db->exec("CREATE TABLE IF NOT EXISTS item_stock (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            content TEXT,
            image_path TEXT,
            is_sold INTEGER NOT NULL DEFAULT 0,
            sold_to_order_id INTEGER,
            expires_at DATETIME,
            created_at DATETIME NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
        )");

        // Order reports
        $db->exec("CREATE TABLE IF NOT EXISTS order_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            details TEXT,
            screenshot TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            admin_response TEXT,
            telegram_message_id INTEGER,
            created_at DATETIME NOT NULL DEFAULT (datetime('now')),
            resolved_at DATETIME,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )");

        // Report messages (chat between user and admin)
        $db->exec("CREATE TABLE IF NOT EXISTS report_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            sender TEXT NOT NULL DEFAULT 'user',
            message TEXT,
            image TEXT,
            created_at DATETIME NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (report_id) REFERENCES order_reports(id) ON DELETE CASCADE
        )");

        // Alerts / Announcements
        $db->exec("CREATE TABLE IF NOT EXISTS alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT,
            status TEXT NOT NULL DEFAULT 'active',
            url TEXT,
            type TEXT NOT NULL DEFAULT 'one_time',
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT (datetime('now'))
        )");

        // v6: Currencies table
        $db->exec("CREATE TABLE IF NOT EXISTS currencies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            symbol TEXT NOT NULL DEFAULT '',
            rate REAL NOT NULL DEFAULT 1.0,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0
        )");

        // v6: Item price options table
        $db->exec("CREATE TABLE IF NOT EXISTS item_price_options (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            price REAL NOT NULL DEFAULT 0.00,
            sort_order INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_item_price_options_item ON item_price_options(item_id)");

        // Migrations for existing databases
        $cols = $db->query("PRAGMA table_info(items)")->fetchAll();
        $colNames = array_column($cols, 'name');
        if (!in_array('delivery_type', $colNames)) {
            $db->exec("ALTER TABLE items ADD COLUMN delivery_type TEXT NOT NULL DEFAULT 'manual'");
        }
        if (!in_array('api_url', $colNames)) {
            $db->exec("ALTER TABLE items ADD COLUMN api_url TEXT");
        }
        // v6: Pricing type columns on items
        if (!in_array('pricing_type', $colNames)) {
            $db->exec("ALTER TABLE items ADD COLUMN pricing_type TEXT NOT NULL DEFAULT 'fixed'");
        }
        if (!in_array('calc_field_id', $colNames)) {
            $db->exec("ALTER TABLE items ADD COLUMN calc_field_id INTEGER");
        }
        if (!in_array('calc_operator', $colNames)) {
            $db->exec("ALTER TABLE items ADD COLUMN calc_operator TEXT DEFAULT '*'");
        }
        if (!in_array('calc_value', $colNames)) {
            $db->exec("ALTER TABLE items ADD COLUMN calc_value REAL DEFAULT 0");
        }

        // Add delivered_at to orders if not exists
        $orderCols = $db->query("PRAGMA table_info(orders)")->fetchAll();
        $orderColNames = array_column($orderCols, 'name');
        if (!in_array('delivered_at', $orderColNames)) {
            $db->exec("ALTER TABLE orders ADD COLUMN delivered_at DATETIME");
        }
        // v6: Pricing option tracking on orders
        if (!in_array('price_option_id', $orderColNames)) {
            $db->exec("ALTER TABLE orders ADD COLUMN price_option_id INTEGER");
        }
        if (!in_array('price_option_label', $orderColNames)) {
            $db->exec("ALTER TABLE orders ADD COLUMN price_option_label TEXT");
        }

        // Migrate item_stock — add image_path, expires_at
        $stockCols = $db->query("PRAGMA table_info(item_stock)")->fetchAll();
        $stockColNames = array_column($stockCols, 'name');
        if (!in_array('image_path', $stockColNames)) {
            $db->exec("ALTER TABLE item_stock ADD COLUMN image_path TEXT");
        }
        if (!in_array('expires_at', $stockColNames)) {
            $db->exec("ALTER TABLE item_stock ADD COLUMN expires_at DATETIME");
        }

        // v7: Add prefix/suffix to item_fields
        $fieldColNames = array_column($db->query("PRAGMA table_info(item_fields)")->fetchAll(), 'name');
        if (!in_array('prefix', $fieldColNames)) {
            $db->exec("ALTER TABLE item_fields ADD COLUMN prefix TEXT DEFAULT ''");
        }
        if (!in_array('suffix', $fieldColNames)) {
            $db->exec("ALTER TABLE item_fields ADD COLUMN suffix TEXT DEFAULT ''");
        }

        // v8: Add api_url to item_price_options
        $optColNames = array_column($db->query("PRAGMA table_info(item_price_options)")->fetchAll(), 'name');
        if (!in_array('api_url', $optColNames)) {
            $db->exec("ALTER TABLE item_price_options ADD COLUMN api_url TEXT DEFAULT ''");
        }

        // Create indexes for performance
        $db->exec("CREATE INDEX IF NOT EXISTS idx_items_category ON items(category_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_orders_user ON orders(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_topup_requests_user ON topup_requests(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_topup_requests_status ON topup_requests(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_item_fields_item ON item_fields(item_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_order_field_values_order ON order_field_values(order_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_order_deliveries_order ON order_deliveries(order_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_item_stock_item ON item_stock(item_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_item_stock_unsold ON item_stock(item_id, is_sold)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_order_reports_order ON order_reports(order_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_report_messages_report ON report_messages(report_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_alerts_status ON alerts(status)");

        // v9: API queue for api_json delivery processing
        $db->exec("CREATE TABLE IF NOT EXISTS api_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL UNIQUE,
            api_url_template TEXT NOT NULL,
            api_url_resolved TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            response TEXT,
            created_at DATETIME DEFAULT (datetime('now')),
            processed_at DATETIME,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_api_queue_status ON api_queue(status, api_url_template)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_api_queue_order ON api_queue(order_id)");

        // v5: Add parent_id to categories for subcategory support
        $cols = $db->query("PRAGMA table_info(categories)")->fetchAll();
        $hasParentId = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'parent_id') {
                $hasParentId = true;
                break;
            }
        }
        if (!$hasParentId) {
            $db->exec("ALTER TABLE categories ADD COLUMN parent_id INTEGER DEFAULT NULL");
        }
        $db->exec("CREATE INDEX IF NOT EXISTS idx_categories_parent ON categories(parent_id)");

        // v10: Profile fields on users
        $userCols = array_column($db->query("PRAGMA table_info(users)")->fetchAll(), 'name');
        if (!in_array('first_name', $userCols)) {
            $db->exec("ALTER TABLE users ADD COLUMN first_name TEXT");
        }
        if (!in_array('last_name', $userCols)) {
            $db->exec("ALTER TABLE users ADD COLUMN last_name TEXT");
        }
        if (!in_array('profile_image', $userCols)) {
            $db->exec("ALTER TABLE users ADD COLUMN profile_image TEXT");
        }
        if (!in_array('totp_secret', $userCols)) {
            $db->exec("ALTER TABLE users ADD COLUMN totp_secret TEXT");
        }

        // v10: KYC applications
        $db->exec("CREATE TABLE IF NOT EXISTS kyc_applications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            address TEXT NOT NULL,
            phone TEXT NOT NULL,
            id_type TEXT NOT NULL,
            id_number TEXT NOT NULL,
            front_image TEXT NOT NULL,
            back_image TEXT NOT NULL,
            selfie_image TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            deny_reason TEXT,
            telegram_message_id INTEGER,
            created_at DATETIME NOT NULL DEFAULT (datetime('now')),
            resolved_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_kyc_user ON kyc_applications(user_id)");

        // v10: Remember tokens (persistent 2FA login)
        $db->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_remember_user ON remember_tokens(user_id)");

        // v10: KYC gate on topup methods
        $tmCols = array_column($db->query("PRAGMA table_info(topup_methods)")->fetchAll(), 'name');
        if (!in_array('require_kyc', $tmCols)) {
            $db->exec("ALTER TABLE topup_methods ADD COLUMN require_kyc INTEGER NOT NULL DEFAULT 0");
        }
        // v11: Auto-updating currency rates from JSON APIs
        $curCols = array_column($db->query("PRAGMA table_info(currencies)")->fetchAll(), 'name');
        if (!in_array('rate_mode', $curCols)) {
            $db->exec("ALTER TABLE currencies ADD COLUMN rate_mode TEXT NOT NULL DEFAULT 'fixed'");
        }
        if (!in_array('rate_url', $curCols)) {
            $db->exec("ALTER TABLE currencies ADD COLUMN rate_url TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('rate_json_path', $curCols)) {
            $db->exec("ALTER TABLE currencies ADD COLUMN rate_json_path TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('rate_adjustment', $curCols)) {
            $db->exec("ALTER TABLE currencies ADD COLUMN rate_adjustment REAL NOT NULL DEFAULT 0");
        }
        if (!in_array('rate_updated_at', $curCols)) {
            $db->exec("ALTER TABLE currencies ADD COLUMN rate_updated_at DATETIME");
        }

        // v12: Rate limiting buckets + TOTP replay protection
        $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            bucket TEXT PRIMARY KEY,
            hits INTEGER NOT NULL DEFAULT 0,
            window_start INTEGER NOT NULL
        )");
        $userCols12 = array_column($db->query("PRAGMA table_info(users)")->fetchAll(), 'name');
        if (!in_array('totp_last_step', $userCols12)) {
            $db->exec("ALTER TABLE users ADD COLUMN totp_last_step INTEGER NOT NULL DEFAULT 0");
        }

        // Insert default settings if not exist
        $defaults = [
            'site_name' => 'CStore',
            'theme_color' => '#4F46E5',
            'company_logo' => '',
            'contact_phone' => '',
            'contact_email' => '',
            'contact_whatsapp' => '',
            'telegram_bot_token' => '',
            'telegram_admin_chat_id' => '',
            'report_window_seconds' => '180',
        ];
        // v6: Default USD currency
        $db->exec("INSERT OR IGNORE INTO currencies (code, name, symbol, rate, sort_order) VALUES ('USD', 'US Dollar', '$', 1.0, 0)");
        $stmt = $db->prepare("INSERT OR IGNORE INTO site_settings (key, value) VALUES (:key, :value)");
        foreach ($defaults as $k => $v) {
            $stmt->execute(['key' => $k, 'value' => $v]);
        }
    }

    /**
     * Get a site setting value
     */
    public static function setting(string $key, string $default = ''): string
    {
        static $cache = [];
        if (array_key_exists($key, $cache))
            return $cache[$key] !== null ? $cache[$key] : $default;
        $stmt = self::get()->prepare("SELECT value FROM site_settings WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = $row ? $row['value'] : null;
        return $cache[$key] !== null ? $cache[$key] : $default;
    }

    /**
     * Set a site setting value
     */
    public static function setSetting(string $key, string $value): void
    {
        $stmt = self::get()->prepare("INSERT OR REPLACE INTO site_settings (key, value) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
    }

    /** Nesting depth of DB::transaction() calls. */
    private static int $txDepth = 0;

    /**
     * Run $fn inside a write transaction (BEGIN IMMEDIATE takes the SQLite write
     * lock up front, so check-then-update logic inside $fn cannot race).
     * Nested calls join the outer transaction. Returns $fn's return value.
     * Do NOT mix with PDO::beginTransaction()/commit()/rollBack().
     */
    public static function transaction(callable $fn)
    {
        $db = self::get();
        if (self::$txDepth > 0) {
            self::$txDepth++;
            try {
                return $fn($db);
            } finally {
                self::$txDepth--;
            }
        }
        $db->exec('BEGIN IMMEDIATE');
        self::$txDepth = 1;
        try {
            $result = $fn($db);
            $db->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            try { $db->exec('ROLLBACK'); } catch (Throwable $ignored) {}
            throw $e;
        } finally {
            self::$txDepth = 0;
        }
    }
}
