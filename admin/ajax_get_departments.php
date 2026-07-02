<?php
require_once 'includes/header.php';
header('Content-Type: application/json');

if (isset($_GET['faculty'])) {
    $faculty = $_GET['faculty'];
    $stmt = $pdo->prepare("SELECT department_id, department_name FROM departments WHERE faculty = ? ORDER BY department_name");
    $stmt->execute([$faculty]);
} else {
    $stmt = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
}

echo json_encode($stmt->fetchAll());
?>