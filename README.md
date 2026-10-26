# CafeFlow – POS & Inventory for DK Cafe

Runs on XAMPP (Apache + MySQL/MariaDB + PHP 8). No internet, cloud account or printer needed.

## Install (5 minutes)

1. Copy this folder to `C:\xampp\htdocs\cafeflow\`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin` → **Import** → choose `schema.sql` → **Go**.
   (Safe to import again later – it never wipes existing data.)
4. On the PC, open `http://localhost/cafeflow/`.
5. On the tablet (same Wi-Fi/router, internet not required), open
   `http://<PC-IP-address>/cafeflow/` – find the IP with `ipconfig` (IPv4 Address).
   Use the browser's "Add to Home Screen" for a full-screen app feel.

If your MySQL has a password, edit the `DB_*` constants at the top of `db.php`.

## Daily use

- **Cashier (left):** tap items → tap **Checkout · Cash** or **Checkout · GCash**. The order goes to the queue.
- **Barista (right):** the queue refreshes every 3 seconds. Tap **Mark Complete** when the drink is served –
  this also deducts ingredients from inventory.
- **Inventory:** restock deliveries, correct counts after a stock take, add new supplies.
  The orange badge shows how many items are at or below their alert level.
- **Sales Report:** totals, Cash vs GCash, top sellers and per-day sales.

## Changing the menu

Menu items live in the `menu_products` table (edit in phpMyAdmin):
`price`, `linked_inventory_id` (which inventory item it uses) and `deduction_amount`
(how much of that item one sale uses).

## Files

| File | Purpose |
|---|---|
| `schema.sql` | Database, tables, starter data |
| `index.php`, `style.css`, `script.js` | The single-screen app |
| `checkout.php` | Creates a Pending order |
| `fetch_queue.php` | Pending orders for the barista panel |
| `complete_order.php` | Marks Completed + deducts inventory |
| `fetch_menu.php`, `inventory_api.php`, `report.php` | Menu grid, Inventory modal, Sales Report modal |
| `db.php` | PDO connection and shared helpers |
