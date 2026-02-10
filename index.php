<?php
session_start();
ob_start();

/**
 * INVENTORY MANAGEMENT SYSTEM with CLIENT ORDERS MODULE
 * For Small to Medium Businesses
 * 
 * Roles: Admin (full access), Staff (limited access)
 * 
 * @author Senior Full-Stack Developer
 * @version 2.0.0
 */

// ==================== DATABASE CONFIGURATION ====================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'inventory_db');

// ==================== APPLICATION CONSTANTS ====================
define('SITE_NAME', 'Inventory Management System');
define('LOW_STOCK_THRESHOLD', 10);
define('CURRENCY', 'ZMW');

// ==================== DATABASE CONNECTION ====================
class Database {
    private $pdo;
    
    public function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create database if not exists
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
            $this->pdo->exec("USE " . DB_NAME);
            
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    public function createTables() {
        try {
            // Drop tables if they exist
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            
            $tables = ['order_items', 'client_orders', 'clients', 'stock_movements', 'products', 'categories', 'suppliers', 'users'];
            foreach ($tables as $table) {
                $this->pdo->exec("DROP TABLE IF EXISTS $table");
            }
            
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            
            // Users table
            $this->pdo->exec("CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                email VARCHAR(100),
                role ENUM('admin','staff') DEFAULT 'staff',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                status ENUM('active','inactive') DEFAULT 'active'
            )");
            
            // Categories table
            $this->pdo->exec("CREATE TABLE categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) UNIQUE NOT NULL,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            
            // Suppliers table
            $this->pdo->exec("CREATE TABLE suppliers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                contact_person VARCHAR(100),
                email VARCHAR(100),
                phone VARCHAR(20),
                address TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            
            // Clients table
            $this->pdo->exec("CREATE TABLE clients (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                email VARCHAR(100),
                phone VARCHAR(20),
                address TEXT,
                company VARCHAR(200),
                contact_person VARCHAR(100),
                tax_number VARCHAR(50),
                status ENUM('active','inactive') DEFAULT 'active',
                notes TEXT,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )");
            
            // Products table
            $this->pdo->exec("CREATE TABLE products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sku VARCHAR(50) UNIQUE NOT NULL,
                name VARCHAR(200) NOT NULL,
                description TEXT,
                category_id INT,
                supplier_id INT,
                unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
                cost_price DECIMAL(10,2) NOT NULL DEFAULT 0,
                quantity INT NOT NULL DEFAULT 0,
                reorder_level INT NOT NULL DEFAULT 10,
                location VARCHAR(100),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
            
            // Client orders table
            $this->pdo->exec("CREATE TABLE client_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_number VARCHAR(50) UNIQUE NOT NULL,
                client_id INT NOT NULL,
                order_date DATE NOT NULL,
                delivery_date DATE,
                status ENUM('pending','confirmed','processing','shipped','delivered','cancelled') DEFAULT 'pending',
                subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
                tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                due_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                payment_status ENUM('pending','partial','paid') DEFAULT 'pending',
                payment_method ENUM('cash','bank_transfer','credit_card','check','online') DEFAULT 'cash',
                notes TEXT,
                user_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                cancelled_at TIMESTAMP NULL,
                cancelled_by INT,
                cancellation_reason TEXT,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL
            )");
            
            // Order items table
            $this->pdo->exec("CREATE TABLE order_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                product_id INT NOT NULL,
                quantity INT NOT NULL,
                unit_price DECIMAL(10,2) NOT NULL,
                total_price DECIMAL(10,2) NOT NULL,
                notes VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (order_id) REFERENCES client_orders(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
            )");
            
            // Stock movements table
            $this->pdo->exec("CREATE TABLE stock_movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                movement_type ENUM('in','out') NOT NULL,
                quantity INT NOT NULL,
                unit_price DECIMAL(10,2),
                reference VARCHAR(150),
                order_id INT NULL,
                notes TEXT,
                user_id INT NOT NULL,
                movement_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (order_id) REFERENCES client_orders(id) ON DELETE SET NULL
            )");
            
            // Add foreign keys to products
            $this->pdo->exec("ALTER TABLE products 
                ADD FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
                ADD FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL");
            
            // Insert default admin user
            $checkAdmin = $this->pdo->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
            if ($checkAdmin->rowCount() == 0) {
                $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
                $stmt = $this->pdo->prepare("INSERT INTO users (username, password, full_name, email, role) 
                                  VALUES (?, ?, ?, ?, ?)");
                $stmt->execute(['admin', $hashedPassword, 'System Administrator', 'admin@inventory.com', 'admin']);
                
                // Insert default staff user
                $stmt->execute(['staff', $hashedPassword, 'Staff User', 'staff@inventory.com', 'staff']);
            }
            
            // Insert sample data
            $this->insertSampleData();
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Table creation error: " . $e->getMessage());
            return false;
        }
    }
    
    private function insertSampleData() {
        // Sample categories
        $categories = [
            ['name' => 'Electronics', 'description' => 'Electronic devices and components'],
            ['name' => 'Office Supplies', 'description' => 'Office equipment and supplies'],
            ['name' => 'Furniture', 'description' => 'Office furniture and equipment'],
            ['name' => 'Stationery', 'description' => 'Paper and writing materials'],
        ];
        
        foreach ($categories as $cat) {
            $stmt = $this->pdo->prepare("INSERT IGNORE INTO categories (name, description) VALUES (?, ?)");
            $stmt->execute([$cat['name'], $cat['description']]);
        }
        
        // Sample suppliers
        $suppliers = [
            ['name' => 'Tech Suppliers Inc', 'contact_person' => 'John Doe', 'email' => 'john@techsuppliers.com', 'phone' => '123-456-7890', 'address' => '123 Tech Street'],
            ['name' => 'Office World', 'contact_person' => 'Jane Smith', 'email' => 'jane@officeworld.com', 'phone' => '987-654-3210', 'address' => '456 Office Ave'],
            ['name' => 'Furniture Hub', 'contact_person' => 'Robert Johnson', 'email' => 'robert@furniturehub.com', 'phone' => '555-123-4567', 'address' => '789 Design Road'],
        ];
        
        foreach ($suppliers as $sup) {
            $stmt = $this->pdo->prepare("INSERT IGNORE INTO suppliers (name, contact_person, email, phone, address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$sup['name'], $sup['contact_person'], $sup['email'], $sup['phone'], $sup['address']]);
        }
        
        // Sample clients
        $clients = [
            ['name' => 'ABC Corporation', 'email' => 'purchasing@abccorp.com', 'phone' => '011-123-4567', 'address' => '123 Business Ave, Lusaka', 'company' => 'ABC Corporation', 'contact_person' => 'John Purchaser'],
            ['name' => 'XYZ Enterprises', 'email' => 'orders@xyzenterprises.com', 'phone' => '021-987-6543', 'address' => '456 Trade Street, Ndola', 'company' => 'XYZ Enterprises', 'contact_person' => 'Sarah Manager'],
            ['name' => 'Small Business Ltd', 'email' => 'info@smallbusiness.co.zm', 'phone' => '096-555-1234', 'address' => '789 Market Road, Kitwe', 'company' => 'Small Business Ltd', 'contact_person' => 'Mike Owner'],
        ];
        
        foreach ($clients as $client) {
            $stmt = $this->pdo->prepare("INSERT IGNORE INTO clients (name, email, phone, address, company, contact_person) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$client['name'], $client['email'], $client['phone'], $client['address'], $client['company'], $client['contact_person']]);
        }
        
        // Sample products
        $products = [
            ['sku' => 'LAP-001', 'name' => 'Laptop', 'description' => '15-inch laptop', 'category_id' => 1, 'supplier_id' => 1, 'unit_price' => 999.99, 'cost_price' => 750.00, 'quantity' => 15, 'reorder_level' => 5, 'location' => 'Shelf A1'],
            ['sku' => 'MON-002', 'name' => 'Monitor', 'description' => '24-inch monitor', 'category_id' => 1, 'supplier_id' => 1, 'unit_price' => 299.99, 'cost_price' => 200.00, 'quantity' => 8, 'reorder_level' => 3, 'location' => 'Shelf B2'],
            ['sku' => 'DESK-003', 'name' => 'Office Desk', 'description' => 'Wooden office desk', 'category_id' => 3, 'supplier_id' => 3, 'unit_price' => 499.99, 'cost_price' => 350.00, 'quantity' => 5, 'reorder_level' => 2, 'location' => 'Warehouse'],
            ['sku' => 'CHAIR-004', 'name' => 'Office Chair', 'description' => 'Ergonomic office chair', 'category_id' => 3, 'supplier_id' => 3, 'unit_price' => 199.99, 'cost_price' => 120.00, 'quantity' => 12, 'reorder_level' => 4, 'location' => 'Shelf C3'],
            ['sku' => 'PRINT-005', 'name' => 'Printer', 'description' => 'Color laser printer', 'category_id' => 1, 'supplier_id' => 1, 'unit_price' => 399.99, 'cost_price' => 280.00, 'quantity' => 6, 'reorder_level' => 2, 'location' => 'Shelf B1'],
        ];
        
        foreach ($products as $prod) {
            $stmt = $this->pdo->prepare("INSERT IGNORE INTO products (sku, name, description, category_id, supplier_id, unit_price, cost_price, quantity, reorder_level, location) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $prod['sku'], $prod['name'], $prod['description'], 
                $prod['category_id'], $prod['supplier_id'], $prod['unit_price'],
                $prod['cost_price'], $prod['quantity'], $prod['reorder_level'], 
                $prod['location']
            ]);
        }
    }
}

// Initialize database
$db = new Database();
$pdo = $db->getConnection();

// Check if we need to reset database
if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    $db->createTables();
    $_SESSION['success'] = "Database reset successfully!";
    header("Location: index.php");
    exit();
}

// Check if tables exist, if not create them
try {
    $result = $pdo->query("SELECT 1 FROM users LIMIT 1");
} catch (PDOException $e) {
    // Tables don't exist, create them
    $db->createTables();
}

// ==================== AUTHENTICATION & SESSION MANAGEMENT ====================
class Auth {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function login($username, $password) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            return true;
        }
        return false;
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] == 'admin';
    }
    
    public function logout() {
        session_destroy();
        header("Location: index.php");
        exit();
    }
    
    public function requireAuth() {
        if (!$this->isLoggedIn()) {
            header("Location: index.php");
            exit();
        }
    }
    
    public function requireAdmin() {
        $this->requireAuth();
        if (!$this->isAdmin()) {
            $_SESSION['error'] = "Access denied. Admin privileges required.";
            header("Location: dashboard.php");
            exit();
        }
    }
}

$auth = new Auth($pdo);

// ==================== INVENTORY MANAGEMENT CLASS ====================
class Inventory {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // Product Management
    public function addProduct($data) {
        $sql = "INSERT INTO products (sku, name, description, category_id, supplier_id, 
                unit_price, cost_price, quantity, reorder_level, location) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            $data['sku'], $data['name'], $data['description'], 
            $data['category_id'], $data['supplier_id'], $data['unit_price'],
            $data['cost_price'], $data['quantity'], $data['reorder_level'], 
            $data['location']
        ]);
    }
    
