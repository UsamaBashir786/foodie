<?php
session_start();
include 'config/db.php';

// Check if user is logged in
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Fetch vendor details
$vendor_id = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 1;
$vendorQuery = "SELECT v.*, vi.image_path 
                FROM vendors v 
                LEFT JOIN vendor_images vi ON v.id = vi.vendor_id 
                WHERE v.id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $vendorQuery);
mysqli_stmt_bind_param($stmt, 'i', $vendor_id);
mysqli_stmt_execute($stmt);
$vendorResult = mysqli_stmt_get_result($stmt);
$vendor = mysqli_fetch_assoc($vendorResult);
$vendor_id = $vendor ? (int)$vendor['id'] : 0;

// Initialize variables
$subscription = null;
$delivery_fee = 0;
$non_subscriber_delivery_fee = 0;
$discount_percentage = 0;

// Fetch user’s active subscription for this vendor
if ($user_id > 0) {
  $subQuery = "SELECT us.*, s.dish_limit, s.meal_times, s.plan_type, s.non_subscriber_delivery_fee, s.per_head, s.price AS subscription_price, s.min_headcount, s.max_headcount
               FROM user_subscriptions us 
               JOIN subscriptions s ON us.subscription_id = s.id 
               WHERE us.user_id = ? AND s.vendor_id = ? AND us.status = 'active' 
               AND us.end_date > NOW() LIMIT 1";
  $stmt = mysqli_prepare($conn, $subQuery);
  mysqli_stmt_bind_param($stmt, 'ii', $user_id, $vendor_id);
  mysqli_stmt_execute($stmt);
  $subResult = mysqli_stmt_get_result($stmt);
  $subscription = mysqli_fetch_assoc($subResult);

  if ($subscription) {
    $non_subscriber_delivery_fee = (float)$subscription['non_subscriber_delivery_fee'];
    // Set discounts and delivery fee for subscribers
    switch ($subscription['plan_type']) {
      case 'Basic':
        $discount_percentage = 10;
        $delivery_fee = 0; // Free delivery
        break;
      case 'Standard':
        $discount_percentage = 15;
        $delivery_fee = 0; // Free delivery
        break;
      case 'Premium':
        $discount_percentage = 20;
        $delivery_fee = 0; // Free delivery
        break;
      default:
        $discount_percentage = 0;
        $delivery_fee = $non_subscriber_delivery_fee;
    }
  } else {
    // Non-subscriber: No discount, apply delivery fee
    $feeQuery = "SELECT non_subscriber_delivery_fee 
                 FROM subscriptions 
                 WHERE vendor_id = ? AND status = 'active' LIMIT 1";
    $stmt = mysqli_prepare($conn, $feeQuery);
    mysqli_stmt_bind_param($stmt, 'i', $vendor_id);
    mysqli_stmt_execute($stmt);
    $feeResult = mysqli_stmt_get_result($stmt);
    $feeRow = mysqli_fetch_assoc($feeResult);
    $non_subscriber_delivery_fee = $feeRow ? (float)$feeRow['non_subscriber_delivery_fee'] : 100;
    $delivery_fee = $non_subscriber_delivery_fee;
    $discount_percentage = 0; // Explicitly no discount for non-subscribers
  }
} else {
  // Non-logged-in users: Can view menu and add to cart, but prompted to log in at checkout
  $feeQuery = "SELECT non_subscriber_delivery_fee 
               FROM subscriptions 
               WHERE vendor_id = ? AND status = 'active' LIMIT 1";
  $stmt = mysqli_prepare($conn, $feeQuery);
  mysqli_stmt_bind_param($stmt, 'i', $vendor_id);
  mysqli_stmt_execute($stmt);
  $feeResult = mysqli_stmt_get_result($stmt);
  $feeRow = mysqli_fetch_assoc($feeResult);
  $non_subscriber_delivery_fee = $feeRow ? (float)$feeRow['non_subscriber_delivery_fee'] : 100;
  $delivery_fee = $non_subscriber_delivery_fee;
  $discount_percentage = 0; // No discount for non-logged-in users
}

// Fetch menu items for the vendor
$menuQuery = "SELECT * FROM menu_items WHERE vendor_id = ? ORDER BY category, name";
$stmt = mysqli_prepare($conn, $menuQuery);
mysqli_stmt_bind_param($stmt, 'i', $vendor_id);
mysqli_stmt_execute($stmt);
$menuResult = mysqli_stmt_get_result($stmt);
$menuItems = [];
$categories = [];

if ($menuResult) {
  while ($row = mysqli_fetch_assoc($menuResult)) {
    // Apply discount to menu item price (0% for non-subscribers)
    $row['original_price'] = (float)$row['price'];
    $row['price'] = $row['original_price'] * (1 - $discount_percentage / 100);
    $row['image'] = !empty($row['image']) ? 'vendor/' . $row['image'] : "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 400 200'><rect fill='%23ff6b35' width='400' height='200'/><text x='200' y='110' text-anchor='middle' fill='white' font-size='30'>🍽️</text></svg>";
    $menuItems[] = $row;
    if (!in_array($row['category'], $categories)) {
      $categories[] = $row['category'];
    }
  }
}

// Initialize carts
if (!isset($_SESSION['cart'])) {
  $_SESSION['cart'] = [];
}
if (!isset($_SESSION['reservation_cart'])) {
  $_SESSION['reservation_cart'] = [];
}

