<?php
session_start();
// Cek sesi login
if (!isset($_SESSION['operator_id'])) {
    header('Location: login.php');
    exit;
}

// Sertakan file koneksi database
include 'db_connect.php';

// Inisialisasi status pesan
$status_message = '';
$status_class = '';
$reset_cart_flag = false;
$last_transaction_details = null; // Variabel untuk menyimpan detail transaksi sukses

// Data Kasir dan Operator diambil dari sesi setelah login
$current_kasir_id = $_SESSION['kasir_id'];
$current_operator_id = $_SESSION['operator_id'];

// --- LOGIKA LOGOUT ---
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// --- LOGIKA PEMROSESAN TRANSAKSI (CREATE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    
    // PERBAIKAN: Deklarasikan variabel $conn sebagai global
    global $conn;
    
    // Ambil data dari form tersembunyi
    $cart_json = $_POST['cart_data'];
    $total_final = (int)$_POST['total_final'];
    $disc_percent = (int)$_POST['disc_percent'];
    $ppn_percent = (int)$_POST['ppn_percent'];
    
    // Decode data keranjang
    $cart_data = json_decode($cart_json, true);
    
    // Lakukan validasi dasar (pengecekan di sisi server)
    if (empty($cart_data) || $total_final <= 0) {
        $status_message = "Gagal: Keranjang kosong atau total tidak valid.";
        $status_class = 'bg-red-100 border-red-400 text-red-700';
    } else {
        // Mulai transaksi database (untuk memastikan semua operasi berhasil/gagal bersamaan)
        $conn->begin_transaction();
        
        try {
            // 1. GENERATE ID TRANSAKSI UNIK (TRX)
            $id_transaksi = 'TRX' . date('Ymd') . time() . mt_rand(100, 999);
            $tanggal = date('Y-m-d H:i:s');
            
            // 2. INSERT ke tabel transaksi
            $sql_transaksi = "INSERT INTO transaksi (id_transaksi, id_kasir, id_operator, tanggal, disc, ppn, total) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt_transaksi = $conn->prepare($sql_transaksi);
            // Menggunakan $current_kasir_id dan $current_operator_id dari Sesi
            $stmt_transaksi->bind_param("ssssiii", $id_transaksi, $current_kasir_id, $current_operator_id, $tanggal, $disc_percent, $ppn_percent, $total_final);
            
            if (!$stmt_transaksi->execute()) {
                throw new Exception("Gagal insert Transaksi: " . $stmt_transaksi->error);
            }
            $stmt_transaksi->close();
            
            // 3. LOOP dan INSERT ke tabel transaksi_detail & UPDATE Stock
            $receipt_items = []; // Array untuk detail struk
            
            // Ambil nama item dari database (untuk ditampilkan di struk)
            $item_names = [];
            $item_id_list = array_map(fn($d) => $conn->real_escape_string($d['id_items']), $cart_data);
            $sql_get_names = "SELECT id_items, nama_item FROM items WHERE id_items IN ('" . implode("','", $item_id_list) . "')";
            $result_names = $conn->query($sql_get_names);
            if ($result_names) {
                while($row = $result_names->fetch_assoc()) {
                    $item_names[$row['id_items']] = $row['nama_item'];
                }
            }


            foreach ($cart_data as $detail) {
                $id_items = $detail['id_items'];
                $kuantitas = (int)$detail['quantity'];
                $subtotal_item = (int)$detail['subtotal'];
                
                // 3a. Update Stock di tabel items (Kurangi stock)
                $sql_update_stock = "UPDATE items SET stock = stock - ? WHERE id_items = ? AND stock >= ?";
                $stmt_update = $conn->prepare($sql_update_stock);
                $stmt_update->bind_param("isi", $kuantitas, $id_items, $kuantitas);
                
                if (!$stmt_update->execute()) {
                    throw new Exception("Gagal update Stock Item: " . $stmt_update->error);
                }
                
                if ($stmt_update->affected_rows === 0) {
                    throw new Exception("Stok untuk item '$id_items' tidak mencukupi atau item tidak ditemukan.");
                }
                $stmt_update->close();
                
                // 3b. Insert ke transaksi_detail
                // Menggunakan microtime() untuk ID yang unik dalam loop cepat
                $id_detail = 'DTL' . str_replace('.', '', microtime(true)) . mt_rand(100, 999);
                $sql_detail = "INSERT INTO transaksi_detail (id_detail, id_transaksi, id_items, kuantitas, subtotal)
                               VALUES (?, ?, ?, ?, ?)";
                
                $stmt_detail = $conn->prepare($sql_detail);
                $stmt_detail->bind_param("sssii", $id_detail, $id_transaksi, $id_items, $kuantitas, $subtotal_item);
                
                if (!$stmt_detail->execute()) {
                    throw new Exception("Gagal insert Transaksi Detail: " . $stmt_detail->error);
                }
                $stmt_detail->close();

                // Kumpulkan detail untuk struk
                $receipt_items[] = [
                    'nama' => $item_names[$id_items] ?? $id_items, // Gunakan nama item
                    'harga' => $subtotal_item / $kuantitas,
                    'qty' => $kuantitas,
                    'subtotal' => $subtotal_item,
                ];
            }
            
            // Commit transaksi jika semua berhasil
            $conn->commit();
            $status_message = "Transaksi $id_transaksi berhasil! Total: Rp " . number_format($total_final, 0, ',', '.') . ".";
            $status_class = 'bg-green-100 border-green-400 text-green-700';
            $reset_cart_flag = true; 

            // Simpan detail transaksi untuk ditampilkan di modal
            $last_transaction_details = json_encode([
                'id_transaksi' => $id_transaksi,
                'tanggal' => $tanggal,
                'kasir_id' => $current_kasir_id,
                'items' => $receipt_items,
                'disc_percent' => $disc_percent,
                'ppn_percent' => $ppn_percent,
                'subtotal' => array_sum(array_column($receipt_items, 'subtotal')),
                'total_final' => $total_final,
            ]);
            
        } catch (Exception $e) {
            // Rollback jika ada error
            $conn->rollback();
            $status_message = "Transaksi GAGAL diproses: " . $e->getMessage();
            $status_class = 'bg-red-100 border-red-400 text-red-700';
        }
    }
}

