<?php
session_start();

// Check if data is received
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $input = json_decode(file_get_contents('php://input'), true);

  if (isset($input['cart'])) {
    $_SESSION['cart'] = $input['cart'];
    echo json_encode(['status' => 'success', 'message' => 'Cart updated']);
  } elseif (isset($input['reservation_cart'])) {
    $_SESSION['reservation_cart'] = $input['reservation_cart'];
    echo json_encode(['status' => 'success', 'message' => 'Reservation cart updated']);
  } else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid cart data']);
  }
} else {
  http_response_code(405);
  echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
}
