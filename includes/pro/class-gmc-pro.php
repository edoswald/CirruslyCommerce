<?php
// monitor/index.php - Cirrusly Admin Console v2.0

// --- 1. CONFIGURATION ---
session_start();
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', 'cirrusly-worker'); 
define('DB_USER', getenv('DB_USER') ?: 'cirrusly'); 
define('DB_PASS', getenv('DB_PASS') ?: 'Weather97vw!');

// Default credentials for FIRST RUN only (will be migrated to DB)
$default_user = 'cirrusly';
$default_pass = 'Weather97vw!';

// Connect DB
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) { die("<h2>Database Connection Failed</h2><p>" . $e->getMessage() . "</p>"); }

// --- 2. SELF-INSTALLATION / MIGRATION ---
// Automatically creates necessary tables if they don't exist
$tables_sql = "
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL UNIQUE,
  `password_hash` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
);
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255),
  PRIMARY KEY (`setting_key`)
);
";
$pdo->exec($tables_sql);

// Check if admin user exists, if not, create default
$check_user = $pdo->query("SELECT count(*) FROM admin_users")->fetchColumn();
if ($check_user == 0) {
    $stmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)");
    $stmt->execute([$default_user, password_hash($default_pass, PASSWORD_DEFAULT)]);
}

// --- 3. AUTHENTICATION LOGIC ---

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Login Processing
$login_error = '';
if (isset($_POST['login_attempt'])) {
    $u = $_POST['username'];
    $p = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? LIMIT 1");
    $stmt->execute([$u]);
    $user = $stmt->fetch();

    if ($user && password_verify($p, $user['password_hash'])) {
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        header("Location: index.php");
        exit;
    } else {
        $login_error = "Invalid Username or Password";
    }
}

// Require Login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Cirrusly Admin</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f3f4f6; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-box { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); width: 320px; }
        h2 { margin-top: 0; color: #1f2937; text-align: center; margin-bottom: 20px; }
        input { width: 100%; padding: 12px; margin: 8px 0; border: 1px solid #d1d5db; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        button { width: 100%; background: #2563eb; color: white; padding: 12px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 16px; margin-top: 10px; transition: background 0.2s;}
        button:hover { background: #1d4ed8; }
        .error { background: #fee2e2; color: #b91c1c; padding: 10px; border-radius: 6px; text-align: center; font-size: 0.9em; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>🌩️ Admin Access</h2>
        <?php if($login_error) echo "<div class='error'>$login_error</div>"; ?>
        <form method="POST">
            <input type="hidden" name="login_attempt" value="1">
            <input type="text" name="username" placeholder="Username" required autofocus>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Sign In</button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}

// --- 4. ADMIN ACTIONS (POST HANDLING) ---

$msg = "";
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // -> Create Key
    if (isset($_POST['create_key'])) {
        $new_key = bin2hex(random_bytes(24));
        $email = $_POST['owner_email'];
        $plan = $_POST['plan_id'];
        $stmt = $pdo->prepare("INSERT INTO api_keys (license_key, plan_id, owner_email, status) VALUES (?, ?, ?, 'active')");
        if ($stmt->execute([$new_key, $plan, $email])) {
            $msg = "<div class='alert success'>Key Generated: <strong>$new_key</strong></div>";
        } else { $msg = "<div class='alert error'>Error creating key.</div>"; }
        $active_tab = 'keys';
    }

    // -> Key Actions (Revoke/Delete)
    if (isset($_POST['key_action'])) {
        $id = $_POST['key_id'];
        if ($_POST['key_action'] == 'toggle') {
            $curr = $_POST['current_status'];
            $new = ($curr == 'active') ? 'inactive' : 'active';
            $pdo->prepare("UPDATE api_keys SET status = ? WHERE id = ?")->execute([$new, $id]);
            $msg = "<div class='alert success'>Key updated to $new.</div>";
        } elseif ($_POST['key_action'] == 'delete') {
            $pdo->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$id]);
            $msg = "<div class='alert success'>Key deleted.</div>";
        }
        $active_tab = 'keys';
    }

    // -> Update Password
    if (isset($_POST['update_profile'])) {
        $new_user = trim($_POST['new_username']);
        $new_pass = $_POST['new_password'];
        $confirm  = $_POST['confirm_password'];
        
        if (!empty($new_pass) && $new_pass !== $confirm) {
            $msg = "<div class='alert error'>Passwords do not match.</div>";
        } else {
            // Update username
            $pdo->prepare("UPDATE admin_users SET username = ? WHERE id = ?")->execute([$new_user, $_SESSION['user_id']]);
            $_SESSION['username'] = $new_user;
            
            // Update password if provided
            if (!empty($new_pass)) {
                $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?")->execute([$hash, $_SESSION['user_id']]);
            }
            $msg = "<div class='alert success'>Profile updated successfully.</div>";
        }
        $active_tab = 'settings';
    }

    // -> System Settings (Maintenance Mode)
    if (isset($_POST['update_settings'])) {
        $mode = isset($_POST['maintenance_mode']) ? 'on' : 'off';
        // Use Insert on Duplicate Key Update to handle first set
        $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES ('maintenance_mode', ?) ON DUPLICATE KEY UPDATE setting_value = ?";
        $pdo->prepare($sql)->execute([$mode, $mode]);
        $msg = "<div class='alert success'>System settings updated.</div>";
        $active_tab = 'settings';
    }

    // -> Prune Logs
    if (isset($_POST['prune_logs'])) {
        $days = intval($_POST['prune_days']);
        if ($days > 0) {
            $stmt = $pdo->prepare("DELETE FROM api_logs WHERE created_at < (NOW() - INTERVAL ? DAY)");
            $stmt->execute([$days]);
            $count = $stmt->rowCount();
            $msg = "<div class='alert success'>Pruned $count log entries older than $days days.</div>";
        }
        $active_tab = 'settings';
    }
}

