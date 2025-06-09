<?php
session_name('vendor_session');
session_start();

if (!isset($_SESSION['vendor_id'])) {
  header('HTTP/1.1 401 Unauthorized');
  exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "foodiehub";

try {
  $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $reservation_id = $_POST['reservation_id'] ?? null;
  $status = $_POST['status'] ?? null;

  if (!$reservation_id || !$status) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit();
  }

  $valid_statuses = ['Pending', 'Confirmed', 'Cancelled'];
  if (!in_array($status, $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
  }

  $query = "UPDATE subscription_reservations SET status = :status WHERE id = :reservation_id AND vendor_id = :vendor_id";
  $stmt = $conn->prepare($query);
  $stmt->bindParam(':status', $status);
  $stmt->bindParam(':reservation_id', $reservation_id, PDO::PARAM_INT);
  $stmt->bindParam(':vendor_id', $_SESSION['vendor_id'], PDO::PARAM_INT);

  if ($stmt->execute()) {
    echo json_encode(['success' => true]);
  } else {
    echo json_encode(['success' => false, 'message' => 'Failed to update status']);
  }
} catch (PDOException $e) {
  echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
