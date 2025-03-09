<html>
    e6nBmMrfoYBsIjTLFvTiIyvfmvF8a89CB1maPfsfX6tAU7QKE4z202as2q1nHuTS
    </html>

<?php
// api_password_receiver.php

// Set the response content type to JSON
header("Content-Type: application/json");

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(["error" => "Only POST requests allowed."]);
    exit;
}

// Check for API token in the Authorization header
$apiToken = 'e6nBmMrfoYBsIjTLFvTiIyvfmvF8a89CB1maPfsfX6tAU7QKE4z202as2q1nHuTS'; // Replace with your secure token
if (!isset($_SERVER['HTTP_AUTHORIZATION']) || $_SERVER['HTTP_AUTHORIZATION'] !== $apiToken) {
    http_response_code(403); // Forbidden
    echo json_encode(["error" => "Unauthorized request."]);
    exit;
}

// Get the raw POST data and decode it from JSON
$inputJSON = file_get_contents('php://input');
$data = json_decode($inputJSON, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400); // Bad Request
    echo json_encode(["error" => "Invalid JSON payload."]);
    exit;
}

// Validate that both required parameters are present
if (!isset($data['server_id']) || !isset($data['password'])) {
    http_response_code(400); // Bad Request
    echo json_encode(["error" => "Missing required parameters: server_id and password."]);
    exit;
}

$server_id = $data['server_id'];
$password = $data['password'];

// OPTIONAL: Validate password complexity or server_id format if needed

try {
    // Set your database connection details here
    $dsn = 'mysql:host=your_db_host;dbname=your_db_name;charset=utf8';
    $dbUser = 'your_db_user';
    $dbPassword = 'your_db_password';

    // Create a new PDO instance with error mode set to Exception
    $pdo = new PDO($dsn, $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Update the server record with the new password.
    // Assumes you have a 'servers' table with 'id' and 'password' columns.
    $stmt = $pdo->prepare("UPDATE servers SET password = :password WHERE id = :server_id");
    $stmt->bindParam(':password', $password);
    $stmt->bindParam(':server_id', $server_id);
    $stmt->execute();

    // Check if the server was found and updated
    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "Server ID not found."]);
        exit;
    }

    // Respond with a success message
    http_response_code(200);
    echo json_encode(["success" => true, "message" => "Password updated successfully."]);

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    echo json_encode(["error" => "Database error: " . $e->getMessage()]);
    exit;
}
?>
