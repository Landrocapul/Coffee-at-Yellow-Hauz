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
        
        if (isset($_SESSION['cart'][$itemId])) {
            $_SESSION['cart'][$itemId]['quantity'] += $quantity;
        } else {
            $stmt = $pdo->prepare("SELECT id, name, price, image_url FROM menu_items WHERE id = ?");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            if ($item) {
                $_SESSION['cart'][$itemId] = [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'price' => $item['price'],
                    'image_url' => $item['image_url'],
                    'quantity' => $quantity
                ];
            }
        }
    } elseif ($action === 'update_quantity') {
        $itemId = (int)$_POST['item_id'];
        $quantity = (int)$_POST['quantity'];
        
        if ($quantity <= 0) {
            unset($_SESSION['cart'][$itemId]);
        } elseif (isset($_SESSION['cart'][$itemId])) {
            $_SESSION['cart'][$itemId]['quantity'] = $quantity;
        }
    } elseif ($action === 'remove_from_cart') {
        $itemId = (int)$_POST['item_id'];
        unset($_SESSION['cart'][$itemId]);
    } elseif ($action === 'clear_cart') {
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
<body class="bg-[#EAE8E3] h-screen w-screen p-4 md:p-6 flex items-center justify-center font-sans text-brand-black overflow-hidden">

    <div class="bg-vintage-paper w-full max-w-[1440px] h-full rounded-[32px] shadow-2xl flex overflow-hidden border border-gray-300">
        
        <!-- LEFT SIDEBAR -->
        <aside id="sidebar" class="w-[240px] bg-white border-r border-vintage-border flex flex-col justify-between py-6 px-4 shrink-0 z-10 transition-all duration-300 ease-in-out">
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

            <div class="flex-1 overflow-y-auto px-8 pb-32 pt-6">
                <!-- Categories -->
                <div class="flex gap-4 overflow-x-auto pb-4 hide-scrollbar">
                    <?php foreach ($categories as $category): ?>
                    <div class="min-w-[100px] h-[120px] <?php echo $category['id'] == $selectedCategoryId ? 'bg-brand text-brand-black' : 'bg-white'; ?> rounded-2xl flex flex-col items-center justify-center cursor-pointer shadow-sm border <?php echo $category['id'] == $selectedCategoryId ? 'border-brand/30' : 'border-gray-200 hover:border-brand'; ?> transition-all" onclick="selectCategory(<?php echo $category['id']; ?>)">
                        <div class="w-10 h-10 mb-2 flex items-center justify-center text-xl"><i class="<?php echo htmlspecialchars($category['icon']); ?>"></i></div>
                        <span class="font-bold text-sm"><?php echo htmlspecialchars($category['name']); ?></span>
                        <span class="text-xs <?php echo $category['id'] == $selectedCategoryId ? 'text-brand-black/70' : 'text-gray-400'; ?> mt-1"><?php echo $categoryCounts[$category['id']] ?? 0; ?> Items</span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Product Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3 gap-6 mt-4">
                    <?php foreach ($menuItems as $item): ?>
                    <div class="bg-white p-3 rounded-2xl shadow-sm border border-gray-200 flex flex-col hover:shadow-md transition-all group">
                        <div class="relative w-full h-[160px] rounded-xl overflow-hidden mb-3 bg-gray-100 border border-gray-100">
                            <?php if ($item['is_best_seller']): ?>
                            <span class="absolute top-2 left-2 bg-brand text-brand-black text-[10px] font-bold px-2 py-1 rounded-md z-10 uppercase tracking-wide border border-brand-black">Best Seller</span>
                            <?php endif; ?>
                            <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                        </div>
                        <h3 class="font-bold text-sm leading-tight mb-1 font-serif text-lg"><?php echo htmlspecialchars($item['name']); ?></h3>
                        <div class="flex justify-between items-end mt-auto pt-2">
                            <span class="font-bold text-brand-black"><?php echo formatCurrency($item['price']); ?></span>
                            <div class="flex items-center gap-1 text-[10px] text-gray-600 font-bold tracking-wider uppercase border border-gray-200 px-2 py-0.5 rounded bg-gray-50">
                                <?php echo strtoupper($item['temperature']); ?>
                            </div>
                        </div>
                        <button class="w-full mt-3 bg-gray-100 text-brand-black font-semibold py-2.5 rounded-xl text-sm hover:bg-brand hover:text-brand-black transition-colors border border-transparent hover:border-brand-black" onclick="addToCart(<?php echo $item['id']; ?>)">Add to Order</button>
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
            <div class="p-6 pb-4 border-b border-gray-100">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h2 class="text-2xl font-serif font-bold">Table 4</h2>
                        <p class="text-sm text-gray-500 font-medium mt-1"><?php echo htmlspecialchars($currentUser['full_name']); ?></p>
                    </div>
                    <button class="w-10 h-10 rounded-full bg-gray-100 text-brand-black hover:bg-brand hover:text-brand-black transition-colors flex items-center justify-center border border-transparent hover:border-brand-black">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                </div>

                <!-- Order Type Tabs -->
                <div class="bg-gray-100 p-1.5 rounded-xl flex items-center justify-between text-sm font-semibold border border-gray-200">
                    <button class="flex-1 py-2 bg-white rounded-lg shadow-sm border border-gray-300 text-brand-black">Dine in</button>
                    <button class="flex-1 py-2 text-gray-500 hover:text-brand-black">Take Away</button>
                    <button class="flex-1 py-2 text-gray-500 hover:text-brand-black">Delivery</button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-6 space-y-4">
                <?php if (empty($_SESSION['cart'])): ?>
                <div class="text-center py-8 text-gray-400">
                    <i class="fa-solid fa-cart-shopping text-4xl mb-3"></i>
                    <p class="text-sm font-medium">No items in cart</p>
                </div>
                <?php else: ?>
                <?php foreach ($_SESSION['cart'] as $item): ?>
                <div class="flex gap-3 border border-gray-200 p-3 rounded-2xl shadow-sm bg-white">
                    <img src="<?php echo htmlspecialchars($item['image_url']); ?>" class="w-14 h-14 rounded-xl object-cover shrink-0 border border-gray-100">
                    <div class="flex-1 flex flex-col justify-between">
                        <h4 class="text-sm font-serif font-bold leading-tight"><?php echo htmlspecialchars($item['name']); ?></h4>
                        <div class="flex justify-between items-center mt-1">
                            <span class="text-gray-500 text-xs"><?php echo formatCurrency($item['price']); ?></span>
                            <span class="text-xs font-bold px-2 py-0.5 bg-gray-100 rounded border border-gray-200"><?php echo $item['quantity']; ?>X</span>
                            <span class="font-bold text-sm"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></span>
                        </div>
                    </div>
                    <button class="text-gray-400 hover:text-red-500 transition-colors ml-2" onclick="removeFromCart(<?php echo $item['id']; ?>)">
                        <i class="fa-solid fa-trash text-sm"></i>
                    </button>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Totals & Payment Checkout -->
            <div class="p-6 bg-vintage-paper border-t border-gray-200 shrink-0">
                <div class="space-y-2 mb-4">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600 font-medium">Sub Total</span>
                        <span class="font-bold text-gray-800"><?php echo formatCurrency($subtotal); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600 font-medium">Tax <?php echo $taxRate; ?>%</span>
                        <span class="font-bold text-gray-800"><?php echo formatCurrency($taxAmount); ?></span>
                    </div>
                </div>
                
                <hr class="border-gray-300 border-dashed mb-4">
                
                <div class="flex justify-between items-center mb-6">
                    <span class="font-bold text-lg font-serif">Total Amount</span>
                    <span class="font-bold text-2xl text-brand-black"><?php echo formatCurrency($totalAmount); ?></span>
                </div>

                <!-- Payment Methods -->
                <div class="grid grid-cols-3 gap-3 mb-6">
                    <button class="flex flex-col items-center justify-center gap-2 p-3 border-2 border-brand-black bg-brand text-brand-black rounded-xl shadow-[2px_2px_0px_0px_rgba(23,23,23,1)] transition-transform active:translate-y-0.5 active:shadow-none">
                        <i class="fa-solid fa-money-bill-wave text-xl"></i>
                        <span class="text-xs font-bold uppercase tracking-wider">Cash</span>
                    </button>
                    <button class="flex flex-col items-center justify-center gap-2 p-3 border border-gray-300 rounded-xl hover:border-brand-black bg-white transition-colors">
                        <i class="fa-regular fa-credit-card text-xl text-gray-600"></i>
                        <span class="text-xs font-bold text-gray-600">CARD</span>
                    </button>
                    <button class="flex flex-col items-center justify-center gap-2 p-3 border border-gray-300 rounded-xl hover:border-brand-black bg-white transition-colors">
                        <i class="fa-solid fa-mobile-screen text-xl text-gray-600"></i>
                        <span class="text-xs font-bold text-gray-600">GCASH</span>
                    </button>
                </div>

                <!-- Action Button -->
                <button class="w-full bg-brand-black text-brand py-4 rounded-xl font-bold text-lg shadow-[4px_4px_0px_0px_rgba(251,191,36,1)] hover:bg-gray-800 transition-all active:translate-y-1 active:translate-x-1 active:shadow-none border border-transparent uppercase tracking-widest">
                    Print Bill
                </button>
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

    <script>
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

        // Sidebar toggle functionality
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const navTexts = document.querySelectorAll('.nav-text');
        let isCollapsed = false;

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
