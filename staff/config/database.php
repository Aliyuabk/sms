 <?php
/**
 * Database Configuration - Simplified & Production-Ready
 * Student Management System
 */

// ============================================================
// 1. DATABASE CREDENTIALS (ONLINE HOSTING)
// ============================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'utgoohwm_sms');
define('DB_USER', 'utgoohwm_kowaguru');
define('DB_PASS', 'Jiddaahh@1');
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// 2. SIMPLE PDO CONNECTION
// ============================================================
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log("DB Connection Failed: " . $e->getMessage());
            die("Database connection error. Please try again later.");
        }
    }
    
    return $pdo;
}

// ============================================================
// 3. SIMPLE HELPER FUNCTIONS
// ============================================================

function dbQuery($sql, $params = []) {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function dbFetchOne($sql, $params = []) {
    return dbQuery($sql, $params)->fetch();
}

function dbFetchAll($sql, $params = []) {
    return dbQuery($sql, $params)->fetchAll();
}

function dbInsert($table, $data) {
    $pdo = getDB();
    $columns = implode(', ', array_keys($data));
    $placeholders = ':' . implode(', :', array_keys($data));
    $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
    return $pdo->lastInsertId();
}

function dbUpdate($table, $data, $where, $whereParams = []) {
    $setParts = [];
    foreach ($data as $key => $value) {
        $setParts[] = "{$key} = :{$key}";
    }
    $sql = "UPDATE {$table} SET " . implode(', ', $setParts) . " WHERE {$where}";
    $stmt = dbQuery($sql, array_merge($data, $whereParams));
    return $stmt->rowCount();
}

function dbDelete($table, $where, $params = []) {
    $stmt = dbQuery("DELETE FROM {$table} WHERE {$where}", $params);
    return $stmt->rowCount();
}

// ============================================================
// 4. TRANSACTIONS
// ============================================================
function dbBegin() { getDB()->beginTransaction(); }
function dbCommit() { getDB()->commit(); }
function dbRollback() { getDB()->rollBack(); }

// ============================================================
// 5. SANITIZATION
// ============================================================
function dbSanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// ============================================================
// 6. TIMEZONE
// ============================================================
date_default_timezone_set('Africa/Lagos');

// ============================================================
// 7. GLOBAL PDO (FOR BACKWARDS COMPATIBILITY)
// ============================================================
try {
    $pdo = getDB();
} catch (Exception $e) {
    $pdo = null;
}

// ============================================================
// 8. SECURITY HEADERS
// ============================================================
if (!headers_sent()) {
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
} 