# CafeFlow – Point of Sale & Inventory Management System
### Custom Built for DK Cafe

**CafeFlow** is an offline-first, single-screen Point of Sale (POS), Barista Queue, Raw Inventory Management, Staff Administration, Executive Sales Reporting, and Predictive Machine Learning system designed specifically for **DK Cafe**.

Built with vanilla PHP 8, modern modular JavaScript (ES6+), Python 3 (OpenXML engine), and MySQL/MariaDB, it runs entirely on a local network via XAMPP—requiring **no internet connection, no external cloud subscriptions, and no proprietary POS hardware**.

---

## Key Features

### 1. Dynamic Cup Size Pricing (12oz Hot, 16oz Cold / Tall & 22oz Grande)
- **Coffee Cup Sizes**: In accordance with DK Cafe's official menu, all **Coffee** category beverages exclusively offer **12oz Hot** and **16oz Cold** (22oz is completely omitted from Coffee).
- **Non-Coffee Beverage Sizes**: Other beverage categories (Milk Tea, Milk Series, Fruit Tea / Lemonade) offer **16oz (Tall)** and **22oz (Grande)**.
- **Interactive Size Popover**: Tapping any beverage opens an intuitive cup-size selector with visual cup icons, exact prices, and real-time stock indicators.
- **Card Price Range Display**: Menu cards cleanly display the pricing span (e.g., `₱130.00 – ₱150.00`), maintaining a tidy, uncluttered grid layout.
- **Full System Consistency**: Chosen cup sizes flow seamlessly into checkout totals, e-receipts, printed thermal receipts, the barista queue, and sales reports.

### 2. Centralized Recipe Engine & Raw Inventory Consumption
- **Pure Raw Inventory Tracking**: Eliminates disconnected "finished beverage" stock counts. Stock availability is derived 100% from actual raw ingredients (beans, milk, powders, syrups, ice, cups, lids, and straws).
- **Hot vs. Cold Serving Logic**:
  * **12oz Hot Beverages**: Deduct `12oz Hot cup & lid` (Item ID 23) and scaled espresso/milk ingredients, automatically omitting Ice and straws.
  * **16oz & 22oz Cold Beverages**: Deduct `16oz cup` or `22oz cup`, `Flat lid`, `Bendable straw`, `Ice`, and scaled ingredients.
- **Proportional Scaling**: Larger cup sizes (22oz Grande) automatically consume proportionally more raw ingredients (~1.375×) than baseline servings (16oz Tall), while 12oz servings scale by 0.75×.
- **Automated Packaging Deductions**: Every cup sold automatically deducts its corresponding cup size (`16oz cup` or `22oz cup`), `Flat lid`, and straw upon completion.
- **Centralized Engine (`core/recipe_engine.php`)**: All ingredient ratios, volume multipliers, and standard recipes are organized in one centralized file for easy future calibration.
- **Atomic Completion Deductions**: Deductions occur exclusively when the barista marks an order as **Completed**, utilizing database row-level locking (`FOR UPDATE`) to ensure zero double-deductions and prevent negative balances.

### 3. Smart Per-Size Inventory Availability
- **Independent Size Stock Checks**: If 16oz cups run out (`0 pcs`) but 22oz cups remain in stock (e.g., `500 pcs`), the beverage is **not** locked out. The card displays a `Grande only` badge and remains orderable.
- **Visual Popover Guards**: Inside the size picker, depleted sizes are automatically disabled, styled in muted tones, and marked **"OUT OF STOCK"**, while available sizes remain active.
- **Total Stock Protection**: If a core shared ingredient (such as Espresso Beans or Matcha Powder) reaches zero, the entire product card is flagged `Out of stock` and disabled with clear feedback toasts.

### 4. Cashier POS, Non-VAT Pricing & Payment Processing
- **Non-VAT Financial Model**: Clean, transparent pricing with 0% VAT. Product prices reflect the exact net customer charge.
- **Senior Citizen & PWD 20% Discount**: Accurate 20% statutory discount computed directly on the gross subtotal with mandatory OSCA / PWD ID or Booklet number verification (minimum 3 characters required).
- **Custom Promotional Discounts**: Flexible promotional discounts (custom % from 1% to 50%) for seasonal marketing campaigns.
- **Cash Tender Modal**: Fast cash calculator that compares amount tendered against order total, displaying change due in real time with quick-action tender shortcuts.
- **GCash Digital Payment & Anti-Fraud Security**: Native GCash payment tender with server-enforced duplicate reference number prevention (`HTTP 409 Conflict`) to protect the cafe from fraudulent double-redemptions.
- **E-Receipt & Thermal Print**: Instant electronic receipts with re-print capabilities and optimized CSS for 58mm/80mm thermal receipt printers.

