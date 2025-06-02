<?php
session_start();
include 'config/db.php';



// Configuration constants
define('DELIVERY_FEE', 250.00);

// Check if user is logged in
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Check if user has an active subscription
$has_subscription = false;
$subscription_plan = null;
$discount_percentage = 0.00;
if ($user_id > 0) {
  $subQuery = "SELECT s.plan_type, s.discount_percentage 
                 FROM user_subscriptions us 
                 LEFT JOIN subscriptions s ON us.subscription_id = s.id 
                 WHERE us.user_id = ? AND us.status = 'active' LIMIT 1";
  $stmt = mysqli_prepare($conn, $subQuery);
  mysqli_stmt_bind_param($stmt, 'i', $user_id);
  mysqli_stmt_execute($stmt);
  $subResult = mysqli_stmt_get_result($stmt);
  if ($subResult && mysqli_num_rows($subResult) > 0) {
    $has_subscription = true;
    $subData = mysqli_fetch_assoc($subResult);
    $subscription_plan = $subData['plan_type'];
    $discount_percentage = (float)$subData['discount_percentage'];
  }
}

// Fetch vendor details
$vendor_id = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 1; // Default to 1 or modify as needed
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
    $row['image'] = !empty($row['image']) ? 'vendor/' . $row['image'] : "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 400 200'><rect fill='%23ff6b35' width='400' height='200'/><text x='200' y='110' text-anchor='middle' fill='white' font-size='30'>🍽️</text></svg>";
    $menuItems[] = $row;
    if (!in_array($row['category'], $categories)) {
      $categories[] = $row['category'];
    }
  }
}

// Fetch available reservation slots
$slotsQuery = "SELECT * FROM reservation_slots WHERE vendor_id = ? AND slot_date >= CURDATE() AND status = 'available' ORDER BY slot_date, slot_time";
$stmt = mysqli_prepare($conn, $slotsQuery);
mysqli_stmt_bind_param($stmt, 'i', $vendor_id);
mysqli_stmt_execute($stmt);
$slotsResult = mysqli_stmt_get_result($stmt);
$reservationSlots = [];
if ($slotsResult) {
  while ($row = mysqli_fetch_assoc($slotsResult)) {
    $reservationSlots[] = $row;
  }
}

// Check reservation eligibility based on subscription
$reservation_limit = 0;
if ($has_subscription) {
  $reservation_limit = match ($subscription_plan) {
    'Basic' => 1,
    'Standard' => 3,
    'Premium' => PHP_INT_MAX,
    default => 0
  };
}

// Count user's reservations this week
$current_week_start = date('Y-m-d', strtotime('monday this week'));
$reservationCountQuery = "SELECT COUNT(*) as count FROM reservations WHERE user_id = ? AND reservation_date >= ? AND status = 'confirmed'";
$stmt = mysqli_prepare($conn, $reservationCountQuery);
mysqli_stmt_bind_param($stmt, 'is', $user_id, $current_week_start);
mysqli_stmt_execute($stmt);
$reservationCountResult = mysqli_stmt_get_result($stmt);
$weekly_reservations = mysqli_fetch_assoc($reservationCountResult)['count'];

