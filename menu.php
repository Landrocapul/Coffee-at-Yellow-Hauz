<?php
require_once 'db.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('index.php');
}

// Get current user info
$currentUser = getCurrentUser();

// Get categories
$stmt = $pdo->query("SELECT * FROM categories WHERE status = 'active' ORDER BY sort_order ASC");
$categories = $stmt->fetchAll();

// Get selected category (default to first category)
$selectedCategoryId = isset($_GET['category']) ? (int)$_GET['category'] : (isset($categories[0]['id']) ? $categories[0]['id'] : 0);

// Get menu items for selected category
$menuItems = [];
if ($selectedCategoryId > 0) {
    $stmt = $pdo->prepare("SELECT mi.*, c.name as category_name, c.icon as category_icon 
                          FROM menu_items mi 
                          JOIN categories c ON mi.category_id = c.id 
                          WHERE mi.category_id = ? AND mi.is_available = 1 
                          ORDER BY mi.sort_order ASC");
    $stmt->execute([$selectedCategoryId]);
    $menuItems = $stmt->fetchAll();
}

// Get category item counts
$categoryCounts = [];
foreach ($categories as $cat) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM menu_items WHERE category_id = ? AND is_available = 1");
    $stmt->execute([$cat['id']]);
    $categoryCounts[$cat['id']] = $stmt->fetch()['count'];
}

// Get tables
$stmt = $pdo->query("SELECT * FROM tables ORDER BY table_number ASC");
$tables = $stmt->fetchAll();

// Get active orders (for bottom bar)
$stmt = $pdo->prepare("SELECT o.*, t.table_number, u.full_name as cashier_name 
                      FROM orders o 
                      LEFT JOIN tables t ON o.table_id = t.id 
                      LEFT JOIN users u ON o.cashier_id = u.id 
                      WHERE o.status IN ('pending', 'processing') 
                      ORDER BY o.created_at DESC 
                      LIMIT 5");
$activeOrders = $stmt->fetchAll();

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Handle cart actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add_to_cart') {
        $itemId = (int)$_POST['item_id'];
        $quantity = (int)$_POST['quantity'];
        
        // Get current stock
        $stmt = $pdo->prepare("SELECT id, name, price, image_url, quantity FROM menu_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        
        if ($item) {
            // Check if enough stock available
            $currentCartQuantity = isset($_SESSION['cart'][$itemId]) ? $_SESSION['cart'][$itemId]['quantity'] : 0;
            $totalRequestedQuantity = $currentCartQuantity + $quantity;
            
            if ($totalRequestedQuantity > $item['quantity']) {
                // Not enough stock
                $_SESSION['error'] = "Not enough stock for " . htmlspecialchars($item['name']) . ". Available: " . $item['quantity'] . ", Requested: " . $totalRequestedQuantity;
            } else {
                // Enough stock, add to cart and reduce stock
                if (isset($_SESSION['cart'][$itemId])) {
                    $_SESSION['cart'][$itemId]['quantity'] += $quantity;
                } else {
                    $_SESSION['cart'][$itemId] = [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'price' => $item['price'],
                        'image_url' => $item['image_url'],
                        'quantity' => $quantity
                    ];
                }
                
                // Reduce stock immediately
                $stmt = $pdo->prepare("UPDATE menu_items SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");
                $stmt->execute([$quantity, $itemId, $quantity]);
            }
        }
    } elseif ($action === 'update_quantity') {
        $itemId = (int)$_POST['item_id'];
        $quantity = (int)$_POST['quantity'];
        
        if (isset($_SESSION['cart'][$itemId])) {
            $currentCartQuantity = $_SESSION['cart'][$itemId]['quantity'];
            
            if ($quantity <= 0) {
                // Return stock when removing from cart
                $stmt = $pdo->prepare("UPDATE menu_items SET quantity = quantity + ? WHERE id = ?");
                $stmt->execute([$currentCartQuantity, $itemId]);
                unset($_SESSION['cart'][$itemId]);
            } else {
                $quantityDiff = $quantity - $currentCartQuantity;
                
                if ($quantityDiff > 0) {
                    // Increasing quantity - check if enough stock available
                    $stmt = $pdo->prepare("SELECT quantity FROM menu_items WHERE id = ?");
                    $stmt->execute([$itemId]);
                    $availableStock = $stmt->fetchColumn();
                    
                    if ($quantityDiff > $availableStock) {
                        $_SESSION['error'] = "Not enough stock. Available: " . $availableStock . ", Requested additional: " . $quantityDiff;
                    } else {
                        // Reduce additional stock
                        $stmt = $pdo->prepare("UPDATE menu_items SET quantity = quantity - ? WHERE id = ?");
                        $stmt->execute([$quantityDiff, $itemId]);
                        $_SESSION['cart'][$itemId]['quantity'] = $quantity;
                    }
                } else {
                    // Decreasing quantity - return difference to stock
                    $stmt = $pdo->prepare("UPDATE menu_items SET quantity = quantity + ? WHERE id = ?");
                    $stmt->execute([abs($quantityDiff), $itemId]);
                    $_SESSION['cart'][$itemId]['quantity'] = $quantity;
                }
            }
        }
    } elseif ($action === 'remove_from_cart') {
        $itemId = (int)$_POST['item_id'];
        
        // Return stock to inventory
        if (isset($_SESSION['cart'][$itemId])) {
            $cartQuantity = $_SESSION['cart'][$itemId]['quantity'];
            $stmt = $pdo->prepare("UPDATE menu_items SET quantity = quantity + ? WHERE id = ?");
            $stmt->execute([$cartQuantity, $itemId]);
            unset($_SESSION['cart'][$itemId]);
        }
    } elseif ($action === 'clear_cart') {
        // Return all stock to inventory
        foreach ($_SESSION['cart'] as $itemId => $item) {
            $stmt = $pdo->prepare("UPDATE menu_items SET quantity = quantity + ? WHERE id = ?");
            $stmt->execute([$item['quantity'], $itemId]);
        }
        $_SESSION['cart'] = [];
    } elseif ($action === 'clear_cart_no_return') {
        // Clear cart without returning stock (for confirmed orders)
        $_SESSION['cart'] = [];
    }
    
    redirect('menu.php?category=' . $selectedCategoryId);
}