### 5. Live Barista Queue
- **Real-Time Auto-Refresh**: Polls the server every 3 seconds for new orders with zero flicker.
- **Visual Wait-Time SLA Badges**: Color-coded wait-time indicators (Green < 5 min, Amber < 10 min, Red > 10 min) to maintain quick service times.
- **Size-Explicit Order Details**: Orders display clear brewing instructions (e.g., `Matcha (Tall - 16oz) x 2`) so baristas immediately know which cup size to prepare.
- **Single-Tap Fulfillment**: Tapping **Mark Complete** changes status to Completed and atomically triggers ingredient and packaging deductions.
- **Order Voiding & Cancellation**: Dedicated cancellation modal requiring a mandatory reason (e.g., customer change of mind, incorrect drink size), automatically reversing ingredient deductions if voided.

### 6. Standardized Contextual Empty State Architecture
- **Universal Component (`.cf-empty-state`)**: Replaces blank screens and ambiguous states across all core modules with clear, actionable UI guidance:
  * **POS Cart**: Order is Empty with Senior/PWD 20% discount reminder.
  * **Barista Queue**: All Orders Served with SLA wait-time guidelines.
  * **POS Menu**: No items in category / No products match query with one-click filter clear button.
  * **Supplies Inventory**: No Supplies Found / search no-match with restock threshold tips.
  * **Product Catalog**: No Products Available / search no-match with recipe linking pro-tips.
  * **Account Management**: No Accounts Found / search no-match with role-based permission context.
  * **Sales Reports**: No Items Sold, No Breakdown Data, and No Transaction Records with calendar navigation tips.
- **Professional Input Placeholders & Microcopy**: Clear descriptive placeholder text across all search and tender inputs eliminating operational guesswork.

### 7. Product Catalog & Recipe Management Modal
- **Reference-Aligned Category Dropdown**: Dedicated `<select>` dropdown populated with DK Cafe's official menu categories (`Coffee`, `Croffle Classic`, `Croffle Premium`, `Fruit Tea / Lemonade`, `Milk Series`, `Milk Tea`, `Pastry`, `Sandwich`, `Snacks`).
- **Dynamic Form Intelligence**: Selecting a beverage category automatically enables the cup size and Hot/Cold pricing builder; selecting a food item automatically toggles to Single / Fixed Price.
- **Custom Ingredient Linking**: Add, edit, or map specific raw inventory supplies and precise deduction amounts directly in the product sheet.
- **Consolidated Product Imagery**: Built-in image uploader with format validation, thumbnail generation, and consolidated high-resolution product photography under `uploads/products/`.

### 8. Raw Supplies Inventory Management
- **Instant Search & Status Filtering**: Real-time search bar filtering across item names, units of measure, and stock status keywords (`out`, `low`, `ok`). Includes instant clear button (`✕`) and empty-state guidance.
- **Live Stock Table**: Monitor stock quantities, units of measure (`g`, `ml`, `pcs`), and real-time status pills (`OK`, `Low`, `Out`).
- **Restock & Adjustments**: Log delivery restocks, perform stock take adjustments, and set custom low-stock alert thresholds.
- **Quick-Add Supply Actions**: Introduce new raw ingredients and packaging with a dedicated `+ Add Supply` button in the inventory modal or via the in-product recipe drawer.

### 9. Executive Sales Analytics & Enterprise 7-Worksheet Excel Engine
- **Role-Differentiated Sales Reporting Intelligence**:
  * **Barista Operational & Shift-Focused Dashboard**:
    - **Dedicated Full-Width Sales Trend Graph**: Prominent hourly sales (₱) and order count area curve spanning 100% width, eliminating empty grid holes and segmented tabs.
    - **Integrated Operational Description Box**: Concise timestamps (e.g., peak rush windows `7:00 PM – 8:00 PM`, midday order flow, and register closeouts) advising on station prep and ingredient stocks.
    - **Vertical Bar Top Sellers Graph**: Compact vertical bar chart (`indexAxis: 'x'`) paired with a live, database-driven side-by-side narrative panel.
    - **Interactive Category & Limit Filtering**: Quick-toggle pill buttons for Drinks, Snacks, and All, combined with Top 5 and Top 10 limits.
    - **Daily / Hourly Breakdown Shift Handover**: Plain-language handover summary with timestamps and average ticket volume.
  * **Manager Strategic & Analytical Dashboard**:
    - **Strategic Chart Selector**: Single tabs (`[Sales Trend]`, `[Top Products]`, `[Payment Breakdown]`) and the unified `[Compare All]` view.
    - **Resized & Centered Payment Breakdown**: Donut chart with center hover percentage indicator and cash vs. GCash distribution analysis.
    - **Dedicated Analytical Narratives for Every Chart**: Every graph renders a strategic business narrative detailing revenue velocity, margin implications, and cashless adoption.
    - **Balanced "Compare All" Grid Geometry**: Top row dual-column grid (Sales Trend + Top Products) and full-width bottom row (Payment Breakdown + Tender Audit Narrative).