    public function updateProduct($id, $data) {
        $sql = "UPDATE products SET 
                sku = ?, name = ?, description = ?, category_id = ?, 
                supplier_id = ?, unit_price = ?, cost_price = ?, 
                reorder_level = ?, location = ? 
                WHERE id = ?";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            $data['sku'], $data['name'], $data['description'], 
            $data['category_id'], $data['supplier_id'], $data['unit_price'],
            $data['cost_price'], $data['reorder_level'], $data['location'], $id
        ]);
    }
    
    public function deleteProduct($id) {
        // Check if product has stock movements
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE product_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            return false; // Cannot delete product with stock history
        }
        
        $stmt = $this->pdo->prepare("DELETE FROM products WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    public function getProduct($id) {
        $stmt = $this->pdo->prepare("
            SELECT p.*, c.name as category_name, s.name as supplier_name 
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function getAllProducts($page = 1, $limit = 20) {
        $offset = ($page - 1) * $limit;
        $stmt = $this->pdo->prepare("
            SELECT p.*, c.name as category_name, s.name as supplier_name 
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            ORDER BY p.name
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getLowStockProducts() {
        $stmt = $this->pdo->prepare("
            SELECT p.*, c.name as category_name 
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.quantity <= p.reorder_level
            ORDER BY p.quantity ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    // Category Management
    public function addCategory($name, $description = '') {
        $stmt = $this->pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
        return $stmt->execute([$name, $description]);
    }
    
    public function getAllCategories() {
        $stmt = $this->pdo->query("SELECT * FROM categories ORDER BY name");
        return $stmt->fetchAll();
    }
    
    // Supplier Management
    public function addSupplier($data) {
        $sql = "INSERT INTO suppliers (name, contact_person, email, phone, address) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            $data['name'], $data['contact_person'], $data['email'],
            $data['phone'], $data['address']
        ]);
    }
    
    public function getAllSuppliers() {
        $stmt = $this->pdo->query("SELECT * FROM suppliers ORDER BY name");
        return $stmt->fetchAll();
    }
    
    // Stock Management
    public function stockIn($productId, $quantity, $unitPrice, $reference = '', $notes = '', $userId, $transactional = true, $orderId = null) {
        if ($transactional) {
            $this->pdo->beginTransaction();
        }
        
        try {
            // Add stock movement
            $stmt = $this->pdo->prepare("
                INSERT INTO stock_movements 
                (product_id, movement_type, quantity, unit_price, reference, notes, user_id, order_id) 
                VALUES (?, 'in', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$productId, $quantity, $unitPrice, $reference, $notes, $userId, $orderId]);
            
            // Update product quantity and cost price (weighted average)
            $product = $this->getProduct($productId);
            $newQuantity = $product['quantity'] + $quantity;
            $newCostPrice = (($product['quantity'] * $product['cost_price']) + ($quantity * $unitPrice)) / $newQuantity;
            
            $updateStmt = $this->pdo->prepare("
                UPDATE products SET quantity = ?, cost_price = ? WHERE id = ?
            ");
            $updateStmt->execute([$newQuantity, $newCostPrice, $productId]);
            
            if ($transactional) {
                $this->pdo->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($transactional) {
                $this->pdo->rollBack();
            }
            error_log("Stock in error: " . $e->getMessage());
            return false;
        }
    }
    
    public function stockOut($productId, $quantity, $reference = '', $notes = '', $userId, $transactional = true, $orderId = null) {
        if ($transactional) {
            $this->pdo->beginTransaction();
        }
        
        try {
            // Check if enough stock
            $product = $this->getProduct($productId);
            if ($product['quantity'] < $quantity) {
                throw new Exception("Insufficient stock. Available: {$product['quantity']}");
            }
            
            // Add stock movement
            $stmt = $this->pdo->prepare("
                INSERT INTO stock_movements 
                (product_id, movement_type, quantity, unit_price, reference, notes, user_id, order_id) 
                VALUES (?, 'out', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$productId, $quantity, $product['unit_price'], $reference, $notes, $userId, $orderId]);
            
            // Update product quantity
            $newQuantity = $product['quantity'] - $quantity;
            $updateStmt = $this->pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?");
            $updateStmt->execute([$newQuantity, $productId]);
            
            if ($transactional) {
                $this->pdo->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($transactional) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
    
    // Dashboard Statistics
    public function getDashboardStats() {
        $stats = [];
        
        try {
            // Total products
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM products");
            $stats['total_products'] = (int)($stmt->fetchColumn() ?? 0);
            
            // Low stock items
            $stmt = $this->pdo->query("SELECT COUNT(*) as low FROM products WHERE quantity <= reorder_level");
            $stats['low_stock_items'] = (int)($stmt->fetchColumn() ?? 0);
            
            // Total stock value
            $stmt = $this->pdo->query("SELECT SUM(quantity * cost_price) as value FROM products");
            $stats['total_stock_value'] = (float)($stmt->fetchColumn() ?? 0);
            
            // Recent movements
            $stmt = $this->pdo->prepare("
                SELECT sm.*, p.name as product_name, p.sku, u.full_name as user_name
                FROM stock_movements sm
                JOIN products p ON sm.product_id = p.id
                JOIN users u ON sm.user_id = u.id
                ORDER BY sm.movement_date DESC
                LIMIT 10
            ");
            $stmt->execute();
            $stats['recent_movements'] = $stmt->fetchAll();
            
        } catch (PDOException $e) {
            $stats['total_products'] = 0;
            $stats['low_stock_items'] = 0;
            $stats['total_stock_value'] = 0;
            $stats['recent_movements'] = [];
        }
        
        return $stats;
    }
    
    // Stock movement history
    public function getStockHistory($filters = []) {
        $sql = "
            SELECT sm.*, p.name as product_name, p.sku, u.full_name as user_name,
                   c.name as category_name, co.order_number
            FROM stock_movements sm
            JOIN products p ON sm.product_id = p.id
            JOIN users u ON sm.user_id = u.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN client_orders co ON sm.order_id = co.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if (!empty($filters['product_id'])) {
            $sql .= " AND sm.product_id = ?";
            $params[] = $filters['product_id'];
        }
        
        if (!empty($filters['movement_type'])) {
            $sql .= " AND sm.movement_type = ?";
            $params[] = $filters['movement_type'];
        }
        
        if (!empty($filters['start_date'])) {
            $sql .= " AND DATE(sm.movement_date) >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $sql .= " AND DATE(sm.movement_date) <= ?";
            $params[] = $filters['end_date'];
        }
        
        $sql .= " ORDER BY sm.movement_date DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

$inventory = new Inventory($pdo);

// ==================== ORDERS MANAGEMENT CLASS ====================
class Orders {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // Client Management
    public function addClient($data) {
        $sql = "INSERT INTO clients (name, email, phone, address, company, contact_person, tax_number, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            $data['name'], $data['email'], $data['phone'], $data['address'], 
            $data['company'], $data['contact_person'], $data['tax_number'] ?? '',
            $data['notes'] ?? '', $data['created_by']
        ]);
    }
    
    public function updateClient($id, $data) {
        $sql = "UPDATE clients SET 
                name = ?, email = ?, phone = ?, address = ?, 
                company = ?, contact_person = ?, tax_number = ?, 
                notes = ?, status = ?
                WHERE id = ?";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            $data['name'], $data['email'], $data['phone'], $data['address'],
            $data['company'], $data['contact_person'], $data['tax_number'] ?? '',
            $data['notes'] ?? '', $data['status'], $id
        ]);
    }
    
    public function getClient($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function getAllClients() {
        $stmt = $this->pdo->query("SELECT * FROM clients ORDER BY name");
        return $stmt->fetchAll();
    }
    
    public function searchClients($search) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM clients 
            WHERE name LIKE ? OR email LIKE ? OR phone LIKE ? OR contact_person LIKE ?
            ORDER BY name
            LIMIT 20
        ");
        $searchTerm = "%$search%";
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        return $stmt->fetchAll();
    }
    
    // Order Management
    public function createOrder($orderData, $items, $userId) {
        $this->pdo->beginTransaction();
        
        try {
            // Generate order number
            $orderNumber = $this->generateOrderNumber();
            
            // Calculate totals
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += $item['quantity'] * $item['unit_price'];
            }
            
            $taxAmount = $subtotal * ($orderData['tax_rate'] / 100);
            $discountAmount = $orderData['discount_amount'] ?? 0;
            $totalAmount = $subtotal + $taxAmount - $discountAmount;
            $dueAmount = $totalAmount - ($orderData['paid_amount'] ?? 0);
            $paymentStatus = $dueAmount <= 0 ? 'paid' : ($orderData['paid_amount'] > 0 ? 'partial' : 'pending');
            
            // Insert order
            $stmt = $this->pdo->prepare("
                INSERT INTO client_orders 
                (order_number, client_id, order_date, delivery_date, status, 
                 subtotal, tax_amount, discount_amount, total_amount, 
                 paid_amount, due_amount, payment_status, payment_method, 
                 notes, user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $orderNumber, $orderData['client_id'], $orderData['order_date'], 
                $orderData['delivery_date'] ?? null, 'pending',
                $subtotal, $taxAmount, $discountAmount, $totalAmount,
                $orderData['paid_amount'] ?? 0, $dueAmount, $paymentStatus,
                $orderData['payment_method'] ?? 'cash', $orderData['notes'] ?? '',
                $userId
            ]);
            
            $orderId = $this->pdo->lastInsertId();
            
            // Insert order items
            foreach ($items as $item) {
                $itemStmt = $this->pdo->prepare("
                    INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price, notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                $totalPrice = $item['quantity'] * $item['unit_price'];
                $itemStmt->execute([
                    $orderId, $item['product_id'], $item['quantity'], 
                    $item['unit_price'], $totalPrice, $item['notes'] ?? ''
                ]);
            }
            
            $this->pdo->commit();
            return ['success' => true, 'order_id' => $orderId, 'order_number' => $orderNumber];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function confirmOrder($orderId, $userId) {
        $this->pdo->beginTransaction();
        
        try {
            // Get order details
            $order = $this->getOrder($orderId);
            if (!$order) {
                throw new Exception("Order not found");
            }
            
            if ($order['status'] != 'pending') {
                throw new Exception("Order cannot be confirmed. Current status: {$order['status']}");
            }
            
            // Get order items
            $items = $this->getOrderItems($orderId);
            
            // Check stock availability for all items
            $inventory = new Inventory($this->pdo);
            foreach ($items as $item) {
                $product = $inventory->getProduct($item['product_id']);
                if ($product['quantity'] < $item['quantity']) {
                    throw new Exception("Insufficient stock for: {$product['name']}. Available: {$product['quantity']}, Required: {$item['quantity']}");
                }
            }
            
            // Deduct stock and record movements
            foreach ($items as $item) {
                if (!$inventory->stockOut(
                    $item['product_id'],
                    $item['quantity'],
                    "Order #{$order['order_number']}",
                    "Client: {$order['client_name']}",
                    $userId,
                    false,
                    $orderId
                )) {
                    throw new Exception("Failed to deduct stock for product ID: {$item['product_id']}");
                }
            }
            
            // Update order status
            $stmt = $this->pdo->prepare("
                UPDATE client_orders 
                SET status = 'confirmed', updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$orderId]);
            
            $this->pdo->commit();
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function cancelOrder($orderId, $userId, $reason = '') {
        $this->pdo->beginTransaction();
        
        try {
            // Get order details
            $order = $this->getOrder($orderId);
            if ($order['status'] == 'cancelled') {
                throw new Exception("Order already cancelled");
            }
            
            // Restock items if order was confirmed
            if ($order['status'] == 'confirmed') {
                $items = $this->getOrderItems($orderId);
                $inventory = new Inventory($this->pdo);
                
                foreach ($items as $item) {
                    $product = $inventory->getProduct($item['product_id']);
                    $inventory->stockIn(
                        $item['product_id'],
                        $item['quantity'],
                        $product['unit_price'],
                        "Order Cancellation #{$order['order_number']}",
                        "Restock due to order cancellation",
                        $userId,
                        false,
                        $orderId
                    );
                }
            }
            
            // Update order status
            $stmt = $this->pdo->prepare("
                UPDATE client_orders 
                SET status = 'cancelled', cancelled_at = NOW(), 
                    cancelled_by = ?, cancellation_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$userId, $reason, $orderId]);
            
            $this->pdo->commit();
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function getOrder($orderId) {
        $stmt = $this->pdo->prepare("
            SELECT co.*, c.name as client_name, c.email as client_email, 
                   c.phone as client_phone, u.full_name as sales_person
            FROM client_orders co
            JOIN clients c ON co.client_id = c.id
            JOIN users u ON co.user_id = u.id
            WHERE co.id = ?
        ");
        $stmt->execute([$orderId]);
        return $stmt->fetch();
    }
    
    public function getOrderItems($orderId) {
        $stmt = $this->pdo->prepare("
            SELECT oi.*, p.name as product_name, p.sku, p.quantity as stock_quantity
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }
    
    public function getAllOrders($filters = [], $isAdmin = true, $userId = null) {
        $sql = "
            SELECT co.*, c.name as client_name, u.full_name as sales_person
            FROM client_orders co
            JOIN clients c ON co.client_id = c.id
            JOIN users u ON co.user_id = u.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if (!$isAdmin && $userId) {
            $sql .= " AND co.user_id = ?";
            $params[] = $userId;
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND co.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['client_id'])) {
            $sql .= " AND co.client_id = ?";
            $params[] = $filters['client_id'];
        }
        
        if (!empty($filters['payment_status'])) {
            $sql .= " AND co.payment_status = ?";
            $params[] = $filters['payment_status'];
        }
        
        if (!empty($filters['start_date'])) {
            $sql .= " AND DATE(co.order_date) >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $sql .= " AND DATE(co.order_date) <= ?";
            $params[] = $filters['end_date'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (co.order_number LIKE ? OR c.name LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql .= " ORDER BY co.order_date DESC, co.id DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function getSalesStats($startDate = null, $endDate = null) {
        $sql = "
            SELECT 
                COUNT(*) as total_orders,
                SUM(total_amount) as total_sales,
                SUM(paid_amount) as total_paid,
                SUM(due_amount) as total_due,
                AVG(total_amount) as average_order_value
            FROM client_orders 
            WHERE status IN ('confirmed', 'shipped', 'delivered')
        ";
        
        $params = [];
        
        if ($startDate) {
            $sql .= " AND DATE(order_date) >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $sql .= " AND DATE(order_date) <= ?";
            $params[] = $endDate;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
    
    public function getTopClients($limit = 10, $startDate = null, $endDate = null) {
        $sql = "
            SELECT 
                c.id, c.name, c.company,
                COUNT(co.id) as total_orders,
                SUM(co.total_amount) as total_spent
            FROM clients c
            JOIN client_orders co ON c.id = co.client_id
            WHERE co.status IN ('confirmed', 'shipped', 'delivered')
        ";
        
        $params = [];
        
        if ($startDate) {
            $sql .= " AND DATE(co.order_date) >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $sql .= " AND DATE(co.order_date) <= ?";
            $params[] = $endDate;
        }
        
        $sql .= " GROUP BY c.id ORDER BY total_spent DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function getRecentOrders($limit = 5) {
        $stmt = $this->pdo->prepare("
            SELECT co.*, c.name as client_name, u.full_name as sales_person
            FROM client_orders co
            JOIN clients c ON co.client_id = c.id
            JOIN users u ON co.user_id = u.id
            ORDER BY co.created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    private function generateOrderNumber() {
        $prefix = 'ORD';
        $year = date('Y');
        $month = date('m');
        
        // Get last order number for this month
        $stmt = $this->pdo->prepare("
            SELECT order_number FROM client_orders 
            WHERE order_number LIKE ? 
            ORDER BY id DESC LIMIT 1
        ");
        $likePattern = "{$prefix}-{$year}{$month}-%";
        $stmt->execute([$likePattern]);
        $lastOrder = $stmt->fetch();
        
        if ($lastOrder) {
            $lastNumber = (int)substr($lastOrder['order_number'], -4);
            $nextNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $nextNumber = '0001';
        }
        
        return "{$prefix}-{$year}{$month}-{$nextNumber}";
    }
}

$orders = new Orders($pdo);

// ==================== HTML HELPER FUNCTIONS ====================
function displayAlert($type = 'success') {
    if (isset($_SESSION[$type])) {
        $message = $_SESSION[$type];
        unset($_SESSION[$type]);
        $alertClass = $type == 'error' ? 'danger' : $type;
        return "<div class='alert alert-$alertClass alert-dismissible fade show' role='alert'>
                    $message
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                </div>";
    }
    return '';
}

// ==================== ROUTING LOGIC ====================
$action = $_GET['action'] ?? '';
$page = $_GET['page'] ?? 'dashboard';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Login (allow before authentication)
    if (isset($_POST['login'])) {
        if ($auth->login($_POST['username'], $_POST['password'])) {
            $_SESSION['success'] = "Welcome back, " . $_SESSION['full_name'] . "!";
            header("Location: index.php");
            exit();
        } else {
            $_SESSION['error'] = "Invalid username or password";
        }
    } else {
        // Require authentication for all other POST actions
        $auth->requireAuth();
    }
    
    // Add Product
    if (isset($_POST['add_product'])) {
        if ($inventory->addProduct([
            'sku' => $_POST['sku'],
            'name' => $_POST['name'],
            'description' => $_POST['description'],
            'category_id' => $_POST['category_id'] ?: null,
            'supplier_id' => $_POST['supplier_id'] ?: null,
            'unit_price' => $_POST['unit_price'],
            'cost_price' => $_POST['cost_price'],
            'quantity' => $_POST['quantity'],
            'reorder_level' => $_POST['reorder_level'],
            'location' => $_POST['location']
        ])) {
            $_SESSION['success'] = "Product added successfully";
        } else {
            $_SESSION['error'] = "Failed to add product";
        }
        header("Location: ?page=products");
        exit();
    }
    
    // Update Product
    if (isset($_POST['update_product'])) {
        if ($inventory->updateProduct($_POST['id'], [
            'sku' => $_POST['sku'],
            'name' => $_POST['name'],
            'description' => $_POST['description'],
            'category_id' => $_POST['category_id'] ?: null,
            'supplier_id' => $_POST['supplier_id'] ?: null,
            'unit_price' => $_POST['unit_price'],
            'cost_price' => $_POST['cost_price'],
            'reorder_level' => $_POST['reorder_level'],
            'location' => $_POST['location']
        ])) {
            $_SESSION['success'] = "Product updated successfully";
        } else {
            $_SESSION['error'] = "Failed to update product";
        }
        header("Location: ?page=products");
        exit();
    }
    
    // Stock In
    if (isset($_POST['stock_in'])) {
        if ($inventory->stockIn(
            $_POST['product_id'],
            $_POST['quantity'],
            $_POST['unit_price'],
            $_POST['reference'],
            $_POST['notes'],
            $_SESSION['user_id']
        )) {
            $_SESSION['success'] = "Stock added successfully";
        } else {
            $_SESSION['error'] = "Failed to add stock";
        }
        header("Location: ?page=stock");
        exit();
    }
    
    // Stock Out
    if (isset($_POST['stock_out'])) {
        try {
            if ($inventory->stockOut(
                $_POST['product_id'],
                $_POST['quantity'],
                $_POST['reference'],
                $_POST['notes'],
                $_SESSION['user_id']
            )) {
                $_SESSION['success'] = "Stock removed successfully";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        header("Location: ?page=stock");
        exit();
    }
    
    // Add Category
    if (isset($_POST['add_category']) && $auth->isAdmin()) {
        if ($inventory->addCategory($_POST['name'], $_POST['description'])) {
            $_SESSION['success'] = "Category added successfully";
        } else {
            $_SESSION['error'] = "Failed to add category";
        }
        header("Location: ?page=categories");
        exit();
    }
    
    // Add Supplier
    if (isset($_POST['add_supplier']) && $auth->isAdmin()) {
        if ($inventory->addSupplier([
            'name' => $_POST['name'],
            'contact_person' => $_POST['contact_person'],
            'email' => $_POST['email'],
            'phone' => $_POST['phone'],
            'address' => $_POST['address']
        ])) {
            $_SESSION['success'] = "Supplier added successfully";
        } else {
            $_SESSION['error'] = "Failed to add supplier";
        }
        header("Location: ?page=suppliers");
        exit();
    }
    
    // Add Client
    if (isset($_POST['add_client'])) {
        if ($orders->addClient([
            'name' => $_POST['name'],
            'email' => $_POST['email'],
            'phone' => $_POST['phone'],
            'address' => $_POST['address'],
            'company' => $_POST['company'] ?? '',
            'contact_person' => $_POST['contact_person'] ?? '',
            'tax_number' => $_POST['tax_number'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'created_by' => $_SESSION['user_id']
        ])) {
            $_SESSION['success'] = "Client added successfully";
        } else {
            $_SESSION['error'] = "Failed to add client";
        }
        header("Location: ?page=clients");
        exit();
    }
    
    // Create Order
    if (isset($_POST['create_order'])) {
        $items = [];
        $itemCount = count($_POST['product_id'] ?? []);
        
        for ($i = 0; $i < $itemCount; $i++) {
            if ($_POST['quantity'][$i] > 0 && $_POST['unit_price'][$i] > 0) {
                $items[] = [
                    'product_id' => $_POST['product_id'][$i],
                    'quantity' => $_POST['quantity'][$i],
                    'unit_price' => $_POST['unit_price'][$i],
                    'notes' => $_POST['item_notes'][$i] ?? ''
                ];
            }
        }
        
        if (empty($items)) {
            $_SESSION['error'] = "Please add at least one product to the order";
            header("Location: ?page=orders&add=1");
            exit();
        }
        
        $result = $orders->createOrder([
            'client_id' => $_POST['client_id'],
            'order_date' => $_POST['order_date'],
            'delivery_date' => $_POST['delivery_date'] ?? null,
            'tax_rate' => $_POST['tax_rate'] ?? 0,
            'discount_amount' => $_POST['discount_amount'] ?? 0,
            'paid_amount' => $_POST['paid_amount'] ?? 0,
            'payment_method' => $_POST['payment_method'] ?? 'cash',
            'notes' => $_POST['notes'] ?? ''
        ], $items, $_SESSION['user_id']);
        
        if ($result['success']) {
            $_SESSION['success'] = "Order #{$result['order_number']} created successfully";
            header("Location: ?page=orders&view=" . $result['order_id']);
            exit();
        } else {
            $_SESSION['error'] = "Failed to create order: " . $result['error'];
            header("Location: ?page=orders&add=1");
            exit();
        }
    }
    
    // Confirm Order
    if (isset($_POST['confirm_order'])) {
        $result = $orders->confirmOrder($_POST['order_id'], $_SESSION['user_id']);
        
        if ($result['success']) {
            $_SESSION['success'] = "Order confirmed and stock updated successfully";
        } else {
            $_SESSION['error'] = "Failed to confirm order: " . $result['error'];
        }
        header("Location: ?page=orders&view=" . $_POST['order_id']);
        exit();
    }
    
    // Cancel Order
    if (isset($_POST['cancel_order']) && $auth->isAdmin()) {
        $result = $orders->cancelOrder($_POST['order_id'], $_SESSION['user_id'], $_POST['cancellation_reason'] ?? '');
        
        if ($result['success']) {
            $_SESSION['success'] = "Order cancelled successfully";
        } else {
            $_SESSION['error'] = "Failed to cancel order: " . $result['error'];
        }
        header("Location: ?page=orders&view=" . $_POST['order_id']);
        exit();
    }
}

// Handle GET actions
if ($action === 'logout') {
    $auth->logout();
}

// ==================== PAGE INCLUSION FUNCTIONS ====================
function includeDashboard() {
    global $inventory, $orders, $auth, $pdo;
    $stats = $inventory->getDashboardStats();
    $lowStockProducts = $inventory->getLowStockProducts();
    
    // Get order statistics
    try {
        $orderStmt = $pdo->query("
            SELECT 
                COUNT(*) as total_orders,
                SUM(total_amount) as today_sales
            FROM client_orders 
            WHERE DATE(order_date) = CURDATE() 
            AND status IN ('confirmed', 'shipped', 'delivered')
        ");
        $orderStats = $orderStmt->fetch();
        
        $pendingStmt = $pdo->query("SELECT COUNT(*) as pending FROM client_orders WHERE status = 'pending'");
        $pendingOrders = $pendingStmt->fetchColumn();
        
        $recentOrders = $orders->getRecentOrders(5);
        
    } catch (PDOException $e) {
        $orderStats = ['total_orders' => 0, 'today_sales' => 0];
        $pendingOrders = 0;
        $recentOrders = [];
    }
    ?>
    
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card products">
                <div class="d-flex justify-content-between">
                    <div>
                        <h5 class="fw-bold">Total Products</h5>
                        <h2><?php echo $stats['total_products']; ?></h2>
                    </div>
                    <div class="stat-icon">
                        <i class="bi bi-box"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card low-stock">
                <div class="d-flex justify-content-between">
                    <div>
                        <h5 class="fw-bold">Low Stock</h5>
                        <h2><?php echo $stats['low_stock_items']; ?></h2>
                    </div>
                    <div class="stat-icon">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card value">
                <div class="d-flex justify-content-between">
                    <div>
                        <h5 class="fw-bold">Stock Value</h5>
                        <h2><?php echo CURRENCY . number_format($stats['total_stock_value'], 2); ?></h2>
                    </div>
                    <div class="stat-icon">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card movements">
                <div class="d-flex justify-content-between">
                    <div>
                        <h5 class="fw-bold">Today's Orders</h5>
                        <h2><?php echo $orderStats['total_orders'] ?? 0; ?></h2>
                        <small>Sales: <?php echo CURRENCY . number_format($orderStats['today_sales'] ?? 0, 2); ?></small>
                    </div>
                    <div class="stat-icon">
                        <i class="bi bi-cart-check"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Stock Movements</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($stats['recent_movements'])): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>Type</th>
                                    <th>Quantity</th>
                                    <th>User</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['recent_movements'] as $movement): ?>
                                <tr>
                                    <td><?php echo date('M d, H:i', strtotime($movement['movement_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($movement['product_name']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $movement['movement_type'] == 'in' ? 'bg-success' : 'bg-warning'; ?>">
                                            <?php echo strtoupper($movement['movement_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $movement['quantity']; ?></td>
                                    <td><?php echo htmlspecialchars($movement['user_name']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-center text-muted my-3">No recent stock movements found.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-cart"></i> Recent Orders</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($recentOrders)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Client</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th class="text-end">Amount</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentOrders as $order): 
                                    $statusColors = [
                                        'pending' => 'warning',
                                        'confirmed' => 'info',
                                        'processing' => 'primary',
                                        'shipped' => 'info',
                                        'delivered' => 'success',
                                        'cancelled' => 'danger'
                                    ];
                                ?>
                                <tr>
                                    <td><strong><?php echo $order['order_number']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($order['client_name']); ?></td>
                                    <td><?php echo date('M d', strtotime($order['order_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $statusColors[$order['status']] ?? 'secondary'; ?>">
                                            <?php echo strtoupper($order['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end"><?php echo CURRENCY . number_format($order['total_amount'], 2); ?></td>
                                    <td>
                                        <a href="?page=orders&view=<?php echo $order['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-center text-muted my-3">No recent orders found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Low Stock Alert</h5>
                </div>
                <div class="card-body">
                    <?php if (count($lowStockProducts) > 0): ?>
                    <div class="list-group">
                        <?php foreach ($lowStockProducts as $product): ?>
                        <a href="?page=products&edit=<?php echo $product['id']; ?>" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><?php echo htmlspecialchars($product['name']); ?></h6>
                                <small class="text-danger"><?php echo $product['quantity']; ?> left</small>
                            </div>
                            <small>Reorder Level: <?php echo $product['reorder_level']; ?></small>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-center text-success my-3">
                        <i class="bi bi-check-circle-fill"></i> All products are sufficiently stocked
                    </p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="?page=products&add=1" class="btn btn-outline-primary">
                            <i class="bi bi-plus-circle"></i> Add New Product
                        </a>
                        <a href="?page=stock" class="btn btn-outline-success">
                            <i class="bi bi-box-arrow-in-down"></i> Stock In
                        </a>
                        <a href="?page=clients" class="btn btn-outline-info">
                            <i class="bi bi-people"></i> Manage Clients
                        </a>
                        <a href="?page=orders&add=1" class="btn btn-outline-success">
                            <i class="bi bi-cart-plus"></i> New Customer Order
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if ($pendingOrders > 0): ?>
            <div class="card shadow-sm mt-4 border-warning">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-clock"></i> Pending Orders</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>You have <?php echo $pendingOrders; ?> pending order(s)</strong>
                        <p class="mb-0 mt-2">These orders need to be confirmed to deduct stock.</p>
                        <a href="?page=orders&status=pending" class="btn btn-sm btn-warning mt-2">
                            View Pending Orders
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function includeProducts() {
    global $inventory, $auth;
    $categories = $inventory->getAllCategories();
    $suppliers = $inventory->getAllSuppliers();
    
    $editId = $_GET['edit'] ?? 0;
    $deleteId = $_GET['delete'] ?? 0;
    
    if ($deleteId && $auth->isAdmin()) {
        if ($inventory->deleteProduct($deleteId)) {
            $_SESSION['success'] = "Product deleted successfully";
        } else {
            $_SESSION['error'] = "Cannot delete product with stock history";
        }
        header("Location: ?page=products");
        exit();
    }
    
    $editProduct = null;
    if ($editId) {
        $editProduct = $inventory->getProduct($editId);
    }
    ?>
    
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="bi bi-box"></i> 
                <?php echo $editId ? 'Edit Product' : 'Product Management'; ?>
            </h5>
            <?php if (!$editId && $auth->isAdmin()): ?>
            <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
                <i class="bi bi-plus-circle"></i> Add Product
            </button>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($editId && $editProduct): ?>
            <!-- Edit Product Form -->
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <form method="POST" action="?page=products" class="needs-validation" novalidate>
                        <input type="hidden" name="update_product" value="1">
                        <input type="hidden" name="id" value="<?php echo $editProduct['id']; ?>">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="sku" class="form-label">SKU *</label>
                                <input type="text" class="form-control" id="sku" name="sku" 
                                       value="<?php echo htmlspecialchars($editProduct['sku']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="name" class="form-label">Product Name *</label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       value="<?php echo htmlspecialchars($editProduct['name']); ?>" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($editProduct['description']); ?></textarea>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="category_id" class="form-label">Category</label>
                                <select class="form-select" id="category_id" name="category_id">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" 
                                        <?php echo $editProduct['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="supplier_id" class="form-label">Supplier</label>
                                <select class="form-select" id="supplier_id" name="supplier_id">
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?php echo $sup['id']; ?>"
                                        <?php echo $editProduct['supplier_id'] == $sup['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sup['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="unit_price" class="form-label">Unit Price (<?php echo CURRENCY; ?>)</label>
                                <input type="number" step="0.01" class="form-control" id="unit_price" name="unit_price" 
                                       value="<?php echo $editProduct['unit_price']; ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label for="cost_price" class="form-label">Cost Price (<?php echo CURRENCY; ?>)</label>
                                <input type="number" step="0.01" class="form-control" id="cost_price" name="cost_price" 
                                       value="<?php echo $editProduct['cost_price']; ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label for="reorder_level" class="form-label">Reorder Level</label>
                                <input type="number" class="form-control" id="reorder_level" name="reorder_level" 
                                       value="<?php echo $editProduct['reorder_level']; ?>" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="location" class="form-label">Location/Storage</label>
                            <input type="text" class="form-control" id="location" name="location" 
                                   value="<?php echo htmlspecialchars($editProduct['location']); ?>">
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="?page=products" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Update Product</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php else: ?>
            
            <!-- Products List -->
            <div class="table-responsive">
                <?php 
                $products = $inventory->getAllProducts();
                if (count($products) > 0): 
                ?>
                <table class="table table-hover data-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Stock Value</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): 
                            $isLowStock = $product['quantity'] <= $product['reorder_level'];
                        ?>
                        <tr class="<?php echo $isLowStock ? 'table-warning' : ''; ?>">
                            <td><?php echo htmlspecialchars($product['sku']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                <?php if ($product['location']): ?>
                                <br><small class="text-muted">Location: <?php echo htmlspecialchars($product['location']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="badge <?php echo $isLowStock ? 'bg-danger' : 'bg-success'; ?>">
                                    <?php echo $product['quantity']; ?>
                                </span>
                            </td>
                            <td><?php echo CURRENCY . number_format($product['unit_price'], 2); ?></td>
                            <td><?php echo CURRENCY . number_format($product['quantity'] * $product['cost_price'], 2); ?></td>
                            <td>
                                <?php if ($isLowStock): ?>
                                <span class="badge bg-danger">Low Stock</span>
                                <?php else: ?>
                                <span class="badge bg-success">In Stock</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?page=products&edit=<?php echo $product['id']; ?>" 
                                   class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($auth->isAdmin()): ?>
                                <a href="?page=products&delete=<?php echo $product['id']; ?>" 
                                   class="btn btn-sm btn-outline-danger" 
                                   onclick="return confirm('Are you sure?')" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p class="text-center text-muted">No products found. Add your first product!</p>
                <?php endif; ?>
            </div>
            
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="?page=products">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add New Product</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="add_product" value="1">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="modal_sku" class="form-label">SKU *</label>
                                <input type="text" class="form-control" id="modal_sku" name="sku" required>
                            </div>
                            <div class="col-md-6">
                                <label for="modal_name" class="form-label">Product Name *</label>
                                <input type="text" class="form-control" id="modal_name" name="name" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="modal_description" class="form-label">Description</label>
                            <textarea class="form-control" id="modal_description" name="description" rows="2"></textarea>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="modal_category" class="form-label">Category</label>
                                <select class="form-select" id="modal_category" name="category_id">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="modal_supplier" class="form-label">Supplier</label>
                                <select class="form-select" id="modal_supplier" name="supplier_id">
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="modal_unit_price" class="form-label">Unit Price (<?php echo CURRENCY; ?>) *</label>
                                <input type="number" step="0.01" class="form-control" id="modal_unit_price" name="unit_price" required>
                            </div>
                            <div class="col-md-4">
                                <label for="modal_cost_price" class="form-label">Cost Price (<?php echo CURRENCY; ?>) *</label>
                                <input type="number" step="0.01" class="form-control" id="modal_cost_price" name="cost_price" required>
                            </div>
                            <div class="col-md-4">
                                <label for="modal_quantity" class="form-label">Initial Quantity *</label>
                                <input type="number" class="form-control" id="modal_quantity" name="quantity" value="0" required>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="modal_reorder_level" class="form-label">Reorder Level *</label>
                                <input type="number" class="form-control" id="modal_reorder_level" name="reorder_level" value="10" required>
                            </div>
                            <div class="col-md-6">
                                <label for="modal_location" class="form-label">Location</label>
                                <input type="text" class="form-control" id="modal_location" name="location" placeholder="e.g., Shelf A1">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}

function includeCategories() {
    global $inventory;
    $categories = $inventory->getAllCategories();
    ?>
    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Add New Category</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="?page=categories">
                        <div class="mb-3">
                            <label for="cat_name" class="form-label">Category Name *</label>
                            <input type="text" class="form-control" id="cat_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="cat_description" class="form-label">Description</label>
                            <textarea class="form-control" id="cat_description" name="description" rows="3"></textarea>
                        </div>
                        <button type="submit" name="add_category" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Add Category
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-tags"></i> All Categories</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <?php if (count($categories) > 0): ?>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Category Name</th>
                                    <th>Description</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><?php echo $cat['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($cat['name']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($cat['description']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($cat['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p class="text-center text-muted">No categories found. Add your first category!</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function includeSuppliers() {
    global $inventory;
    $suppliers = $inventory->getAllSuppliers();
    ?>
    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Add New Supplier</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="?page=suppliers">
                        <div class="mb-3">
                            <label for="sup_name" class="form-label">Supplier Name *</label>
                            <input type="text" class="form-control" id="sup_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="sup_contact" class="form-label">Contact Person</label>
                            <input type="text" class="form-control" id="sup_contact" name="contact_person">
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="sup_email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="sup_email" name="email">
                            </div>
                            <div class="col-md-6">
                                <label for="sup_phone" class="form-label">Phone</label>
                                <input type="text" class="form-control" id="sup_phone" name="phone">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="sup_address" class="form-label">Address</label>
                            <textarea class="form-control" id="sup_address" name="address" rows="3"></textarea>
                        </div>
                        <button type="submit" name="add_supplier" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Add Supplier
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-truck"></i> All Suppliers</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <?php if (count($suppliers) > 0): ?>
                        <table class="table table-hover data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Supplier</th>
                                    <th>Contact</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($suppliers as $sup): ?>
                                <tr>
                                    <td><?php echo $sup['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($sup['name']); ?></strong>
                                        <?php if ($sup['address']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($sup['address']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($sup['contact_person']); ?></td>
                                    <td><?php echo htmlspecialchars($sup['email']); ?></td>
                                    <td><?php echo htmlspecialchars($sup['phone']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p class="text-center text-muted">No suppliers found. Add your first supplier!</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function includeClients() {
    global $orders, $auth;
    $clients = $orders->getAllClients();
    ?>
    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-person-plus"></i> Add New Client</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="?page=clients">
                        <div class="mb-3">
                            <label for="client_name" class="form-label">Client Name *</label>
                            <input type="text" class="form-control" id="client_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="client_contact" class="form-label">Contact Person</label>
                            <input type="text" class="form-control" id="client_contact" name="contact_person">
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="client_email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="client_email" name="email">
                            </div>
                            <div class="col-md-6">
                                <label for="client_phone" class="form-label">Phone</label>
                                <input type="text" class="form-control" id="client_phone" name="phone">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="client_company" class="form-label">Company</label>
                            <input type="text" class="form-control" id="client_company" name="company">
                        </div>
                        <div class="mb-3">
                            <label for="client_address" class="form-label">Address</label>
                            <textarea class="form-control" id="client_address" name="address" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="client_notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="client_notes" name="notes" rows="2"></textarea>
                        </div>
                        <button type="submit" name="add_client" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Add Client
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-people"></i> All Clients</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <?php if (count($clients) > 0): ?>
                        <table class="table table-hover data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Client Name</th>
                                    <th>Contact</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Company</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clients as $client): ?>
                                <tr>
                                    <td><?php echo $client['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($client['name']); ?></strong>
                                        <?php if ($client['contact_person']): ?>
                                        <br><small>Contact: <?php echo htmlspecialchars($client['contact_person']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($client['contact_person']); ?></td>
                                    <td><?php echo htmlspecialchars($client['email']); ?></td>
                                    <td><?php echo htmlspecialchars($client['phone']); ?></td>
                                    <td><?php echo htmlspecialchars($client['company']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $client['status'] == 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst($client['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="?page=orders&add=1&client_id=<?php echo $client['id']; ?>" 
                                           class="btn btn-sm btn-outline-success" title="New Order">
                                            <i class="bi bi-cart-plus"></i>
                                        </a>
                                        <a href="?page=clients&view=<?php echo $client['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p class="text-center text-muted">No clients found. Add your first client!</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function includeOrders() {
    global $orders, $inventory, $auth;
    $clients = $orders->getAllClients();
    $products = $inventory->getAllProducts();
    
    // Get action parameters
    $viewId = $_GET['view'] ?? 0;
    $addOrder = isset($_GET['add']) && $_GET['add'] == 1;
    $clientId = $_GET['client_id'] ?? 0;
    
    // View single order
    if ($viewId) {
        $order = $orders->getOrder($viewId);
        $items = $orders->getOrderItems($viewId);
        
        if (!$order) {
            echo '<div class="alert alert-danger">Order not found</div>';
            return;
        }
        
        // Check if user can view this order
        if (!$auth->isAdmin() && $order['user_id'] != $_SESSION['user_id']) {
            echo '<div class="alert alert-danger">You can only view orders you created</div>';
            return;
        }
        ?>
        
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center bg-primary text-white">
                <h5 class="mb-0">
                    <i class="bi bi-receipt"></i> Order #<?php echo $order['order_number']; ?>
                    <span class="badge bg-<?php 
                        $statusColors = [
                            'pending' => 'warning',
                            'confirmed' => 'info',
                            'processing' => 'primary',
                            'shipped' => 'info',
                            'delivered' => 'success',
                            'cancelled' => 'danger'
                        ];
                        echo $statusColors[$order['status']] ?? 'secondary';
                    ?> ms-2"><?php echo strtoupper($order['status']); ?></span>
                </h5>
                <div>
                    <?php if ($order['status'] == 'pending'): ?>
                    <form method="POST" action="?page=orders" style="display: inline;">
                        <input type="hidden" name="confirm_order" value="1">
                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="bi bi-check-circle"></i> Confirm Order
                        </button>
                    </form>
                    <?php endif; ?>
                    
                    <?php if ($auth->isAdmin() && $order['status'] != 'cancelled'): ?>
                    <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#cancelOrderModal">
                        <i class="bi bi-x-circle"></i> Cancel Order
                    </button>
                    <?php endif; ?>
                    
                    <a href="?page=orders" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Order Details</h6>
                        <table class="table table-sm">
                            <tr>
                                <th width="40%">Order Number:</th>
                                <td><?php echo $order['order_number']; ?></td>
                            </tr>
                            <tr>
                                <th>Order Date:</th>
                                <td><?php echo date('F d, Y', strtotime($order['order_date'])); ?></td>
                            </tr>
                            <tr>
                                <th>Delivery Date:</th>
                                <td><?php echo $order['delivery_date'] ? date('F d, Y', strtotime($order['delivery_date'])) : 'Not specified'; ?></td>
                            </tr>
                            <tr>
                                <th>Sales Person:</th>
                                <td><?php echo $order['sales_person']; ?></td>
                            </tr>
                            <tr>
                                <th>Payment Method:</th>
                                <td><?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?></td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="col-md-6">
                        <h6>Client Information</h6>
                        <table class="table table-sm">
                            <tr>
                                <th width="40%">Client Name:</th>
                                <td><?php echo $order['client_name']; ?></td>
                            </tr>
                            <tr>
                                <th>Email:</th>
                                <td><?php echo $order['client_email']; ?></td>
                            </tr>
                            <tr>
                                <th>Phone:</th>
                                <td><?php echo $order['client_phone']; ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <hr>
                
                <h6>Order Items</h6>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Total</th>
                                <th>Stock Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $subtotal = 0;
                            foreach ($items as $index => $item): 
                                $subtotal += $item['total_price'];
                                $stockStatus = $item['stock_quantity'] >= $item['quantity'] ? 'success' : 'danger';
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                <td><?php echo $item['sku']; ?></td>
                                <td class="text-end"><?php echo $item['quantity']; ?></td>
                                <td class="text-end"><?php echo CURRENCY . number_format($item['unit_price'], 2); ?></td>
                                <td class="text-end"><?php echo CURRENCY . number_format($item['total_price'], 2); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $stockStatus; ?>">
                                        Stock: <?php echo $item['stock_quantity']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="5" class="text-end"><strong>Subtotal:</strong></td>
                                <td class="text-end"><?php echo CURRENCY . number_format($order['subtotal'], 2); ?></td>
                                <td></td>
                            </tr>
                            <?php if ($order['tax_amount'] > 0): ?>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Tax:</strong></td>
                                <td class="text-end"><?php echo CURRENCY . number_format($order['tax_amount'], 2); ?></td>
                                <td></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($order['discount_amount'] > 0): ?>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Discount:</strong></td>
                                <td class="text-end">-<?php echo CURRENCY . number_format($order['discount_amount'], 2); ?></td>
                                <td></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Total Amount:</strong></td>
                                <td class="text-end"><strong><?php echo CURRENCY . number_format($order['total_amount'], 2); ?></strong></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Paid Amount:</strong></td>
                                <td class="text-end"><?php echo CURRENCY . number_format($order['paid_amount'], 2); ?></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Due Amount:</strong></td>
                                <td class="text-end <?php echo $order['due_amount'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <strong><?php echo CURRENCY . number_format($order['due_amount'], 2); ?></strong>
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <?php if ($order['notes']): ?>
                <div class="mt-3">
                    <h6>Notes:</h6>
                    <p><?php echo nl2br(htmlspecialchars($order['notes'])); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($order['status'] == 'cancelled' && $order['cancellation_reason']): ?>
                <div class="alert alert-danger mt-3">
                    <h6>Cancellation Reason:</h6>
                    <p><?php echo nl2br(htmlspecialchars($order['cancellation_reason'])); ?></p>
                    <small>Cancelled on <?php echo date('F d, Y H:i', strtotime($order['cancelled_at'])); ?></small>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Cancel Order Modal -->
        <div class="modal fade" id="cancelOrderModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="?page=orders">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title">Cancel Order #<?php echo $order['order_number']; ?></h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="cancel_order" value="1">
                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                            
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> 
                                <strong>Warning:</strong> Cancelling this order will:
                                <ul class="mb-0 mt-2">
                                    <li>Change order status to "Cancelled"</li>
                                    <?php if ($order['status'] == 'confirmed'): ?>
                                    <li>Restock all products back to inventory</li>
                                    <?php endif; ?>
                                    <li>This action cannot be undone</li>
                                </ul>
                            </div>
                            
                            <div class="mb-3">
                                <label for="cancellation_reason" class="form-label">Cancellation Reason *</label>
                                <textarea class="form-control" id="cancellation_reason" name="cancellation_reason" rows="3" required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <?php
        return;
    }
    
    // Add new order
    if ($addOrder) {
        $selectedClient = $clientId ? $orders->getClient($clientId) : null;
        ?>
        
        <div class="card shadow-sm">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-cart-plus"></i> Create New Order</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="?page=orders" id="orderForm">
                    <input type="hidden" name="create_order" value="1">
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="client_id" class="form-label">Client *</label>
                                <select class="form-select" id="client_id" name="client_id" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo $client['id']; ?>" 
                                        <?php echo $selectedClient && $selectedClient['id'] == $client['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($client['name']); ?>
                                        <?php if ($client['company']): ?> - <?php echo htmlspecialchars($client['company']); ?><?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="order_date" class="form-label">Order Date *</label>
                                <input type="date" class="form-control" id="order_date" name="order_date" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="delivery_date" class="form-label">Delivery Date</label>
                                <input type="date" class="form-control" id="delivery_date" name="delivery_date">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Order Items -->
                    <div class="mb-4">
                        <h6>Order Items</h6>
                        <div class="table-responsive">
                            <table class="table table-bordered" id="orderItemsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th width="120">Quantity</th>
                                        <th width="150">Unit Price (<?php echo CURRENCY; ?>)</th>
                                        <th width="150">Total (<?php echo CURRENCY; ?>)</th>
                                        <th width="50">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="orderItemsBody">
                                    <tr id="noItemsRow">
                                        <td colspan="5" class="text-center text-muted">No items added yet</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="5">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addOrderItem()">
                                                <i class="bi bi-plus-circle"></i> Add Product
                                            </button>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="order_notes" class="form-label">Order Notes</label>
                                <textarea class="form-control" id="order_notes" name="notes" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="tax_rate" class="form-label">Tax Rate (%)</label>
                                        <input type="number" class="form-control" id="tax_rate" name="tax_rate" value="0" min="0" max="100" step="0.01">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="discount_amount" class="form-label">Discount Amount (<?php echo CURRENCY; ?>)</label>
                                        <input type="number" class="form-control" id="discount_amount" name="discount_amount" value="0" min="0" step="0.01">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="payment_method" class="form-label">Payment Method</label>
                                        <select class="form-select" id="payment_method" name="payment_method">
                                            <option value="cash">Cash</option>
                                            <option value="bank_transfer">Bank Transfer</option>
                                            <option value="credit_card">Credit Card</option>
                                            <option value="check">Check</option>
                                            <option value="online">Online</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="paid_amount" class="form-label">Paid Amount (<?php echo CURRENCY; ?>)</label>
                                        <input type="number" class="form-control" id="paid_amount" name="paid_amount" value="0" min="0" step="0.01">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Order Summary -->
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">Order Summary</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 offset-md-6">
                                    <table class="table table-sm">
                                        <tr>
                                            <th>Subtotal:</th>
                                            <td class="text-end"><?php echo CURRENCY; ?><span id="summary_subtotal">0.00</span></td>
                                        </tr>
                                        <tr>
                                            <th>Tax:</th>
                                            <td class="text-end"><?php echo CURRENCY; ?><span id="summary_tax">0.00</span></td>
                                        </tr>
                                        <tr>
                                            <th>Discount:</th>
                                            <td class="text-end">-<?php echo CURRENCY; ?><span id="summary_discount">0.00</span></td>
                                        </tr>
                                        <tr class="table-active">
                                            <th>Total Amount:</th>
                                            <td class="text-end"><strong><?php echo CURRENCY; ?><span id="summary_total">0.00</span></strong></td>
                                        </tr>
                                        <tr>
                                            <th>Paid Amount:</th>
                                            <td class="text-end"><?php echo CURRENCY; ?><span id="summary_paid">0.00</span></td>
                                        </tr>
                                        <tr>
                                            <th>Due Amount:</th>
                                            <td class="text-end text-danger"><strong><?php echo CURRENCY; ?><span id="summary_due">0.00</span></strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="?page=orders" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Create Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
        let itemCounter = 0;
        const products = <?php echo json_encode(array_map(function($p) {
            return [
                'id' => $p['id'],
                'name' => $p['name'],
                'sku' => $p['sku'],
                'unit_price' => $p['unit_price'],
                'quantity' => $p['quantity']
            ];
        }, $products)); ?>;
        
        function addOrderItem(productId = '', quantity = 1, unitPrice = 0) {
            $('#noItemsRow').hide();
            
            const itemId = 'item_' + itemCounter++;
            const productOptions = products.map(p => 
                `<option value="${p.id}" data-price="${p.unit_price}" data-stock="${p.quantity}" ${p.id == productId ? 'selected' : ''}>
                    ${p.name} (${p.sku}) - Stock: ${p.quantity}
                </option>`
            ).join('');
            
            const row = `
            <tr id="${itemId}">
                <td>
                    <select class="form-select product-select" name="product_id[]" required onchange="updateProductPrice(this)">
                        <option value="">Select Product</option>
                        ${productOptions}
                    </select>
                    <input type="hidden" name="item_notes[]">
                </td>
                <td>
                    <input type="number" class="form-control quantity-input" name="quantity[]" 
                           value="${quantity}" min="1" step="1" required onchange="calculateItemTotal(this)">
                </td>
                <td>
                    <input type="number" class="form-control price-input" name="unit_price[]" 
                           value="${unitPrice}" min="0" step="0.01" required onchange="calculateItemTotal(this)">
                </td>
                <td>
                    <input type="text" class="form-control total-display" readonly value="0.00">
                </td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeOrderItem('${itemId}')">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>`;
            
            $('#orderItemsBody').append(row);
            
            if (productId) {
                $(`#${itemId} .product-select`).trigger('change');
            }
            
            updateOrderSummary();
        }
        
        function removeOrderItem(itemId) {
            $(`#${itemId}`).remove();
            if ($('#orderItemsBody tr').length === 1) {
                $('#noItemsRow').show();
            }
            updateOrderSummary();
        }
        
        function updateProductPrice(select) {
            const row = $(select).closest('tr');
            const price = $(select).find(':selected').data('price') || 0;
            const stock = $(select).find(':selected').data('stock') || 0;
            
            row.find('.price-input').val(price);
            calculateItemTotal(select);
            
            // Update quantity max based on stock
            row.find('.quantity-input').attr('max', stock);
        }
        
        function calculateItemTotal(input) {
            const row = $(input).closest('tr');
            const quantity = parseFloat(row.find('.quantity-input').val()) || 0;
            const price = parseFloat(row.find('.price-input').val()) || 0;
            const total = quantity * price;
            
            row.find('.total-display').val(total.toFixed(2));
            updateOrderSummary();
        }
        
        function updateOrderSummary() {
            let subtotal = 0;
            
            $('.total-display').each(function() {
                subtotal += parseFloat($(this).val()) || 0;
            });
            
            const taxRate = parseFloat($('#tax_rate').val()) || 0;
            const discount = parseFloat($('#discount_amount').val()) || 0;
            const paid = parseFloat($('#paid_amount').val()) || 0;
            
            const tax = subtotal * (taxRate / 100);
            const total = subtotal + tax - discount;
            const due = total - paid;
            
            $('#summary_subtotal').text(subtotal.toFixed(2));
            $('#summary_tax').text(tax.toFixed(2));
            $('#summary_discount').text(discount.toFixed(2));
            $('#summary_total').text(total.toFixed(2));
            $('#summary_paid').text(paid.toFixed(2));
            $('#summary_due').text(due.toFixed(2));
            
            // Update due amount color
            $('#summary_due').parent().toggleClass('text-danger', due > 0);
        }
        
        $(document).ready(function() {
            $('#tax_rate, #discount_amount, #paid_amount').on('input', updateOrderSummary);
            addOrderItem();
        });
        </script>
        
        <?php
        return;
    }
    
    // List all orders
    $filters = [
        'status' => $_GET['status'] ?? '',
        'client_id' => $_GET['client_id'] ?? '',
        'payment_status' => $_GET['payment_status'] ?? '',
        'start_date' => $_GET['start_date'] ?? '',
        'end_date' => $_GET['end_date'] ?? '',
        'search' => $_GET['search'] ?? ''
    ];
    
    $allOrders = $orders->getAllOrders($filters, $auth->isAdmin(), $_SESSION['user_id']);
    $salesStats = $orders->getSalesStats();
    ?>
    
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-cart-check"></i> Customer Orders</h5>
            <div>
                <a href="?page=orders&add=1" class="btn btn-light btn-sm">
                    <i class="bi bi-plus-circle"></i> New Order
                </a>
            </div>
        </div>
        
        <div class="card-body">
            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" action="">
                        <input type="hidden" name="page" value="orders">
                        <div class="row g-2">
                            <div class="col-md-2">
                                <select class="form-select" name="status">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php echo $filters['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="confirmed" <?php echo $filters['status'] == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="processing" <?php echo $filters['status'] == 'processing' ? 'selected' : ''; ?>>Processing</option>
                                    <option value="shipped" <?php echo $filters['status'] == 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                                    <option value="delivered" <?php echo $filters['status'] == 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                    <option value="cancelled" <?php echo $filters['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" name="payment_status">
                                    <option value="">All Payments</option>
                                    <option value="pending" <?php echo $filters['payment_status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="partial" <?php echo $filters['payment_status'] == 'partial' ? 'selected' : ''; ?>>Partial</option>
                                    <option value="paid" <?php echo $filters['payment_status'] == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" name="client_id">
                                    <option value="">All Clients</option>
                                    <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo $client['id']; ?>" 
                                        <?php echo $filters['client_id'] == $client['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($client['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input type="date" class="form-control" name="start_date" 
                                       value="<?php echo $filters['start_date']; ?>" placeholder="From Date">
                            </div>
                            <div class="col-md-2">
                                <input type="date" class="form-control" name="end_date" 
                                       value="<?php echo $filters['end_date']; ?>" placeholder="To Date">
                            </div>
                            <div class="col-md-2">
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">Filter</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Sales Statistics -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-white bg-info">
                        <div class="card-body">
                            <h6 class="card-title">Total Orders</h6>
                            <h2 class="mb-0"><?php echo $salesStats['total_orders'] ?? 0; ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-success">
                        <div class="card-body">
                            <h6 class="card-title">Total Sales</h6>
                            <h2 class="mb-0"><?php echo CURRENCY . number_format($salesStats['total_sales'] ?? 0, 2); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-warning">
                        <div class="card-body">
                            <h6 class="card-title">Total Due</h6>
                            <h2 class="mb-0"><?php echo CURRENCY . number_format($salesStats['total_due'] ?? 0, 2); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-primary">
                        <div class="card-body">
                            <h6 class="card-title">Avg Order Value</h6>
                            <h2 class="mb-0"><?php echo CURRENCY . number_format($salesStats['average_order_value'] ?? 0, 2); ?></h2>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Orders List -->
            <div class="table-responsive">
                <?php if (count($allOrders) > 0): ?>
                <table class="table table-hover data-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Client</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Due</th>
                            <th>Sales Person</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allOrders as $order): 
                            $statusColors = [
                                'pending' => 'warning',
                                'confirmed' => 'info',
                                'processing' => 'primary',
                                'shipped' => 'info',
                                'delivered' => 'success',
                                'cancelled' => 'danger'
                            ];
                            
                            $paymentColors = [
                                'pending' => 'warning',
                                'partial' => 'info',
                                'paid' => 'success'
                            ];
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo $order['order_number']; ?></strong>
                                <?php if ($order['delivery_date']): ?>
                                <br><small>Delivery: <?php echo date('M d', strtotime($order['delivery_date'])); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($order['client_name']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $statusColors[$order['status']] ?? 'secondary'; ?>">
                                    <?php echo strtoupper($order['status']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $paymentColors[$order['payment_status']] ?? 'secondary'; ?>">
                                    <?php echo strtoupper($order['payment_status']); ?>
                                </span>
                            </td>
                            <td class="text-end"><?php echo CURRENCY . number_format($order['total_amount'], 2); ?></td>
                            <td class="text-end <?php echo $order['due_amount'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                <strong><?php echo CURRENCY . number_format($order['due_amount'], 2); ?></strong>
                            </td>
                            <td><?php echo $order['sales_person']; ?></td>
                            <td>
                                <a href="?page=orders&view=<?php echo $order['id']; ?>" 
                                   class="btn btn-sm btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-cart-x" style="font-size: 3rem; color: #ccc;"></i>
                    <h5 class="mt-3 text-muted">No orders found</h5>
                    <a href="?page=orders&add=1" class="btn btn-primary mt-2">
                        <i class="bi bi-plus-circle"></i> Create Your First Order
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

function includeStock() {
    global $inventory, $pdo;
    $products = $inventory->getAllProducts();
    ?>
    <div class="row">
        <div class="col-md-6">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-box-arrow-in-down"></i> Stock In</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="?page=stock">
                        <div class="mb-3">
                            <label for="stockin_product" class="form-label">Product *</label>
                            <select class="form-select" id="stockin_product" name="product_id" required>
                                <option value="">Select Product</option>
                                <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>">
                                    <?php echo htmlspecialchars($product['name']); ?> (Current: <?php echo $product['quantity']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="stockin_quantity" class="form-label">Quantity *</label>
                                <input type="number" class="form-control" id="stockin_quantity" name="quantity" min="1" required>
                            </div>
                            <div class="col-md-6">
                                <label for="stockin_price" class="form-label">Unit Price (<?php echo CURRENCY; ?>) *</label>
                                <input type="number" step="0.01" class="form-control" id="stockin_price" name="unit_price" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="stockin_reference" class="form-label">Reference (Invoice/PONo)</label>
                            <input type="text" class="form-control" id="stockin_reference" name="reference">
                        </div>
                        <div class="mb-3">
                            <label for="stockin_notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="stockin_notes" name="notes" rows="2"></textarea>
                        </div>
                        <button type="submit" name="stock_in" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Add Stock
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-box-arrow-up"></i> Stock Out</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="?page=stock">
                        <div class="mb-3">
                            <label for="stockout_product" class="form-label">Product *</label>
                            <select class="form-select" id="stockout_product" name="product_id" required>
                                <option value="">Select Product</option>
                                <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>">
                                    <?php echo htmlspecialchars($product['name']); ?> (Available: <?php echo $product['quantity']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="stockout_quantity" class="form-label">Quantity *</label>
                            <input type="number" class="form-control" id="stockout_quantity" name="quantity" min="1" required>
                        </div>
                        <div class="mb-3">
                            <label for="stockout_reference" class="form-label">Reference (Sales Order/Delivery)</label>
                            <input type="text" class="form-control" id="stockout_reference" name="reference">
                        </div>
                        <div class="mb-3">
                            <label for="stockout_notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="stockout_notes" name="notes" rows="2"></textarea>
                        </div>
                        <button type="submit" name="stock_out" class="btn btn-warning">
                            <i class="bi bi-check-circle"></i> Remove Stock
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Stock Movements -->
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-arrow-left-right"></i> Recent Stock Movements</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <?php 
                try {
                    $stmt = $pdo->query("
                        SELECT sm.*, p.name as product_name, p.sku, u.full_name as user_name
                        FROM stock_movements sm
                        JOIN products p ON sm.product_id = p.id
                        JOIN users u ON sm.user_id = u.id
                        ORDER BY sm.movement_date DESC
                        LIMIT 20
                    ");
                    if ($stmt->rowCount() > 0): 
                ?>
                <table class="table table-hover data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Type</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th>Reference</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($movement = $stmt->fetch()): ?>
                        <tr>
                            <td><?php echo date('M d, H:i', strtotime($movement['movement_date'])); ?></td>
                            <td><?php echo htmlspecialchars($movement['product_name']); ?></td>
                            <td>
                                <span class="badge <?php echo $movement['movement_type'] == 'in' ? 'bg-success' : 'bg-warning'; ?>">
                                    <?php echo strtoupper($movement['movement_type']); ?>
                                </span>
                            </td>
                            <td><?php echo $movement['quantity']; ?></td>
                            <td><?php echo CURRENCY . number_format($movement['unit_price'], 2); ?></td>
                            <td><?php echo htmlspecialchars($movement['reference']); ?></td>
                            <td><?php echo $movement['user_name']; ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p class="text-center text-muted">No stock movements found yet.</p>
                <?php endif; 
                } catch (PDOException) {
                    echo '<p class="text-center text-muted">Error loading stock movements.</p>';
                }
                ?>
            </div>
        </div>
    </div>
    <?php
}

function includeHistory() {
    global $inventory;
    
    // Get filter values
    $filters = [
        'product_id' => $_GET['product_id'] ?? '',
        'movement_type' => $_GET['movement_type'] ?? '',
        'start_date' => $_GET['start_date'] ?? '',
        'end_date' => $_GET['end_date'] ?? ''
    ];
    
    $history = $inventory->getStockHistory($filters);
    $products = $inventory->getAllProducts();
    ?>
    
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-clock-history"></i> Stock Movement History</h5>
        </div>
        <div class="card-body">
            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" action="">
                        <input type="hidden" name="page" value="history">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="filter_product" class="form-label">Product</label>
                                <select class="form-select" id="filter_product" name="product_id">
                                    <option value="">All Products</option>
                                    <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>"
                                        <?php echo $filters['product_id'] == $product['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="filter_type" class="form-label">Movement Type</label>
                                <select class="form-select" id="filter_type" name="movement_type">
                                    <option value="">All Types</option>
                                    <option value="in" <?php echo $filters['movement_type'] == 'in' ? 'selected' : ''; ?>>Stock In</option>
                                    <option value="out" <?php echo $filters['movement_type'] == 'out' ? 'selected' : ''; ?>>Stock Out</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="filter_start" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="filter_start" name="start_date" 
                                       value="<?php echo $filters['start_date']; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="filter_end" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="filter_end" name="end_date" 
                                       value="<?php echo $filters['end_date']; ?>">
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-funnel"></i> Apply Filters
                            </button>
                            <a href="?page=history" class="btn btn-secondary">Clear Filters</a>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- History Table -->
            <div class="table-responsive">
                <?php if (count($history) > 0): ?>
                <table class="table table-hover data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Type</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total Value</th>
                            <th>Reference</th>
                            <th>Notes</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $movement): ?>
                        <tr>
                            <td><?php echo date('M d, Y H:i', strtotime($movement['movement_date'])); ?></td>
                            <td><?php echo htmlspecialchars($movement['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($movement['sku']); ?></td>
                            <td>
                                <span class="badge <?php echo $movement['movement_type'] == 'in' ? 'bg-success' : 'bg-warning'; ?>">
                                    <?php echo strtoupper($movement['movement_type']); ?>
                                </span>
                            </td>
                            <td><?php echo $movement['quantity']; ?></td>
                            <td><?php echo CURRENCY . number_format($movement['unit_price'], 2); ?></td>
                            <td><?php echo CURRENCY . number_format($movement['quantity'] * $movement['unit_price'], 2); ?></td>
                            <td><?php echo htmlspecialchars($movement['reference']); ?></td>
                            <td><?php echo htmlspecialchars($movement['notes']); ?></td>
                            <td><?php echo $movement['user_name']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
                    <h5 class="mt-3 text-muted">No stock movements found</h5>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// ==================== HTML OUTPUT ====================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
        }
        
        .sidebar {
            background-color: var(--primary-color);
            color: white;
            min-height: 100vh;
            box-shadow: 3px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar a {
            color: #ecf0f1;
            text-decoration: none;
            display: block;
            padding: 12px 20px;
            border-radius: 5px;
            margin-bottom: 5px;
            transition: all 0.3s;
        }
        
        .sidebar a:hover, .sidebar a.active {
            background-color: var(--secondary-color);
            color: white;
        }
        
        .sidebar .logo {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .content {
            padding: 20px;
        }
        
        .stat-card {
            border-radius: 10px;
            padding: 20px;
            color: white;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card .stat-icon {
            font-size: 3rem;
            opacity: 0.8;
        }
        
        .stat-card.products { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card.low-stock { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-card.value { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-card.movements { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        
        .table th {
            background-color: var(--primary-color);
            color: white;
        }
        
        .badge-low {
            background-color: var(--danger-color);
        }
        
        .badge-in {
            background-color: var(--success-color);
        }
        
        .badge-out {
            background-color: var(--warning-color);
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
            .content {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <?php if (!$auth->isLoggedIn()): ?>
    
    <!-- ==================== LOGIN PAGE ==================== -->
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-lg border-0">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <h2 class="fw-bold" style="color: var(--primary-color);">
                                <i class="bi bi-box-seam"></i> <?php echo SITE_NAME; ?>
                            </h2>
                            <p class="text-muted">Sign in to access your inventory</p>
                            <?php if (!isset($_GET['reset'])): ?>
                            <div class="mt-3">
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php echo displayAlert('error'); ?>
                        <?php echo displayAlert('success'); ?>
                        
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       placeholder="Enter username" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" 
                                       placeholder="Enter password" required>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" name="login" class="btn btn-primary btn-lg">
                                    <i class="bi bi-box-arrow-in-right"></i> Sign In
                                </button>
                            </div>
                        </form>
                        
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    
    <!-- ==================== MAIN LAYOUT ==================== -->
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 px-0 sidebar">
                <div class="logo">
                    <h4><i class="bi bi-box-seam"></i> <?php echo SITE_NAME; ?></h4>
                    <small class="text-muted">Welcome, <?php echo $_SESSION['full_name']; ?></small>
                </div>
                
                <nav class="nav flex-column p-3">
                    <a href="?page=dashboard" class="<?php echo $page == 'dashboard' ? 'active' : ''; ?>">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a href="?page=products" class="<?php echo $page == 'products' ? 'active' : ''; ?>">
                        <i class="bi bi-box"></i> Products
                    </a>
                    <a href="?page=clients" class="<?php echo $page == 'clients' ? 'active' : ''; ?>">
                        <i class="bi bi-people"></i> Clients
                    </a>
                    <a href="?page=orders" class="<?php echo $page == 'orders' ? 'active' : ''; ?>">
                        <i class="bi bi-cart-check"></i> Orders
                    </a>
                    <?php if ($auth->isAdmin()): ?>
                    <a href="?page=categories" class="<?php echo $page == 'categories' ? 'active' : ''; ?>">
                        <i class="bi bi-tags"></i> Categories
                    </a>
                    <a href="?page=suppliers" class="<?php echo $page == 'suppliers' ? 'active' : ''; ?>">
                        <i class="bi bi-truck"></i> Suppliers
                    </a>
                    <?php endif; ?>
                    <a href="?page=stock" class="<?php echo $page == 'stock' ? 'active' : ''; ?>">
                        <i class="bi bi-arrow-left-right"></i> Stock Movement
                    </a>
                    <a href="?page=history" class="<?php echo $page == 'history' ? 'active' : ''; ?>">
                        <i class="bi bi-clock-history"></i> History
                    </a>
                    <hr class="text-white-50">
                    <a href="?action=logout" class="text-danger">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </nav>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3 class="fw-bold">
                        <?php 
                        $pageTitles = [
                            'dashboard' => 'Dashboard',
                            'products' => 'Product Management',
                            'clients' => 'Client Management',
                            'orders' => 'Customer Orders',
                            'categories' => 'Category Management',
                            'suppliers' => 'Supplier Management',
                            'stock' => 'Stock Movement',
                            'history' => 'Stock Movement History'
                        ];
                        echo $pageTitles[$page] ?? 'Dashboard';
                        ?>
                    </h3>
                    <span class="badge bg-secondary">Role: <?php echo ucfirst($_SESSION['role']); ?></span>
                </div>
                
                <?php echo displayAlert('success'); ?>
                <?php echo displayAlert('error'); ?>
                
                <!-- Page Content -->
                <div class="page-content">
                    <?php
                    switch ($page) {
                        case 'dashboard':
                            includeDashboard();
                            break;
                        case 'products':
                            includeProducts();
                            break;
                        case 'clients':
                            includeClients();
                            break;
                        case 'orders':
                            includeOrders();
                            break;
                        case 'categories':
                            if ($auth->isAdmin()) includeCategories();
                            else header("Location: ?page=dashboard");
                            break;
                        case 'suppliers':
                            if ($auth->isAdmin()) includeSuppliers();
                            else header("Location: ?page=dashboard");
                            break;
                        case 'stock':
                            includeStock();
                            break;
                        case 'history':
                            includeHistory();
                            break;
                        default:
                            includeDashboard();
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php endif; ?>
    
    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        // Initialize DataTables
        $(document).ready(function() {
            $('.data-table').DataTable({
                pageLength: 10,
                responsive: true
            });
        });
        
        // Form validation
        function validateForm(formId) {
            const form = document.getElementById(formId);
            if (form.checkValidity() === false) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }
    </script>
</body>
</html>
<?php
ob_end_flush();
?>