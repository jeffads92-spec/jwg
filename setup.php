<?php
/**
 * Database Setup Script for Railway
 * Run this ONCE after deployment: https://your-app.railway.app/setup.php
 * 
 * SECURITY: Delete this file after setup!
 */

// Prevent public access - add password protection
$SETUP_PASSWORD = 'your-secret-setup-password-123'; // CHANGE THIS!

if (!isset($_POST['password']) || $_POST['password'] !== $SETUP_PASSWORD) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Database Setup</title>
        <style>
            body { 
                font-family: Arial; 
                max-width: 500px; 
                margin: 100px auto; 
                padding: 20px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            }
            .container {
                background: white;
                padding: 40px;
                border-radius: 20px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            input[type="password"] {
                width: 100%;
                padding: 12px;
                margin: 10px 0;
                border: 2px solid #e2e8f0;
                border-radius: 8px;
            }
            button {
                width: 100%;
                padding: 15px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 600;
            }
            button:hover { opacity: 0.9; }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>🔒 Database Setup</h2>
            <p>Enter setup password to initialize database:</p>
            <form method="POST">
                <input type="password" name="password" placeholder="Setup Password" required>
                <button type="submit">Initialize Database</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Password correct, proceed with setup
require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Read and execute schema file
    $schemaFile = __DIR__ . '/Database Schema.sql';
    
    if (!file_exists($schemaFile)) {
        die("❌ Error: Database Schema.sql not found!");
    }
    
    $sql = file_get_contents($schemaFile);
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $success = 0;
    $errors = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement)) continue;
        
        try {
            $conn->exec($statement);
            $success++;
        } catch (PDOException $e) {
            $errors++;
            echo "⚠️ Error in statement: " . substr($statement, 0, 50) . "...<br>";
            echo "Error: " . $e->getMessage() . "<br><br>";
        }
    }
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Setup Complete</title>
        <style>
            body { 
                font-family: Arial; 
                max-width: 600px; 
                margin: 100px auto; 
                padding: 20px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            }
            .container {
                background: white;
                padding: 40px;
                border-radius: 20px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            .success { color: #10b981; font-size: 48px; }
            .stats { 
                background: #f7fafc; 
                padding: 20px; 
                border-radius: 10px; 
                margin: 20px 0; 
            }
            .warning {
                background: #fef3c7;
                color: #92400e;
                padding: 15px;
                border-radius: 8px;
                margin: 20px 0;
            }
            a {
                display: inline-block;
                margin-top: 20px;
                padding: 15px 30px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 600;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="success">✅</div>
            <h2>Database Setup Complete!</h2>
            
            <div class="stats">
                <p><strong>Executed:</strong> <?= $success ?> statements</p>
                <p><strong>Errors:</strong> <?= $errors ?> statements</p>
                <p><strong>Database:</strong> <?= $database->getConnectionInfo()['database'] ?></p>
                <p><strong>Host:</strong> <?= $database->getConnectionInfo()['host'] ?></p>
            </div>
            
            <div class="warning">
                ⚠️ <strong>IMPORTANT:</strong> Delete this setup.php file immediately for security!
            </div>
            
            <a href="/admin/">Go to Admin Panel</a>
        </div>
    </body>
    </html>
    <?php
    
} catch (Exception $e) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Setup Error</title>
        <style>
            body { 
                font-family: Arial; 
                max-width: 600px; 
                margin: 100px auto; 
                padding: 20px;
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            }
            .container {
                background: white;
                padding: 40px;
                border-radius: 20px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            .error { color: #ef4444; }
            pre {
                background: #f7fafc;
                padding: 15px;
                border-radius: 8px;
                overflow-x: auto;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h2 class="error">❌ Setup Error</h2>
            <p>Database setup failed:</p>
            <pre><?= htmlspecialchars($e->getMessage()) ?></pre>
            
            <h3>Debug Info:</h3>
            <pre><?php print_r($database->getConnectionInfo()); ?></pre>
            
            <h3>Environment Variables:</h3>
            <pre>
DB_HOST: <?= getenv('DB_HOST') ?: 'NOT SET' ?>
DB_PORT: <?= getenv('DB_PORT') ?: 'NOT SET' ?>
DB_NAME: <?= getenv('DB_NAME') ?: 'NOT SET' ?>
DB_USER: <?= getenv('DB_USER') ?: 'NOT SET' ?>
            </pre>
        </div>
    </body>
    </html>
    <?php
}
?>
