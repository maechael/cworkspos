# Arnold Bulan Mini Grocery: Inventory & POS

Laravel 12 (Blade) with React screens for the cashier, receiving and stock counts. MySQL from XAMPP.

## What's inside

- **Cashier (POS)**: scan with a USB barcode scanner, or type a product name. Rice and other kilo items ask for the weight. Type `3*barcode` to add 3 at once. Cash, GCash or card, discount, change, and a 58mm receipt. Keys: F2 scanner, F8 pay. The menu on the left can be minimized to icons or hidden (Ctrl+B), remembered per device. Voiding a line or clearing the order needs a supervisor's account password or passkey, unless an admin is the one logged in. Admins set a passkey on the User accounts page. Five wrong tries lock the check for 5 minutes, and every attempt is written to `storage/logs/laravel.log`. After it, an Undo toast stays up for 8 seconds (Ctrl+Z).
- **POS style** (Setup → POS style): pick the business type and the app recommends one of 8 cashier styles from the CodeWorks POS UI package: *Scan Pro*, *Café Tiles*, *Table Service*, *Self-Order Kiosk*, *Boutique*, *Booking & Services*, *Pocket* and *Counter Classic*. All 8 wear one green brand, with a choice of three greens (*Fresh Green*, *Leaf Green*, *Deep Forest*). The live preview shows the real cashier screen; "Try it live" opens it without saving. The "Which style fits which business?" guide is on the same page. Styles and recommendations are in `config/pos.php`, colors in `resources/js/components/pos/schemes.json`, and the shared cards, order panel and top bar in `resources/js/components/pos/Parts.jsx`. Table Service keeps each table's open order on that device until it is paid.
- **Products**: categories, units (piece, pack, case, kilo), cost and selling price, reorder level. Items with no barcode get an in-store code, and you can print barcode labels. One product can have many barcodes (edit the product and scan them under "More barcodes"), each saying how many pieces one scan counts as, so a box barcode adds every piece in the box at the piece price. Stock stays counted in pieces. A scan that isn't on file during Batch add or Receive delivery can be added to an existing product on the spot. A new product delivered in a box: in Batch add, tick "This barcode is on a box" and enter the pieces inside, and stock gets every piece. Pieces with no barcode of their own get an in-store code to print.
- **Promos** (on each product): Buy 1 Take 1, Buy 2 Take 1 or any Buy X Take Y, with an optional end date. The cashier scans every item, free ones included; the free ones come off by themselves, and the screen says when the customer has one more free to pick up. The receipt shows what was free. Not for items sold by weight.
- **Baskets** (Master data → Baskets): several items sold together at one price, such as a gift basket. Each basket has its own barcode (or an in-store code with a printable label) and shows what the customer saves and the profit per basket. At the cashier it scans like an item, or is under "Baskets". Selling one takes each item out of stock; the basket price is shared out by the items' regular prices so reports stay right, and voiding the sale puts every item back. A basket that was sold can only be turned off, not deleted.
- **Batch add** (Products → Batch add): scan items in any order. Scanning an item again adds 1 to its quantity, and a barcode that isn't in the system becomes a new product to fill in. Saving creates the new products and adds all the stock as one delivery. Unsaved scans are kept in that browser if the page is reloaded.
- **Receive delivery**: scan items as they are unpacked. Each delivery line keeps its own expiry date, and "+ another date" splits one item into two, such as boxes that expire differently. A blank date gets a reminder before saving. On a saved delivery's page, dates can be added or corrected, and "Some have another date" moves pieces still on the shelf to their own date.
- **Stock count**: scan the shelf, one scan per piece. Saving adjusts stock to what was counted.
- **Adjust stock** (on each product): enter what is really on the shelf and a reason, such as damaged or expired. Stock and expiry lots follow, and the stock history records the change, the reason and who made it. Admins only.
- **Dashboard**: a greeting with what needs attention, then charts for transactions per day and top sellers, each with a table view. A recent sales table for today, this week or this month, with filter, paging and CSV export, and a side rail with this week's deliveries and expiring lots, items expiring soon and low stock.- **Look of the admin pages**: a blue brand with a sidebar that collapses to icons and a light or dark theme, both remembered per device (buttons in the top bar). Colors are set in `resources/css/app.css`.
- **Reports**: sales by day, category and product; inventory value; expiring items; full stock movement history. Inventory value also takes dates (last 30 days by default): it shows the profit made in those dates, how long each item's stock lasts, and an estimate of the profit today's stock should make in the rest of the month and the next 30 days at that selling speed.
- **Users**: Owner/Admin sees everything. Cashier sees only the POS and their own sales, all in the POS's green with their own name at the top of the menu. Admin can void a sale (items go back to stock).

