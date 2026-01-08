<?php
/**
 * Create Dummy Data for Testing
 * Run once: https://your-app.railway.app/create-dummy-data.php
 * DELETE after use!
 */

$SETUP_PASSWORD = 'create-data-123';

if (!isset($_POST['password']) || $_POST['password'] !== $SETUP_PASSWORD) {
    ?>
    <!DOCTYPE html>
    <html><head><title>Create Dummy Data</title>
    <style>
        body {font-family: Arial; max-width: 500px; margin: 100px auto; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);}
        .container {background: white; padding: 40px; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3);}
        input {width: 100%; padding: 12px; margin: 10px 0; border: 2px solid #e2e8f0; border-radius: 8px;}
        button {width: 100%; padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;}
    </style></head><body>
        <div class="container">
            <h2>🎲 Create Dummy Data</h2>
            <p>Enter password to create sample categories and menu items:</p>
            <form method="POST">
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit">Create Data</button>
            </form>
        </div>
    </body></html>
    <?php
    exit;
}

require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $results = [];
    
    // 1. Insert Categories
    $categories = [
        ['name' => 'Makanan Utama', 'description' => 'Hidangan utama'],
        ['name' => 'Minuman', 'description' => 'Berbagai minuman'],
        ['name' => 'Dessert', 'description' => 'Makanan penutup'],
        ['name' => 'Appetizer', 'description' => 'Makanan pembuka']
    ];
    
    $categoryIds = [];
    foreach ($categories as $cat) {
        try {
            $stmt = $conn->prepare("INSERT INTO categories (name, description, is_active, created_at) VALUES (?, ?, 1, NOW())");
            $stmt->execute([$cat['name'], $cat['description']]);
            $categoryIds[$cat['name']] = $conn->lastInsertId();
            $results[] = "✅ Category created: " . $cat['name'];
        } catch (Exception $e) {
            $results[] = "⚠️ Category exists: " . $cat['name'];
        }
    }
    
    // Get category IDs if already exist
    if (empty($categoryIds)) {
        $stmt = $conn->query("SELECT id, name FROM categories");
        while ($row = $stmt->fetch()) {
            $categoryIds[$row['name']] = $row['id'];
        }
    }
    
    // 2. Insert Menu Items
    $menuItems = [
        ['name' => 'Nasi Goreng Spesial', 'category' => 'Makanan Utama', 'price' => 25000, 'prep_time' => 15],
        ['name' => 'Mie Goreng', 'category' => 'Makanan Utama', 'price' => 20000, 'prep_time' => 12],
        ['name' => 'Ayam Bakar', 'category' => 'Makanan Utama', 'price' => 30000, 'prep_time' => 20],
        ['name' => 'Sate Ayam', 'category' => 'Makanan Utama', 'price' => 28000, 'prep_time' => 18],
        ['name' => 'Es Teh Manis', 'category' => 'Minuman', 'price' => 5000, 'prep_time' => 3],
        ['name' => 'Jus Jeruk', 'category' => 'Minuman', 'price' => 12000, 'prep_time' => 5],
        ['name' => 'Kopi Hitam', 'category' => 'Minuman', 'price' => 8000, 'prep_time' => 5],
        ['name' => 'Es Pisang Ijo', 'category' => 'Dessert', 'price' => 15000, 'prep_time' => 10],
        ['name' => 'Lumpia Goreng', 'category' => 'Appetizer', 'price' => 18000, 'prep_time' => 12]
    ];
    
    foreach ($menuItems as $item) {
        try {
            $catId = $categoryIds[$item['category']] ?? 1;
            $stmt = $conn->prepare("
                INSERT INTO menu_items (name, description, category_id, price, preparation_time, is_available, created_at) 
                VALUES (?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([
                $item['name'],
                'Delicious ' . $item['name'],
                $catId,
                $item['price'],
                $item['prep_time']
            ]);
            $results[] = "✅ Menu item created: " . $item['name'];
        } catch (Exception $e) {
            $results[] = "⚠️ Menu item exists: " . $item['name'];
        }
    }
    
    // 3. Create sample tables
    for ($i = 1; $i <= 10; $i++) {
        try {
            $stmt = $conn->prepare("INSERT INTO tables (table_number, capacity, status, created_at) VALUES (?, ?, 'available', NOW())");
            $stmt->execute([$i, 4]);
        } catch (Exception $e) {
            // Table exists
        }
    }
    $results[] = "✅ Created 10 tables";
    
    ?>
    <!DOCTYPE html>
    <html><head><title>Data Created</title>
    <style>
        body {font-family: Arial; max-width: 700px; margin: 100px auto; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);}
        .container {background: white; padding: 40px; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3);}
        .success {color: #10b981; font-size: 48px;}
        .results {background: #f7fafc; padding: 20px; border-radius: 10px; margin: 20px 0; max-height: 400px; overflow-y: auto;}
        .warning {background: #fef3c7; color: #92400e; padding: 15px; border-radius: 8px; margin: 20px 0;}
        a {display: inline-block; margin-top: 20px; padding: 15px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 600;}
    </style></head><body>
        <div class="container">
            <div class="success">✅</div>
            <h2>Dummy Data Created!</h2>
            
            <div class="results">
                <?php foreach ($results as $result) {
                    echo "<p>$result</p>";
                } ?>
            </div>
            
            <div class="warning">
                ⚠️ <strong>IMPORTANT:</strong> Delete this create-dummy-data.php file now!
            </div>
            
            <a href="/admin/menu-management.html">Go to Menu Management</a>
            <a href="/admin/dashboard.html" style="background: #6366f1;">Go to Dashboard</a>
        </div>
    </body></html>
    <?php
    
} catch (Exception $e) {
    echo "<h2>Error:</h2><pre>" . $e->getMessage() . "</pre>";
}
?>
