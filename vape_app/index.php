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

// --- LOGIKA LOGOUT ---
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// --- LOGIKA UTAMA: DELETE, UPDATE, CREATE ---

// 1. LOGIKA DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    
    global $conn; // Pastikan $conn tersedia
    
    $id_items = $conn->real_escape_string($_POST['delete_id']);
    
    // Perlu dicek apakah item digunakan di transaksi_detail
    // Jika item sudah pernah dijual (masuk transaksi_detail), lebih baik tidak dihapus secara permanen
    $sql_check = "SELECT id_detail FROM transaksi_detail WHERE id_items = '$id_items' LIMIT 1";
    $result_check = $conn->query($sql_check);

    if ($result_check && $result_check->num_rows > 0) {
        $status_message = "Gagal Hapus: Item $id_items tidak dapat dihapus karena sudah tercatat dalam transaksi. (Constraint Integrity)";
        $status_class = 'bg-red-100 border-red-400 text-red-700';
    } else {
        $sql_delete = "DELETE FROM items WHERE id_items = '$id_items'";
        if ($conn->query($sql_delete) === TRUE) {
            $status_message = "Item dengan ID '$id_items' berhasil dihapus.";
            $status_class = 'bg-green-100 border-green-400 text-green-700';
        } else {
            $status_message = "Error saat menghapus item: " . $conn->error;
            $status_class = 'bg-red-100 border-red-400 text-red-700';
        }
    }
}

// 2. LOGIKA UPDATE (Edit Item)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    
    global $conn; // Pastikan $conn tersedia
    
    $id_items = $conn->real_escape_string($_POST['edit_id_items']);
    $nama_item = $conn->real_escape_string($_POST['edit_nama_item']);
    $harga = (int)$_POST['edit_harga'];
    $stock = (int)$_POST['edit_stock'];

    if (empty($id_items) || empty($nama_item) || $harga <= 0 || $stock < 0) {
        $status_message = "Gagal Update: Semua field harus diisi dengan nilai valid.";
        $status_class = 'bg-red-100 border-red-400 text-red-700';
    } else {
        $sql_update = "UPDATE items SET nama_item = '$nama_item', harga = $harga, stock = $stock WHERE id_items = '$id_items'";
        if ($conn->query($sql_update) === TRUE) {
            $status_message = "Item '$nama_item' berhasil diperbarui.";
            $status_class = 'bg-green-100 border-green-400 text-green-700';
        } else {
            $status_message = "Error saat memperbarui item: " . $conn->error;
            $status_class = 'bg-red-100 border-red-400 text-red-700';
        }
    }
}

// 3. LOGIKA CREATE (TAMBAH ITEM BARU)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    
    global $conn; // Pastikan $conn tersedia
    
    // Ambil dan bersihkan data input
    $id_items = $conn->real_escape_string($_POST['id_items']);
    $nama_item = $conn->real_escape_string($_POST['nama_item']);
    $harga = (int)$_POST['harga'];
    $stock = (int)$_POST['stock'];

    // LOGIKA PENANGANAN ID ITEM UNIK (Otomatis jika kosong)
    if (empty($id_items)) {
        // ID Item menggunakan prefix ITM + tanggal + waktu + random
        $id_items = 'ITM' . date('Ymd') . time() . mt_rand(100, 999);
    }

    // Validasi dasar
    if (empty($nama_item) || $harga <= 0 || $stock < 0) {
        $status_message = "Semua field (kecuali ID Item jika ingin otomatis) harus diisi dan Harga/Stok harus bernilai positif.";
        $status_class = 'bg-red-100 border-red-400 text-red-700';
    } else {
        $sql_insert = "INSERT INTO items (id_items, nama_item, harga, stock) VALUES ('$id_items', '$nama_item', $harga, $stock)";

        try {
            if ($conn->query($sql_insert) === TRUE) {
                $status_message = "Item '$nama_item' berhasil ditambahkan dengan ID: $id_items!";
                $status_class = 'bg-green-100 border-green-400 text-green-700';
                $_POST = array(); // Clear form
            }
        } catch (mysqli_sql_exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                 $status_message = "Gagal: ID Item '$id_items' sudah ada. Mohon gunakan ID yang unik atau biarkan kosong untuk ID otomatis.";
            } else {
                $status_message = "Error saat menambahkan item: " . $e->getMessage();
            }
            $status_class = 'bg-red-100 border-red-400 text-red-700';
        }
    }
}

// Fungsi untuk mengambil semua data items
function get_items($conn) {
    $sql = "SELECT id_items, nama_item, harga, stock FROM items ORDER BY id_items DESC";
    $result = $conn->query($sql);
    $items = [];
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    }
    return $items;
}

// Panggil fungsi untuk mendapatkan data (dipanggil setelah operasi CRUD)
$items_data = get_items($conn);

