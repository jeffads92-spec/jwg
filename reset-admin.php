<?php
/**
 * Reset Admin Password
 * Access: https://your-app.railway.app/reset-admin.php
 * DELETE THIS FILE AFTER USE!
 */

// Password protection
$RESET_PASSWORD = 'reset-secret-123'; // CHANGE THIS!

if (!isset($_POST['reset_password']) || $_POST['reset_password'] !== $RESET_PASSWORD) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Reset Admin Password</title>
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
            <h2>🔒 Reset Admin Password</h2>
            <p>Enter reset password to continue:</p>
            <form method="POST">
                <input type="password" name="reset_password" placeholder="Reset Password" required>
                <button type="submit">Reset Password</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Include database
require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // New password for admin
    $newPassword = 'admin123';
    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
    
    // Update admin password
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
    $result = $stmt->execute([$hashedPassword]);
    
    if ($result) {
        // Verify the update
        $checkStmt = $conn->prepare("SELECT username, password FROM users WHERE username = 'admin'");
        $checkStmt->execute();
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // Test if password works
        $testVerify = password_verify($newPassword, $user['password']);
        
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Reset Complete</title>
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
                .info { 
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
                code {
                    background: #f7fafc;
                    padding: 2px 6px;
                    border-radius: 4px;
                    font-family: monospace;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="success">✅</div>
                <h2>Admin Password Reset Complete!</h2>
                
                <div class="info">
                    <h3>New Login Credentials:</h3>
                    <p><strong>Username:</strong> <code>admin</code></p>
                    <p><strong>Password:</strong> <code><?= htmlspecialchars($newPassword) ?></code></p>
                </div>
                
                <div class="info">
                    <h3>Password Hash Info:</h3>
                    <p><strong>Hash:</strong> <code style="word-break: break-all;"><?= htmlspecialchars($user['password']) ?></code></p>
                    <p><strong>Verification Test:</strong> <?= $testVerify ? '✅ PASSED' : '❌ FAILED' ?></p>
                </div>
                
                <div class="warning">
                    ⚠️ <strong>IMPORTANT:</strong> Delete this reset-admin.php file immediately for security!
                </div>
                
                <a href="/admin/">Go to Admin Login</a>
            </div>
        </body>
        </html>
        <?php
        
    } else {
        throw new Exception("Failed to update password");
    }
    
} catch (Exception $e) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Reset Error</title>
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
            <h2 class="error">❌ Reset Error</h2>
            <p>Failed to reset password:</p>
            <pre><?= htmlspecialchars($e->getMessage()) ?></pre>
            
            <h3>Debug Info:</h3>
            <pre><?php print_r($database->getConnectionInfo()); ?></pre>
        </div>
    </body>
    </html>
    <?php
}
?>