// Handle reservation submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_reservation'])) {
  if ($user_id === 0) {
    header("Location: login.php?redirect=menu.php");
    exit;
  }

  $slot_id = (int)$_POST['slot_id'];
  $party_size = (int)$_POST['party_size'];

  // Validate party size
  if ($party_size < 1 || $party_size > 20) {
    $reservation_error = "Party size must be between 1 and 20.";
  } elseif ($weekly_reservations >= $reservation_limit) {
    $reservation_error = "You have reached your weekly reservation limit for your $subscription_plan plan.";
  } else {
    // Fetch slot details
    $slotQuery = "SELECT capacity, slot_date, slot_time FROM reservation_slots WHERE id = ? AND vendor_id = ? AND status = 'available'";
    $stmt = mysqli_prepare($conn, $slotQuery);
    mysqli_stmt_bind_param($stmt, 'ii', $slot_id, $vendor_id);
    mysqli_stmt_execute($stmt);
    $slotResult = mysqli_stmt_get_result($stmt);
    if ($slotResult && $slot = mysqli_fetch_assoc($slotResult)) {
      if ($party_size <= $slot['capacity']) {
        $reservation_date = $slot['slot_date'] . ' ' . $slot['slot_time'];
        $subscription_id = null;
        if ($has_subscription) {
          $subIdQuery = "SELECT subscription_id FROM user_subscriptions WHERE user_id = ? AND status = 'active' LIMIT 1";
          $stmt = mysqli_prepare($conn, $subIdQuery);
          mysqli_stmt_bind_param($stmt, 'i', $user_id);
          mysqli_stmt_execute($stmt);
          $subIdResult = mysqli_stmt_get_result($stmt);
          $subscription_id = mysqli_fetch_assoc($subIdResult)['subscription_id'];
        }

        // Insert reservation
        $reservationQuery = "INSERT INTO reservations (user_id, vendor_id, subscription_id, slot_id, reservation_date, party_size, status, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, 'confirmed', NOW())";
        $stmt = mysqli_prepare($conn, $reservationQuery);
        mysqli_stmt_bind_param($stmt, 'iiisis', $user_id, $vendor_id, $subscription_id, $slot_id, $reservation_date, $party_size);
        if (mysqli_stmt_execute($stmt)) {
          // Update slot capacity
          $new_capacity = $slot['capacity'] - $party_size;
          $slotStatus = $new_capacity <= 0 ? 'fully_booked' : 'available';
          $updateSlotQuery = "UPDATE reservation_slots SET capacity = ?, status = ? WHERE id = ?";
          $stmt = mysqli_prepare($conn, $updateSlotQuery);
          mysqli_stmt_bind_param($stmt, 'isi', $new_capacity, $slotStatus, $slot_id);
          mysqli_stmt_execute($stmt);
          $_SESSION['reservation_success'] = "Table reserved successfully!";
          header("Location: menu.php");
          exit;
        } else {
          $reservation_error = "Error booking reservation: " . mysqli_error($conn);
        }
      } else {
        $reservation_error = "Party size exceeds available capacity for this slot.";
      }
    } else {
      $reservation_error = "Selected slot is not available.";
    }
  }
}

// Initialize cart
if (!isset($_SESSION['cart'])) {
  $_SESSION['cart'] = [];
}