// Tutup koneksi setelah semua operasi selesai
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Item - Vape Store</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
        .table-container::-webkit-scrollbar {
            height: 8px;
        }
        .table-container::-webkit-scrollbar-thumb {
            background-color: #a78bfa;
            border-radius: 4px;
        }
        /* Modal Backdrop */
        .modal-backdrop {
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 50;
        }
    </style>
</head>
<body>

<div class="min-h-screen p-4 sm:p-8">
    <header class="mb-8 flex justify-between items-center">
        <div>
            <h1 class="text-4xl font-bold text-gray-800">Sistem Manajemen Vape Store</h1>
            <p class="text-gray-600 mt-1">Daftar Inventaris Item | Operator: **<?php echo htmlspecialchars($_SESSION['operator_id']); ?>**</p>
        </div>
        <a href="index.php?logout=true" class="py-2 px-4 bg-red-600 text-white font-semibold rounded-lg shadow-md hover:bg-red-700 transition duration-300">
            Logout
        </a>
    </header>

    <!-- Navigasi Utama -->
    <nav class="max-w-6xl mx-auto mb-6 flex justify-center space-x-4">
        <a href="index.php" class="py-2 px-4 bg-purple-600 text-white font-semibold rounded-lg shadow-md hover:bg-purple-700 transition duration-300">
            Inventaris Item
        </a>
        <a href="kasir.php" class="py-2 px-4 bg-white text-purple-600 font-semibold rounded-lg shadow-md border-2 border-purple-600 hover:bg-purple-100 transition duration-300">
            Sistem Kasir Transaksi
        </a>
    </nav>

    <main class="max-w-6xl mx-auto bg-white shadow-xl rounded-xl p-6">
        
        <!-- Pesan Status/Feedback -->
        <?php if ($status_message): ?>
            <div role="alert" class="p-4 rounded-lg border mb-6 <?php echo $status_class; ?>">
                <p class="font-bold">Info:</p>
                <p><?php echo htmlspecialchars($status_message); ?></p>
            </div>
        <?php endif; ?>

        <!-- FORM TAMBAH ITEM BARU -->
        <div id="add-item-form-container" class="mb-8 p-6 bg-purple-50 border border-purple-200 rounded-xl shadow-inner">
            <h3 class="text-xl font-semibold text-purple-700 mb-4">Tambah Item Baru</h3>
            <form method="POST" action="index.php" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <!-- ID Item -->
                <div>
                    <label for="id_items" class="block text-sm font-medium text-gray-700">ID Item (Kosongkan untuk otomatis)</label>
                    <input type="text" name="id_items" id="id_items"
                           value="<?php echo isset($_POST['id_items']) ? htmlspecialchars($_POST['id_items']) : ''; ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:ring-purple-500 focus:border-purple-500 border">
                    <p class="text-xs text-gray-500 mt-1">Contoh: ITM001. Harus unik jika diisi.</p>
                </div>
                
                <!-- Nama Item -->
                <div>
                    <label for="nama_item" class="block text-sm font-medium text-gray-700">Nama Item</label>
                    <input type="text" name="nama_item" id="nama_item" required
                           value="<?php echo isset($_POST['nama_item']) ? htmlspecialchars($_POST['nama_item']) : ''; ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:ring-purple-500 focus:border-purple-500 border">
                </div>

                <!-- Harga -->
                <div>
                    <label for="harga" class="block text-sm font-medium text-gray-700">Harga (Rp)</label>
                    <input type="number" name="harga" id="harga" required min="1"
                           value="<?php echo isset($_POST['harga']) ? htmlspecialchars($_POST['harga']) : ''; ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:ring-purple-500 focus:border-purple-500 border">
                </div>

                <!-- Stok -->
                <div>
                    <label for="stock" class="block text-sm font-medium text-gray-700">Stok</label>
                    <input type="number" name="stock" id="stock" required min="0"
                           value="<?php echo isset($_POST['stock']) ? htmlspecialchars($_POST['stock']) : ''; ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:ring-purple-500 focus:border-purple-500 border">
                </div>
                
                <!-- Tombol Submit -->
                <div class="col-span-full flex justify-end mt-4">
                    <button type="submit" name="add_item" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-6 rounded-lg shadow-md transition duration-300 w-full md:w-auto">
                        Simpan Item
                    </button>
                </div>
            </form>
        </div>
        <!-- AKHIR FORM TAMBAH ITEM BARU -->

        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-semibold text-purple-700">Data Item (Stock)</h2>
        </div>

        <?php if (empty($items_data)): ?>
            <div class="text-center py-10 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                <p class="text-gray-500 font-medium">Tidak ada data item yang ditemukan. Silakan tambahkan item baru menggunakan formulir di atas.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto rounded-lg shadow-lg table-container">
                <table class="min-w-full divide-y divide-purple-200">
                    <thead class="bg-purple-100">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-purple-700 uppercase tracking-wider">ID Item</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-purple-700 uppercase tracking-wider">Nama Item</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-purple-700 uppercase tracking-wider">Harga (Rp)</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-purple-700 uppercase tracking-wider">Stok</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-purple-700 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($items_data as $item): ?>
                            <tr class="hover:bg-purple-50 transition duration-150">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900"><?php echo htmlspecialchars($item['id_items']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['nama_item']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">Rp <?php echo number_format($item['harga'], 0, ',', '.'); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold <?php echo $item['stock'] < 5 ? 'text-red-500' : 'text-green-600'; ?>"><?php echo htmlspecialchars($item['stock']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium flex space-x-2">
                                    <button onclick="openEditModal('<?php echo htmlspecialchars($item['id_items']); ?>', '<?php echo htmlspecialchars(addslashes($item['nama_item'])); ?>', <?php echo $item['harga']; ?>, <?php echo $item['stock']; ?>)" 
                                            class="text-indigo-600 hover:text-indigo-900 font-semibold p-1 rounded hover:bg-indigo-50 transition duration-150">
                                        Edit
                                    </button>
                                    <button onclick="confirmDelete('<?php echo htmlspecialchars($item['id_items']); ?>', '<?php echo htmlspecialchars(addslashes($item['nama_item'])); ?>')"
                                            class="text-red-600 hover:text-red-900 font-semibold p-1 rounded hover:bg-red-50 transition duration-150">
                                        Hapus
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <!-- MODAL EDIT ITEM -->
    <div id="editModal" class="fixed inset-0 hidden items-center justify-center modal-backdrop">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-lg p-6 m-4" onclick="event.stopPropagation()">
            <h3 class="text-xl font-bold text-purple-700 mb-4 border-b pb-2">Edit Data Item</h3>
            <form method="POST" action="index.php">
                <input type="hidden" name="edit_id_items" id="modal_id_items">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">ID Item (Tidak Bisa Diubah)</label>
                    <input type="text" id="modal_display_id" disabled class="mt-1 block w-full rounded-md bg-gray-100 p-2 border border-gray-300">
                </div>

                <div class="mb-4">
                    <label for="modal_nama_item" class="block text-sm font-medium text-gray-700">Nama Item</label>
                    <input type="text" name="edit_nama_item" id="modal_nama_item" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:ring-purple-500 focus:border-purple-500 border">
                </div>

                <div class="mb-4">
                    <label for="modal_harga" class="block text-sm font-medium text-gray-700">Harga (Rp)</label>
                    <input type="number" name="edit_harga" id="modal_harga" required min="1" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:ring-purple-500 focus:border-purple-500 border">
                </div>

                <div class="mb-6">
                    <label for="modal_stock" class="block text-sm font-medium text-gray-700">Stok</label>
                    <input type="number" name="edit_stock" id="modal_stock" required min="0" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:ring-purple-500 focus:border-purple-500 border">
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeEditModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded-lg transition duration-150">
                        Batal
                    </button>
                    <button type="submit" name="update_item" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-150">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- FORM DELETE TERSEMBUNYI -->
    <form id="delete-form" method="POST" action="index.php" class="hidden">
        <input type="hidden" name="delete_id" id="delete_id_input">
        <input type="hidden" name="delete_item">
    </form>