// --- 5. DATA FETCHING ---

function safe_query($pdo, $sql, $fetch_mode = PDO::FETCH_ASSOC, $fetch_all = false) {
    try {
        $stmt = $pdo->query($sql);
        return $fetch_all ? $stmt->fetchAll($fetch_mode) : $stmt->fetch($fetch_mode);
    } catch (PDOException $e) { return false; }
}

$plan_names = [ '36829' => 'Free', '36830' => 'Pro', '37116' => 'Agency', 'default' => 'Unknown' ];

// Stats
$stats = safe_query($pdo, "SELECT COUNT(*) as total, COALESCE(SUM(CASE WHEN status='error' THEN 1 ELSE 0 END), 0) as errors, COALESCE(SUM(CASE WHEN status='blocked' THEN 1 ELSE 0 END), 0) as blocked FROM api_logs WHERE created_at > (NOW() - INTERVAL 24 HOUR)");
if (!$stats) $stats = ['total' => 0, 'errors' => 0, 'blocked' => 0];

// Keys
$all_keys = safe_query($pdo, "SELECT * FROM api_keys ORDER BY created_at DESC", PDO::FETCH_ASSOC, true);

// Maintenance Mode Status
$m_mode = safe_query($pdo, "SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'");
$is_maintenance = ($m_mode && $m_mode['setting_value'] === 'on');

// Logs (Latest 50)
$logs = safe_query($pdo, "SELECT * FROM api_logs ORDER BY id DESC LIMIT 50", PDO::FETCH_ASSOC, true);