- **Master 7-Worksheet Enterprise Sales Workbook (`DK_Cafe_Executive_Sales_*.xlsx`)**:
  Generated dynamically via Python (`core/excel_generator.py`) and streamed via `api/export_excel.php`:
  * **Sheet 1 ('Executive Dashboard')**: Executive Deep Espresso banner (`#1F1610`), 6 high-contrast KPI cards, payment channel reconciliation, product category mix, and an embedded native OpenXML vector **Doughnut Chart**.
  * **Sheet 2 ('Predictive Demand Forecast')**: Holt-Winters time-series projection with 90% confidence bounds, day-of-week seasonality, and an embedded native vector **Line Chart**.
  * **Sheet 3 ('Predictive Inventory Reorder')**: Raw ingredient consumption velocity, current stock vs. restock thresholds, projected runout dates, and prioritized replenishment directives (`CRITICAL`, `WARNING`, `STABLE`).
  * **Sheet 4 ('Hourly Rush & Staffing Model')**: Shift-by-shift transaction density, peak rush identification, recommended barista staffing allocations, and an embedded native vector **Column Bar Chart**.
  * **Sheet 5 ('Product Performance')**: Menu item ranking with podium badges (#1, #2, #3), units sold, gross sales, revenue share %, and an embedded native horizontal **Bar Chart** comparing Top 10 Best Sellers.
  * **Sheet 6 ('Itemized Sales Ledger')**: 21-column granular audit ledger with frozen header rows, auto-filters, alternating zebra striping, and dynamic `=SUBTOTAL(9, ...)` accounting rows that recalculate automatically when filtered.
  * **Sheet 7 ('Raw Inventory & Stock Health')**: Complete stock audit tracking all 62 physical supplies with health pills (`HEALTHY`, `LOW STOCK`, `OUT OF STOCK`) and reorder quantities.
- **Decommissioned Legacy CSV Exports**: Retired fragmented streaming CSV endpoints in favor of presentation-grade, ECMA-376 OpenXML workbooks compatible with Microsoft Excel, Microsoft 365, Google Sheets, and LibreOffice Calc.

### 10. Predictive Machine Learning & Demand Forecasting Engine
- **Seasonal Decomposition & Holt-Winters Forecasting (`api/predictive_api.php`)**:
  * Employs multi-seasonal time-series forecasting calibrated against Philippine retail cycles (Habagat rainy season, Tag-init summer beach surges, Quincena 15th/30th paydays, and Q4 Ber-months holiday spikes).
  * Evaluates baseline levels, trend velocity, and weekly cyclical seasonality with 90% confidence intervals (upper/lower bounds).
- **4 Dedicated Forecasting Horizons**:
  * **Daily Prediction**: 24-hour demand projection, hourly rush curves (peak rush `7:00 PM – 9:00 PM`), and shift barista staffing recommendations.
  * **7-Day Prediction**: Weekly trajectory with Quincena payday indicators and weekend surge modeling.
  * **Specific Month Prediction**: 30-day projection with day-of-week seasonality, moving averages, and ingredient consumption projections.
  * **Yearly Prediction**: Annual trajectory with 12-month trend forecasting, macro retail commentary, and capacity planning.
- **1-Click Model Recalculation (`#predRecalculateBtn`)**: Instant model retraining from the latest database transaction records with solid high-contrast notification feedback (`.toast.info` with solid dark navy gradient `#1a3a5f` -> `#0f243d` and `z-index: 2000`).
- **1-Click Dedicated Forecast Export (`#predExportExcelBtn`)**: Direct download of the 7-sheet executive forecast workbook (`DK_Cafe_Executive_Predictive_Forecast_*.xlsx`).
- **Strict Role-Based Access Control**: Exclusively accessible to the Manager role. Direct API requests from Baristas or unauthenticated clients are strictly rejected with `HTTP 403 Forbidden`.

### 11. Full-Page Edge-to-Edge Workspaces & Modern UI Design System
- **1424x719px Full Desktop Workspaces**: `#reportModal` and `#predictiveModal` expand edge-to-edge across the screen (`padding: 0px`, `border-radius: 0px`), providing ample breathing room for multi-chart layouts, KPI cards, and analytical narratives.
- **Constrained Scrollable Breakdown Ledger**: Sales report monthly breakdown table constrained to `max-height: 340px` with vertical scrolling and sticky column headers, eliminating awkward page overflow.
- **Clean Yearly View Alignment**: Sanitized yearly sales view to match the clean layout of Specific Month view, eliminating redundant alert badges and "Actual Production" tags in favor of unified period metadata.
- **Balanced 2x2 Manager Dashboard**: Replaced asymmetrical card placements with a balanced 2x2 responsive grid layout (`grid-template-columns: 1fr 1fr`).
- **Zero-Emoji Professional Design System**: Completely purged emojis across all menus, buttons, modals, toasts, and dialogs. Replaced with clean vector SVGs, `.status-pip` indicators, and modern sans-serif typography (`Work Sans`).

### 12. Modular Frontend JavaScript Architecture
- **Zero-Dependency Modular Design**: Decomposed from a legacy monolithic script into 16 ES modules:
  * `core/config.js`, `core/state.js`, `core/utils.js`, `core/api.js`
  * `modules/pos-menu.js`, `modules/pos-cart.js`, `modules/pos-queue.js`, `modules/inventory.js`, `modules/products.js`, `modules/accounts.js`, `modules/reports.js`, `modules/predictive.js`, `modules/receipt.js`, `modules/dashboard.js`, `modules/navigation.js`
  * `app.js` (orchestration and bootstrapping)
- **Zero Functional Regression**: Retained full `window.CafeFlow` namespace and global window bindings to seamlessly preserve HTML inline event compatibility (`window.loadReport`, `window.viewReceipt`, etc.).

### 13. Co-Branded Dual Logo Lockup (CafeFlow & DK Cafe)
- **Header Lockup**: Features a co-branded header lockup pairing the circular **CafeFlow** vector emblem on the left with the official **DK Cafe** logo on the right, joined by a sleek `CAFEFLOW & DK CAFE` pill badge matching professional corporate lockup guidelines.
- **Harmonized Color Palette**: Built on DK Cafe's brand colors:
  - Deep Forest Green (`#162318` / `#1b2e20`)
  - Warm Flame Orange / Caramel (`#e8623a` / `#c07c2e`)
  - Leaf Green (`#2a9b52`)
  - Warm parchment & cream surfaces for low eye strain during long cafe shifts.

---

## System Architecture & Directory Structure

```text
cafeflow_v5-1/
├── api/                           # Backend REST API Endpoints (JSON / Binary)
│   ├── accounts_api.php           # Staff account CRUD, role changes, password resets
│   ├── auth_api.php               # Login, session inspection, logout, brute-force rate limit
│   ├── cancel_order.php           # Cancels orders & reverses ingredient deductions
│   ├── checkout.php               # Validates cart, verifies raw inventory, creates orders
│   ├── complete_order.php         # Completes orders & atomically deducts raw ingredients
│   ├── export_excel.php           # Streams enterprise 7-sheet Excel (.xlsx) sales & forecast workbooks
│   ├── fetch_menu.php             # Serves menu cards with size pricing & stock status
│   ├── fetch_queue.php            # Serves pending barista queue items & low stock alerts
│   ├── inventory_api.php          # Raw supplies CRUD, stock counts, restock actions
│   ├── predictive_api.php         # Holt-Winters ML forecasting engine (Daily, 7-Day, Month, Year)
│   ├── products_api.php           # Product management, recipe linking & image upload
│   ├── report.php                 # Analytics, date filtering & sales report data
│   └── transactions_api.php       # Past order lookups, receipts & live search filtering
├── assets/                        # Client-Side Frontend Assets
│   ├── css/                       # Modular Stylesheet Architecture
│   │   ├── auth.css               # Login, account management & profile styling
│   │   ├── base.css               # Reset, typography, utility classes, solid toasts
│   │   ├── cart.css               # POS cart drawer, steppers, discount layout
│   │   ├── inventory.css          # Raw inventory modal, table, threshold badges
│   │   ├── layout.css             # Main grid, contextual empty states (.cf-empty-state)
│   │   ├── modals.css             # Modal backdrops, header actions, sheet cards
│   │   ├── pos.css                # Cashier menu grid, category pills, item cards
│   │   ├── predictive.css         # Full-page predictive workspace, KPI cards, rush charts
│   │   ├── print.css              # 58mm/80mm thermal receipt printer styles
│   │   ├── products.css           # Product management form, cup size builder
│   │   ├── queue.css              # Barista preparation tickets & wait-time SLA badges
│   │   ├── reports.css            # Full-page sales report workspace, 340px scrollable breakdown ledger
│   │   ├── responsive.css         # Mobile and tablet responsive breakpoint adaptations
│   │   ├── style.css              # Master CSS bundler / loader
│   │   ├── topbar.css             # Header, dual brand lockup, connection indicator
│   │   └── variables.css          # Color tokens, spacing, typography variables
│   ├── js/                        # Modular JavaScript Architecture (ES6+)
│   │   ├── core/                  # Foundational Subsystems
│   │   │   ├── api.js             # Hardened fetch wrapper with auto CSRF injection
│   │   │   ├── config.js          # Global constants, currency formatting, endpoints
│   │   │   ├── state.js           # Centralized reactive application state store
│   │   │   └── utils.js           # DOM helpers, toast notifications, emptyState() component
│   │   ├── modules/               # Single-Responsibility Feature Modules
│   │   │   ├── accounts.js        # Staff administration & RBAC management
│   │   │   ├── dashboard.js       # Manager workspace KPI cards & 2x2 grid layout
│   │   │   ├── icons.js           # SVG icon definitions & helpers (Zero emojis)
│   │   │   ├── inventory.js       # Supplies table, restock modal, threshold alerts
│   │   │   ├── navigation.js      # View switching, modal lifecycle, keyboard esc
│   │   │   ├── pos-cart.js        # Cart management, discount math, Cash/GCash tender
│   │   │   ├── pos-menu.js        # Menu rendering, category scroll, size popover
│   │   │   ├── pos-queue.js       # Barista queue poller, ticket completion & voiding
│   │   │   ├── predictive.js      # Multi-period ML forecasting, Chart.js graphs, recalculate handler
│   │   │   ├── products.js        # Product catalog, multi-size pricing, recipe builder
│   │   │   ├── receipt.js         # Official E-Receipt generator & thermal print formatting
│   │   │   └── reports.js         # Sales analytics, 340px scrollable ledger, calendar drawer
│   │   ├── vendor/                # Vendored Offline Third-Party Libraries
│   │   │   └── chart.umd.min.js   # Local Chart.js library for offline vector charts
│   │   ├── app.js                 # Master bootstrap coordinator & event delegator
│   │   └── script.js              # Backward-compatibility legacy script facade
│   └── images/                    # System & Brand Graphics
│       ├── cafeflow_logo.svg      # Vector circular emblem for CafeFlow
│       ├── dk_cafe_logo.png       # Original official DK Cafe logo
│       └── dk_cafe_logo_cropped.png # Tightly-cropped DK Cafe lockup logo
├── core/                          # Central Backend Logic & Database
│   ├── auth.php                   # Authentication engine, RBAC checks, session security
│   ├── db.php                     # PDO connection, request helpers & schema verification
│   ├── excel_generator.py         # Enterprise 7-sheet openpyxl Excel workbook generator
│   ├── recipe_engine.php          # Centralized recipe profiles, cup sizes & deduction logic
│   └── seed_2025_ml_data.php      # Calibrated 2025 historical dataset seeder for ML training
├── database/                      # SQL Schemas & Canonical Seed Files
│   ├── archive/                   # Historical database migrations
│   └── schema.sql                 # Canonical baseline database schema & starter seed data (100% synced)
├── docs/                          # Project Documentation & Release Changelogs
│   ├── CHANGES_2026-10-26.txt     # Initial technical changelog
│   ├── CHANGES_2026-11-06.txt     # Non-VAT model transition & UI expansion release
│   ├── CHANGES_2026-11-09.txt     # Multi-sheet Excel (.xlsx) overhaul release
│   ├── CHANGES_2026-11-11.txt     # Modular JS breakdown, empty states & full QA audit
│   ├── CHANGES_2026-11-16.txt     # Predictive ML engine & Enterprise 7-sheet Excel overhaul
│   └── SYSTEM_EXPANSION_SUGGESTIONS.txt # Architecture suggestions for academic courses
├── ref_materials/                 # Official DK Cafe Menu & Inventory Reference Sheets
├── tools/                         # Operational CLI Utilities
│   ├── generate_canonical_schema.py # Automated DDL & seed extraction tool to sync schema.sql
│   └── provision_user.php         # Out-of-band user account provisioning CLI
├── uploads/                       # Product Imagery & Uploads
│   └── products/                  # Consolidated high-res product photography
├── index.php                      # Application Entry Point & Single-Screen Interface
├── login.php                      # Secure Authentication Gateway
├── logout.php                     # Session Termination & Redirection
└── README.md                      # Comprehensive System Guide & Documentation
```

---

## Database Architecture & Canonical Schema

### What is the Database Schema?
In relational database systems, the **database schema** is the formal blueprint that defines the tables, columns, data types, default values, primary keys, unique constraints, foreign key relationships, and performance indexes.

The canonical schema file ([`database/schema.sql`](database/schema.sql)) represents the **clean, "empty" initial state of the system**:
- **Clean Relational Tables**: It establishes all 8 production tables (`inventory`, `menu_products`, `product_size_prices`, `product_ingredients`, `transactions`, `transaction_items`, `users`, `login_attempts`) with strict referential integrity.
- **Pre-Configured Master Seed Data**: It populates the essential foundation required for the cafe to open immediately upon launch:
  * **62 Raw Inventory Items & Packaging**: Accurate units of measure (`g`, `ml`, `pcs`), baseline initial stock, and low-stock warning thresholds.
  * **42 Official DK Cafe Products**: Categorized menu catalog with calibrated single and multi-size prices, plus exact image asset paths under `uploads/products/`.
  * **44 Product Size Price Records**: Explicit prices mapped to cup size oz ratings (12oz Hot, 16oz Cold / Tall, 22oz Grande).
  * **231 Product Ingredient Formulas**: Multi-ingredient deduction profiles linking menu products to raw supplies.
  * **3 Authorized Starter Accounts**: Default credentials for `barista` (Dual POS register), `manager` (Executive Analytics & Admin), and `tan_smsn` (Active Barista).
- **Zero Volatile Transactions**: Crucially, the schema contains **zero customer transaction rows** (`transactions: 0 rows`, `transaction_items: 0 rows`, `login_attempts: 0 rows`). It represents a pristine database ready to record its very first live customer checkout.

### Strict Segregation of Live vs. Machine Learning Data (`is_mock`)
To enable advanced time-series demand forecasting without corrupting financial audit integrity, the database enforces column-level segregation on the `transactions` table:
- **`is_mock = 0` (Live Production Sales)**: Real orders processed by cashiers at the POS register. All official sales reports, accounting audits, and financial statements default strictly to live records.
- **`is_mock = 1` (Synthetic Training Data)**: Simulated historical orders (e.g., the 7,521 transactions generated across 2025 via `core/seed_2025_ml_data.php`). These records incorporate realistic Philippine retail seasonality (Habagat rainy season, Tag-init summer surge, Quincena paydays, and Q4 holidays) to train the Holt-Winters predictive model.
- **Index Optimization**: A dedicated index (`KEY idx_tx_is_mock (is_mock)`) guarantees millisecond query execution even across thousands of historical transactions.

### Entity-Relationship Diagram

```mermaid
erDiagram
    users ||--o{ transactions : "processes as cashier"
    users ||--o{ transactions : "voids as manager"
    menu_products ||--o{ product_size_prices : "has size prices"
    menu_products ||--o{ product_ingredients : "consumes raw"
    inventory ||--o{ product_ingredients : "supplied as"
    inventory ||--o{ menu_products : "linked single supply"
    transactions ||--o{ transaction_items : "contains"
    menu_products ||--o{ transaction_items : "sold as"

    users {
        int user_id PK
        string full_name
        string username
        string email
        string password_hash
        string role
        string status
        timestamp created_at
        timestamp updated_at
        timestamp last_login
    }

    login_attempts {
        int id PK
        string ip_address
        string username
        timestamp attempt_time
        boolean success
    }

    menu_products {
        int product_id PK
        string name
        string category
        decimal price
        string image_url
        decimal stock_quantity
        boolean track_stock
        boolean has_sizes
        decimal price_12oz
        decimal price_16oz
        decimal price_22oz
        int linked_inventory_id FK
        decimal deduction_amount
    }

    product_size_prices {
        int id PK
        int product_id FK
        string size_code
        string size_label
        int size_oz
        decimal price
    }

    inventory {
        int item_id PK
        string name
        decimal current_stock
        string unit_measure
        decimal restock_threshold
        boolean is_active
    }

    product_ingredients {
        int id PK
        int product_id FK
        int item_id FK
        decimal deduction_amount
    }

    transactions {
        int transaction_id PK
        int cashier_user_id FK
        decimal subtotal
        decimal discount
        string discount_type
        decimal discount_rate
        string discount_id_no
        string discount_reason
        decimal tax
        decimal total_amount
        decimal amount_paid
        decimal change_due
        string payment_method
        string reference_number
        boolean is_mock
        string status
        int cancelled_by_user_id FK
        timestamp cancelled_at
        string cancellation_reason
        timestamp timestamp
    }

    transaction_items {
        int id PK
        int transaction_id FK
        int product_id FK
        string product_name
        int quantity
        decimal unit_price
        string size_code
        string size_label
        int size_oz
    }
```

---

## Quick Installation Guide (XAMPP)

### Prerequisites
- **XAMPP** (Apache + MySQL/MariaDB + PHP 8.0 or higher)
- **Python 3.10+** (Required for presentation-grade `.xlsx` Excel exports with `openpyxl 3.1.5` installed via `pip install openpyxl`)
- Any modern web browser (Chrome, Edge, Firefox, Safari)

### Setup Steps

1. **Place Project in XAMPP Web Root**:
   Copy the project folder to your XAMPP `htdocs` directory:
   ```text
   C:\xampp\htdocs\cafeflow_v5-1\
   ```

2. **Start Servers**:
   Open the **XAMPP Control Panel** and start both **Apache** and **MySQL**.

3. **Database Initialization**:
   - Open your browser and navigate to: `http://localhost/phpmyadmin`
   - Create a new database named `cafeflow` (Collation: `utf8mb4_unicode_ci`).
   - Click the **Import** tab, choose `database/schema.sql` from the project directory, and click **Import**.
   - *Result*: You will have a clean, initialized database with all 62 raw supplies, 42 menu products, 44 cup-size price records, 231 recipe mappings, 3 starter accounts, and 0 customer transactions.
   - *Optional ML Training Dataset*: If you wish to populate the 2025 synthetic historical dataset (7,521 transactions) for exploring the Machine Learning engine, run:
     ```bash
     php core/seed_2025_ml_data.php
     ```

4. **Verify Configuration**:
   Open `core/db.php`. Default settings connect to MySQL on `127.0.0.1:3306` with user `root` and an empty password. Adjust constants if your environment uses custom credentials:
   ```php
   if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
   if (!defined('DB_NAME')) define('DB_NAME', 'cafeflow');
   if (!defined('DB_USER')) define('DB_USER', 'root');
   if (!defined('DB_PASS')) define('DB_PASS', '');
   ```

5. **Launch CafeFlow**:
   - **On the Host PC**: Open `http://localhost/cafeflow_v5-1/login.php` in your browser.
   - **On Tablets / Mobile (Same Local Wi-Fi)**:
     - Find the host PC's IP address by running `ipconfig` in Command Prompt (e.g. `192.168.1.100`).
     - On the tablet browser, navigate to: `http://192.168.1.100/cafeflow_v5-1/login.php`
     - Select **"Add to Home Screen"** in your mobile browser to run CafeFlow as a clean, full-screen progressive web app.

---

### Upgrading an installation with existing audit logs

Take CafeFlow offline and back up its database to an access-controlled location outside the web root. With the upgraded code installed, set `DB_NAME` to the intended database and supply its migration credentials through `DB_HOST`, `DB_PORT`, `DB_USER` and `DB_PASS`. From the project directory, run `php tools/migrate_audit_sessions.php` before reopening the POS.

The command preserves event and session rows, replaces historical PHP session IDs with audit correlation IDs, closes legacy active sessions at their last heartbeat, and sanitizes metadata in both audit tables. A second run should report zero conversions and zero redactions. Staff signed in before the upgrade must sign in again. Restrict historical CSV exports, SQL dumps and backups containing old session identifiers to authorized administrators. If restoring an older release, invalidate its PHP session files before resuming service.

## Authentication & Role-Based Access Control (RBAC)

CafeFlow enforces strict role-based access control across all frontend UI modules and backend API endpoints.

### Default Starter Accounts

| Role | Username | Password | Default Landing Page | Authorized Modules & Permissions |
|---|---|---|---|---|
| **Barista** | `barista` | `barista123` | **Dual POS Screen** (`index.php`) | **Full POS Operations**, Order Taking, Cash/GCash Tender, Barista Queue Preparation, Product Management, Raw Supplies View/Restock, Sales Reports (Shift View). Strictly blocked from Account Administration and Predictive Analytics (`HTTP 403 Forbidden`). |
| **Manager** | `manager` | `manager123` | **Manager Dashboard** (`index.php`) | **Executive Dashboard (2x2 Grid)**, **Raw Inventory Management**, **Executive Sales Reports** (Historical date analysis, visual charts, 7-sheet Excel exports), **Predictive Sales Analytics** (Holt-Winters ML demand forecasting, rush curves, par recommendations), **Staff Account Administration** (Create, reset passwords, activate/deactivate, role assignment). Strictly blocked from POS Cashier order processing. |
| **Active Barista** | `tan_smsn` | `alcaraz030303` | **Dual POS Screen** (`index.php`) | Active Barista account (Tristan Alcaraz). |

### Authorized Account Provisioning (No Public Registration)
Public self-registration is permanently disabled. CafeFlow operates strictly as an enterprise, closed-system POS where accounts are provisioned exclusively through authorized channels:
1. **In-App Manager Provisioning**: An authenticated Manager signs in (`manager` / `manager123`), opens the **Profile Menu** -> **Account Management**, clicks **"+ Add Employee"**, and provisions new Barista or Manager credentials with immediate active status.
2. **CLI Account Provisioning Tool**: Authorized system administrators can provision or reset accounts out-of-band using the secure CLI utility:
   ```bash
   php tools/provision_user.php --username=new_user --role=barista --password=secret123 --name="New Employee"
   # Or run interactively:
   php tools/provision_user.php --interactive
   ```
3. **Backend Restriction**: All unauthorized registration attempts via direct API calls (`action=register`) or forged HTTP requests are strictly rejected with `HTTP 403 Forbidden`.

---

## Daily Cafe Operations Workflow

### 1. Cashier (Order Taking & Checkout)
1. Tap a beverage from the menu grid.
2. If the product has multiple sizes, the cup size popover appears:
   - Select **12oz Hot**, **16oz Tall**, or **22oz Grande**.
   - Depleted sizes are clearly indicated and disabled.
3. Review the order in the **Current Order** cart. Adjust quantities using `+` or `−`.
4. If the customer is a Senior Citizen or PWD, select **Senior / PWD (20% Discount)** from the discount dropdown and enter their OSCA / PWD ID or Booklet number.
5. Tap **Checkout · Cash** or **Checkout · GCash**:
   - For **Cash**: The Cash Tender dialog appears. Enter the amount received (or use quick-cash buttons). The exact change due is calculated automatically.
   - For **GCash**: Enter the customer's GCash receipt reference number (minimum 6 digits).
6. Tap **Confirm Payment**. The transaction is recorded, an official e-receipt is generated, and the order is instantly pushed to the barista queue.

### 2. Barista (Order Preparation & Inventory Deduction)
1. The right-hand **Barista Queue** automatically updates every 3 seconds.
2. The barista views the customer queue with exact drink specifications (e.g., `Spanish Latte (Grande - 22oz) x 1`).
3. Once the drink is brewed and served, the barista taps **Mark Complete**.
4. The system automatically marks the transaction `Completed` and deducts:
   - Specific cup (`22oz cup`) and `Flat lid`
   - Coffee beans, milk, and specialty syrups scaled to the 22oz serving size.

### 3. Inventory Monitoring & Restocking
1. The **Inventory** button in the header features an alert badge displaying the number of supplies at or below their restock threshold.
2. Open the **Raw Supplies Inventory** modal to:
   - Search supplies by name, unit (`g`, `ml`, `pcs`), or status (`low`, `ok`).
   - Log restock deliveries by entering quantities added.
   - Perform periodic stock takes and enter reconciled counts.
   - Set warning alert thresholds (e.g., alert when 16oz cups drop below 50 pcs).
   - Export standalone physical stock audits to Excel via **Export Excel**.

### 4. End-of-Day Sales Reporting & Accounting
1. Open the **Sales Report** workspace.
2. Select the desired period (**Today**, **Specific Date**, **7-Day Report**, **Specific Month**, or **Yearly Analytics**), or use the visual calendar drawer.
3. Review total gross sales, completed order count, Cash vs. GCash breakdown, and top-selling items.
4. Click **Export Excel (.xlsx)** to download the presentation-grade 7-worksheet master sales workbook (`DK_Cafe_Executive_Sales_*.xlsx`) for comprehensive financial audit and inventory reconciliation.

### 5. Predictive Machine Learning Demand Forecasting
1. Open the **Predictive Analytics** workspace via the topbar navigation or Manager Dashboard.
2. Toggle between **Daily**, **7-Day**, **Specific Month**, and **Yearly** forecasting horizons.
3. Review peak rush hours, recommended barista staffing allocations, and raw inventory reorder burn rates.
4. Click **Recalculate Model** to instantly retrain the Holt-Winters parameters from recent transaction data.
5. Click **Export Forecast (.xlsx)** to download the dedicated 7-sheet executive forecast workbook (`DK_Cafe_Executive_Predictive_Forecast_*.xlsx`).

---

## Customizing Recipes & Ingredient Measurements

To calibrate recipes or update ingredient estimates as measurements are refined, edit [`core/recipe_engine.php`](core/recipe_engine.php).

### Standard Cup Size Scale Multipliers
```php
const RECIPE_CUP_SIZES = [
    '12oz' => [
        'code'        => '12oz',
        'label'       => 'Hot',
        'oz'          => 12,
        'scale'       => 0.75,             // Scaled for 12oz Hot serving
        'cup_pattern' => '12oz Hot cup & lid',
        'lid_pattern' => null,             // Lid integrated into cup packaging
        'straw'       => null,             // Hot drinks do not serve straws
    ],
    '16oz' => [
        'code'        => '16oz',
        'label'       => 'Tall',
        'oz'          => 16,
        'scale'       => 1.00,             // Baseline scale (1.0x)
        'cup_pattern' => '16oz cup',       // Inventory supply match
        'lid_pattern' => 'Flat lid',
        'straw'       => 'Bendable straw',
    ],
    '22oz' => [
        'code'        => '22oz',
        'label'       => 'Grande',
        'oz'          => 22,
        'scale'       => 1.375,            // 37.5% larger volume
        'cup_pattern' => '22oz cup',
        'lid_pattern' => 'Flat lid',
        'straw'       => 'Bendable straw',
    ],
];
```

### Adjusting Recipe Profiles
In `ESTIMATED_CONSUMPTION_PROFILES`, specify exact quantities for servings:
```php
'matcha' => [
    'items' => [
        ['name' => 'Matcha Powder',   'qty_16oz' => 15.0,  'qty_22oz' => 22.0,  'unit' => 'g'],
        ['name' => 'Full Cream Milk', 'qty_16oz' => 180.0, 'qty_22oz' => 250.0, 'unit' => 'ml'],
        ['name' => 'Ice',             'qty_16oz' => 120.0, 'qty_22oz' => 160.0, 'unit' => 'g'],
    ],
    'requires_cup'   => true,
    'requires_lid'   => true,
    'requires_straw' => true,
],
```
When adjusting measurements, simply update the quantities in this array. All stock calculations, inventory deductions, and POS availability checks will automatically reflect the updated values across the entire system.

---

## Security & Offline Reliability
- **Strict Input Validation**: All POST requests validate numeric types, sanitize string inputs, and guard against SQL injection via PDO parameterized queries.
- **CSRF Defense**: All mutating requests auto-attach and validate session-scoped `X-CSRF-Token` headers.
- **Centavo Precision**: All financial calculations are evaluated in whole integer centavos internally to avoid floating-point rounding discrepancies.
- **Race Condition Prevention**: Order completion uses `FOR UPDATE` transaction locks to ensure inventory counts remain synchronized even during simultaneous order completions.
- **Brute-Force Rate Limiting**: Centralized `login_attempts` tracking with IP-based and username-based lockout thresholds (maximum 5 failed attempts in 15 minutes).
- **100% Offline Capable**: Zero external CDN dependencies, remote fonts, or third-party cloud APIs. All assets, SVGs, styles, and Chart.js scripts reside locally in the repository.

---

*Developed for DK Cafe — Simple, Fast, and Reliable POS & Inventory Management.*
