<?php
/**
 * Database Connection Test
 * Access: https://your-app.railway.app/test-db.php
 * 
 * DELETE THIS FILE after testing!
 */

require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Connection Test</title>
    <style>
        body { 
            font-family: 'Segoe UI', Arial; 
            max-width: 800px; 
            margin: 50px auto; 
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .info { 
            background: #f7fafc; 
            padding: 20px; 
            border-radius: 10px; 
            margin: 20px 0;
            font-family: 'Courier New', monospace;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th { background: #f7fafc; font-weight: 600; }
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔌 Database Connection Test</h1>
        
        <?php
        try {
            $database = new Database();
            $conn = $database->getConnection();
            $info = $database->getConnectionInfo();
            
            echo '<div class="success">';
            echo '<h2>✅ Connection Successful!</h2>';
            echo '</div>';
            
            echo '<div class="info">';
            echo '<h3>Connection Details:</h3>';
            echo '<table>';
            echo '<tr><th>Parameter</th><th>Value</th></tr>';
            echo '<tr><td>Host</td><td>' . htmlspecialchars($info['host']) . '</td></tr>';
            echo '<tr><td>Port</td><td>' . htmlspecialchars($info['port']) . '</td></tr>';
            echo '<tr><td>Database</td><td>' . htmlspecialchars($info['database']) . '</td></tr>';
            echo '<tr><td>User</td><td>' . htmlspecialchars($info['user']) . '</td></tr>';
            echo '</table>';
            echo '</div>';
            
            // Test query
            $stmt = $conn->query("SELECT VERSION() as version, DATABASE() as db_name, USER() as current_user");
            $result = $stmt->fetch();
            
            echo '<div class="info">';
            echo '<h3>Database Info:</h3>';
            echo '<table>';
            echo '<tr><th>Property</th><th>Value</th></tr>';
            echo '<tr><td>MySQL Version</td><td>' . htmlspecialchars($result['version']) . '</td></tr>';
            echo '<tr><td>Current Database</td><td>' . htmlspecialchars($result['db_name']) . '</td></tr>';
            echo '<tr><td>Current User</td><td>' . htmlspecialchars($result['current_user']) . '</td></tr>';
            echo '</table>';
            echo '</div>';
            
            // Check tables
            $stmt = $conn->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            echo '<div class="info">';
            echo '<h3>Database Tables (' . count($tables) . '):</h3>';
            if (count($tables) > 0) {
                echo '<table>';
                echo '<tr><th>#</th><th>Table Name</th><th>Status</th></tr>';
                foreach ($tables as $i => $table) {
                    echo '<tr>';
                    echo '<td>' . ($i + 1) . '</td>';
                    echo '<td>' . htmlspecialchars($table) . '</td>';
                    echo '<td><span class="badge badge-success">✓ Exists</span></td>';
                    echo '</tr>';
                }
                echo '</table>';
            } else {
                echo '<p class="error">⚠️ No tables found. Run setup.php to initialize database.</p>';
            }
            echo '</div>';
            
            // Environment variables check
            echo '<div class="info">';
            echo '<h3>Environment Variables:</h3>';
            echo '<table>';
            echo '<tr><th>Variable</th><th>Status</th></tr>';
            $env_vars = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'JWT_SECRET'];
            foreach ($env_vars as $var) {
                $value = getenv($var);
                $status = $value ? '<span class="badge badge-success">✓ Set</span>' : '<span class="badge badge-error">✗ Not Set</span>';
                echo '<tr><td>' . $var . '</td><td>' . $status . '</td></tr>';
            }
            echo '</table>';
            echo '</div>';
            
        } catch (Exception $e) {
            echo '<div class="error">';
            echo '<h2>❌ Connection Failed!</h2>';
            echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
            
            echo '<div class="info">';
            echo '<h3>Debug Information:</h3>';
            echo '<table>';
            echo '<tr><th>Environment Variable</th><th>Value</th></tr>';
            $env_vars = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'MYSQL_URL'];
            foreach ($env_vars as $var) {
                $value = getenv($var);
                echo '<tr><td>' . $var . '</td><td>' . ($value ?: '<em>NOT SET</em>') . '</td></tr>';
            }
            echo '</table>';
            echo '</div>';
            
            echo '<div class="info">';
            echo '<h3>Common Issues:</h3>';
            echo '<ul>';
            echo '<li>Check if MySQL service is running in Railway</li>';
            echo '<li>Verify environment variables are set correctly</li>';
            echo '<li>Make sure DB_HOST is "mysql.railway.internal"</li>';
            echo '<li>Ensure both services are in the same Railway project</li>';
            echo '</ul>';
            echo '</div>';
        }
        ?>
        
        <hr style="margin: 30px 0;">
        <p><strong>⚠️ Security Warning:</strong> Delete this test-db.php file after testing!</p>
        <a href="/admin/" style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 600;">Go to Admin Panel</a>
    </div>
</body>
</html>