// Charts
$time_data = safe_query($pdo, "SELECT DATE_FORMAT(created_at, '%H:00') as label, COUNT(*) as count FROM api_logs WHERE created_at > (NOW() - INTERVAL 24 HOUR) GROUP BY label ORDER BY MIN(created_at) ASC", PDO::FETCH_KEY_PAIR, true);
$plan_data = safe_query($pdo, "SELECT plan_id, COUNT(*) as count FROM api_logs WHERE created_at > (NOW() - INTERVAL 24 HOUR) GROUP BY plan_id", PDO::FETCH_KEY_PAIR, true);
$chart_data = [
    'time_labels' => array_keys($time_data ?: []),
    'time_values' => array_values($time_data ?: []),
    'plan_labels' => array_map(function($k) use ($plan_names) { return isset($plan_names[$k]) ? $plan_names[$k] : $k; }, array_keys($plan_data ?: [])),
    'plan_values' => array_values($plan_data ?: [])
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cirrusly Admin</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f3f4f6; color: #1f2937; margin: 0; }
        
        /* Sidebar Layout */
        .wrapper { display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background: #111827; color: white; padding: 20px; flex-shrink: 0; }
        .content { flex-grow: 1; padding: 30px; overflow-y: auto; }
        
        .brand { font-size: 1.5rem; font-weight: bold; margin-bottom: 30px; display: block; color: white; text-decoration: none; display: flex; align-items: center; gap: 10px; }
        .nav-link { display: block; padding: 12px 15px; color: #9ca3af; text-decoration: none; border-radius: 6px; margin-bottom: 5px; transition: 0.2s; }
        .nav-link:hover { background: #374151; color: white; }
        .nav-link.active { background: #2563eb; color: white; }
        .nav-link.logout { margin-top: 40px; color: #f87171; }
        .nav-link.logout:hover { background: #7f1d1d; color: white; }

        /* Cards & Grid */
        .card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; margin-bottom: 20px; }

        /* Typography & Elements */
        h2 { margin-top: 0; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px; margin-bottom: 20px; font-size: 1.25rem; }
        h3 { margin-top: 0; font-size: 1.1rem; color: #4b5563; }
        .stat-val { font-size: 2.5rem; font-weight: 700; color: #111827; }
        .stat-lbl { text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; color: #6b7280; font-weight: 600; }
        
        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 10px; border-bottom: 2px solid #f3f4f6; color: #6b7280; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; }
        td { padding: 12px 10px; border-bottom: 1px solid #f3f4f6; font-size: 0.95rem; vertical-align: middle; }
        
        /* Badges */
        .badge { padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; display: inline-block; }
        .bg-green { background: #d1fae5; color: #065f46; }
        .bg-red { background: #fee2e2; color: #991b1b; }
        .bg-yellow { background: #fef3c7; color: #92400e; }
        .bg-gray { background: #f3f4f6; color: #1f2937; }
        .code { font-family: monospace; background: #f3f4f6; padding: 2px 6px; border-radius: 4px; color: #374151; font-size: 0.9em; }

        /* Forms */
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 0.9rem; }
        input[type="text"], input[type="email"], input[type="password"], input[type="number"], select { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; box-sizing: border-box; }
        .btn { padding: 10px 16px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 0.9rem; transition: 0.2s; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 5px 10px; font-size: 0.8rem; }

        /* Alerts */
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; }
        .alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        /* Toggle Switch */
        .switch { position: relative; display: inline-block; width: 50px; height: 26px; vertical-align: middle; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #ef4444; } /* Red for danger/maintenance */
        input:checked + .slider:before { transform: translateX(24px); }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="sidebar">
        <a href="?" class="brand">🌩️ Cirrusly</a>
        <nav>
            <a href="?tab=dashboard" class="nav-link <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
            <a href="?tab=keys" class="nav-link <?php echo $active_tab == 'keys' ? 'active' : ''; ?>">API Keys</a>
            <a href="?tab=logs" class="nav-link <?php echo $active_tab == 'logs' ? 'active' : ''; ?>">Live Logs</a>
            <a href="?tab=settings" class="nav-link <?php echo $active_tab == 'settings' ? 'active' : ''; ?>">Settings</a>
            <a href="?logout=true" class="nav-link logout">Logout</a>
        </nav>
        
        <?php if($is_maintenance): ?>
        <div style="margin-top: 30px; padding: 10px; background: #7f1d1d; border-radius: 8px; font-size: 0.85em; text-align: center;">
            ⚠️ <strong>Maintenance Mode</strong><br>API is currently Offline
        </div>
        <?php endif; ?>
    </div>

    <div class="content">
        
        <?php echo $msg; ?>

        <?php if ($active_tab == 'dashboard'): ?>
            <div class="grid-3">
                <div class="card">
                    <div class="stat-lbl">Total Requests (24h)</div>
                    <div class="stat-val"><?php echo number_format($stats['total']); ?></div>
                </div>
                <div class="card">
                    <div class="stat-lbl">Errors / Blocked</div>
                    <div class="stat-val" style="color: <?php echo ($stats['blocked'] > 0 || $stats['errors'] > 50) ? '#d97706' : '#059669'; ?>">
                        <?php echo number_format($stats['errors']); ?> <span style="font-size:0.5em; color:#9ca3af;">/</span> <?php echo number_format($stats['blocked']); ?>
                    </div>
                </div>
                <div class="card">
                    <div class="stat-lbl">Active Keys (Total)</div>
                    <div class="stat-val"><?php echo count(array_filter($all_keys, function($k){ return $k['status'] == 'active'; })); ?></div>
                </div>
            </div>

            <div class="grid-2">
                <div class="card">
                    <h3>Traffic Volume (24h)</h3>
                    <div style="height: 250px;"><canvas id="chartTime"></canvas></div>
                </div>
                <div class="card">
                    <h3>Usage by Plan</h3>
                    <div style="height: 250px;"><canvas id="chartPlan"></canvas></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($active_tab == 'keys'): ?>
            <div class="card">
                <h2>Create New License</h2>
                <form method="POST" style="display:flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                    <div style="flex-grow: 1;">
                        <label>Owner Email / Reference</label>
                        <input type="text" name="owner_email" placeholder="client@example.com" required>
                    </div>
                    <div style="width: 200px;">
                        <label>Plan Type</label>
                        <select name="plan_id">
                            <option value="36829">Free (50 req/hr)</option>
                            <option value="36830">Pro (500 req/hr)</option>
                            <option value="37116">Agency (2500 req/hr)</option>
                        </select>
                    </div>
                    <button type="submit" name="create_key" class="btn btn-primary">Generate Key</button>
                </form>
            </div>

            <div class="card">
                <h2>Active Licenses</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Owner</th>
                            <th>Plan</th>
                            <th>Key Preview</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($all_keys as $k): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($k['owner_email']); ?></td>
                            <td><span class="badge bg-gray"><?php echo $plan_names[$k['plan_id']] ?? $k['plan_id']; ?></span></td>
                            <td><span class="code"><?php echo substr($k['license_key'], 0, 12); ?>...</span></td>
                            <td>
                                <span class="badge <?php echo $k['status']=='active'?'bg-green':'bg-red'; ?>">
                                    <?php echo strtoupper($k['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($k['created_at'])); ?></td>
                            <td style="text-align:right;">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="key_id" value="<?php echo $k['id']; ?>">
                                    <input type="hidden" name="current_status" value="<?php echo $k['status']; ?>">
                                    <button type="submit" name="key_action" value="toggle" class="btn btn-sm" style="background:none; border:1px solid #d1d5db; cursor:pointer;">
                                        <?php echo $k['status']=='active' ? 'Revoke' : 'Activate'; ?>
                                    </button>
                                    <button type="submit" name="key_action" value="delete" class="btn btn-sm btn-danger" onclick="return confirm('Delete this key permanently?');">Del</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($active_tab == 'logs'): ?>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h2>Recent Traffic Logs (Last 50)</h2>
                    <a href="?tab=logs" class="btn btn-sm btn-primary" style="text-decoration:none;">Refresh</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Action</th>
                            <th>Status</th>
                            <th>Plan</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($logs as $log): ?>
                        <tr>
                            <td><?php echo date('H:i:s', strtotime($log['created_at'])); ?></td>
                            <td><strong><?php echo htmlspecialchars($log['action']); ?></strong></td>
                            <td>
                                <?php 
                                    $c = 'bg-green';
                                    if($log['status'] == 'error') $c = 'bg-red';
                                    if($log['status'] == 'blocked') $c = 'bg-yellow';
                                ?>
                                <span class="badge <?php echo $c; ?>"><?php echo $log['status']; ?></span>
                            </td>
                            <td><?php echo $plan_names[$log['plan_id']] ?? '-'; ?></td>
                            <td class="code"><?php echo $log['ip']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($active_tab == 'settings'): ?>
            <div class="grid-2">
                
                <div class="card">
                    <h2>👤 Admin Profile</h2>
                    <form method="POST">
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" name="new_username" value="<?php echo htmlspecialchars($_SESSION['username']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>New Password (leave empty to keep current)</label>
                            <input type="password" name="new_password">
                        </div>
                        <div class="form-group">
                            <label>Confirm Password</label>
                            <input type="password" name="confirm_password">
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                    </form>
                </div>

                <div style="display:flex; flex-direction: column; gap: 20px;">
                    
                    <div class="card" style="border-left: 5px solid #ef4444;">
                        <h2>⚠️ Maintenance Mode</h2>
                        <p style="color:#6b7280; font-size:0.9em; margin-bottom:15px;">
                            If enabled, the API will return <code>503 Service Unavailable</code> for ALL requests. Use this during updates or attacks.
                        </p>
                        <form method="POST" style="display:flex; align-items:center; gap: 15px;">
                            <label class="switch">
                                <input type="checkbox" name="maintenance_mode" <?php echo $is_maintenance ? 'checked' : ''; ?> onchange="this.form.submit()">
                                <span class="slider"></span>
                            </label>
                            <span style="font-weight:bold; color: <?php echo $is_maintenance ? '#dc2626' : '#059669'; ?>">
                                <?php echo $is_maintenance ? 'SYSTEM OFFLINE' : 'System Online'; ?>
                            </span>
                            <input type="hidden" name="update_settings" value="1">
                        </form>
                    </div>

                    <div class="card">
                        <h2>🧹 Database Maintenance</h2>
                        <form method="POST">
                            <label>Delete logs older than:</label>
                            <div style="display:flex; gap:10px;">
                                <select name="prune_days">
                                    <option value="30">30 Days</option>
                                    <option value="60">60 Days</option>
                                    <option value="90">90 Days</option>
                                    <option value="7">7 Days (Aggressive)</option>
                                </select>
                                <button type="submit" name="prune_logs" class="btn btn-danger btn-sm">Prune Logs</button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php if ($active_tab == 'dashboard'): ?>
<script>
    const data = <?php echo json_encode($chart_data); ?>;
    new Chart(document.getElementById('chartTime'), {
        type: 'line',
        data: {
            labels: data.time_labels,
            datasets: [{
                label: 'Requests', data: data.time_values,
                borderColor: '#2563eb', backgroundColor: 'rgba(37, 99, 235, 0.1)',
                fill: true, tension: 0.3
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });
    new Chart(document.getElementById('chartPlan'), {
        type: 'doughnut',
        data: {
            labels: data.plan_labels,
            datasets: [{
                data: data.plan_values,
                backgroundColor: ['#10b981', '#3b82f6', '#8b5cf6', '#f59e0b']
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });
</script>
<?php endif; ?>

</body>
</html>