// Handle order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
  if ($user_id === 0) {
    $_SESSION['error'] = "Please log in to place an order.";
    header("Location: login.php?redirect=menu.php?vendor_id=$vendor_id");
    exit;
  }

  $cart_data = json_decode($_POST['cart_data'], true);
  if (empty($cart_data)) {
    $_SESSION['error'] = "Your cart is empty.";
    header("Location: menu.php?vendor_id=$vendor_id");
    exit;
  }

  $subtotal = (float)$_POST['subtotal'];
  $delivery_address = htmlspecialchars($_POST['delivery_address']);
  if (empty($delivery_address)) {
    $_SESSION['error'] = "Please provide a delivery address.";
    header("Location: menu.php?vendor_id=$vendor_id");
    exit;
  }

  $payment_method = 'Cash on Delivery';
  $order_date = date('Y-m-d H:i:s');
  $total = $subtotal + $delivery_fee;

  // Start transaction
  mysqli_begin_transaction($conn);

  try {
    // Insert order
    $orderQuery = "INSERT INTO orders (order_type, user_id, vendor_id, subtotal, delivery_fee, total, delivery_address, payment_method, order_date, status)
                   VALUES ('Order', ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')";
    $stmt = mysqli_prepare($conn, $orderQuery);
    mysqli_stmt_bind_param($stmt, 'iiddssss', $user_id, $vendor_id, $subtotal, $delivery_fee, $total, $delivery_address, $payment_method, $order_date);
    if (!mysqli_stmt_execute($stmt)) {
      throw new Exception("Error inserting order: " . mysqli_error($conn));
    }
    $order_id = mysqli_insert_id($conn);

    // Insert order items
    foreach ($cart_data as $item) {
      $item_id = (int)$item['id'];
      $quantity = (int)$item['quantity'];
      $price = (float)$item['price'];
      $item_subtotal = $price * $quantity;
      $itemQuery = "INSERT INTO order_items (order_id, menu_item_id, quantity, price, subtotal)
                    VALUES (?, ?, ?, ?, ?)";
      $stmt = mysqli_prepare($conn, $itemQuery);
      mysqli_stmt_bind_param($stmt, 'iiidd', $order_id, $item_id, $quantity, $price, $item_subtotal);
      if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error inserting order item: " . mysqli_error($conn));
      }
    }

    // Commit transaction
    mysqli_commit($conn);

    // Clear cart
    $_SESSION['cart'] = [];
    $_SESSION['order_success'] = "Order placed successfully! Order ID: $order_id";
    header("Location: order_confirmation.php?order_id=$order_id");
    exit;
  } catch (Exception $e) {
    mysqli_rollback($conn);
    error_log($e->getMessage(), 3, "logs/error_log");
    $_SESSION['error'] = "Failed to place order: " . htmlspecialchars($e->getMessage());
    header("Location: menu.php?vendor_id=$vendor_id");
    exit;
  }
}


// Handle reservation submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reserve'])) {
  if ($user_id === 0) {
    $_SESSION['error'] = "Please log in to reserve meals.";
    header("Location: login.php?redirect=menu.php?vendor_id=$vendor_id");
    exit;
  }

  $reservation_cart_data = json_decode($_POST['reservation_cart_data'], true);
  $headcount = isset($_POST['headcount']) ? (int)$_POST['headcount'] : $subscription['min_headcount'];

  // Debugging: Log the received headcount
  error_log("Received headcount: $headcount", 3, "logs/debug_log");

  if (empty($reservation_cart_data)) {
    $_SESSION['error'] = "Your reservation cart is empty.";
    header("Location: menu.php?vendor_id=$vendor_id");
    exit;
  }

  // Validate headcount
  if ($subscription && ($headcount < $subscription['min_headcount'] || $headcount > $subscription['max_headcount'])) {
    $_SESSION['error'] = "Headcount must be between {$subscription['min_headcount']} and {$subscription['max_headcount']}.";
    header("Location: menu.php?vendor_id=$vendor_id");
    exit;
  }

  $total_dishes = array_sum(array_column($reservation_cart_data, 'quantity'));
  if ($subscription && $total_dishes > $subscription['dish_limit']) {
    $_SESSION['error'] = "Total dishes exceed the subscription dish limit of {$subscription['dish_limit']}.";
    header("Location: menu.php?vendor_id=$vendor_id");
    exit;
  }

  // Find an available slot
  $slotQuery = "SELECT id, slot_date, slot_time, capacity 
                FROM reservation_slots 
                WHERE vendor_id = ? AND status = 'available' 
                AND slot_date >= CURDATE() AND capacity >= ? 
                AND id NOT IN (SELECT slot_id FROM subscription_reservations WHERE slot_id IS NOT NULL)
                ORDER BY slot_date ASC, slot_time ASC LIMIT 1";
  $stmt = mysqli_prepare($conn, $slotQuery);
  mysqli_stmt_bind_param($stmt, 'ii', $vendor_id, $headcount);
  mysqli_stmt_execute($stmt);
  $slotResult = mysqli_stmt_get_result($stmt);
  $slot = mysqli_fetch_assoc($slotResult);

  if (!$slot) {
    $_SESSION['error'] = "No available reservation slots found for your criteria. Please contact the vendor.";
    header("Location: menu.php?vendor_id=$vendor_id");
    exit;
  }

  $slot_id = (int)$slot['id'];
  $reservation_date = $slot['slot_date'];
  $meal_time = date('H:i', strtotime($slot['slot_time']));

  // Start transaction
  mysqli_begin_transaction($conn);

  try {
    // Insert into subscription_reservations
    $reservationQuery = "INSERT INTO subscription_reservations (user_id, subscription_id, vendor_id, meal_time, reservation_date, headcount, slot_id, status, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())";
    $stmt = mysqli_prepare($conn, $reservationQuery);
    $subscription_id = $subscription ? (int)$subscription['id'] : 0;
    mysqli_stmt_bind_param($stmt, 'iiissii', $user_id, $subscription_id, $vendor_id, $meal_time, $reservation_date, $headcount, $slot_id);
    if (!mysqli_stmt_execute($stmt)) {
      throw new Exception("Error inserting reservation: " . mysqli_error($conn));
    }
    $reservation_id = mysqli_insert_id($conn);

    // Debugging: Log the stored headcount
    error_log("Stored headcount for reservation ID $reservation_id: $headcount", 3, "logs/debug_log");

    // Insert reservation items
    foreach ($reservation_cart_data as $item) {
      $item_id = (int)$item['id'];
      $quantity = (int)$item['quantity'];
      $itemQuery = "INSERT INTO reservation_items (reservation_id, menu_item_id, quantity)
                        VALUES (?, ?, ?)";
      $stmt = mysqli_prepare($conn, $itemQuery);
      mysqli_stmt_bind_param($stmt, 'iii', $reservation_id, $item_id, $quantity);
      if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error inserting reservation item: " . mysqli_error($conn));
      }
    }

    // Mark slot as reserved
    $updateSlotQuery = "UPDATE reservation_slots SET status = 'reserved', updated_at = NOW() WHERE id = ? AND status = 'available'";
    $stmt = mysqli_prepare($conn, $updateSlotQuery);
    mysqli_stmt_bind_param($stmt, 'i', $slot_id);
    if (!mysqli_stmt_execute($stmt)) {
      throw new Exception("Error updating slot status: " . mysqli_error($conn));
    }
    if (mysqli_affected_rows($conn) === 0) {
      throw new Exception("Slot was already reserved by another user.");
    }

    // Commit transaction
    mysqli_commit($conn);

    // Clear reservation cart
    $_SESSION['reservation_cart'] = [];
    $_SESSION['reservation_success'] = "Meals reserved successfully! Reservation ID: $reservation_id";
    header("Location: menu.php?vendor_id=$vendor_id");
    exit;
  } catch (Exception $e) {
    mysqli_rollback($conn);
    error_log($e->getMessage(), 3, "logs/error_log");
    $_SESSION['error'] = "Failed to reserve meals: " . htmlspecialchars($e->getMessage());
    header("Location: menu.php?vendor_id=$vendor_id");
    exit;
  }
}