// Handle order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
  if ($user_id === 0) {
    header("Location: login.php?redirect=menu.php");
    exit;
  }

  $cart_data = json_decode($_POST['cart_data'], true);
  $subtotal = (float)$_POST['subtotal'];
  $delivery_address = mysqli_real_escape_string($conn, $_POST['delivery_address']);
  $payment_method = 'Cash on Delivery';
  $order_date = date('Y-m-d H:i:s');

  // Calculate discount
  $discount_amount = ($discount_percentage / 100) * $subtotal;
  $subtotal_after_discount = $subtotal - $discount_amount;

  // Add delivery fee for non-subscribers
  $delivery_fee = $has_subscription ? 0 : DELIVERY_FEE;
  $total = $subtotal_after_discount + $delivery_fee;

  // Insert order
  $orderQuery = "INSERT INTO orders (user_id, vendor_id, subtotal, discount_amount, total, delivery_address, payment_method, order_date, status, delivery_fee)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)";
  $stmt = mysqli_prepare($conn, $orderQuery);
  mysqli_stmt_bind_param($stmt, 'iiddssdss', $user_id, $vendor_id, $subtotal, $discount_amount, $total, $delivery_address, $payment_method, $order_date, $delivery_fee);
  if (mysqli_stmt_execute($stmt)) {
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
      mysqli_stmt_execute($stmt);
    }

    // Clear cart
    $_SESSION['cart'] = [];
    $_SESSION['order_id'] = $order_id;
    header("Location: order_confirmation.php?order_id=$order_id");
    exit;
  } else {
    $error = "Error placing order: " . mysqli_error($conn);
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

    .add-to-cart-btn {
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

    .add-to-cart-btn:hover {
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
    .cart-discount,
    .cart-delivery-fee,
    .cart-total {
      margin-top: 1rem;
      font-size: 1.1rem;
      font-weight: 600;
      text-align: right;
    }

    .cart-discount {
      color: var(--success-color);
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

    .checkout-btn {
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

    .checkout-btn:hover {
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

    .reservation-section {
      padding: 3rem 0;
    }

    .reservation-card {
      background: var(--white);
      border-radius: var(--border-radius);
      box-shadow: var(--shadow);
      padding: 1.5rem;
      margin-bottom: 1.5rem;
    }

    .reservation-card h5 {
      color: var(--primary-color);
      font-weight: 600;
    }

    .book-reservation-btn {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      border: none;
      color: var(--white);
      padding: 0.75rem 1.5rem;
      border-radius: 25px;
      font-weight: 600;
      transition: var(--transition);
    }

    .book-reservation-btn:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
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

    .fade-in {
      opacity: 0;
      transform: translateY(30px);
      transition: all 0.6s ease;
    }

    .fade-in.visible {
      opacity: 1;
      transform: translateY(0);
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
          <p>Explore delicious dishes and book a table</p>
          <div class="shop-meta">
            <div class="rating-stars" id="restaurantRating">
              <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
            </div>
            <div class="delivery-time"><i class="fas fa-clock me-1"></i><span id="restaurantDeliveryTime">25-35</span> min</div>
            <div class="delivery-fee" id="restaurantDeliveryFee"><?php echo $has_subscription ? 'Free delivery' : 'PKR ' . number_format(DELIVERY_FEE, 2) . ' delivery'; ?></div>
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

  <!-- Reservation Section -->
  <section class="reservation-section">
    <div class="container">
      <h3>Book a Table</h3>
      <?php if (isset($_SESSION['reservation_success'])): ?>
        <div class="alert alert-success">
          <?php echo $_SESSION['reservation_success'];
          unset($_SESSION['reservation_success']); ?>
        </div>
      <?php endif; ?>
      <?php if (isset($reservation_error)): ?>
        <div class="alert alert-danger">
          <?php echo $reservation_error; ?>
        </div>
      <?php endif; ?>
      <?php if ($user_id === 0): ?>
        <div class="alert alert-warning">
          Please <a href="login.php?redirect=menu.php">log in</a> to book a table.
        </div>
      <?php elseif ($weekly_reservations >= $reservation_limit): ?>
        <div class="alert alert-warning">
          You have reached your weekly reservation limit for your <?php echo $subscription_plan; ?> plan.
        </div>
      <?php else: ?>
        <div class="reservation-card">
          <h5>Available Reservation Slots</h5>
          <?php if (empty($reservationSlots)): ?>
            <p class="text-center text-muted">No reservation slots available. Check back later!</p>
          <?php else: ?>
            <form method="POST">
              <div class="mb-3">
                <label for="slot_id" class="form-label">Select Slot</label>
                <select class="form-select" id="slot_id" name="slot_id" required>
                  <?php foreach ($reservationSlots as $slot): ?>
                    <option value="<?php echo $slot['id']; ?>">
                      <?php echo date('M d, Y', strtotime($slot['slot_date'])) . ' at ' . date('H:i', strtotime($slot['slot_time'])) . ' (' . $slot['capacity'] . ' seats available)'; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label for="party_size" class="form-label">Party Size</label>
                <input type="number" class="form-control" id="party_size" name="party_size" min="1" max="20" required>
              </div>
              <button type="submit" name="book_reservation" class="book-reservation-btn">Book Reservation</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Menu Grid -->
  <section class="menu-grid">
    <div class="container">
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
                  <div class="menu-price">PKR <?php echo number_format($item['price'], 2); ?></div>
                  <button class="add-to-cart-btn"
                    data-id="<?php echo $item['id']; ?>"
                    data-name="<?php echo htmlspecialchars($item['name']); ?>"
                    data-price="<?php echo $item['price']; ?>"
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
  </section>

  <!-- Cart Sidebar -->
  <div class="cart-sidebar" id="cartSidebar">
    <div class="cart-header">
      <h4>Your Cart</h4>
      <button class="cart-close" id="cartClose"><i class="fas fa-times"></i></button>
    </div>
    <?php if (isset($_SESSION['order_success'])): ?>
      <div class="alert alert-success">
        <?php echo $_SESSION['order_success'];
        unset($_SESSION['order_success']); ?>
      </div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
      <div class="alert alert-danger">
        <?php echo $error; ?>
      </div>
    <?php endif; ?>
    <div id="cartItems"></div>
    <div class="cart-subtotal" id="cartSubtotal">Subtotal: PKR 0.00</div>
    <div class="cart-discount" id="cartDiscount">Discount (<?php echo number_format($discount_percentage, 2); ?>%): PKR 0.00</div>
    <?php if (!$has_subscription): ?>
      <div class="cart-delivery-fee" id="cartDeliveryFee">Delivery Fee: PKR <?php echo number_format(DELIVERY_FEE, 2); ?></div>
    <?php endif; ?>
    <div class="cart-total" id="cartTotal">Total: PKR 0.00</div>
    <div class="checkout-form">
      <label for="deliveryAddress">Delivery Address</label>
      <input type="text" id="deliveryAddress" placeholder="Enter your delivery address" required>
      <label for="paymentMethod" class="mt-3">Payment Method</label>
      <select id="paymentMethod" disabled>
        <option value="cod" selected>Cash on Delivery</option>
      </select>
      <button class="checkout-btn" id="checkoutBtn">Proceed to Checkout</button>
    </div>
  </div>
  <div class="cart-toggle" id="cartToggle">
    <i class="fas fa-shopping-cart"></i>
    <span class="cart-count" id="cartCount">0</span>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>

  <!-- Custom JavaScript -->
  <script>
    let currentMenu = <?php echo json_encode($menuItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    let currentCategory = 'all';
    let cart = <?php echo json_encode($_SESSION['cart'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const isLoggedIn = <?php echo $user_id > 0 ? 'true' : 'false'; ?>;
    const hasSubscription = <?php echo $has_subscription ? 'true' : 'false'; ?>;
    const deliveryFee = hasSubscription ? 0 : <?php echo DELIVERY_FEE; ?>;
    const discountPercentage = <?php echo $discount_percentage; ?>;

    // DOM Elements
    const restaurantName = document.getElementById('restaurantName');
    const restaurantRating = document.getElementById('restaurantRating');
    const restaurantDeliveryTime = document.getElementById('restaurantDeliveryTime');
    const restaurantDeliveryFee = document.getElementById('restaurantDeliveryFee');
    const menuContainer = document.getElementById('menuContainer');
    const searchInput = document.getElementById('searchInput');
    const categoryFilters = document.getElementById('categoryFilters');
    const cartSidebar = document.getElementById('cartSidebar');
    const cartClose = document.getElementById('cartClose');
    const cartToggle = document.getElementById('cartToggle');
    const cartItemsContainer = document.getElementById('cartItems');
    const cartSubtotal = document.getElementById('cartSubtotal');
    const cartDiscount = document.getElementById('cartDiscount');
    const cartTotal = document.getElementById('cartTotal');
    const cartCount = document.getElementById('cartCount');
    const checkoutBtn = document.getElementById('checkoutBtn');
    const deliveryAddress = document.getElementById('deliveryAddress');

    // Render menu items
    function renderMenu(items) {
      if (items.length === 0) {
        menuContainer.innerHTML = `
          <div class="col-12 no-results">
            <i class="fas fa-search"></i>
            <h3>No dishes found</h3>
            <p>Try adjusting your search or filters</p>
          </div>
        `;
        return;
      }

      const menuHtml = items.map(item => `
        <div class="col-lg-4 col-md-6 mb-4 menu-item" data-category="${item.category.toLowerCase()}" data-name="${item.name.toLowerCase()}">
          <div class="menu-card fade-in">
            <img src="${item.image}" alt="${item.name}" class="menu-image" loading="lazy">
            <div class="menu-info">
              <div class="menu-category">${item.category}</div>
              <h5 class="menu-title">${item.name}</h5>
              <p class="menu-description">${item.description}</p>
              <div class="menu-price">PKR ${parseFloat(item.price).toFixed(2)}</div>
              <button class="add-to-cart-btn" data-id="${item.id}" data-name="${item.name}" 
                      data-price="${item.price}" data-image="${item.image}" data-category="${item.category}">
                <i class="fas fa-cart-plus me-2"></i>Add to Cart
              </button>
            </div>
          </div>
        </div>
      `).join('');

      menuContainer.innerHTML = menuHtml;

      // Trigger fade-in animation
      setTimeout(() => {
        document.querySelectorAll('.fade-in').forEach(el => {
          el.classList.add('visible');
        });
      }, 100);

      // Add event listeners for add to cart buttons
      document.querySelectorAll('.add-to-cart-btn').forEach(button => {
        button.addEventListener('click', function() {
          const item = {
            id: parseInt(this.dataset.id),
            name: this.dataset.name,
            price: parseFloat(this.dataset.price),
            image: this.dataset.image,
            category: this.dataset.category,
            quantity: 1
          };
          addToCart(item);
        });
      });
    }

    // Search functionality
    searchInput.addEventListener('input', debounce(function() {
      applyFilters();
    }, 300));

    // Apply filters and search
    function applyFilters() {
      let filteredItems = [...currentMenu];
      if (currentCategory !== 'all') {
        filteredItems = filteredItems.filter(item => item.category.toLowerCase() === currentCategory);
      }
      const searchTerm = searchInput.value.toLowerCase();
      if (searchTerm) {
        filteredItems = filteredItems.filter(item =>
          item.name.toLowerCase().includes(searchTerm) ||
          item.description.toLowerCase().includes(searchTerm) ||
          item.category.toLowerCase().includes(searchTerm)
        );
      }
      renderMenu(filteredItems);
    }

    // Cart functionality
    function addToCart(item) {
      const existingItem = cart.find(cartItem => cartItem.id === item.id);
      if (existingItem) {
        existingItem.quantity += 1;
      } else {
        cart.push(item);
      }
      updateSessionCart();
      updateCart();
      openCart();
    }

    function removeFromCart(itemId) {
      cart = cart.filter(item => item.id !== itemId);
      updateSessionCart();
      updateCart();
    }

    function updateCart() {
      cartItemsContainer.innerHTML = cart.map(item => `
        <div class="cart-item">
          <img src="${item.image}" alt="${item.name}">
          <div class="cart-item-info">
            <div class="cart-item-title">${item.name}</div>
            <div class="cart-item-price">PKR ${(item.price * item.quantity).toFixed(2)}</div>
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
      `).join('');

      const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
      const discountAmount = (discountPercentage / 100) * subtotal;
      const subtotalAfterDiscount = subtotal - discountAmount;
      const total = subtotalAfterDiscount + deliveryFee;

      cartSubtotal.textContent = `Subtotal: PKR ${subtotal.toFixed(2)}`;
      cartDiscount.textContent = `Discount (${discountPercentage.toFixed(2)}%): PKR ${discountAmount.toFixed(2)}`;
      cartTotal.textContent = `Total: PKR ${total.toFixed(2)}`;
      cartCount.textContent = cart.reduce((sum, item) => sum + item.quantity, 0);

      // Update delivery fee display
      const deliveryFeeElement = document.getElementById('cartDeliveryFee');
      if (deliveryFeeElement) {
        deliveryFeeElement.textContent = hasSubscription ? 'Free Delivery' : `Delivery Fee: PKR ${deliveryFee.toFixed(2)}`;
      }

      // Add event listeners for quantity and remove buttons
      document.querySelectorAll('.quantity-btn').forEach(button => {
        button.addEventListener('click', function() {
          const itemId = parseInt(this.dataset.id);
          const action = this.dataset.action;
          const item = cart.find(i => i.id === itemId);
          if (action === 'increase') {
            item.quantity += 1;
          } else if (action === 'decrease' && item.quantity > 1) {
            item.quantity -= 1;
          } else if (action === 'decrease') {
            removeFromCart(itemId);
          }
          updateSessionCart();
          updateCart();
        });
      });

      document.querySelectorAll('.cart-item-remove').forEach(button => {
        button.addEventListener('click', function() {
          const itemId = parseInt(this.dataset.id);
          removeFromCart(itemId);
        });
      });
    }

    function updateSessionCart() {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'update_cart.php', true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.send(JSON.stringify(cart));
    }

    function openCart() {
      cartSidebar.classList.add('open');
    }

    function closeCart() {
      cartSidebar.classList.remove('open');
    }

    cartToggle.addEventListener('click', openCart);
    cartClose.addEventListener('click', closeCart);

    checkoutBtn.addEventListener('click', function() {
      if (cart.length === 0) {
        alert('Your cart is empty!');
        return;
      }
      const address = deliveryAddress.value.trim();
      if (!address) {
        alert('Please enter your delivery address!');
        deliveryAddress.focus();
        return;
      }
      if (!isLoggedIn) {
        window.location.href = 'login.php?redirect=menu.php';
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

    // Initialize page
    document.addEventListener('DOMContentLoaded', function() {
      setTimeout(() => {
        document.querySelectorAll('.fade-in').forEach(el => {
          el.classList.add('visible');
        });
      }, 100);

      updateCart();

      document.querySelectorAll('.filter-btn').forEach(button => {
        button.addEventListener('click', function() {
          document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
          this.classList.add('active');
          currentCategory = this.dataset.category;
          applyFilters();
        });
      });

      document.querySelectorAll('.add-to-cart-btn').forEach(button => {
        button.addEventListener('click', function() {
          const item = {
            id: parseInt(this.dataset.id),
            name: this.dataset.name,
            price: parseFloat(this.dataset.price),
            image: this.dataset.image,
            category: this.dataset.category,
            quantity: 1
          };
          addToCart(item);
        });
      });
    });

    // Accessibility: Handle keyboard navigation for filter buttons
    document.querySelectorAll('.filter-btn').forEach(button => {
      button.addEventListener('keydown', function(event) {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          this.click();
        }
      });
    });
  </script>
</body>

</html>