// --- FUNGSI MENGAMBIL DATA ITEM UNTUK JS ---
function get_available_items($conn) {
    // Ambil hanya item yang stoknya > 0
    $sql = "SELECT id_items, nama_item, harga, stock FROM items WHERE stock > 0 ORDER BY nama_item ASC";
    $result = $conn->query($sql);
    $items = [];
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $row['harga'] = (int)$row['harga']; // Pastikan harga adalah integer untuk JS
            $row['stock'] = (int)$row['stock']; // Pastikan stock adalah integer untuk JS
            $items[] = $row;
        }
    }
    return $items;
}

$available_items = get_available_items($conn);

// Tutup koneksi setelah semua operasi selesai
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Kasir - Vape Store</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .grid-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }
        @media (max-width: 1024px) {
            .grid-container {
                grid-template-columns: 1fr;
            }
        }
        .modal {
            background-color: rgba(0, 0, 0, 0.6);
            z-index: 50;
        }
    </style>
</head>
<body>

<div class="min-h-screen p-4 sm:p-8">
    <header class="mb-8 flex justify-between items-center">
        <h1 class="text-4xl font-extrabold text-gray-800">Sistem Kasir Transaksi</h1>
        <a href="kasir.php?logout=true" class="py-2 px-4 bg-red-600 text-white font-semibold rounded-lg shadow-md hover:bg-red-700 transition duration-300">
            Logout
        </a>
    </header>
    <p class="text-center text-gray-600 mb-6">Kasir ID: **<?php echo htmlspecialchars($current_kasir_id); ?>** | Operator ID: **<?php echo htmlspecialchars($current_operator_id); ?>**</p>

    <!-- Navigasi Utama -->
    <nav class="max-w-6xl mx-auto mb-6 flex justify-center space-x-4">
        <a href="index.php" class="py-2 px-4 bg-white text-purple-600 font-semibold rounded-lg shadow-md border-2 border-purple-600 hover:bg-purple-100 transition duration-300">
            Inventaris Item
        </a>
        <a href="kasir.php" class="py-2 px-4 bg-purple-600 text-white font-semibold rounded-lg shadow-md hover:bg-purple-700 transition duration-300">
            Sistem Kasir Transaksi
        </a>
    </nav>

    <main class="max-w-6xl mx-auto">
        <!-- Pesan Status/Feedback -->
        <?php if ($status_message): ?>
            <div role="alert" class="p-4 rounded-lg border mb-6 <?php echo $status_class; ?>">
                <p class="font-bold">Info Transaksi:</p>
                <p><?php echo htmlspecialchars($status_message); ?></p>
            </div>
        <?php endif; ?>

        <div class="grid-container">
            
            <!-- Kolom 1: Daftar Produk -->
            <div class="bg-white shadow-xl rounded-xl p-6 h-fit">
                <h2 class="text-2xl font-semibold text-purple-700 mb-4 border-b pb-2">Pilih Item (Stok Tersedia: <?php echo count($available_items); ?>)</h2>
                
                <!-- KOTAK PENCARIAN BARU -->
                <div class="mb-4">
                    <label for="itemSearch" class="sr-only">Cari Item</label>
                    <input type="text" id="itemSearch" oninput="filterItems()" 
                           placeholder="Cari item (tekan '/' untuk fokus)" 
                           class="w-full rounded-md border-gray-300 shadow-sm p-3 border focus:ring-purple-500 focus:border-purple-500">
                </div>
                <!-- AKHIR KOTAK PENCARIAN -->
                
                <?php if (empty($available_items)): ?>
                    <div class="text-center py-10 text-gray-500">
                        <p>Tidak ada item tersedia untuk dijual. Mohon cek inventaris.</p>
                    </div>
                <?php else: ?>
                    <div id="product-list" class="space-y-3 max-h-[70vh] overflow-y-auto pr-2">
                        <!-- Item cards akan di-render di sini oleh JavaScript -->
                    </div>
                <?php endif; ?>
            </div>

            <!-- Kolom 2: Keranjang dan Total -->
            <div class="bg-white shadow-xl rounded-xl p-6">
                <h2 class="text-2xl font-semibold text-green-700 mb-4 border-b pb-2">Keranjang Belanja</h2>
                
                <div class="overflow-x-auto mb-4">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase w-16">Qty</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody id="cart-table-body" class="bg-white divide-y divide-gray-200">
                            <!-- Detail keranjang akan di-render di sini oleh JavaScript -->
                            <tr id="empty-cart-message">
                                <td colspan="4" class="text-center py-4 text-gray-500 italic">Keranjang kosong. Tambahkan item!</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Summary Total -->
                <div class="space-y-2 border-t pt-4">
                    <div class="flex justify-between font-medium text-gray-700">
                        <span>Subtotal:</span>
                        <span id="subtotal-display">Rp 0</span>
                    </div>
                    <div class="flex justify-between items-center text-gray-700">
                        <label for="disc" class="font-medium">Diskon (%):</label>
                        <input type="number" id="disc" value="0" min="0" max="100" class="w-20 text-right rounded-md border-gray-300 shadow-sm p-1 border" oninput="calculateTotal()">
                    </div>
                    <div class="flex justify-between items-center text-gray-700">
                        <label for="ppn" class="font-medium">PPN (%):</label>
                        <input type="number" id="ppn" value="11" min="0" max="100" class="w-20 text-right rounded-md border-gray-300 shadow-sm p-1 border" oninput="calculateTotal()">
                    </div>
                    <div class="flex justify-between font-bold text-2xl text-purple-700 border-t pt-2">
                        <span>TOTAL AKHIR:</span>
                        <span id="grand-total-display">Rp 0</span>
                    </div>
                </div>

                <!-- Form Checkout (Hidden fields) -->
                <form id="checkout-form" method="POST" action="kasir.php" class="mt-6">
                    <input type="hidden" name="cart_data" id="cart-data-input">
                    <input type="hidden" name="total_final" id="total-final-input">
                    <input type="hidden" name="disc_percent" id="disc-percent-input">
                    <input type="hidden" name="ppn_percent" id="ppn-percent-input">
                    <button type="submit" name="checkout" id="checkout-button" disabled
                            class="w-full bg-green-500 text-white font-bold py-3 rounded-lg shadow-lg hover:bg-green-600 transition duration-300 disabled:opacity-50 disabled:cursor-not-allowed">
                        Proses Checkout (Rp 0)
                    </button>
                </form>
                
                <button type="button" onclick="clearCart()" class="w-full mt-2 text-sm text-red-500 hover:text-red-700">
                    Bersihkan Keranjang
                </button>
            </div>
            <!-- Akhir Kolom 2 -->
        </div>
    </main>

    <!-- MODAL STRUK TRANSAKSI BERHASIL -->
    <div id="receiptModal" class="fixed inset-0 hidden items-center justify-center modal">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg p-8 m-4 transform transition-all duration-300" onclick="event.stopPropagation()">
            <div class="flex flex-col items-center border-b pb-4 mb-4">
                <!-- Ikon Centang Hijau -->
                <svg class="w-16 h-16 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <h3 class="text-2xl font-bold text-gray-800 mt-2">Transaksi Berhasil!</h3>
                <p id="receipt-id" class="text-sm text-gray-500"></p>
                <p id="receipt-date" class="text-xs text-gray-400"></p>
            </div>

            <div class="mb-4">
                <p class="text-sm text-gray-600">Kasir: <span id="receipt-kasir-id" class="font-semibold text-gray-800"></span></p>
            </div>

            <!-- Detail Item Transaksi -->
            <div class="max-h-60 overflow-y-auto mb-4 border-y py-2">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-gray-500 text-left border-b">
                            <th class="py-1">Item</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Harga</th>
                            <th class="text-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="receipt-items-body">
                        <!-- Detail item akan diisi JS -->
                    </tbody>
                </table>
            </div>

            <!-- Ringkasan Total Struk -->
            <div class="space-y-1 text-sm">
                <div class="flex justify-between">
                    <span>Subtotal:</span>
                    <span id="receipt-subtotal" class="font-medium"></span>
                </div>
                <div class="flex justify-between">
                    <span>Diskon (<span id="receipt-disc-percent">0%</span>):</span>
                    <span id="receipt-disc-amount" class="font-medium text-red-500">Rp 0</span>
                </div>
                <div class="flex justify-between">
                    <span>PPN (<span id="receipt-ppn-percent">0%</span>):</span>
                    <span id="receipt-ppn-amount" class="font-medium">Rp 0</span>
                </div>
                <div class="flex justify-between font-bold text-lg border-t mt-2 pt-2">
                    <span>TOTAL BAYAR:</span>
                    <span id="receipt-grand-total" class="text-green-600"></span>
                </div>
            </div>

            <div class="mt-6 text-center">
                <button type="button" onclick="closeReceiptModal()" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-6 rounded-lg shadow-md transition duration-300">
                    Selesai & Transaksi Baru
                </button>
            </div>
        </div>
    </div>