Stock leaves the earliest-expiring delivery first.

**Live updates:** the dashboard, products and sales pages are React screens that refresh themselves every 4 to 5 seconds
(`resources/js/useLive.js`). A sale on the cashier PC shows up on other devices without reloading, and changed stock
and new sales are briefly highlighted. Polling pauses while the browser tab is hidden.

**Phones and tablets:** below 1024px wide the menu slides in from the ☰ button, and the top bar keeps product search, Create and alerts. On a phone, a table with 5 or more columns shows each row as a card with labels (`resources/js/tableCards.js` and "Tables on a phone" in `resources/css/app.css`); give a table `table-keep` to keep it a table, or `table-stack` to make it cards. Printed reports stay tables. On touch screens, buttons and fields are at least 40px tall and fields use 16px text, so phones don't zoom in when one is tapped.

**Private:** every page tells search engines not to list it (an `X-Robots-Tag: noindex` header from `app/Http/Middleware/NoIndex.php`, plus a robots meta tag), so the app only opens for people given the link. `public/robots.txt` leaves crawling open on purpose, so search engines can see that noindex.

**Years of data:** the sales, stock history and expiry queries have indexes made for them (`database/migrations/2026_11_11_000002_add_indexes_for_large_data.php`), and were timed against 400,000 sales and 1.4 million sale lines. Stock history pages with Previous / Next instead of counting every row. The dashboard's top sellers are kept for a minute and the cashier's best-seller order for 10 minutes, because both are asked for constantly. Any query slower than a second is written to `storage/logs/laravel.log` with its page, and so is a list that runs one query per row (tests fail on that).

## Logins (change these after first use)

| Role | Username | Password |
|---|---|---|
| Owner / Admin | `arnold` | `arnold123` |
| Cashier | `cashier` | `cashier123` |

## Running it

1. Start **MySQL** in the XAMPP Control Panel. The database is `arnold_bulan_minigrocery`.
2. Either:
   - start **Apache** too and open http://localhost/arnold-bulan-minigrocery/, or
   - run `php artisan serve` in this folder and open http://localhost:8000

## First-time setup on another computer

```bash
composer install
npm install
npm run build
copy .env.example .env   # then set DB_DATABASE=arnold_bulan_minigrocery, DB_USERNAME=root
php artisan key:generate
php artisan migrate --seed
```

`php artisan migrate --seed` adds sample products, a delivery and a week of sales so the dashboard has data.
For the store's real start, remove `$this->call(DemoSeeder::class);` from `database/seeders/DatabaseSeeder.php`
and run `php artisan migrate:fresh --seed` (this erases all data).

## Barcode scanner

Any USB or wireless scanner in keyboard mode works with no driver. Open **Setup → Scanner test** and scan an item to check it. That page doesn't change stock or record a sale.

On the cashier, receiving and stock count screens, a scan works wherever the cursor is:

- It's recognized by speed (`resources/js/useScanner.js`), so normal typing isn't affected. If the scanner test says a code came in slowly, keep the cursor in the scan box when scanning.
- Anything the scan typed into another field is undone. The invoice number on Receive delivery is the exception, so a printed invoice barcode can be scanned into it.
- On the payment screen or the kilo weight dialog, a scan is rejected with a low beep and doesn't change the amount or complete the sale.
- On the "Sale complete" screen, a scan starts the next customer's sale.

A 12-digit UPC and the same code as a 13-digit EAN with a leading zero count as the same product.