?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Restaurant Menu - FoodieHub</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <!-- SweetAlert2 CDN -->
  <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
  <style>
    :root {
      --primary-color: #ff6b35;
      --secondary-color: #ffa726;
      --accent-color: #4caf50;
      --dark-color: #2c3e50;
      --light-color: #ecf0f1;
      --success-color: #27ae60;
      --warning-color: #f39c12;
      --danger-color: #e74c3c;
      --info-color: #3498db;
      --white: #ffffff;
      --gray-100: #f8f9fa;
      --gray-200: #e9ecef;
      --gray-300: #dee2e6;
      --gray-400: #ced4da;
      --gray-500: #adb5bd;
      --gray-800: #495057;
      --shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
      --shadow-hover: 0 15px 35px rgba(0, 0, 0, 0.15);
      --border-radius: 12px;
      --transition: all 0.3s ease;
    }

    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      line-height: 1.6;
      color: var(--dark-color);
      background: var(--gray-100);
    }

    .navbar {
      background: var(--white) !important;
      box-shadow: var(--shadow);
      padding: 1rem 0;
      transition: var(--transition);
    }

    .navbar-brand {
      font-weight: 700;
      font-size: 1.8rem;
      color: var(--primary-color) !important;
    }

    .navbar-nav .nav-link {
      font-weight: 500;
      color: var(--dark-color) !important;
      margin: 0 0.5rem;
      transition: var(--transition);
      position: relative;
    }

    .navbar-nav .nav-link:hover,
    .navbar-nav .nav-link.active {
      color: var(--primary-color) !important;
    }

    .navbar-nav .nav-link::after {
      content: '';
      position: absolute;
      width: 0;
      height: 2px;
      bottom: -5px;
      left: 50%;
      background-color: var(--primary-color);
      transition: var(--transition);
      transform: translateX(-50%);
    }

    .navbar-nav .nav-link:hover::after,
    .navbar-nav .nav-link.active::after {
      width: 100%;
    }

    .btn-login {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      border: none;
      color: var(--white);
      padding: 0.5rem 1.5rem;
      border-radius: 25px;
      font-weight: 500;
      transition: var(--transition);
    }

    .btn-login:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
      color: var(--white);
    }

    .page-header {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      color: var(--white);
      padding: 6rem 0 3rem;
      margin-top: 76px;
      position: relative;
      overflow: hidden;
    }

    .page-header::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle fill="%23ffffff" cx="20" cy="20" r="2" opacity="0.1"/><circle fill="%23ffffff" cx="80" cy="40" r="1.5" opacity="0.1"/><circle fill="%23ffffff" cx="40" cy="70" r="1" opacity="0.1"/><circle fill="%23ffffff" cx="90" cy="80" r="2.5" opacity="0.1"/></svg>');
      background-size: 100px 100px;
      animation: float 20s infinite linear;
    }

    @keyframes float {
      0% {
        background-position: 0 0;
      }

      100% {
        background-position: 100px 100px;
      }
    }

    .page-header h1 {
      font-size: 3rem;
      font-weight: 700;
      margin-bottom: 1rem;
      position: relative;
      z-index: 2;
    }

    .page-header p {
      font-size: 1.2rem;
      opacity: 0.9;
      position: relative;
      z-index: 2;
    }

    .shop-meta {
      display: flex;
      align-items: center;
      gap: 1rem;
      font-size: 0.9rem;
      color: var(--white);
      margin-top: 1rem;
    }

    .rating-stars {
      color: var(--secondary-color);
      font-size: 0.9rem;
    }

    .search-filter-section {
      background: var(--white);
      padding: 2rem 0;
      box-shadow: var(--shadow);
      position: sticky;
      top: 76px;
      z-index: 100;
    }

    .search-box {
      position: relative;
    }

    .search-box input {
      border: 2px solid var(--gray-300);
      border-radius: 50px;
      padding: 1rem 1.5rem 1rem 3rem;
      font-size: 1rem;
      transition: var(--transition);
      width: 100%;
    }

    .search-box input:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
      outline: none;
    }

    .search-box .search-icon {
      position: absolute;
      left: 1rem;
      top: 50%;
      transform: translateY(-50%);
      color: var(--gray-500);
      font-size: 1.1rem;
    }

    .filter-buttons {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .filter-btn {
      background: var(--gray-200);
      border: none;
      color: var(--dark-color);
      padding: 0.5rem 1rem;
      border-radius: 25px;
      font-weight: 500;
      transition: var(--transition);
      cursor: pointer;
    }

    .filter-btn:hover,
    .filter-btn.active {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      color: var(--white);
      transform: translateY(-2px);
    }

    .menu-grid {
      padding: 3rem 0;
    }

    .menu-card {
      background: var(--white);
      border-radius: var(--border-radius);
      overflow: hidden;
      box-shadow: var(--shadow);
      transition: var(--transition);
      height: 100%;
      cursor: pointer;
      position: relative;
    }

    .menu-card:hover {
      transform: translateY(-10px);
      box-shadow: var(--shadow-hover);
    }

    .menu-image {
      width: 100%;
      height: 180px;
      object-fit: cover;
      transition: var(--transition);
      loading: lazy;
    }

    .menu-card:hover .menu-image {
      transform: scale(1.05);
    }

    .menu-info {
      padding: 1.5rem;
    }

    .menu-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--dark-color);
      margin-bottom: 0.5rem;
    }

    .menu-category {
      color: var(--primary-color);
      font-weight: 600;
      font-size: 0.85rem;
      margin-bottom: 0.5rem;
    }

    .menu-description {
      color: var(--gray-800);
      font-size: 0.9rem;
      margin-bottom: 1rem;
      line-height: 1.5;
    }

    .menu-price {
      color: var(--primary-color);
      font-weight: 700;
      font-size: 1.1rem;
      margin-bottom: 1rem;
    }

    .menu-price .original-price {
      color: var(--gray-500);
      text-decoration: line-through;
      font-size: 0.9rem;
      margin-left: 0.5rem;
    }

    .add-to-cart-btn,
    .add-to-reservation-btn {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      border: none;
      color: var(--white);
      padding: 0.75rem 1.5rem;
      border-radius: 25px;
      font-weight: 600;
      transition: var(--transition);
      width: 100%;
      text-align: center;
    }

    .add-to-cart-btn:hover,
    .add-to-reservation-btn:hover {
      color: var(--white);
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }

    .no-results {
      text-align: center;
      padding: 4rem 0;
      color: var(--gray-800);
    }

    .no-results i {
      font-size: 4rem;
      color: var(--gray-400);
      margin-bottom: 1.5rem;
    }

    .loading {
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 3rem 0;
    }

    .spinner {
      width: 50px;
      height: 50px;
      border: 4px solid var(--gray-300);
      border-top: 4px solid var(--primary-color);
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      0% {
        transform: rotate(0deg);
      }

      100% {
        transform: rotate(360deg);
      }
    }

    .cart-sidebar {
      position: fixed;
      top: 0;
      right: -400px;
      width: 400px;
      height: 100%;
      background: var(--white);
      box-shadow: -5px 0 15px rgba(0, 0, 0, 0.1);
      transition: right 0.3s ease;
      z-index: 99999;
      padding: 2rem;
      overflow-y: auto;
    }

    .cart-sidebar.open {
      right: 0;
    }

    .cart-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }

    .cart-close {
      background: none;
      border: none;
      font-size: 1.5rem;
      color: var(--dark-color);
      cursor: pointer;
    }

    .cart-item {
      display: flex;
      align-items: center;
      padding: 1rem 0;
      border-bottom: 1px solid var(--gray-200);
    }

    .cart-item img {
      width: 60px;
      height: 60px;
      object-fit: cover;
      border-radius: var(--border-radius);
      margin-right: 1rem;
    }

    .cart-item-info {
      flex: 1;
    }

    .cart-item-title {
      font-size: 1rem;
      font-weight: 600;
      margin-bottom: 0.25rem;
    }

    .cart-item-price {
      color: var(--primary-color);
      font-weight: 600;
    }

    .cart-item-quantity {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .quantity-btn {
      background: var(--gray-200);
      border: none;
      padding: 0.25rem 0.5rem;
      border-radius: 5px;
      cursor: pointer;
    }

    .quantity-btn:hover {
      background: var(--primary-color);
      color: var(--white);
    }

    .cart-item-remove {
      background: none;
      border: none;
      color: var(--danger-color);
      font-size: 1.2rem;
      cursor: pointer;
    }

    .cart-subtotal,
    .cart-delivery,
    .cart-total {
      margin-top: 1rem;
      font-size: 1.1rem;
      font-weight: 600;
      text-align: right;
    }

    .checkout-form {
      margin-top: 1.5rem;
    }

    .checkout-form label {
      font-weight: 600;
      margin-bottom: 0.5rem;
      display: block;
    }

    .checkout-form input,
    .checkout-form select {
      width: 100%;
      padding: 0.75rem;
      border: 2px solid var(--gray-300);
      border-radius: var(--border-radius);
      transition: var(--transition);
    }

    .checkout-form input:focus,
    .checkout-form select:focus {
      border-color: var(--primary-color);
      outline: none;
      box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
    }

    .checkout-btn,
    .reserve-btn {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      border: none;
      color: var(--white);
      padding: 1rem;
      border-radius: 25px;
      font-weight: 600;
      width: 100%;
      text-align: center;
      margin-top: 1rem;
    }

    .checkout-btn:hover,
    .reserve-btn:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }

    .cart-toggle {
      position: fixed;
      bottom: 2rem;
      right: 2rem;
      background: var(--primary-color);
      color: var(--white);
      width: 60px;
      height: 60px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      cursor: pointer;
      box-shadow: var(--shadow);
      transition: var(--transition);
    }

    .cart-toggle:hover {
      background: var(--secondary-color);
      transform: scale(1.1);
    }

    .cart-count {
      position: absolute;
      top: -10px;
      right: -10px;
      background: var(--danger-color);
      color: var(--white);
      border-radius: 50%;
      width: 24px;
      height: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.8rem;
      font-weight: 600;
    }

    .alert {
      margin-bottom: 1rem;
    }

    .nav-tabs {
      border-bottom: 2px solid var(--gray-200);
      margin-bottom: 2rem;
    }

    .nav-tabs .nav-link {
      color: var(--dark-color);
      font-weight: 500;
      padding: 0.75rem 1.5rem;
      border-radius: var(--border-radius) var(--border-radius) 0 0;
    }

    .nav-tabs .nav-link:hover,
    .nav-tabs .nav-link.active {
      background: var(--primary-color);
      color: var(--white) !important;
      border-color: var(--primary-color);
    }

    .subscription-info {
      background: var(--white);
      padding: 1.5rem;
      border-radius: var(--border-radius);
      box-shadow: var(--shadow);
      margin-bottom: 2rem;
    }

    @media (max-width: 768px) {
      .page-header h1 {
        font-size: 2rem;
      }

      .filter-buttons {
        justify-content: center;
        margin-top: 1rem;
      }

      .menu-card {
        margin-bottom: 2rem;
      }

      .cart-sidebar {
        width: 100%;
        right: -100%;
      }
    }

  
  </style>