</div>

<script>
    // Data Item yang tersedia dari PHP (stok > 0)
    let availableItems = <?php echo json_encode($available_items); ?>;
    let cart = []; // Array untuk menyimpan item di keranjang
    
    // Check jika ada flag dari PHP untuk reset keranjang
    const shouldResetCart = <?php echo $reset_cart_flag ? 'true' : 'false'; ?>;
    const lastTransactionDetails = <?php echo $last_transaction_details ?? 'null'; ?>;

    if (shouldResetCart) {
        // Jika transaksi berhasil, hapus keranjang lokal
        localStorage.removeItem('currentCart');
        cart = [];
    } else {
        // Ambil keranjang dari Local Storage jika ada
        const storedCart = localStorage.getItem('currentCart');
        if (storedCart) {
            cart = JSON.parse(storedCart);
        }
    }


    // Elemen DOM
    const itemSearchInput = document.getElementById('itemSearch'); // Element Pencarian BARU
    const productList = document.getElementById('product-list');
    const cartBody = document.getElementById('cart-table-body');
    const emptyCartMessage = document.getElementById('empty-cart-message');
    const subtotalDisplay = document.getElementById('subtotal-display');
    const grandTotalDisplay = document.getElementById('grand-total-display');
    const discInput = document.getElementById('disc');
    const ppnInput = document.getElementById('ppn');
    const checkoutButton = document.getElementById('checkout-button');
    const cartDataInput = document.getElementById('cart-data-input');
    const totalFinalInput = document.getElementById('total-final-input');
    const discPercentInput = document.getElementById('disc-percent-input');
    const ppnPercentInput = document.getElementById('ppn-percent-input');
    const receiptModal = document.getElementById('receiptModal');
    
    /**
     * Helper untuk memformat angka menjadi format mata uang Rupiah.
     */
    function formatRupiah(number) {
        if (typeof number !== 'number') {
            number = parseInt(number) || 0;
        }
        return 'Rp ' + number.toLocaleString('id-ID');
    }

    /**
     * FUNGSI BARU: Menyaring item berdasarkan input pencarian
     */
    function filterItems() {
        const query = itemSearchInput.value.toLowerCase();
        productList.innerHTML = '';
        
        availableItems.forEach(item => {
            // Filter berdasarkan nama item (atau ID jika diperlukan)
            const itemName = item.nama_item.toLowerCase();
            if (itemName.includes(query)) {
                
                const itemElement = document.createElement('div');
                itemElement.className = "flex items-center justify-between p-3 bg-white border border-gray-200 rounded-lg shadow-sm hover:shadow-md transition duration-200";
                
                itemElement.innerHTML = `
                    <div>
                        <p class="font-semibold text-gray-800">${item.nama_item}</p>
                        <p class="text-sm text-gray-500">${formatRupiah(item.harga)} / Stok: <span class="font-medium ${item.stock < 5 ? 'text-red-500' : 'text-green-600'}">${item.stock}</span></p>
                    </div>
                    <button onclick="addToCart('${item.id_items}')" 
                            class="bg-purple-500 text-white p-2 rounded-full hover:bg-purple-600 transition duration-300 transform hover:scale-105 shadow-md"
                            title="Tambah ke Keranjang">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
                        </svg>
                    </button>
                `;
                productList.appendChild(itemElement);
            }
        });
    }


    /**
     * Menampilkan modal struk transaksi.
     */
    function showReceiptModal(data) {
        const itemsBody = document.getElementById('receipt-items-body');
        itemsBody.innerHTML = '';

        // Header Struk
        document.getElementById('receipt-id').textContent = `ID Transaksi: ${data.id_transaksi}`;
        document.getElementById('receipt-date').textContent = `Tanggal: ${new Date(data.tanggal).toLocaleString('id-ID')}`;
        document.getElementById('receipt-kasir-id').textContent = data.kasir_id;

        // Detail Item
        data.items.forEach(item => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td class="py-1">${item.nama}</td>
                <td class="text-right">${item.qty} x</td>
                <td class="text-right">${formatRupiah(item.harga)}</td>
                <td class="text-right font-medium">${formatRupiah(item.subtotal)}</td>
            `;
            itemsBody.appendChild(row);
        });
        
        // Ringkasan Total
        const subtotal = data.subtotal;
        const discAmount = subtotal * (data.disc_percent / 100);
        const subtotalAfterDisc = subtotal - discAmount;
        const ppnAmount = subtotalAfterDisc * (data.ppn_percent / 100);

        document.getElementById('receipt-subtotal').textContent = formatRupiah(subtotal);
        
        document.getElementById('receipt-disc-percent').textContent = `${data.disc_percent}%`;
        document.getElementById('receipt-disc-amount').textContent = formatRupiah(Math.round(discAmount));

        document.getElementById('receipt-ppn-percent').textContent = `${data.ppn_percent}%`;
        document.getElementById('receipt-ppn-amount').textContent = formatRupiah(Math.round(ppnAmount));

        document.getElementById('receipt-grand-total').textContent = formatRupiah(data.total_final);
        
        receiptModal.classList.remove('hidden');
        receiptModal.classList.add('flex');
    }

    /**
     * Menutup modal struk transaksi.
     */
    function closeReceiptModal() {
        receiptModal.classList.add('hidden');
        receiptModal.classList.remove('flex');
    }

    // Menutup modal jika klik di luar box (Event Listener di Modal Backdrop)
    receiptModal.addEventListener('click', closeReceiptModal);
    
    /**
     * Menambahkan item ke keranjang atau menambah kuantitasnya.
     */
    function addToCart(itemId) {
        const item = availableItems.find(i => i.id_items === itemId);
        if (!item) return;

        const existingCartItem = cart.find(i => i.id_items === itemId);
        
        if (existingCartItem) {
            // Cek stok
            if (existingCartItem.quantity < item.stock) {
                existingCartItem.quantity += 1;
            } else {
                console.error(`Stok item ${item.nama_item} hanya tersisa ${item.stock}!`);
                alert(`Stok item ${item.nama_item} hanya tersisa ${item.stock}!`);
            }
        } else {
            // Cek stok awal
            if (item.stock > 0) {
                cart.push({
                    id_items: item.id_items,
                    nama_item: item.nama_item,
                    harga: item.harga,
                    stock_available: item.stock,
                    quantity: 1
                });
            } else {
                console.error(`Stok item ${item.nama_item} kosong!`);
                alert(`Stok item ${item.nama_item} kosong!`);
            }
        }
        renderCart();
    }
    
    /**
     * Mengubah kuantitas item dalam keranjang.
     */
    function updateQuantity(itemId, change) {
        const cartItem = cart.find(i => i.id_items === itemId);
        if (!cartItem) return;

        const itemData = availableItems.find(i => i.id_items === itemId);

        cartItem.quantity += change;

        // Batasi kuantitas
        if (cartItem.quantity < 1) {
            removeItem(itemId);
            return;
        }
        if (cartItem.quantity > itemData.stock) {
            cartItem.quantity = itemData.stock;
            console.error(`Stok maksimum item ${itemData.nama_item} hanya ${itemData.stock}!`);
            alert(`Stok maksimum item ${itemData.nama_item} hanya ${itemData.stock}!`);
        }

        renderCart();
    }

    /**
     * Menghapus item dari keranjang.
     */
    function removeItem(itemId) {
        cart = cart.filter(item => item.id_items !== itemId);
        renderCart();
    }
    
    /**
     * Membersihkan seluruh keranjang.
     */
    function clearCart() {
        if(confirm('Apakah Anda yakin ingin menghapus semua item dari keranjang?')) {
            cart = [];
            renderCart();
        }
    }

    /**
     * Menghitung subtotal, diskon, PPN, dan total akhir.
     */
    function calculateTotal() {
        let subtotal = 0;
        cart.forEach(item => {
            const harga = item.harga || 0;
            item.subtotal = harga * item.quantity;
            subtotal += item.subtotal;
        });
        
        const discPercent = parseFloat(discInput.value) || 0;
        const ppnPercent = parseFloat(ppnInput.value) || 0;
        
        const validDisc = Math.max(0, Math.min(100, discPercent));
        discInput.value = validDisc;

        const discAmount = subtotal * (validDisc / 100);
        const subtotalAfterDisc = subtotal - discAmount;

        const ppnAmount = subtotalAfterDisc * (ppnPercent / 100);
        
        const grandTotal = Math.round(subtotalAfterDisc + ppnAmount);
        
        // Update tampilan
        subtotalDisplay.textContent = formatRupiah(subtotal);
        grandTotalDisplay.textContent = formatRupiah(grandTotal);
        checkoutButton.textContent = `Proses Checkout (${formatRupiah(grandTotal)})`;
        
        // Update form tersembunyi untuk PHP
        const cartDataForPHP = cart.map(i => ({
            id_items: i.id_items,
            quantity: i.quantity,
            subtotal: i.subtotal
        }));
        
        cartDataInput.value = JSON.stringify(cartDataForPHP);
        totalFinalInput.value = grandTotal;
        discPercentInput.value = validDisc;
        ppnPercentInput.value = ppnPercent;
        
        // Simpan keranjang ke Local Storage
        localStorage.setItem('currentCart', JSON.stringify(cart));
        
        // Aktifkan tombol checkout jika keranjang tidak kosong
        checkoutButton.disabled = cart.length === 0 || grandTotal <= 0;
    }

    /**
     * Merender daftar produk yang tersedia di sisi kiri (Sekarang memanggil filterItems)
     */
    function renderProductList() {
        filterItems();
    }

    /**
     * Merender keranjang belanja di sisi kanan.
     */
    function renderCart() {
        cartBody.innerHTML = '';
        
        if (cart.length === 0) {
            cartBody.appendChild(emptyCartMessage);
            calculateTotal();
            return;
        }
        
        const existingEmptyMessage = document.getElementById('empty-cart-message');
        if(existingEmptyMessage) existingEmptyMessage.remove(); 

        cart.forEach(item => {
            const row = document.createElement('tr');
            row.classList.add('hover:bg-gray-50');
            row.innerHTML = `
                <td class="px-3 py-2 whitespace-nowrap text-sm font-medium text-gray-900">
                    ${item.nama_item}
                    <div class="text-xs text-gray-500">${formatRupiah(item.harga)}</div>
                </td>
                <td class="px-3 py-2 text-center whitespace-nowrap">
                    <div class="flex items-center justify-center space-x-1">
                        <button onclick="updateQuantity('${item.id_items}', -1)" class="text-gray-500 hover:text-red-500 font-bold px-1 rounded-full border border-gray-300 text-sm">-</button>
                        <span class="font-semibold text-sm w-4">${item.quantity}</span>
                        <button onclick="updateQuantity('${item.id_items}', 1)" class="text-gray-500 hover:text-green-500 font-bold px-1 rounded-full border border-gray-300 text-sm">+</button>
                    </div>
                </td>
                <td class="px-3 py-2 whitespace-nowrap text-right text-sm font-bold text-gray-800">${formatRupiah(item.harga * item.quantity)}</td>
                <td class="px-3 py-2 whitespace-nowrap text-right">
                    <button onclick="removeItem('${item.id_items}')" class="text-red-500 hover:text-red-700">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </td>
            `;
            cartBody.appendChild(row);
        });
        
        calculateTotal();
    }

    // Inisialisasi tampilan
    window.onload = function() {
        renderProductList(); // Memanggil filterItems untuk inisialisasi
        renderCart(); 
        
        // Panggil modal struk jika transaksi baru saja berhasil
        if (lastTransactionDetails) {
            showReceiptModal(lastTransactionDetails);
        }
    };
    
    // Shortcut Keyboard (Fokus ke Pencarian saat menekan '/' atau F8)
    document.addEventListener('keydown', function(e) {
        if (e.key === '/' || e.key === 'F8') {
            e.preventDefault();
            itemSearchInput.focus();
        }
    });
</script>

</body>
</html>