</div>

<script>
    // FUNGSI MODAL EDIT
    const editModal = document.getElementById('editModal');
    
    function openEditModal(id, nama, harga, stock) {
        document.getElementById('modal_id_items').value = id;
        document.getElementById('modal_display_id').value = id;
        document.getElementById('modal_nama_item').value = nama;
        document.getElementById('modal_harga').value = harga;
        document.getElementById('modal_stock').value = stock;
        
        editModal.classList.remove('hidden');
        editModal.classList.add('flex');
    }

    function closeEditModal() {
        editModal.classList.add('hidden');
        editModal.classList.remove('flex');
    }

    // Menutup modal jika klik di luar form
    editModal.addEventListener('click', closeEditModal);

    // FUNGSI KONFIRMASI DELETE
    function confirmDelete(id, nama) {
        // Menggunakan window.confirm() karena alert() dilarang
        if (confirm(`Apakah Anda yakin ingin menghapus item: ${nama} (${id})? Item ini akan dihapus jika belum pernah terjual (tidak ada di tabel transaksi).`)) {
            document.getElementById('delete_id_input').value = id;
            document.getElementById('delete-form').submit();
        }
    }
    
    // Pastikan tombol Edit tidak terhalang oleh modal
    editModal.querySelector('div').addEventListener('click', function(e) {
        e.stopPropagation();
    });
</script>

</body>
</html>