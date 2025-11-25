<?php
// Mulai sesi PHP
session_start();

// Sertakan file koneksi database
include 'db_connect.php';

$login_message = '';
$login_class = '';

// Cek apakah pengguna sudah login
if (isset($_SESSION['operator_id'])) {
    header('Location: index.php');
    exit;
}

// Proses form login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    
    // Perbaikan: Deklarasikan variabel $conn sebagai global
    global $conn;
    
    $username = $conn->real_escape_string($_POST['username']);
    $password = $conn->real_escape_string($_POST['password']);
    
    // 1. Ambil data operator berdasarkan username
    $sql = "SELECT id_operator, id_karyawan, password FROM operator WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $operator_data = $result->fetch_assoc();
        $stmt->close();
        
        // Catatan: Karena password di database Anda mungkin masih plaintext, kita bandingkan langsung.
        // Dalam aplikasi nyata, gunakan password_verify() setelah mengenkripsi password dengan password_hash()!
        if ($password === $operator_data['password']) {
            
            // 2. Ambil ID Kasir terkait (diasumsikan karyawan yang login adalah kasir)
            $id_karyawan = $operator_data['id_karyawan'];
            $sql_kasir = "SELECT id_kasir FROM kasir WHERE id_kasir = ?";
            $stmt_kasir = $conn->prepare($sql_kasir);
            $stmt_kasir->bind_param("s", $id_karyawan); 
            $stmt_kasir->execute();
            $result_kasir = $stmt_kasir->get_result();
            $kasir_id = $result_kasir->fetch_assoc()['id_kasir'] ?? $id_karyawan; // Fallback jika tidak ditemukan di tabel kasir
            $stmt_kasir->close();

            // Login berhasil
            $_SESSION['operator_id'] = $operator_data['id_operator'];
            $_SESSION['kasir_id'] = $kasir_id; // Simpan ID Kasir
            
            // Redirect ke halaman utama
            header('Location: index.php');
            exit;
            
        } else {
            $login_message = "Password salah.";
            $login_class = 'bg-red-100 border-red-400 text-red-700';
        }
    } else {
        $login_message = "Username tidak ditemukan.";
        $login_class = 'bg-red-100 border-red-400 text-red-700';
    }
    
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Vape Store App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #1f2937; /* Dark background */ }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen">

    <div class="w-full max-w-md bg-white rounded-xl shadow-2xl p-8">
        <h2 class="text-3xl font-bold text-center text-purple-600 mb-6">Sistem Login</h2>
        <p class="text-center text-gray-500 mb-8">Masuk sebagai Operator/Kasir</p>

        <?php if ($login_message): ?>
            <div role="alert" class="p-3 rounded-lg border mb-6 <?php echo $login_class; ?>">
                <p class="font-medium"><?php echo htmlspecialchars($login_message); ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="space-y-6">
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username" id="username" required 
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-3 focus:ring-purple-500 focus:border-purple-500 border"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" id="password" required
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-3 focus:ring-purple-500 focus:border-purple-500 border">
            </div>

            <button type="submit" name="login" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 px-4 rounded-lg shadow-md transition duration-300">
                Login
            </button>
        </form>
        
        <div class="mt-8 pt-4 border-t text-center">
            <p class="text-xs text-gray-500">Pastikan data di tabel 'operator' sudah terisi (misalnya OPR001).</p>
        </div>
    </div>

</body>
</html>