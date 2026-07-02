<?php
session_start();
header('Content-Type: application/json');

// Test if the API is reachable
echo json_encode([
    'response' => '✅ API is working! The AI Assistant is ready to help you.',
    'options' => ['Test successful', 'Continue']
]);