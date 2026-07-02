<?php
// debug.php - Temporary debugging file
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Debug Mode Active</h1>";
echo "<p>PHP Version: " . phpversion() . "</p>";

// Check if dashboard.php has errors
try {
    include 'dashboard.php';
} catch (Throwable $e) {
    echo "<h2 style='color:red;'>Error Loading Dashboard:</h2>";
    echo "<pre>";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString();
    echo "</pre>";
}
?>