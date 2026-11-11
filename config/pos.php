<?php

/*
| Cashier screen styles, the brand greens they share, and which style suits which kind of business.
| The 8 styles come from the CodeWorks POS UI package, all in one green brand.
| Scheme colors live in resources/js/components/pos/schemes.json under the same keys.
*/

return [

    'schemes' => ['fresh-green' => 'Fresh Green', 'leaf-green' => 'Leaf Green', 'deep-forest' => 'Deep Forest'],

    'styles' => [
        'scanpro' => [
            'number' => '01', 'name' => 'Scan Pro', 'kicker' => 'Barcode-first',
            'description' => 'Made for a busy checkout lane. The cashier scans and each line lands in the order table, with one huge total and product cards for items that have no barcode.',
            'best_for' => ['Supermarket', 'Mini grocery', 'Convenience store', 'Pharmacy', 'Sari-sari with scanner'],
            'also' => 'hardware, bookstores, school supplies',
            'not_ideal' => 'restaurants, cafés, salons',
            'why' => ['Scan, beep, next item: about 2–3 seconds each', 'Every button has a keyboard key, so no mouse needed', 'Sell by piece, pack or kilo, with stock warnings on the line'],
            'hardware' => 'Barcode scanner · 80 mm printer · cash drawer · optional weighing scale',
        ],
        'cafe' => [
            'number' => '02', 'name' => 'Café Tiles', 'kicker' => 'Tap-to-add menu',
            'description' => 'Friendly and easy to learn. Product cards under category tiles, so new staff find items without memorizing the menu.',
            'best_for' => ['Coffee shop', 'Milk tea', 'Bakery café', 'Dessert bar', 'Kiosk café'],
            'also' => 'bakeries, juice bars, ice cream, food stalls',
            'not_ideal' => 'supermarkets, large catalogs over 150 items',
            'why' => ['Product cards let new staff find items without memorizing the menu', 'Category tiles on top, one tap to add', 'Big pay button for busy mornings'],
            'hardware' => '10–11" tablet on a stand · receipt printer',
        ],
        'table' => [
            'number' => '03', 'name' => 'Table Service', 'kicker' => 'Floor & kitchen',
            'description' => 'Starts from the floor plan, not the menu. Servers see every table at a glance, keep each table’s order open, and bill out at the end.',
            'best_for' => ['Restaurant', 'Carinderia with tables', 'Samgyupsal & grill', 'Bar & café-bar', 'Hotel dining'],
            'also' => 'resorts, events catering, KTV bars',
            'not_ideal' => 'retail stores, grab-and-go counters',
            'why' => ['Floor plan shows free, seated and waiting-for-bill tables by color', 'Each table keeps its own open order until bill out', 'Service charge and discount at payment'],
            'hardware' => 'Main terminal · waiter tablets · kitchen printer',
        ],
        'kiosk' => [
            'number' => '04', 'name' => 'Self-Order Kiosk', 'kicker' => 'Customer self-order',
            'description' => 'Customers order on their own. Huge cards and buttons, and an order number when they pay.',
            'best_for' => ['Fast food', 'Food court', 'Milk tea chain', 'Burger & chicken shop', 'Pizza'],
            'also' => 'cinemas, school canteens, amusement parks',
            'not_ideal' => 'small shops with one cashier, fine dining',
            'why' => ['Shorter lines at lunch rush: customers build their own order', 'Big category tiles and product cards', 'One clear Pay step'],
            'hardware' => '21–32" touch kiosk · card or QR payment reader · order-ready TV',
        ],
        'boutique' => [
            'number' => '05', 'name' => 'Boutique', 'kicker' => 'Variants & loyalty',
            'description' => 'Calm and uncluttered so the products stand out. One product at a time with its stock, plus "goes well with" suggestions.',
            'best_for' => ['Clothing boutique', 'Shoe store', 'Ukay-ukay (curated)', 'Cosmetics', 'Jewelry'],
            'also' => 'gift shops, bags & accessories, eyewear',
            'not_ideal' => 'food businesses, groceries',
            'why' => ['Each product shown large, with stock on hand', '"Goes well with" suggestions from the same category', 'Scan a tag or search by name'],
            'hardware' => 'Laptop or tablet · tag scanner · barcode label printer · receipt printer',
        ],
        'booking' => [
            'number' => '06', 'name' => 'Booking & Services', 'kicker' => 'Appointments first',
            'description' => 'Calm and clear. Services and retail products on one bill, with a simple day board of who is being served.',
            'best_for' => ['Salon', 'Barbershop', 'Spa & massage', 'Nail studio', 'Lash & brow bar'],
            'also' => 'dental & derma clinics, pet grooming, car wash, tutorial centers',
            'not_ideal' => 'stores with many products and no appointments',
            'why' => ['Services and retail products on the same bill', 'Day board of customers now being served', 'Large, calm checkout panel'],
            'hardware' => 'Front-desk tablet · receipt printer',
        ],
        'pocket' => [
            'number' => '07', 'name' => 'Pocket', 'kicker' => 'Phone-only',
            'description' => 'The whole POS on the owner’s phone. Quick items need one tap, and today’s sales are right on the screen.',
            'best_for' => ['Sari-sari store', 'Market stall', 'Food truck', 'Bazaar & pop-up', 'Small carinderia'],
            'also' => 'online sellers, home bakers, delivery riders',
            'not_ideal' => 'busy stores with long lines, sit-down restaurants',
            'why' => ['Start selling today on a phone you already own', 'Quick items need one tap; search or scan the rest', 'Cash, GCash or card, with a daily total on the screen'],
            'hardware' => 'Any Android phone · optional Bluetooth pocket printer',
        ],
        'counter' => [
            'number' => '08', 'name' => 'Counter Classic', 'kicker' => 'Keyboard & accounts',
            'description' => 'A no-nonsense workhorse for big catalogs. Dense lists, fast keyboard search and unit-aware quantities.',
            'best_for' => ['Hardware', 'Construction supply', 'Auto parts', 'Wholesale', 'Agri supply'],
            'also' => 'school & office supplies, electrical shops, feeds',
            'not_ideal' => 'cafés, restaurants, touch-only setups',
            'why' => ['Thousands of items: type a few letters and pick with the arrow keys', 'Sell by bag, meter, kilo, length or sheet', 'F-key bar for every action'],
            'hardware' => 'Desktop PC · keyboard · scanner · dot-matrix or A4 printer',
        ],
    ],

    // "Which style fits which business?": best = our pick, good = also works, everything else = not a fit
    'business_types' => [
        'supermarket' => ['name' => 'Supermarket', 'sub' => 'Many lanes, long lines', 'best' => 'scanpro', 'good' => ['counter'], 'icon' => 'cart'],
        'grocery' => ['name' => 'Mini grocery & convenience', 'sub' => '1–2 cashiers, barcodes', 'best' => 'scanpro', 'good' => ['pocket', 'counter'], 'icon' => 'cart'],
        'sarisari' => ['name' => 'Sari-sari store', 'sub' => 'Owner sells alone, small space', 'best' => 'pocket', 'good' => ['scanpro'], 'icon' => 'store'],
        'pharmacy' => ['name' => 'Pharmacy / botika', 'sub' => 'Generic names, SC/PWD, expiry', 'best' => 'scanpro', 'good' => ['counter'], 'icon' => 'pill'],
        'hardware' => ['name' => 'Hardware & construction supply', 'sub' => 'Big catalog, contractor accounts', 'best' => 'counter', 'good' => ['scanpro'], 'icon' => 'wrench'],
        'wholesale' => ['name' => 'Wholesale & distributor', 'sub' => 'Bulk units, credit terms', 'best' => 'counter', 'good' => ['scanpro'], 'icon' => 'box'],
        'coffee' => ['name' => 'Coffee shop & milk tea', 'sub' => 'Made-to-order drinks', 'best' => 'cafe', 'good' => ['kiosk', 'pocket'], 'icon' => 'cup'],
        'bakery' => ['name' => 'Bakery & pastry shop', 'sub' => 'Display case, cake orders', 'best' => 'cafe', 'good' => ['scanpro', 'pocket'], 'icon' => 'bread'],
        'restaurant' => ['name' => 'Restaurant (table service)', 'sub' => 'Dine-in, waiters, kitchen', 'best' => 'table', 'good' => ['cafe'], 'icon' => 'dish'],
        'carinderia' => ['name' => 'Carinderia / turo-turo', 'sub' => 'Point-and-pick ulam counter', 'best' => 'cafe', 'good' => ['table', 'pocket'], 'icon' => 'bowl'],
        'fastfood' => ['name' => 'Fast food & food court', 'sub' => 'Lunch-rush volume', 'best' => 'kiosk', 'good' => ['cafe'], 'icon' => 'burger'],
        'foodtruck' => ['name' => 'Food truck, bazaar & market stall', 'sub' => 'Mobile, often no power', 'best' => 'pocket', 'good' => ['cafe'], 'icon' => 'truck'],
        'clothing' => ['name' => 'Clothing & shoe store', 'sub' => 'Sizes, colors, exchanges', 'best' => 'boutique', 'good' => ['scanpro'], 'icon' => 'shirt'],
        'cosmetics' => ['name' => 'Cosmetics, gifts & accessories', 'sub' => 'Loyalty, variants', 'best' => 'boutique', 'good' => ['scanpro'], 'icon' => 'gift'],
        'salon' => ['name' => 'Salon, barbershop & spa', 'sub' => 'Appointments, commission', 'best' => 'booking', 'good' => ['pocket'], 'icon' => 'scissors'],
        'clinic' => ['name' => 'Clinic & pet grooming', 'sub' => 'Bookings, packages', 'best' => 'booking', 'good' => [], 'icon' => 'paw'],
        'carwash' => ['name' => 'Car wash & laundry shop', 'sub' => 'Job orders, queue', 'best' => 'booking', 'good' => ['pocket'], 'icon' => 'drop'],
    ],

    // The four questions at the bottom of the guide
    'questions' => [
        ['q' => 'Do customers bring items to a counter?', 'a' => 'Scan Pro. With 1,000+ items, bulk units or contractor accounts, go Counter Classic.'],
        ['q' => 'Do you make food or drinks to order?', 'a' => 'Café Tiles for a counter, Table Service if you have waiters and tables, Self-Order Kiosk for high volume.'],
        ['q' => 'Do you sell time, by appointment?', 'a' => 'Booking & Services: schedule, staff and commission come first.'],
        ['q' => 'Just you and a phone?', 'a' => 'Pocket. Start on the phone you have, then move to Scan Pro when you add a counter.'],
    ],

    'defaults' => ['business_type' => 'grocery', 'style' => 'scanpro', 'scheme' => 'fresh-green'],
];