</head>

<body>
  <!-- Navbar -->
  <?php include 'includes/navbar.php'; ?>

  <!-- Page Header -->
  <section class="page-header">
    <div class="container">
      <div class="row">
        <div class="col-lg-8">
          <h1 id="restaurantName"><?php echo $vendor ? htmlspecialchars($vendor['restaurant_name']) : 'Restaurant Menu'; ?></h1>
          <p>Explore delicious dishes<?php if ($subscription): ?> and reserve meals with your subscription plan!<?php endif; ?></p>
          <div class="shop-meta">
            <div class="rating-stars" id="restaurantRating">
              <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
            </div>
            <div class="delivery-time"><i class="fas fa-clock me-1"></i><span id="restaurantDeliveryTime">25-35</span> min</div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Search and Filter Section -->
  <section class="search-filter-section">
    <div class="container">
      <div class="row align-items-center">
        <div class="col-lg-4 mb-3 mb-lg-0">
          <div class="search-box">
            <i class="fas fa-search search-icon"></i>
            <input type="text" id="searchInput" placeholder="Search dishes...">
          </div>
        </div>
        <div class="col-lg-8">
          <div class="filter-buttons" id="categoryFilters">
            <button class="filter-btn active" data-category="all" aria-label="Filter by all categories">All</button>
            <?php foreach ($categories as $category): ?>
              <button class="filter-btn" data-category="<?php echo strtolower($category); ?>" aria-label="Filter by <?php echo htmlspecialchars($category); ?> category"><?php echo htmlspecialchars($category); ?></button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Menu Grid -->
  <section class="menu-grid">
    <div class="container">
      <?php if ($subscription): ?>
        <div class="subscription-info fade-in">
          <h4>Your Subscription Plan</h4>
          <!-- <p><strong>Plan:</strong> <?php echo htmlspecialchars($subscription['custom_plan'] ? $subscription['custom_plan_name'] : $subscription['plan_type']); ?></p> -->
          <p><strong>Dish Limit:</strong> <?php echo htmlspecialchars($subscription['dish_limit']); ?> dishes per meal</p>
          <p><strong>Meal Times:</strong> <?php echo htmlspecialchars($subscription['meal_times']); ?></p>
          <p><strong>Item Discount:</strong> <?php echo number_format($discount_percentage, 2); ?>% off</p>
          <p><strong>Delivery:</strong> Free</p>
          <p><strong>Valid Until:</strong> <?php echo date('d M Y', strtotime($subscription['end_date'])); ?></p>
        </div>
      <?php endif; ?>
      <ul class="nav nav-tabs" id="menuTabs">
        <li class="nav-item">
          <button class="nav-link active" id="order-tab" data-bs-toggle="tab" data-bs-target="#order-content" type="button">Order Now</button>
        </li>
        <?php if ($subscription): ?>
          <li class="nav-item">
            <button class="nav-link" id="reserve-tab" data-bs-toggle="tab" data-bs-target="#reserve-content" type="button">Reserve Meals</button>
          </li>
        <?php endif; ?>
      </ul>
      <div class="tab-content">
        <div class="tab-pane fade show active" id="order-content">
          <div class="row" id="menuContainer">
            <?php if (empty($menuItems)): ?>
              <div class="col-12 no-results text-center">
                <i class="fas fa-search fa-2x mb-3"></i>
                <h3>No dishes found</h3>
                <p>Try checking back later for new menu items</p>
              </div>
            <?php else: ?>
              <?php foreach ($menuItems as $item): ?>
                <div class="col-lg-4 col-md-6 mb-4 menu-item"
                  data-category="<?php echo strtolower($item['category']); ?>"
                  data-name="<?php echo strtolower($item['name']); ?>">
                  <div class="menu-card fade-in">
                    <img src="<?php echo htmlspecialchars($item['image']); ?>"
                      alt="<?php echo htmlspecialchars($item['name']); ?>"
                      class="menu-image" loading="lazy">
                    <div class="menu-info">
                      <div class="menu-category"><?php echo htmlspecialchars($item['category']); ?></div>
                      <h5 class="menu-title"><?php echo htmlspecialchars($item['name']); ?></h5>
                      <p class="menu-description"><?php echo htmlspecialchars($item['description']); ?></p>
                      <div class="menu-price">
                        PKR <?php echo number_format($item['price'], 2); ?>
                        <?php if ($discount_percentage > 0): ?>
                          <span class="original-price">PKR <?php echo number_format($item['original_price'], 2); ?></span>
                        <?php endif; ?>
                      </div>
                      <button class="add-to-cart-btn"
                        data-id="<?php echo $item['id']; ?>"
                        data-name="<?php echo htmlspecialchars($item['name']); ?>"
                        data-price="<?php echo $item['price']; ?>"
                        data-original-price="<?php echo $item['original_price']; ?>"
                        data-image="<?php echo htmlspecialchars($item['image']); ?>"
                        data-category="<?php echo htmlspecialchars($item['category']); ?>">
                        <i class="fas fa-cart-plus me-2"></i>Add to Cart
                      </button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($subscription): ?>
          <div class="tab-pane fade" id="reserve-content">
            <div class="row" id="reserveMenuContainer">
              <?php if (empty($menuItems)): ?>
                <div class="col-12 no-results text-center">
                  <i class="fas fa-search fa-2x mb-3"></i>
                  <h3>No dishes found</h3>
                  <p>Try checking back later for new menu items</p>
                </div>
              <?php else: ?>
                <?php foreach ($menuItems as $item): ?>
                  <div class="col-lg-4 col-md-6 mb-4 menu-item"
                    data-category="<?php echo strtolower($item['category']); ?>"
                    data-name="<?php echo strtolower($item['name']); ?>">
                    <div class="menu-card fade-in">
                      <img src="<?php echo htmlspecialchars($item['image']); ?>"
                        alt="<?php echo htmlspecialchars($item['name']); ?>"
                        class="menu-image" loading="lazy">
                      <div class="menu-info">
                        <div class="menu-category"><?php echo htmlspecialchars($item['category']); ?></div>
                        <h5 class="menu-title"><?php echo htmlspecialchars($item['name']); ?></h5>
                        <p class="menu-description"><?php echo htmlspecialchars($item['description']); ?></p>
                        <div class="menu-price">Included in Subscription</div>
                        <button class="add-to-reservation-btn"
                          data-id="<?php echo $item['id']; ?>"
                          data-name="<?php echo htmlspecialchars($item['name']); ?>"
                          data-image="<?php echo htmlspecialchars($item['image']); ?>"
                          data-category="<?php echo htmlspecialchars($item['category']); ?>">
                          <i class="fas fa-calendar-plus me-2"></i>Add to Reservation
                        </button>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Cart Sidebar -->
  <div class="cart-sidebar" id="cartSidebar">
    <div class="cart-header">
      <h4 id="cartHeaderTitle">Your Cart</h4>
      <button class="cart-close" id="cartClose"><i class="fas fa-times"></i></button>
    </div>
    <?php if (isset($_SESSION['order_success'])): ?>
      <div class="alert alert-success" id="successAlert" data-message="<?php echo htmlspecialchars($_SESSION['order_success']); ?>">
        <?php echo htmlspecialchars($_SESSION['order_success']);
        unset($_SESSION['order_success']); ?>
      </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['reservation_success'])): ?>
      <div class="alert alert-success" id="successAlert" data-message="<?php echo htmlspecialchars($_SESSION['reservation_success']); ?>">
        <?php echo htmlspecialchars($_SESSION['reservation_success']);
        unset($_SESSION['reservation_success']); ?>
      </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
      <div class="alert alert-danger" id="errorAlert" data-message="<?php echo htmlspecialchars($_SESSION['error']); ?>">
        <?php echo htmlspecialchars($_SESSION['error']);
        unset($_SESSION['error']); ?>
      </div>
    <?php endif; ?>
    <div id="cartItems"></div>
    <div id="reservationItems" style="display: none;"></div>
    <!-- Order Summary -->
    <div id="orderSummary" style="display: none;">
      <div class="cart-subtotal" id="cartSubtotal">Subtotal: PKR 0.00</div>
      <div class="cart-delivery" id="cartDelivery">Delivery Fee: PKR <?php echo number_format($delivery_fee, 2); ?></div>
      <div class="cart-total" id="cartTotal">Total: PKR 0.00</div>
    </div>
    <!-- Reservation Summary -->
    <div id="reservationSummary" style="display: none;">
      <div class="cart-headcount" id="cartHeadcount">Headcount: 0</div>
      <div class="cart-dish-count" id="cartDishCount">Total Dishes: 0</div>
    </div>
    <div class="checkout-form">
      <label for="deliveryAddress" id="deliveryAddressLabel">Delivery Address</label>
      <input type="text" id="deliveryAddress" placeholder="Enter your delivery address" required>
      <?php if ($subscription): ?>
        <label for="perHead" class="mt-3">Per Head</label>
        <input type="number" id="perHead" name="headcount" min="<?php echo $subscription['min_headcount']; ?>" max="<?php echo $subscription['max_headcount']; ?>" value="<?php echo $subscription['min_headcount']; ?>" required>
      <?php endif; ?>
      <label for="paymentMethod" class="mt-3">Payment Method</label>
      <select id="paymentMethod" disabled>
        <option value="cod" selected>Cash on Delivery</option>
      </select>
      <button class="checkout-btn" id="checkoutBtn">Proceed to Checkout</button>
      <?php if ($subscription): ?>
        <button class="reserve-btn" id="reserveBtn">Reserve Meals</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="cart-toggle" id="cartToggle">
    <i class="fas fa-shopping-cart"></i>
    <span class="cart-count" id="cartCount">0</span>
  </div>
  <div class="cart-toggle" id="cartToggle">
    <i class="fas fa-shopping-cart"></i>
    <span class="cart-count" id="cartCount">0</span>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
  <!-- SweetAlert2 JS -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <!-- Custom JavaScript -->
  <script>
    let currentMenu = <?php echo json_encode($menuItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
let currentCategory = 'all';
let cart = <?php echo json_encode($_SESSION['cart'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
let reservationCart = <?php echo json_encode($_SESSION['reservation_cart'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const isLoggedIn = <?php echo $user_id > 0 ? 'true' : 'false'; ?>;
const dishLimit = <?php echo $subscription ? (int)$subscription['dish_limit'] : 0; ?>;
const deliveryFee = <?php echo $delivery_fee; ?>;

// DOM Elements
const restaurantName = document.getElementById('restaurantName');
const restaurantRating = document.getElementById('restaurantRating');
const restaurantDeliveryTime = document.getElementById('restaurantDeliveryTime');
const menuContainer = document.getElementById('menuContainer');
const reserveMenuContainer = document.getElementById('reserveMenuContainer');
const searchInput = document.getElementById('searchInput');
const categoryFilters = document.getElementById('categoryFilters');
const cartSidebar = document.getElementById('cartSidebar');
const cartClose = document.getElementById('cartClose');
const cartToggle = document.getElementById('cartToggle');
const cartItemsContainer = document.getElementById('cartItems');
const reservationItemsContainer = document.getElementById('reservationItems');
const cartSubtotal = document.getElementById('cartSubtotal');
const cartDelivery = document.getElementById('cartDelivery');
const cartTotal = document.getElementById('cartTotal');
const cartCount = document.getElementById('cartCount');
const checkoutBtn = document.getElementById('checkoutBtn');
const reserveBtn = document.getElementById('reserveBtn');
const deliveryAddress = document.getElementById('deliveryAddress');
const orderSummary = document.getElementById('orderSummary');
const reservationSummary = document.getElementById('reservationSummary');
const cartHeaderTitle = document.getElementById('cartHeaderTitle');

// Show SweetAlert for success/error messages on page load
document.addEventListener('DOMContentLoaded', function () {
  const successAlert = document.getElementById('successAlert');
  const errorAlert = document.getElementById('errorAlert');
  if (successAlert) {
    Swal.fire({
      icon: 'success',
      title: 'Success',
      text: successAlert.dataset.message,
      timer: 3000,
      showConfirmButton: false,
    });
  }
  if (errorAlert) {
    Swal.fire({
      icon: 'error',
      title: 'Error',
      text: errorAlert.dataset.message,
      confirmButtonText: 'OK',
    });
  }

  // Initialize cart
  updateCart();

  // Attach filter button listeners
  document.querySelectorAll('.filter-btn').forEach((button) => {
    button.addEventListener('click', function () {
      document.querySelectorAll('.filter-btn').forEach((btn) => btn.classList.remove('active'));
      this.classList.add('active');
      currentCategory = this.dataset.category;
      applyFilters();
    });
  });

  // Accessibility: Handle keyboard navigation for filter buttons
  document.querySelectorAll('.filter-btn').forEach((button) => {
    button.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        this.click();
      }
    });
  });

  // Event delegation for add-to-cart and add-to-reservation buttons
  document.addEventListener('click', function (e) {
    const cartButton = e.target.closest('.add-to-cart-btn');
    const reservationButton = e.target.closest('.add-to-reservation-btn');

    if (cartButton) {
      e.preventDefault();
      const item = {
        id: parseInt(cartButton.dataset.id),
        name: cartButton.dataset.name,
        price: parseFloat(cartButton.dataset.price),
        original_price: parseFloat(cartButton.dataset.original_price || cartButton.dataset.price),
        image: cartButton.dataset.image,
        category: cartButton.dataset.category,
        quantity: 1,
      };
      addToCart(item);
    }

    if (reservationButton) {
      e.preventDefault();
      const item = {
        id: parseInt(reservationButton.dataset.id),
        name: reservationButton.dataset.name,
        price: 0,
        image: reservationButton.dataset.image,
        category: reservationButton.dataset.category,
        quantity: 1,
      };
      addToReservation(item);
    }
  });
});

// Render menu items
function renderMenu(items, container) {
  if (items.length === 0) {
    container.innerHTML = `
      <div class="col-12 no-results">
        <i class="fas fa-search"></i>
        <h3>No dishes found</h3>
        <p>Try adjusting your search or filters</p>
      </div>
    `;
    return;
  }

  const isReservation = container.id === 'reserveMenuContainer';
  const menuHtml = items
    .map(
      (item) => `
      <div class="col-lg-4 col-md-6 mb-4 menu-item" data-category="${item.category.toLowerCase()}" data-name="${item.name.toLowerCase()}">
        <div class="menu-card fade-in">
          <img src="${item.image}" alt="${item.name}" class="menu-image" loading="lazy">
          <div class="menu-info">
            <div class="menu-category">${item.category}</div>
            <h5 class="menu-title">${item.name}</h5>
            <p class="menu-description">${item.description}</p>
            <div class="menu-price">
              ${
                isReservation
                  ? 'Included in Subscription'
                  : 'PKR ' + parseFloat(item.price).toFixed(2)
              }
              ${
                !isReservation && item.original_price > item.price
                  ? `<span class="original-price">PKR ${parseFloat(
                      item.original_price
                    ).toFixed(2)}</span>`
                  : ''
              }
            </div>
            <button class="${
              isReservation ? 'add-to-reservation-btn' : 'add-to-cart-btn'
            }" 
              data-id="${item.id}" 
              data-name="${item.name}" 
              ${!isReservation ? `data-price="${item.price}" data-original-price="${item.original_price}"` : ''} 
              data-image="${item.image}" 
              data-category="${item.category}">
              <i class="fas ${
                isReservation ? 'fa-calendar-plus' : 'fa-cart-plus'
              } me-2"></i>${isReservation ? 'Add to Reservation' : 'Add to Cart'}
            </button>
          </div>
        </div>
      </div>
    `
    )
    .join('');

  container.innerHTML = menuHtml;

  // Trigger fade-in animation
  setTimeout(() => {
    container.querySelectorAll('.fade-in').forEach((el) => {
      el.classList.add('visible');
    });
  }, 100);
}

// Search functionality
searchInput.addEventListener(
  'input',
  debounce(function () {
    applyFilters();
  }, 300)
);

// Apply filters and search
function applyFilters() {
  let filteredItems = [...currentMenu];
  if (currentCategory !== 'all') {
    filteredItems = filteredItems.filter(
      (item) => item.category.toLowerCase() === currentCategory
    );
  }
  const searchTerm = searchInput.value.toLowerCase();
  if (searchTerm) {
    filteredItems = filteredItems.filter(
      (item) =>
        item.name.toLowerCase().includes(searchTerm) ||
        item.description.toLowerCase().includes(searchTerm) ||
        item.category.toLowerCase().includes(searchTerm)
    );
  }
  renderMenu(filteredItems, menuContainer);
  if (reserveMenuContainer) {
    renderMenu(filteredItems, reserveMenuContainer);
  }
}

// Cart functionality
function addToCart(item) {
  const existingItem = cart.find((cartItem) => cartItem.id === item.id);
  if (existingItem) {
    existingItem.quantity += 1;
  } else {
    cart.push(item);
  }
  updateSessionCart('cart');
  updateCart();
  openCart();
  Swal.fire({
    icon: 'success',
    title: 'Added to Cart',
    text: `${item.name} has been added to your cart!`,
    timer: 1500,
    showConfirmButton: false,
  });
}

function addToReservation(item) {
  const totalDishes = reservationCart.reduce(
    (sum, cartItem) => sum + cartItem.quantity,
    0
  );
  if (totalDishes + 1 > dishLimit) {
    Swal.fire({
      icon: 'error',
      title: 'Limit Exceeded',
      text: `You can only select up to ${dishLimit} dishes for your subscription.`,
      confirmButtonText: 'OK',
    });
    return;
  }
  const existingItem = reservationCart.find((cartItem) => cartItem.id === item.id);
  if (existingItem) {
    if (
      existingItem.quantity + 1 + (totalDishes - existingItem.quantity) <=
      dishLimit
    ) {
      existingItem.quantity += 1;
    } else {
      Swal.fire({
        icon: 'error',
        title: 'Limit Exceeded',
        text: `You can only select up to ${dishLimit} dishes for your subscription.`,
        confirmButtonText: 'OK',
      });
      return;
    }
  } else {
    reservationCart.push(item);
  }
  updateSessionCart('reservation_cart');
  updateCart();
  openCart();
  Swal.fire({
    icon: 'success',
    title: 'Added to Reservation',
    text: `${item.name} has been added to your reservation!`,
    timer: 1500,
    showConfirmButton: false,
  });
}

function removeFromCart(itemId, isReservation = false) {
  if (isReservation) {
    reservationCart = reservationCart.filter((item) => item.id !== itemId);
    updateSessionCart('reservation_cart');
  } else {
    cart = cart.filter((item) => item.id !== itemId);
    updateSessionCart('cart');
  }
  updateCart();
  Swal.fire({
    icon: 'success',
    title: 'Removed',
    text: 'Item removed from ' + (isReservation ? 'reservation' : 'cart') + '!',
    timer: 1500,
    showConfirmButton: false,
  });
}

function updateSessionCart(cartType) {
  const xhr = new XMLHttpRequest();
  xhr.open('POST', 'update_cart.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function () {
    if (xhr.readyState === 4) {
      if (xhr.status === 200) {
        console.log('Cart updated successfully:', xhr.responseText);
      } else {
        console.error('Error updating cart:', xhr.status, xhr.responseText);
        Swal.fire({
          icon: 'error',
          title: 'Cart Update Failed',
          text: 'Unable to update cart. Please try again.',
          confirmButtonText: 'OK',
        });
      }
    }
  };
  xhr.send(
    JSON.stringify({
      [cartType]: cartType === 'cart' ? cart : reservationCart,
    })
  );
}

function updateCart() {
  // Determine cart mode based on active tab or cart contents
  const orderTab = document.getElementById('order-tab');
  const isOrderTabActive = orderTab && orderTab.classList.contains('active');
  const hasOrderItems = cart.length > 0;
  const hasReservationItems = reservationCart.length > 0;
  const showReservation = (!isOrderTabActive && hasReservationItems) || (!hasOrderItems && hasReservationItems);

  // Update cart header title
  cartHeaderTitle.textContent = showReservation ? 'Your Reservation' : 'Your Cart';

  // Toggle visibility of cart and reservation items
  cartItemsContainer.style.display = showReservation ? 'none' : 'block';
  reservationItemsContainer.style.display = showReservation ? 'block' : 'none';
  orderSummary.style.display = showReservation ? 'none' : 'block';
  reservationSummary.style.display = showReservation ? 'block' : 'none';

  // Update regular cart
  cartItemsContainer.innerHTML = cart
    .map(
      (item) => `
      <div class="cart-item">
        <img src="${item.image}" alt="${item.name}">
        <div class="cart-item-info">
          <div class="cart-item-title">${item.name}</div>
          <div class="cart-item-price">
            PKR ${(item.price * item.quantity).toFixed(2)}
            ${
              item.original_price > item.price
                ? `<span class="original-price">PKR ${(item.original_price * item.quantity).toFixed(2)}</span>`
                : ''
            }
          </div>
          <div class="cart-item-quantity">
            <button class="quantity-btn" data-id="${item.id}" data-action="decrease">-</button>
            <span>${item.quantity}</span>
            <button class="quantity-btn" data-id="${item.id}" data-action="increase">+</button>
          </div>
        </div>
        <button class="cart-item-remove" data-id="${item.id}">
          <i class="fas fa-trash"></i>
        </button>
      </div>
    `
    )
    .join('');

  // Update reservation cart
  reservationItemsContainer.innerHTML = reservationCart
    .map(
      (item) => `
      <div class="cart-item">
        <img src="${item.image}" alt="${item.name}">
        <div class="cart-item-info">
          <div class="cart-item-title">${item.name}</div>
          <div class="cart-item-price">Included in Subscription</div>
          <div class="cart-item-quantity">
            <button class="quantity-btn" data-id="${item.id}" data-action="decrease" data-reservation="true">-</button>
            <span>${item.quantity}</span>
            <button class="quantity-btn" data-id="${item.id}" data-action="increase" data-reservation="true">+</button>
          </div>
        </div>
        <button class="cart-item-remove" data-id="${item.id}" data-reservation="true">
          <i class="fas fa-trash"></i>
        </button>
      </div>
    `
    )
    .join('');

  // Update order summary
  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
  const total = subtotal + deliveryFee;
  cartSubtotal.textContent = `Subtotal: PKR ${subtotal.toFixed(2)}`;
  cartDelivery.textContent = `Delivery Fee: PKR ${deliveryFee.toFixed(2)}`;
  cartTotal.textContent = `Total: PKR ${total.toFixed(2)}`;

  // Update reservation summary
  const perHead = document.getElementById('perHead') ? parseInt(document.getElementById('perHead').value) || 0 : 0;
  const totalDishes = reservationCart.reduce((sum, item) => sum + item.quantity, 0);
  document.getElementById('cartHeadcount').textContent = `Per Head: ${perHead}`;
  document.getElementById('cartDishCount').textContent = `Total Dishes: ${totalDishes}`;

  // Update cart count
  cartCount.textContent =
    cart.reduce((sum, item) => sum + item.quantity, 0) +
    reservationCart.reduce((sum, item) => sum + item.quantity, 0);

  // Add event listeners for quantity buttons
  document.querySelectorAll('.quantity-btn').forEach((button) => {
    button.addEventListener('click', function () {
      const itemId = parseInt(this.dataset.id);
      const action = this.dataset.action;
      const isReservation = this.dataset.reservation === 'true';
      const targetCart = isReservation ? reservationCart : cart;
      const item = targetCart.find((i) => i.id === itemId);
      if (action === 'increase') {
        if (isReservation) {
          const totalDishes = reservationCart.reduce(
            (sum, cartItem) => sum + cartItem.quantity,
            0
          );
          if (totalDishes < dishLimit) {
            item.quantity += 1;
            Swal.fire({
              icon: 'success',
              title: 'Quantity Updated',
              text: `Increased quantity of ${item.name} to ${item.quantity}.`,
              timer: 1500,
              showConfirmButton: false,
            });
          } else {
            Swal.fire({
              icon: 'error',
              title: 'Limit Exceeded',
              text: `You can only select up to ${dishLimit} dishes for your subscription.`,
              confirmButtonText: 'OK',
            });
            return;
          }
        } else {
          item.quantity += 1;
          Swal.fire({
            icon: 'success',
            title: 'Quantity Updated',
            text: `Increased quantity of ${item.name} to ${item.quantity}.`,
            timer: 1500,
            showConfirmButton: false,
          });
        }
      } else if (action === 'decrease' && item.quantity > 1) {
        item.quantity -= 1;
        Swal.fire({
          icon: 'success',
          title: 'Quantity Updated',
          text: `Decreased quantity of ${item.name} to ${item.quantity}.`,
          timer: 1500,
          showConfirmButton: false,
        });
      } else if (action === 'decrease') {
        removeFromCart(itemId, isReservation);
        return;
      }
      updateSessionCart(isReservation ? 'reservation_cart' : 'cart');
      updateCart();
    });
  });

  // Add event listeners for remove buttons
  document.querySelectorAll('.cart-item-remove').forEach((button) => {
    button.addEventListener('click', function () {
      const itemId = parseInt(this.dataset.id);
      const isReservation = this.dataset.reservation === 'true';
      removeFromCart(itemId, isReservation);
    });
  });
}

function openCart() {
  cartSidebar.classList.add('open');
}

function closeCart() {
  cartSidebar.classList.remove('open');
}

cartToggle.addEventListener('click', openCart);
cartClose.addEventListener('click', closeCart);

checkoutBtn.addEventListener('click', function () {
  if (cart.length === 0) {
    Swal.fire({
      icon: 'error',
      title: 'Empty Cart',
      text: 'Your cart is empty!',
      confirmButtonText: 'OK',
    });
    return;
  }
  const address = deliveryAddress.value.trim();
  if (!address) {
    Swal.fire({
      icon: 'error',
      title: 'Missing Address',
      text: 'Please enter your delivery address.',
      confirmButtonText: 'OK',
    });
    deliveryAddress.focus();
    return;
  }
  if (!isLoggedIn) {
    Swal.fire({
      icon: 'warning',
      title: 'Login Required',
      text: 'Please log in to place an order.',
      confirmButtonText: 'Go to Login',
      showCancelButton: true,
    }).then((result) => {
      if (result.isConfirmed) {
        window.location.href = 'login.php?redirect=menu.php';
      }
    });
    return;
  }

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '';

  const cartInput = document.createElement('input');
  cartInput.type = 'hidden';
  cartInput.name = 'cart_data';
  cartInput.value = JSON.stringify(cart);
  form.appendChild(cartInput);

  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
  const subtotalInput = document.createElement('input');
  subtotalInput.type = 'hidden';
  subtotalInput.name = 'subtotal';
  subtotalInput.value = subtotal;
  form.appendChild(subtotalInput);

  const addressInput = document.createElement('input');
  addressInput.type = 'hidden';
  addressInput.name = 'delivery_address';
  addressInput.value = address;
  form.appendChild(addressInput);

  const checkoutInput = document.createElement('input');
  checkoutInput.type = 'hidden';
  checkoutInput.name = 'checkout';
  checkoutInput.value = '1';
  form.appendChild(checkoutInput);

  document.body.appendChild(form);
  form.submit();
});

if (reserveBtn) {
  reserveBtn.addEventListener('click', function () {
    if (reservationCart.length === 0) {
      Swal.fire({
        icon: 'error',
        title: 'No Meals Selected',
        text: 'You have not selected any meals to reserve.',
        confirmButtonText: 'OK',
      });
      return;
    }
    const perHead = document.getElementById('perHead').value.trim();
    if (!perHead) {
      Swal.fire({
        icon: 'error',
        title: 'Missing Per Head',
        text: 'Please enter the number of people.',
        confirmButtonText: 'OK',
      });
      document.getElementById('perHead').focus();
      return;
    }
    if (!isLoggedIn) {
      Swal.fire({
        icon: 'warning',
        title: 'Login Required',
        text: 'Please log in to reserve meals.',
        confirmButtonText: 'Go to Login',
        showCancelButton: true,
      }).then((result) => {
        if (result.isConfirmed) {
          window.location.href = 'login.php?redirect=menu.php';
        }
      });
      return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';

    const reservationInput = document.createElement('input');
    reservationInput.type = 'hidden';
    reservationInput.name = 'reservation_cart_data';
    reservationInput.value = JSON.stringify(reservationCart);
    form.appendChild(reservationInput);

    const headcountInput = document.createElement('input');
    headcountInput.type = 'hidden';
    headcountInput.name = 'headcount';
    headcountInput.value = perHead;
    form.appendChild(headcountInput);

    const reserveInput = document.createElement('input');
    reserveInput.type = 'hidden';
    reserveInput.name = 'reserve';
    reserveInput.value = '1';
    form.appendChild(reserveInput);

    document.body.appendChild(form);
    form.submit();
  });
}

// Debounce function
function debounce(func, wait) {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}
  </script>
</body>

</html>