// Calculate cart totals
$subtotal = 0;
$taxRate = getSetting('tax_rate') ? (float)getSetting('tax_rate') : 12;
foreach ($_SESSION['cart'] as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$taxAmount = $subtotal * ($taxRate / 100);
$totalAmount = $subtotal + $taxAmount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yellow Hauz POS Dashboard</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,500&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        serif: ['Playfair Display', 'serif'],
                    },
                    colors: {
                        brand: {
                            DEFAULT: '#FBBF24',
                            light: '#FEF9C3',
                            dark: '#D97706',
                            black: '#171717',
                        },
                        vintage: {
                            paper: '#F5F4F0',
                            border: '#E5E5E5'
                        }
                    }
                }
            }
        }
    </script>
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #E5E5E5; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #A3A3A3; }
    </style>
</head>
<body class="bg-[#EAE8E3] h-screen w-screen p-3 font-sans text-brand-black overflow-hidden">

    <div class="bg-vintage-paper w-full h-full rounded-2xl shadow-2xl flex overflow-hidden border border-gray-300">
        
        
        <aside id="sidebar" class="w-[80px] bg-white border-r border-vintage-border flex flex-col justify-between py-6 px-4 shrink-0 z-10 transition-all duration-300 ease-in-out">
            <div>
                <!-- Logo -->
                <div class="flex flex-col items-center justify-center mb-10 mt-2 text-center">
                    <span class="font-serif italic text-sm text-gray-500 mb-1">Coffee at</span>
                    <h1 class="font-serif font-bold text-2xl leading-none text-brand-black tracking-tight uppercase">Yellow Hauz</h1>
                    <div class="flex items-center gap-2 mt-2">
                        <div class="h-px w-4 bg-brand"></div>
                        <span class="text-[10px] tracking-[0.2em] text-gray-400 uppercase font-semibold">Since 2007</span>
                        <div class="h-px w-4 bg-brand"></div>
                    </div>
                </div>

                <!-- Navigation -->
                <nav id="navigation" class="space-y-2">
                    <a href="menu.php" class="flex items-center gap-4 bg-brand-black text-brand px-4 py-3.5 rounded-2xl font-semibold shadow-md transition-all">
                        <i class="fa-solid fa-mug-hot w-5 text-center"></i> <span class="nav-text">Menu</span>
                    </a>
                    <a href="table.php" class="flex items-center gap-4 text-gray-500 hover:text-brand-black hover:bg-gray-100 px-4 py-3.5 rounded-2xl font-medium transition-all">
                        <i class="fa-solid fa-utensils w-5 text-center"></i> <span class="nav-text">Table Services</span>
                    </a>
                    <a href="ticket.php" class="flex items-center gap-4 text-gray-500 hover:text-brand-black hover:bg-gray-100 px-4 py-3.5 rounded-2xl font-medium transition-all">
                        <i class="fa-solid fa-receipt w-5 text-center"></i> <span class="nav-text">Tickets</span>
                    </a>
                    <?php if (isAdmin()): ?>
                    <a href="items.php" class="flex items-center gap-4 text-gray-500 hover:text-brand-black hover:bg-gray-100 px-4 py-3.5 rounded-2xl font-medium transition-all">
                        <i class="fa-solid fa-clipboard-list w-5 text-center"></i> <span class="nav-text">Manage Food Items</span>
                    </a>
                    <a href="sales.php" class="flex items-center gap-4 text-gray-500 hover:text-brand-black hover:bg-gray-100 px-4 py-3.5 rounded-2xl font-medium transition-all">
                        <i class="fa-solid fa-chart-line w-5 text-center"></i> <span class="nav-text">Sales Report</span>
                    </a>
                    <a href="analysis.php" class="flex items-center gap-4 text-gray-500 hover:text-brand-black hover:bg-gray-100 px-4 py-3.5 rounded-2xl font-medium transition-all">
                        <i class="fa-solid fa-chart-pie w-5 text-center"></i> <span class="nav-text">Product Analytics</span>
                    </a>
                    <a href="settings.php" class="flex items-center gap-4 text-gray-500 hover:text-brand-black hover:bg-gray-100 px-4 py-3.5 rounded-2xl font-medium transition-all">
                        <i class="fa-solid fa-gear w-5 text-center"></i> <span class="nav-text">Settings</span>
                    </a>
                    <?php endif; ?>
                </nav>
            </div>

            <!-- Bottom Users / Logout -->
            <div class="space-y-4">
                <div class="space-y-3 px-2">
                    <div class="flex items-center gap-3 cursor-pointer p-2 rounded-xl hover:bg-gray-100">
                        <div class="w-8 h-8 rounded-full bg-brand text-brand-black flex items-center justify-center text-xs font-bold relative">
                            <?php echo strtoupper(substr($currentUser['full_name'], 0, 2)); ?>
                            <span class="absolute top-0 right-0 w-2.5 h-2.5 bg-green-500 border-2 border-white rounded-full"></span>
                        </div>
                        <span class="text-sm font-medium nav-text"><?php echo htmlspecialchars($currentUser['full_name']); ?></span>
                    </div>
                </div>
                <hr class="border-gray-200">
                <a href="#" onclick="showLogoutModal()" class="flex items-center gap-3 text-gray-500 hover:text-brand-black px-4 py-2 font-medium transition-all">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> <span class="nav-text">Logout</span>
                </a>
            </div>
        </aside>

        <main class="flex-1 flex flex-col relative bg-vintage-paper">
            <!-- Top Header -->
            <header class="h-[88px] flex items-center justify-between px-8 shrink-0 border-b border-gray-200/50">
                <button id="sidebarToggle" class="w-10 h-10 bg-white rounded-xl shadow-sm border border-gray-200 flex items-center justify-center text-gray-500 hover:text-brand-black">
                    <i class="fa-solid fa-bars"></i>
                </button>
                
                <div class="flex-1 max-w-2xl mx-6 relative">
                    <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" placeholder="Search coffee, pastries, etc..." class="w-full bg-white h-12 rounded-full pl-12 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-brand shadow-sm border border-gray-200">
                </div>

                <button class="w-10 h-10 bg-white rounded-xl shadow-sm border border-gray-200 flex items-center justify-center text-gray-500 hover:text-brand-black">
                    <i class="fa-solid fa-sliders"></i>
                </button>
            </header>

            <div class="flex-1 overflow-y-auto px-8 pb-32 pt-6 flex gap-6">
                <!-- Categories Sidebar -->
                <div class="flex flex-col gap-3 shrink-0">
                    <?php foreach ($categories as $category): ?>
                    <div class="w-[100px] h-[100px] <?php echo $category['id'] == $selectedCategoryId ? 'bg-brand text-brand-black' : 'bg-white'; ?> rounded-2xl flex flex-col items-center justify-center cursor-pointer shadow-sm border <?php echo $category['id'] == $selectedCategoryId ? 'border-brand/30' : 'border-gray-200 hover:border-brand'; ?> transition-all" onclick="selectCategory(<?php echo $category['id']; ?>)">
                        <div class="w-10 h-10 mb-1 flex items-center justify-center text-xl"><i class="<?php echo htmlspecialchars($category['icon']); ?>"></i></div>
                        <span class="font-bold text-xs text-center leading-tight"><?php echo htmlspecialchars($category['name']); ?></span>
                        <span class="text-[10px] <?php echo $category['id'] == $selectedCategoryId ? 'text-brand-black/70' : 'text-gray-400'; ?> mt-0.5"><?php echo $categoryCounts[$category['id']] ?? 0; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Product Grid -->
                <div class="flex-1 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    <?php foreach ($menuItems as $item): ?>
                    <div class="bg-white p-3 rounded-2xl shadow-sm border border-gray-200 flex flex-col hover:shadow-md transition-all group cursor-pointer hover:border-brand" onclick="addToCart(<?php echo $item['id']; ?>)">
                        <div class="relative w-full h-[160px] rounded-xl overflow-hidden mb-3 bg-gray-100 border border-gray-100">
                            <?php if ($item['is_best_seller']): ?>
                            <span class="absolute top-2 left-2 bg-brand text-brand-black text-[10px] font-bold px-2 py-1 rounded-md z-10 uppercase tracking-wide border border-brand-black">Best Seller</span>
                            <?php endif; ?>
                            <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                        </div>
                        <h3 class="font-bold text-sm leading-tight mb-1 font-serif text-lg"><?php echo htmlspecialchars($item['name']); ?></h3>
                        <div class="flex justify-between items-end mt-auto pt-2">
                            <div class="flex flex-col">
                                <span class="font-bold text-brand-black"><?php echo formatCurrency($item['price']); ?></span>
                                <span class="text-xs text-gray-500">Qty: <?php echo $item['quantity'] ?? 0; ?></span>
                            </div>
                            <div class="flex items-center gap-1 text-[10px] text-gray-600 font-bold tracking-wider uppercase border border-gray-200 px-2 py-0.5 rounded bg-gray-50">
                                <?php echo strtoupper($item['temperature']); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Bottom Active Tickets Bar -->
            <div class="absolute bottom-6 left-8 right-8 flex gap-4">
                <?php foreach ($activeOrders as $order): ?>
                <div class="<?php echo $order['status'] == 'processing' ? 'bg-brand-black text-white border-2 border-brand' : 'bg-white'; ?> rounded-full pl-2 <?php echo $order['status'] == 'processing' ? 'pr-4' : 'pr-6'; ?> py-2 flex items-center gap-3 shadow-lg border border-gray-200 cursor-pointer">
                    <div class="w-10 h-10 rounded-full <?php echo $order['status'] == 'processing' ? 'bg-brand text-brand-black' : 'bg-brand text-brand-black border border-brand-black'; ?> font-bold flex items-center justify-center">T<?php echo $order['table_number'] ?? 'TA'; ?></div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h4 class="text-sm font-bold leading-tight"><?php echo htmlspecialchars(substr($order['customer_name'] ?? 'Guest', 0, 10)); ?><?php echo strlen($order['customer_name'] ?? '') > 10 ? '...' : ''; ?></h4>
                            <?php if ($order['status'] == 'processing'): ?>
                            <span class="bg-white/20 text-[10px] px-1.5 py-0.5 rounded text-white border border-white/10 uppercase tracking-wider">Process</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs <?php echo $order['status'] == 'processing' ? 'text-gray-300' : 'text-gray-400'; ?>"><?php echo $order['id']; ?> items &rarr; Bar</p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </main>

        <!-- RIGHT ORDER PANEL -->
        <aside class="w-[360px] bg-white border-l border-vintage-border flex flex-col shrink-0 z-10 shadow-[-10px_0_20px_rgba(0,0,0,0.02)]">
            
            <!-- Panel Header -->
            <div class="p-4 pb-3 border-b border-gray-100">
                <div class="flex justify-between items-start mb-3">
                    <div>
                        <h2 class="text-xl font-serif font-bold">Table 4</h2>
                        <p class="text-xs text-gray-500 font-medium mt-1"><?php echo htmlspecialchars($currentUser['full_name']); ?></p>
                    </div>
                    <div class="flex items-center gap-2">
                        <!-- Order Type Switch -->
                        <div class="bg-gray-100 p-1 rounded-lg flex items-center text-sm font-semibold border border-gray-200">
                            <button id="dineInBtn" onclick="setOrderType('dinein')" class="px-3 py-1.5 rounded-md bg-white text-brand-black font-bold shadow-sm border border-gray-300 transition-all">
                                <i class="fa-solid fa-utensils mr-1"></i>
                                Dine In
                            </button>
                            <button id="takeAwayBtn" onclick="setOrderType('takeaway')" class="px-3 py-1.5 rounded-md text-gray-500 hover:text-brand-black font-semibold transition-all">
                                <i class="fa-solid fa-bag-shopping mr-1"></i>
                                Take Out
                            </button>
                        </div>
                        <button class="w-8 h-8 rounded-full bg-gray-100 text-brand-black hover:bg-brand hover:text-brand-black transition-colors flex items-center justify-center border border-transparent hover:border-brand-black">
                            <i class="fa-solid fa-pen text-sm"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-4 space-y-3">
                <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-xl mb-4">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-exclamation-triangle"></i>
                        <span class="font-medium"><?php echo htmlspecialchars($_SESSION['error']); ?></span>
                    </div>
                </div>
                <?php unset($_SESSION['error']); ?>
                <?php else: ?>
                <?php if (empty($_SESSION['cart'])): ?>
                <div class="text-center py-8 text-gray-400">
                    <i class="fa-solid fa-cart-shopping text-4xl mb-3"></i>
                    <p class="text-sm font-medium">No items in cart</p>
                </div>
                <?php else: ?>
                <?php foreach ($_SESSION['cart'] as $item): ?>
                <div class="flex gap-2 border border-gray-200 p-2 rounded-xl shadow-sm bg-white">
                    <img src="<?php echo htmlspecialchars($item['image_url']); ?>" class="w-12 h-12 rounded-lg object-cover shrink-0 border border-gray-100">
                    <div class="flex-1 flex flex-col justify-between">
                        <h4 class="text-sm font-serif font-bold leading-tight"><?php echo htmlspecialchars($item['name']); ?></h4>
                        <div class="flex justify-between items-center mt-1">
                            <span class="text-gray-500 text-xs"><?php echo formatCurrency($item['price']); ?></span>
                            <div class="flex items-center gap-1">
                                <button onclick="updateQuantity(<?php echo $item['id']; ?>, <?php echo $item['quantity']; ?> - 1)" class="w-6 h-6 rounded bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-gray-800 flex items-center justify-center transition-colors">
                                    <i class="fa-solid fa-minus text-xs"></i>
                                </button>
                                <span class="text-xs font-bold px-2 py-0.5 bg-gray-100 rounded border border-gray-200 min-w-[30px] text-center"><?php echo $item['quantity']; ?></span>
                                <button onclick="updateQuantity(<?php echo $item['id']; ?>, <?php echo $item['quantity']; ?> + 1)" class="w-6 h-6 rounded bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-gray-800 flex items-center justify-center transition-colors">
                                    <i class="fa-solid fa-plus text-xs"></i>
                                </button>
                            </div>
                            <span class="font-bold text-sm"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></span>
                        </div>
                    </div>
                    <button class="text-gray-400 hover:text-red-500 transition-colors ml-2" onclick="removeFromCart(<?php echo $item['id']; ?>)">
                        <i class="fa-solid fa-trash text-sm"></i>
                    </button>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Totals & Payment Checkout -->
            <div class="p-4 bg-vintage-paper border-t border-gray-200 shrink-0">
                <div class="flex justify-between items-center mb-4">
                    <span class="font-bold text-base font-serif">Total Amount</span>
                    <span class="font-bold text-xl text-brand-black"><?php echo formatCurrency($totalAmount); ?></span>
                </div>

                <!-- Action Buttons -->
                <div class="grid grid-cols-3 gap-2">
                    <button onclick="showPaymentModal()" class="bg-brand-black text-brand py-3 rounded-xl font-bold text-sm hover:bg-gray-800 transition-colors border border-transparent flex items-center justify-center gap-2">
                        <i class="fa-solid fa-credit-card"></i>
                        Payment
                    </button>
                    <button onclick="showCouponModal()" class="bg-gray-100 text-brand-black py-3 rounded-xl font-bold text-sm hover:bg-gray-200 transition-colors border border-gray-300 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-ticket"></i>
                        Coupon
                    </button>
                    <button onclick="showBillModal()" class="bg-brand-black text-brand py-3 rounded-xl font-bold text-sm hover:bg-gray-800 transition-colors border border-transparent flex items-center justify-center gap-2">
                        <i class="fa-solid fa-print"></i>
                        Print Bill
                    </button>
                </div>
            </div>
        </aside>

    </div>

    <!-- Logout Confirmation Modal -->
    <div id="logoutModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-2xl p-6 max-w-md w-full mx-4 shadow-2xl border border-gray-200">
            <div class="flex items-center justify-center w-16 h-16 bg-red-100 rounded-full mx-auto mb-4">
                <i class="fa-solid fa-arrow-right-from-bracket text-red-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-serif font-bold text-brand-black text-center mb-2">Confirm Logout</h3>
            <p class="text-gray-600 text-center mb-6">Are you sure you want to logout? You will need to sign in again to access the system.</p>
            <div class="flex gap-3">
                <button onclick="hideLogoutModal()" class="flex-1 bg-gray-100 text-gray-700 py-3 rounded-xl font-bold hover:bg-gray-200 transition-colors">
                    Cancel
                </button>
                <a href="logout.php" class="flex-1 bg-red-600 text-white py-3 rounded-xl font-bold hover:bg-red-700 transition-colors text-center">
                    Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Bill Modal -->
    <div id="billModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-2xl max-w-md w-full mx-4 shadow-2xl border border-gray-200 max-h-[90vh] overflow-y-auto">
            <!-- Bill Header -->
            <div class="p-6 border-b border-gray-100">
                <div class="text-center mb-4">
                    <h3 class="text-2xl font-serif font-bold text-brand-black mb-2">Coffee at Yellow Hauz</h3>
                    <p class="text-xs text-gray-500">Yellow Hauz, Philippines</p>
                    <p class="text-xs text-gray-500">+63 912 345 6789</p>
                </div>
                
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <p class="text-sm font-bold text-brand-black">Table 4</p>
                        <p class="text-xs text-gray-500">Dine In</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-500">Order #<?php echo date('Ymd') . rand(100, 999); ?></p>
                        <p class="text-xs text-gray-500"><?php echo date('M d, Y h:i A'); ?></p>
                    </div>
                </div>
                
                <div class="text-center">
                    <p class="text-sm font-bold text-brand-black"><?php echo htmlspecialchars($currentUser['full_name']); ?></p>
                </div>
            </div>

            <!-- Bill Items -->
            <div class="p-6">
                <div class="space-y-3 mb-6">
                    <?php if (!empty($_SESSION['cart'])): ?>
                        <?php foreach ($_SESSION['cart'] as $item): ?>
                        <div class="flex justify-between items-start">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-brand-black"><?php echo htmlspecialchars($item['name']); ?></p>
                                <p class="text-xs text-gray-500"><?php echo $item['quantity']; ?> × <?php echo formatCurrency($item['price']); ?></p>
                            </div>
                            <p class="text-sm font-bold text-brand-black"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></p>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-center text-gray-400 text-sm">No items in cart</p>
                    <?php endif; ?>
                </div>

                <!-- Bill Summary -->
                <div class="border-t border-gray-200 pt-4 space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600 font-medium">Subtotal</span>
                        <span class="font-bold"><?php echo formatCurrency($subtotal); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600 font-medium">Tax (<?php echo $taxRate; ?>%)</span>
                        <span class="font-bold"><?php echo formatCurrency($taxAmount); ?></span>
                    </div>
                    <div class="flex justify-between text-lg font-bold pt-2 border-t border-gray-200">
                        <span class="text-brand-black">Total</span>
                        <span class="text-brand-black"><?php echo formatCurrency($totalAmount); ?></span>
                    </div>
                </div>
            </div>

            <!-- Bill Footer -->
            <div class="p-6 border-t border-gray-100 bg-gray-50">
                <p class="text-center text-xs text-gray-500 mb-4">Thank you for visiting Coffee at Yellow Hauz!</p>
                <div class="flex gap-3">
                    <button onclick="hideBillModal()" class="flex-1 bg-gray-100 text-gray-700 py-3 rounded-xl font-bold hover:bg-gray-200 transition-colors">
                        Close
                    </button>
                    <button onclick="printBill()" class="flex-1 bg-brand-black text-brand py-3 rounded-xl font-bold hover:bg-gray-800 transition-colors">
                        <i class="fa-solid fa-print mr-2"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-2xl p-6 max-w-md w-full mx-4 shadow-2xl border border-gray-200">
            <div class="flex items-center justify-center w-16 h-16 bg-brand-light rounded-full mx-auto mb-4">
                <i class="fa-solid fa-credit-card text-brand-dark text-2xl"></i>
            </div>
            <h3 class="text-xl font-serif font-bold text-brand-black text-center mb-2">Select Payment Method</h3>
            <p class="text-gray-600 text-center mb-6">Choose your preferred payment option</p>
            
            <div class="space-y-3">
                <button class="w-full flex items-center justify-between p-4 border-2 border-brand-black bg-brand text-brand-black rounded-xl shadow-[2px_2px_0px_0px_rgba(23,23,23,1)] transition-transform active:translate-y-0.5 active:shadow-none">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-money-bill-wave text-xl"></i>
                        <span class="font-bold">Cash</span>
                    </div>
                    <i class="fa-solid fa-check text-brand-black"></i>
                </button>
                
                <button class="w-full flex items-center justify-between p-4 border border-gray-300 rounded-xl hover:border-brand-black bg-white transition-colors">
                    <div class="flex items-center gap-3">
                        <i class="fa-regular fa-credit-card text-xl text-gray-600"></i>
                        <span class="font-bold text-gray-600">Card</span>
                    </div>
                    <i class="fa-solid fa-chevron-right text-gray-400"></i>
                </button>
                
                <button class="w-full flex items-center justify-between p-4 border border-gray-300 rounded-xl hover:border-brand-black bg-white transition-colors">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-mobile-screen text-xl text-gray-600"></i>
                        <span class="font-bold text-gray-600">GCash</span>
                    </div>
                    <i class="fa-solid fa-chevron-right text-gray-400"></i>
                </button>
            </div>
            
            <div class="flex gap-3 mt-6">
                <button onclick="hidePaymentModal()" class="flex-1 bg-gray-100 text-gray-700 py-3 rounded-xl font-bold hover:bg-gray-200 transition-colors">
                    Cancel
                </button>
                <button onclick="processPayment()" class="flex-1 bg-brand-black text-brand py-3 rounded-xl font-bold hover:bg-gray-800 transition-colors">
                    Process Payment
                </button>
            </div>
        </div>
    </div>

    <!-- Coupon Modal -->
    <div id="couponModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-2xl p-6 max-w-md w-full mx-4 shadow-2xl border border-gray-200">
            <div class="flex items-center justify-center w-16 h-16 bg-brand-light rounded-full mx-auto mb-4">
                <i class="fa-solid fa-ticket text-brand-dark text-2xl"></i>
            </div>
            <h3 class="text-xl font-serif font-bold text-brand-black text-center mb-2">Apply Coupon</h3>
            <p class="text-gray-600 text-center mb-6">Enter your coupon code to get discounts</p>
            
            <form class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Coupon Code</label>
                    <div class="relative">
                        <input type="text" placeholder="Enter coupon code" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-3 pr-12 text-sm focus:outline-none focus:ring-2 focus:ring-brand">
                        <button type="button" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-brand-black transition-colors">
                            <i class="fa-solid fa-qrcode"></i>
                        </button>
                    </div>
                </div>
                
                <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-600">Discount Applied</span>
                        <span class="font-bold text-green-600">-₱0.00</span>
                    </div>
                </div>
                
                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="hideCouponModal()" class="flex-1 bg-gray-100 text-gray-700 py-3 rounded-xl font-bold hover:bg-gray-200 transition-colors">
                        Cancel
                    </button>
                    <button type="button" onclick="applyCoupon()" class="flex-1 bg-brand-black text-brand py-3 rounded-xl font-bold hover:bg-gray-800 transition-colors">
                        Apply
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Initialize JavaScript variables
        let cart = <?php echo json_encode(array_values($_SESSION['cart'] ?? [])); ?>;
        let selectedTableId = null;
        let selectedCustomerName = null;
        let orderType = 'dine_in';

        // Initialize order type buttons
        document.addEventListener('DOMContentLoaded', function() {
            setOrderType('dinein');
        });

        function selectCategory(categoryId) {
            window.location.href = 'menu.php?category=' + categoryId;
        }

        function addToCart(itemId) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'menu.php?category=<?php echo $selectedCategoryId; ?>';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'add_to_cart';
            
            const itemIdInput = document.createElement('input');
            itemIdInput.type = 'hidden';
            itemIdInput.name = 'item_id';
            itemIdInput.value = itemId;
            
            const quantityInput = document.createElement('input');
            quantityInput.type = 'hidden';
            quantityInput.name = 'quantity';
            quantityInput.value = 1;
            
            form.appendChild(actionInput);
            form.appendChild(itemIdInput);
            form.appendChild(quantityInput);
            document.body.appendChild(form);
            form.submit();
        }

        function clearCartNoReturn() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'menu.php?category=<?php echo $selectedCategoryId; ?>';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'clear_cart_no_return';
            
            form.appendChild(actionInput);
            document.body.appendChild(form);
            form.submit();
        }

        function updateQuantity(itemId, newQuantity) {
            if (newQuantity < 1) {
                removeFromCart(itemId);
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'menu.php?category=<?php echo $selectedCategoryId; ?>';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'update_quantity';
            
            const itemIdInput = document.createElement('input');
            itemIdInput.type = 'hidden';
            itemIdInput.name = 'item_id';
            itemIdInput.value = itemId;
            
            const quantityInput = document.createElement('input');
            quantityInput.type = 'hidden';
            quantityInput.name = 'quantity';
            quantityInput.value = newQuantity;
            
            form.appendChild(actionInput);
            form.appendChild(itemIdInput);
            form.appendChild(quantityInput);
            document.body.appendChild(form);
            form.submit();
        }

        function removeFromCart(itemId) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'menu.php?category=<?php echo $selectedCategoryId; ?>';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'remove_from_cart';
            
            const itemIdInput = document.createElement('input');
            itemIdInput.type = 'hidden';
            itemIdInput.name = 'item_id';
            itemIdInput.value = itemId;
            
            form.appendChild(actionInput);
            form.appendChild(itemIdInput);
            document.body.appendChild(form);
            form.submit();
        }

        function showLogoutModal() {
            document.getElementById('logoutModal').classList.remove('hidden');
        }
        
        function hideLogoutModal() {
            document.getElementById('logoutModal').classList.add('hidden');
        }

        function showBillModal() {
            document.getElementById('billModal').classList.remove('hidden');
        }
        
        function hideBillModal() {
            document.getElementById('billModal').classList.add('hidden');
        }

        function printBill() {
            // First record the sale in sales report and ticket system
            recordSaleAndShowPrintConfirmation();
        }

        function recordSaleAndShowPrintConfirmation() {
            if (cart.length === 0) {
                showNotification('Cart is empty', 'error');
                return;
            }

            const orderData = {
                cart: cart,
                table_id: selectedTableId || null,
                customer_name: selectedCustomerName || 'Guest',
                order_type: orderType || 'dine_in',
                payment_method: 'cash' // Default payment method
            };

            fetch('api.php?action=create_order', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(orderData)
            })
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                return response.text();
            })
            .then(text => {
                console.log('Raw response:', text);
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        // Sale recorded successfully, show print confirmation
                        showPrintConfirmation(data.order_id, data.order_number);
                    } else {
                        showNotification('Failed to record sale: ' + data.error, 'error');
                    }
                } catch (e) {
                    console.error('JSON parse error:', e);
                    showNotification('Invalid response from server', 'error');
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                showNotification('Error recording sale', 'error');
            });
        }

        function showPrintConfirmation(orderId, orderNumber) {
            // Create confirmation modal
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50';
            modal.innerHTML = `
                <div class="bg-white rounded-2xl p-6 max-w-md w-full mx-4 shadow-2xl border border-gray-200">
                    <div class="flex items-center justify-center w-16 h-16 bg-green-100 rounded-full mx-auto mb-4">
                        <i class="fa-solid fa-check text-green-600 text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-serif font-bold text-brand-black text-center mb-2">Sale Recorded!</h3>
                    <p class="text-gray-600 text-center mb-2">Order #${orderNumber} has been saved to sales report and ticket system.</p>
                    <p class="text-sm text-gray-500 text-center mb-6">Would you like to print the receipt now?</p>
                    
                    <div class="flex gap-3">
                        <button onclick="closePrintConfirmation('${modal.id}')" class="flex-1 bg-gray-100 text-gray-700 py-3 rounded-xl font-bold hover:bg-gray-200 transition-colors">
                            Skip
                        </button>
                        <button onclick="confirmPrint('${modal.id}')" class="flex-1 bg-brand-black text-brand py-3 rounded-xl font-bold hover:bg-gray-800 transition-colors">
                            <i class="fa-solid fa-print mr-2"></i> Print
                        </button>
                    </div>
                </div>
            `;
            
            modal.id = 'printConfirmationModal';
            document.body.appendChild(modal);
        }

        function closePrintConfirmation(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.remove();
            }
            // Clear cart without returning stock and close bill modal after recording sale
            cart = [];
            selectedTableId = null;
            selectedCustomerName = null;
            clearCartNoReturn();
            hideBillModal();
        }

        function confirmPrint(modalId) {
            // Remove the confirmation modal
            closePrintConfirmation(modalId);
            
            // Show print preview
            window.print();
        }

        function updateCart() {
            // Reload the page to sync cart with server
            window.location.reload();
        }

        function showNotification(message, type = 'success') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-xl shadow-lg border transform transition-all duration-300 translate-x-full`;
            
            // Set colors based on type
            if (type === 'error') {
                notification.className += ' bg-red-50 border-red-200 text-red-700';
            } else if (type === 'warning') {
                notification.className += ' bg-yellow-50 border-yellow-200 text-yellow-700';
            } else {
                notification.className += ' bg-green-50 border-green-200 text-green-700';
            }
            
            notification.innerHTML = `
                <div class="flex items-center gap-3">
                    <i class="fa-solid ${type === 'error' ? 'fa-exclamation-circle' : type === 'warning' ? 'fa-exclamation-triangle' : 'fa-check-circle'}"></i>
                    <span class="font-medium">${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Slide in
            setTimeout(() => {
                notification.classList.remove('translate-x-full');
                notification.classList.add('translate-x-0');
            }, 100);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                notification.classList.add('translate-x-full');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        function showCouponModal() {
            document.getElementById('couponModal').classList.remove('hidden');
        }
        
        function hideCouponModal() {
            document.getElementById('couponModal').classList.add('hidden');
        }

        function applyCoupon() {
            // Placeholder for coupon application logic
            alert('Coupon functionality coming soon!');
            hideCouponModal();
        }

        function showPaymentModal() {
            document.getElementById('paymentModal').classList.remove('hidden');
        }
        
        function hidePaymentModal() {
            document.getElementById('paymentModal').classList.add('hidden');
        }

        function processPayment() {
            // Placeholder for payment processing logic
            alert('Payment processing coming soon!');
            hidePaymentModal();
        }

        function setOrderType(type) {
            const dineInBtn = document.getElementById('dineInBtn');
            const takeAwayBtn = document.getElementById('takeAwayBtn');
            
            if (type === 'dinein') {
                dineInBtn.className = 'px-3 py-1.5 rounded-md bg-white text-brand-black font-bold shadow-sm border border-gray-300 transition-all';
                takeAwayBtn.className = 'px-3 py-1.5 rounded-md text-gray-500 hover:text-brand-black font-semibold transition-all';
                orderType = 'dine_in';
            } else {
                dineInBtn.className = 'px-3 py-1.5 rounded-md text-gray-500 hover:text-brand-black font-semibold transition-all';
                takeAwayBtn.className = 'px-3 py-1.5 rounded-md bg-white text-brand-black font-bold shadow-sm border border-gray-300 transition-all';
                orderType = 'take_away';
            }
        }

        // Sidebar toggle functionality
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const navTexts = document.querySelectorAll('.nav-text');
        let isCollapsed = true;

        // Apply collapsed state by default
        sidebarToggle.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
        navTexts.forEach(text => {
            text.classList.add('hidden');
        });
        const navItems = document.querySelectorAll('#navigation a');
        navItems.forEach(item => {
            item.classList.add('justify-center');
            item.classList.remove('gap-4');
        });
        const logoText = sidebar.querySelector('h1');
        const logoSubtext = sidebar.querySelector('span.text-gray-500');
        const logoDivider = sidebar.querySelectorAll('.h-px');
        const logoSince = sidebar.querySelector('span.text-gray-400');
        if (logoText) logoText.classList.add('hidden');
        if (logoSubtext) logoSubtext.classList.add('hidden');
        if (logoSince) logoSince.classList.add('hidden');
        logoDivider.forEach(div => div.classList.add('hidden'));
        const userName = sidebar.querySelector('.text-sm.font-medium');
        if (userName) userName.classList.add('hidden');

        sidebarToggle.addEventListener('click', () => {
            isCollapsed = !isCollapsed;
            
            if (isCollapsed) {
                sidebar.classList.remove('w-[240px]');
                sidebar.classList.add('w-[80px]');
                sidebarToggle.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
                
                navTexts.forEach(text => {
                    text.classList.add('hidden');
                });
                
                const navItems = document.querySelectorAll('#navigation a');
                navItems.forEach(item => {
                    item.classList.add('justify-center');
                    item.classList.remove('gap-4');
                });
                
                const logoText = sidebar.querySelector('h1');
                const logoSubtext = sidebar.querySelector('span.text-gray-500');
                const logoDivider = sidebar.querySelectorAll('.h-px');
                const logoSince = sidebar.querySelector('span.text-gray-400');
                
                if (logoText) logoText.classList.add('hidden');
                if (logoSubtext) logoSubtext.classList.add('hidden');
                if (logoSince) logoSince.classList.add('hidden');
                logoDivider.forEach(div => div.classList.add('hidden'));
                
                const userName = sidebar.querySelector('.text-sm.font-medium');
                if (userName) userName.classList.add('hidden');
                
            } else {
                sidebar.classList.remove('w-[80px]');
                sidebar.classList.add('w-[240px]');
                sidebarToggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
                
                navTexts.forEach(text => {
                    text.classList.remove('hidden');
                });
                
                const navItems = document.querySelectorAll('#navigation a');
                navItems.forEach(item => {
                    item.classList.remove('justify-center');
                    item.classList.add('gap-4');
                });
                
                const logoText = sidebar.querySelector('h1');
                const logoSubtext = sidebar.querySelector('span.text-gray-500');
                const logoDivider = sidebar.querySelectorAll('.h-px');
                const logoSince = sidebar.querySelector('span.text-gray-400');
                
                if (logoText) logoText.classList.remove('hidden');
                if (logoSubtext) logoSubtext.classList.remove('hidden');
                if (logoSince) logoSince.classList.remove('hidden');
                logoDivider.forEach(div => div.classList.remove('hidden'));
                
                const userName = sidebar.querySelector('.text-sm.font-medium');
                if (userName) userName.classList.remove('hidden');
            }
        });
    </script>
</body>
</html>
