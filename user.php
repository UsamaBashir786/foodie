<?php
session_start();
require_once 'vendor/autoload.php'; // Include Composer autoloader for Dompdf
use Dompdf\Dompdf;
use Dompdf\Options;

include 'config/db.php';
if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

// Set timezone to PKT
date_default_timezone_set('Asia/Karachi');

// Database connection
try {
  $conn = new PDO("mysql:host=localhost;dbname=foodiehub", "root", "");
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  die("Connection failed: " . htmlspecialchars($e->getMessage()));
}

// User info
$user_id = $_SESSION['user_id'];
$user_info = [];
try {
  $stmt = $conn->prepare("SELECT first_name, email FROM users WHERE id = :user_id");
  $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
  $stmt->execute();
  $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
  $username = $user_info['first_name'] ?? "User";
  $email = $user_info['email'] ?? "";
} catch (PDOException $e) {
  error_log("Error fetching user info: " . $e->getMessage());
  $username = "User";
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
  try {
    $new_first_name = trim(htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8'));
    $new_email = trim(htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8'));
    $new_password = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;

    if (empty($new_first_name) || empty($new_email)) {
      throw new Exception("First name and email are required.");
    }
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
      throw new Exception("Invalid email format.");
    }

    $sql = "UPDATE users SET first_name = :first_name, email = :email" . ($new_password ? ", password = :password" : "") . " WHERE id = :user_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':first_name', $new_first_name, PDO::PARAM_STR);
    $stmt->bindParam(':email', $new_email, PDO::PARAM_STR);
    if ($new_password) {
      $stmt->bindParam(':password', $new_password, PDO::PARAM_STR);
    }
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();

    $user_info['first_name'] = $new_first_name;
    $user_info['email'] = $new_email;
    $username = $new_first_name;
    $_SESSION['success_message'] = "Profile updated successfully!";
  } catch (Exception $e) {
    error_log("Error updating profile: " . $e->getMessage());
    $_SESSION['error_message'] = $e->getMessage();
  }
}

// Handle subscription activation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscribe'])) {
  try {
    $subscription_id = (int)$_POST['subscription_id'];
    $start_date = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("SELECT validity_period, validity_unit, price FROM subscriptions WHERE id = :subscription_id AND status = 'active'");
    $stmt->bindParam(':subscription_id', $subscription_id, PDO::PARAM_INT);
    $stmt->execute();
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$subscription) {
      throw new Exception("Invalid or inactive subscription.");
    }

    $validity_period = $subscription['validity_period'];
    $validity_unit = $subscription['validity_unit'];
    $end_date = match ($validity_unit) {
      'Days' => date('Y-m-d H:i:s', strtotime("+$validity_period days", strtotime($start_date))),
      'Weeks' => date('Y-m-d H:i:s', strtotime("+$validity_period weeks", strtotime($start_date))),
      'Months' => date('Y-m-d H:i:s', strtotime("+$validity_period months", strtotime($start_date))),
      default => throw new Exception("Invalid validity unit.")
    };

    $stmt = $conn->prepare("DELETE FROM user_subscriptions WHERE user_id = :user_id AND status = 'active'");
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();

    $stmt = $conn->prepare("INSERT INTO user_subscriptions (user_id, subscription_id, start_date, end_date, status, created_at) VALUES (:user_id, :subscription_id, :start_date, :end_date, 'active', NOW())");
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':subscription_id', $subscription_id, PDO::PARAM_INT);
    $stmt->bindParam(':start_date', $start_date, PDO::PARAM_STR);
    $stmt->bindParam(':end_date', $end_date, PDO::PARAM_STR);
    $stmt->execute();

    $amount = $subscription['price'];
    $stmt = $conn->prepare("INSERT INTO payments (user_id, subscription_id, amount, status, created_at) VALUES (:user_id, :subscription_id, :amount, 'paid', NOW())");
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':subscription_id', $subscription_id, PDO::PARAM_INT);
    $stmt->bindParam(':amount', $amount, PDO::PARAM_STR);
    $stmt->execute();

    $_SESSION['success_message'] = "Successfully subscribed to the plan!";
  } catch (Exception $e) {
    error_log("Error subscribing: " . $e->getMessage());
    $_SESSION['error_message'] = $e->getMessage();
  }
}

// Handle subscription cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_subscription'])) {
  try {
    $user_subscription_id = (int)$_POST['user_subscription_id'];
    $stmt = $conn->prepare("DELETE FROM user_subscriptions WHERE id = :id AND user_id = :user_id");
    $stmt->bindParam(':id', $user_subscription_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $_SESSION['success_message'] = "Subscription cancelled successfully.";
  } catch (PDOException $e) {
    error_log("Error cancelling subscription: " . $e->getMessage());
    $_SESSION['error_message'] = "Error cancelling subscription.";
  }
}

// Handle reservation cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_reservation'])) {
  try {
    $reservation_id = (int)$_POST['reservation_id'];
    $stmt = $conn->prepare("UPDATE subscription_reservations SET status = 'Cancelled' WHERE id = :id AND user_id = :user_id AND status = 'Pending'");
    $stmt->bindParam(':id', $reservation_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
      $stmt = $conn->prepare("UPDATE reservation_slots SET status = 'available', updated_at = NOW() WHERE id = (SELECT slot_id FROM subscription_reservations WHERE id = :reservation_id)");
      $stmt->bindParam(':reservation_id', $reservation_id, PDO::PARAM_INT);
      $stmt->execute();
      $_SESSION['success_message'] = "Reservation cancelled successfully.";
    } else {
      throw new Exception("Reservation cannot be cancelled.");
    }
  } catch (Exception $e) {
    error_log("Error cancelling reservation: " . $e->getMessage());
    $_SESSION['error_message'] = $e->getMessage();
  }
}

// Handle slot editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_slot'])) {
  try {
    $reservation_id = (int)$_POST['reservation_id'];
    $new_slot_id = (int)$_POST['slot_id'];
    $random_slot = isset($_POST['random_slot']) && $_POST['random_slot'] === '1';

    $stmt = $conn->prepare("SELECT sr.slot_id, sr.headcount, sr.vendor_id, s.min_headcount, s.max_headcount, s.dish_limit 
                            FROM subscription_reservations sr 
                            JOIN subscriptions s ON sr.subscription_id = s.id 
                            WHERE sr.id = :reservation_id AND sr.user_id = :user_id AND sr.status = 'Pending'");
    $stmt->bindParam(':reservation_id', $reservation_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reservation) {
      throw new Exception("Invalid or non-editable reservation.");
    }

    $stmt = $conn->prepare("SELECT SUM(quantity) as total_dishes FROM reservation_items WHERE reservation_id = :reservation_id");
    $stmt->bindParam(':reservation_id', $reservation_id, PDO::PARAM_INT);
    $stmt->execute();
    $total_dishes = (int)$stmt->fetchColumn();
    if ($total_dishes > $reservation['dish_limit']) {
      throw new Exception("Total dishes exceed subscription dish limit of {$reservation['dish_limit']}.");
    }

    if ($random_slot) {
      $stmt = $conn->prepare("SELECT id FROM reservation_slots 
                              WHERE vendor_id = :vendor_id AND status = 'available' 
                              AND slot_date >= CURDATE() AND capacity >= :headcount 
                              AND id NOT IN (SELECT slot_id FROM subscription_reservations WHERE slot_id IS NOT NULL)
                              ORDER BY RAND() LIMIT 1");
      $stmt->bindParam(':vendor_id', $reservation['vendor_id'], PDO::PARAM_INT);
      $stmt->bindParam(':headcount', $reservation['headcount'], PDO::PARAM_INT);
      $stmt->execute();
      $slot = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$slot) {
        throw new Exception("No available slots found for random selection.");
      }
      $new_slot_id = (int)$slot['id'];
    }

    $stmt = $conn->prepare("SELECT slot_date, slot_time, capacity FROM reservation_slots 
                            WHERE id = :slot_id AND vendor_id = :vendor_id AND status = 'available' 
                            AND capacity >= :headcount");
    $stmt->bindParam(':slot_id', $new_slot_id, PDO::PARAM_INT);
    $stmt->bindParam(':vendor_id', $reservation['vendor_id'], PDO::PARAM_INT);
    $stmt->bindParam(':headcount', $reservation['headcount'], PDO::PARAM_INT);
    $stmt->execute();
    $new_slot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$new_slot) {
      throw new Exception("Selected slot is not available or does not meet headcount requirements.");
    }

    $conn->beginTransaction();

    $stmt = $conn->prepare("UPDATE reservation_slots SET status = 'available', updated_at = NOW() 
                            WHERE id = :old_slot_id");
    $stmt->bindParam(':old_slot_id', $reservation['slot_id'], PDO::PARAM_INT);
    $stmt->execute();

    $stmt = $conn->prepare("UPDATE subscription_reservations 
                            SET slot_id = :slot_id, reservation_date = :reservation_date, meal_time = :meal_time, updated_at = NOW() 
                            WHERE id = :reservation_id AND user_id = :user_id");
    $stmt->bindParam(':slot_id', $new_slot_id, PDO::PARAM_INT);
    $stmt->bindParam(':reservation_date', $new_slot['slot_date'], PDO::PARAM_STR);
    $stmt->bindParam(':meal_time', $new_slot['slot_time'], PDO::PARAM_STR);
    $stmt->bindParam(':reservation_id', $reservation_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    if (!$stmt->execute()) {
      throw new Exception("Failed to update reservation.");
    }

    $stmt = $conn->prepare("UPDATE reservation_slots SET status = 'reserved', updated_at = NOW() 
                            WHERE id = :slot_id AND status = 'available'");
    $stmt->bindParam(':slot_id', $new_slot_id, PDO::PARAM_INT);
    if (!$stmt->execute() || $stmt->rowCount() === 0) {
      throw new Exception("Selected slot was already reserved.");
    }

    $conn->commit();
    $_SESSION['success_message'] = "Reservation slot updated successfully.";
  } catch (Exception $e) {
    $conn->rollBack();
    error_log("Error editing slot: " . $e->getMessage());
    $_SESSION['error_message'] = $e->getMessage();
  }
}

// Handle receipt generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_receipt'])) {
  try {
    $reservation_id = (int)$_POST['reservation_id'];
    $stmt = $conn->prepare("
      SELECT sr.id AS reservation_id, sr.meal_time, sr.reservation_date, sr.headcount, sr.status, 
             v.restaurant_name AS vendor_name, s.plan_type, s.custom_plan, s.custom_plan_name,
             GROUP_CONCAT(CONCAT(mi.name, ' (x', ri.quantity, ')') SEPARATOR ', ') AS items
      FROM subscription_reservations sr
      JOIN vendors v ON sr.vendor_id = v.id
      JOIN reservation_items ri ON sr.id = ri.reservation_id
      JOIN menu_items mi ON ri.menu_item_id = mi.id
      LEFT JOIN subscriptions s ON sr.subscription_id = s.id
      WHERE sr.id = :reservation_id AND sr.user_id = :user_id
      GROUP BY sr.id
    ");
    $stmt->bindParam(':reservation_id', $reservation_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reservation) {
      throw new Exception("Reservation not found.");
    }

    // HTML template for receipt
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Reservation Receipt</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .container { max-width: 800px; margin: 0 auto; }
            .header { text-align: center; border-bottom: 2px solid #ff6b35; padding-bottom: 10px; }
            .header h1 { color: #ff6b35; }
            .details-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            .details-table th, .details-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            .details-table th { background-color: #ff6b35; color: white; }
            .footer { text-align: center; margin-top: 20px; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>FoodieHub Reservation Receipt</h1>
                <p>Issued to: ' . htmlspecialchars($username) . '</p>
                <p>Email: ' . htmlspecialchars($email) . '</p>
                <p>Issued on: ' . date('d M Y') . '</p>
            </div>
            <table class="details-table">
                <tr><th>Reservation ID</th><td>' . htmlspecialchars($reservation['reservation_id']) . '</td></tr>
                <tr><th>Vendor</th><td>' . htmlspecialchars($reservation['vendor_name']) . '</td></tr>
                <tr><th>Plan</th><td>' . htmlspecialchars($reservation['custom_plan'] ? $reservation['custom_plan_name'] : $reservation['plan_type']) . '</td></tr>
                <tr><th>Meal Time</th><td>' . htmlspecialchars($reservation['meal_time']) . '</td></tr>
                <tr><th>Reservation Date</th><td>' . date('d M Y', strtotime($reservation['reservation_date'])) . '</td></tr>
                <tr><th>Headcount</th><td>' . htmlspecialchars($reservation['headcount']) . '</td></tr>
                <tr><th>Status</th><td>' . htmlspecialchars($reservation['status']) . '</td></tr>
                <tr><th>Items</th><td>' . htmlspecialchars($reservation['items']) . '</td></tr>
            </table>
            <div class="footer">
                <p>Thank you for using FoodieHub! For inquiries, contact support@foodiehub.com.</p>
            </div>
        </div>
    </body>
    </html>';

    // Initialize Dompdf
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Output PDF
    $dompdf->stream("reservation_receipt_{$reservation_id}.pdf", ['Attachment' => true]);
    exit;
  } catch (Exception $e) {
    error_log("Error generating receipt: " . $e->getMessage());
    $_SESSION['error_message'] = "Failed to generate receipt: " . $e->getMessage();
  }
}

// Fetch available subscriptions
$subscriptions = [];
try {
  $stmt = $conn->prepare("SELECT * FROM subscriptions WHERE status = 'active'");
  $stmt->execute();
  $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log("Error fetching subscriptions: " . $e->getMessage());
}

// Fetch user's active subscriptions
$user_subscriptions = [];
try {
  $stmt = $conn->prepare("SELECT us.*, s.plan_type, s.custom_plan, s.custom_plan_name, s.price, s.validity_period, s.validity_unit, s.discount_percentage, s.meal_times, s.dish_limit, s.dietary_preferences, s.description FROM user_subscriptions us JOIN subscriptions s ON us.subscription_id = s.id WHERE us.user_id = :user_id AND us.status = 'active'");
  $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
  $stmt->execute();
  $user_subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log("Error fetching user subscriptions: " . $e->getMessage());
}

// Fetch order history
$orders = [];
try {
  $stmt = $conn->prepare("SELECT o.*, v.restaurant_name AS vendor_name FROM orders o JOIN vendors v ON o.vendor_id = v.id WHERE o.user_id = :user_id ORDER BY o.created_at DESC LIMIT 10");
  $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
  $stmt->execute();
  $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log("Error fetching orders: " . $e->getMessage());
}

// Fetch payment history
$payments = [];
try {
  $stmt = $conn->prepare("SELECT p.*, s.plan_type, s.custom_plan, s.custom_plan_name FROM payments p JOIN subscriptions s ON p.subscription_id = s.id WHERE p.user_id = :user_id ORDER BY p.created_at DESC LIMIT 10");
  $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
  $stmt->execute();
  $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log("Error fetching payments: " . $e->getMessage());
}

// Fetch reserved meals
$reserved_meals = [];
try {
  $stmt = $conn->prepare("
        SELECT sr.id AS reservation_id, sr.meal_time, sr.reservation_date, sr.headcount, sr.status, sr.vendor_id, sr.slot_id,
               v.restaurant_name AS vendor_name, s.plan_type, s.custom_plan, s.custom_plan_name,
               GROUP_CONCAT(CONCAT(mi.name, ' (x', ri.quantity, ')')) AS items
        FROM subscription_reservations sr
        JOIN vendors v ON sr.vendor_id = v.id
        JOIN reservation_items ri ON sr.id = ri.reservation_id
        JOIN menu_items mi ON ri.menu_item_id = mi.id
        LEFT JOIN subscriptions s ON sr.subscription_id = s.id
        WHERE sr.user_id = :user_id
        GROUP BY sr.id
        ORDER BY sr.reservation_date DESC
        LIMIT 10
    ");
  $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
  $stmt->execute();
  $reserved_meals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log("Error fetching reserved meals: " . $e->getMessage());
}

// Fetch available slots for each reservation
$available_slots = [];
foreach ($reserved_meals as $reservation) {
  try {
    $stmt = $conn->prepare("
      SELECT id, slot_date, slot_time, capacity 
      FROM reservation_slots 
      WHERE vendor_id = :vendor_id AND status = 'available' 
      AND slot_date >= CURDATE() AND capacity >= :headcount 
      AND id NOT IN (SELECT slot_id FROM subscription_reservations WHERE slot_id IS NOT NULL AND id != :reservation_id)
      ORDER BY slot_date ASC, slot_time ASC
    ");
    $stmt->bindParam(':vendor_id', $reservation['vendor_id'], PDO::PARAM_INT);
    $stmt->bindParam(':headcount', $reservation['headcount'], PDO::PARAM_INT);
    $stmt->bindParam(':reservation_id', $reservation['reservation_id'], PDO::PARAM_INT);
    $stmt->execute();
    $available_slots[$reservation['reservation_id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    error_log("Error fetching available slots for reservation {$reservation['reservation_id']}: " . $e->getMessage());
    $available_slots[$reservation['reservation_id']] = [];
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Dashboard - FoodieHub</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
  <style>
    :root {
      --primary-color: #ff6b35;
      --secondary-color: #ffa726;
      --accent-color: #4caf50;
      --dark-color: #2c3e50;
      --light-color: #ecf0f1;
      --success-color: #27ae60;
      --danger-color: #e74c3c;
      --white: #ffffff;
      --gray-100: #f8f9fa;
      --gray-200: #e9ecef;
      --border-radius: 12px;
      --shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
      --shadow-hover: 0 15px 35px rgba(0, 0, 0, 0.15);
      --transition: all 0.3s ease;
    }

    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background: var(--gray-100);
      color: var(--dark-color);
    }

    .navbar {
      background: var(--white);
      box-shadow: var(--shadow);
      padding: 1rem;
    }

    .navbar-brand {
      font-weight: 700;
      color: var(--primary-color) !important;
    }

    .nav-link {
      color: var(--dark-color) !important;
      transition: var(--transition);
    }

    .nav-link:hover,
    .nav-link.active {
      color: var(--primary-color) !important;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      border: none;
      border-radius: 25px;
      transition: var(--transition);
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }

    .btn-danger {
      background: var(--danger-color);
      border: none;
      border-radius: 25px;
      transition: var(--transition);
    }

    .btn-danger:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }

    .btn-info {
      background: #3498db;
      border: none;
      border-radius: 25px;
      transition: var(--transition);
    }

    .btn-info:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }

    .section {
      padding: 3rem 0;
    }

    .welcome-section {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      color: var(--white);
      text-align: center;
      padding: 2rem;
      border-radius: var(--border-radius);
      box-shadow: var(--shadow);
    }

    .card {
      border: none;
      border-radius: var(--border-radius);
      box-shadow: var(--shadow);
      transition: var(--transition);
      overflow: hidden;
    }

    .card:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-hover);
    }

    .card-header {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      color: var(--white);
      font-weight: bold;
      border-bottom: none;
    }

    .card-body {
      padding: 1.5rem;
    }

    .subscription-card .card-body {
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      height: 100%;
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

    .tab-content {
      background: var(--white);
      padding: 2rem;
      border-radius: var(--border-radius);
      box-shadow: var(--shadow);
    }

    .fade-in {
      opacity: 0;
      transform: translateY(30px);
      transition: var(--transition);
    }

    .fade-in.visible {
      opacity: 1;
      transform: translateY(0);
    }

    .alert {
      border-radius: var(--border-radius);
    }

    .table {
      border-radius: var(--border-radius);
      overflow: hidden;
    }

    .table thead {
      background: var(--primary-color);
      color: var(--white);
    }

    footer {
      background: var(--dark-color);
      color: var(--light-color);
      padding: 2rem 0;
      text-align: center;
    }

    @media (max-width: 768px) {
      .welcome-section {
        padding: 2rem 1rem;
      }

      .card-body {
        padding: 1rem;
      }

      .nav-tabs .nav-link {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
      }

      .table-responsive {
        font-size: 0.9rem;
      }
    }
  </style>
</head>

<body>
  <!-- Navbar -->
  <nav class="navbar navbar-expand-lg">
    <div class="container">
      <a class="navbar-brand" href="index.php">FoodieHub</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav ms-auto">
          <li class="nav-item">
            <a class="nav-link active" href="user.php">Dashboard</a>
          </li>
          <li class="nav-item">
            <a class="nav-link active" href="index.php">Home</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="logout.php">Logout</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

  <!-- Welcome Section -->
  <section class="welcome-section section">
    <div class="container">
      <h1 class="display-4">Welcome, <?php echo htmlspecialchars($username); ?>!</h1>
      <p class="lead">Manage your subscriptions, orders, profile, and more from your FoodieHub dashboard.</p>
    </div>
  </section>

  <!-- Messages -->
  <?php if (isset($_SESSION['success_message'])): ?>
    <div class="container">
      <div class="alert alert-success fade-in" id="successAlert" data-message="<?php echo htmlspecialchars($_SESSION['success_message']); ?>">
        <?php echo htmlspecialchars($_SESSION['success_message']); ?>
      </div>
    </div>
    <?php unset($_SESSION['success_message']); ?>
  <?php endif; ?>
  <?php if (isset($_SESSION['error_message'])): ?>
    <div class="container">
      <div class="alert alert-danger fade-in" id="errorAlert" data-message="<?php echo htmlspecialchars($_SESSION['error_message']); ?>">
        <?php echo htmlspecialchars($_SESSION['error_message']); ?>
      </div>
    </div>
    <?php unset($_SESSION['error_message']); ?>
  <?php endif; ?>

  <!-- Dashboard Tabs -->
  <section class="section">
    <div class="container">
      <ul class="nav nav-tabs" id="dashboardTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="subscriptions-tab" data-bs-toggle="tab" data-bs-target="#subscriptions" type="button" role="tab">Subscriptions</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="my-subscriptions-tab" data-bs-toggle="tab" data-bs-target="#my-subscriptions" type="button" role="tab">My Subscriptions</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="orders-tab" data-bs-toggle="tab" data-bs-target="#orders" type="button" role="tab">Orders</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments" type="button" role="tab">Payments</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab">Profile</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="reserved-tab" data-bs-toggle="tab" data-bs-target="#reserved" type="button" role="tab">Reserved Meals</button>
        </li>
      </ul>
      <div class="tab-content" id="dashboardTabContent">
        <!-- Subscriptions Tab -->
        <div class="tab-pane fade show active" id="subscriptions" role="tabpanel">
          <h2 class="mb-4">Available Subscription Plans</h2>
          <div class="row g-4">
            <?php if (empty($subscriptions)): ?>
              <div class="col-12 text-center">
                <p class="text-muted">No subscription plans available at the moment.</p>
              </div>
            <?php else: ?>
              <?php foreach ($subscriptions as $subscription): ?>
                <div class="col-md-4">
                  <div class="card subscription-card fade-in">
                    <div class="card-header text-center">
                      <?php echo htmlspecialchars($subscription['custom_plan'] ? $subscription['custom_plan_name'] : $subscription['plan_type']); ?> Plan
                      <?php if ($subscription['per_head']): ?> (Per Head) <?php endif; ?>
                    </div>
                    <div class="card-body">
                      <ul class="list-unstyled">
                        <?php if ($subscription['per_head']): ?>
                          <li><strong>Headcount:</strong> <?php echo htmlspecialchars($subscription['min_headcount']) . ' - ' . htmlspecialchars($subscription['max_headcount']); ?></li>
                        <?php endif; ?>
                        <li><strong>Dishes:</strong> <?php echo htmlspecialchars($subscription['dish_limit']); ?> per meal</li>
                        <li><strong>Meal Times:</strong> <?php echo htmlspecialchars($subscription['meal_times']); ?></li>
                        <li><strong>Price:</strong> PKR <?php echo number_format($subscription['price'], 2); ?><?php echo $subscription['per_head'] ? ' per head' : ''; ?></li>
                        <li><strong>Validity:</strong> <?php echo htmlspecialchars($subscription['validity_period']) . ' ' . htmlspecialchars($subscription['validity_unit']); ?></li>
                        <li><strong>Discount:</strong> <?php echo number_format($subscription['discount_percentage'], 2); ?>%</li>
                        <li><strong>Delivery Fee (Non-Subscribers):</strong> PKR <?php echo number_format($subscription['non_subscriber_delivery_fee'], 2); ?></li>
                        <li><strong>Dietary Preferences:</strong> <?php echo htmlspecialchars($subscription['dietary_preferences'] ?: 'None'); ?></li>
                        <li><strong>Delivery Frequency:</strong> <?php echo htmlspecialchars($subscription['delivery_frequency']); ?></li>
                        <li><strong>Description:</strong> <?php echo htmlspecialchars($subscription['description'] ?: 'No description'); ?></li>
                      </ul>
                      <form method="POST" class="mt-auto">
                        <input type="hidden" name="subscription_id" value="<?php echo $subscription['id']; ?>">
                        <button type="submit" name="subscribe" class="btn btn-primary w-100">Subscribe Now</button>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- My Subscriptions Tab -->
        <div class="tab-pane fade" id="my-subscriptions" role="tabpanel">
          <h2 class="mb-4">My Active Subscriptions</h2>
          <div class="row g-4">
            <?php if (empty($user_subscriptions)): ?>
              <div class="col-12 text-center">
                <p class="text-muted">You have no active subscriptions.</p>
              </div>
            <?php else: ?>
              <?php foreach ($user_subscriptions as $subscription): ?>
                <div class="col-md-6">
                  <div class="card fade-in">
                    <div class="card-header">
                      <?php echo htmlspecialchars($subscription['custom_plan'] ? $subscription['custom_plan_name'] : $subscription['plan_type']); ?> Plan
                    </div>
                    <div class="card-body">
                      <p><strong>Price:</strong> PKR <?php echo number_format($subscription['price'], 2); ?></p>
                      <p><strong>Discount:</strong> <?php echo number_format($subscription['discount_percentage'], 2); ?>%</p>
                      <p><strong>Dishes:</strong> <?php echo htmlspecialchars($subscription['dish_limit']); ?> per meal</p>
                      <p><strong>Meal Times:</strong> <?php echo htmlspecialchars($subscription['meal_times']); ?></p>
                      <p><strong>Dietary Preferences:</strong> <?php echo htmlspecialchars($subscription['dietary_preferences'] ?: 'None'); ?></p>
                      <p><strong>Description:</strong> <?php echo htmlspecialchars($subscription['description'] ?: 'No description'); ?></p>
                      <p><strong>Start Date:</strong> <?php echo date('d M Y', strtotime($subscription['start_date'])); ?></p>
                      <p><strong>End Date:</strong> <?php echo date('d M Y', strtotime($subscription['end_date'])); ?></p>
                      <p><strong>Status:</strong> <?php echo ucfirst($subscription['status']); ?></p>
                      <form method="POST" class="mt-3">
                        <input type="hidden" name="user_subscription_id" value="<?php echo $subscription['id']; ?>">
                        <button type="submit" name="cancel_subscription" class="btn btn-danger">Cancel Subscription</button>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Orders Tab -->
        <div class="tab-pane fade" id="orders" role="tabpanel">
          <h2 class="mb-4">Order History</h2>
          <?php if (empty($orders)): ?>
            <p class="text-muted">No orders found.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover">
                <thead>
                  <tr>
                    <th>Order ID</th>
                    <th>Vendor</th>
                    <th>Total (PKR)</th>
                    <th>Status</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($orders as $order): ?>
                    <tr class="fade-in">
                      <td><?php echo htmlspecialchars($order['id']); ?></td>
                      <td><?php echo htmlspecialchars($order['vendor_name']); ?></td>
                      <td><?php echo number_format($order['total'], 2); ?></td>
                      <td><?php echo ucfirst(htmlspecialchars($order['status'])); ?></td>
                      <td><?php echo date('d M Y', strtotime($order['created_at'])); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <!-- Payments Tab -->
        <div class="tab-pane fade" id="payments" role="tabpanel">
          <h2 class="mb-4">Payment History</h2>
          <?php if (empty($payments)): ?>
            <p class="text-muted">No payments found.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover">
                <thead>
                  <tr>
                    <th>Payment ID</th>
                    <th>Plan</th>
                    <th>Amount (PKR)</th>
                    <th>Status</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($payments as $payment): ?>
                    <tr class="fade-in">
                      <td><?php echo htmlspecialchars($payment['id']); ?></td>
                      <td><?php echo htmlspecialchars($payment['custom_plan'] ? $payment['custom_plan_name'] : $payment['plan_type']); ?></td>
                      <td><?php echo number_format($payment['amount'], 2); ?></td>
                      <td><?php echo ucfirst(htmlspecialchars($payment['status'])); ?></td>
                      <td><?php echo date('d M Y', strtotime($payment['created_at'])); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <!-- Profile Tab -->
        <div class="tab-pane fade" id="profile" role="tabpanel">
          <h2 class="mb-4">Manage Profile</h2>
          <a href="edit_profile.php?user_id=<?php echo $user_id; ?>">manage full profile</a>
          <form method="POST" class="fade-in">
            <div class="mb-3">
              <label for="username" class="form-label">First Name</label>
              <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user_info['first_name']); ?>" required>
            </div>
            <div class="mb-3">
              <label for="email" class="form-label">Email</label>
              <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user_info['email']); ?>" required>
            </div>
            <div class="mb-3">
              <label for="password" class="form-label">New Password (leave blank to keep current)</label>
              <input type="password" class="form-control" id="password" name="password" placeholder="Enter new password">
            </div>
            <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
          </form>
        </div>

        <!-- Reserved Meals Tab -->
        <div class="tab-pane fade" id="reserved" role="tabpanel">
          <h2 class="mb-4">Reserved Meals</h2>
          <?php if (empty($reserved_meals)): ?>
            <p class="text-muted">No reserved meals found.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover">
                <thead>
                  <tr>
                    <th>Reservation ID</th>
                    <!-- <th>Plan</th> -->
                    <th>Vendor</th>
                    <th>Meal Time</th>
                    <th>Reservation Date</th>
                    <th>Headcount</th>
                    <th>Status</th>
                    <th>Items</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($reserved_meals as $reservation): ?>
                    <tr class="fade-in">
                      <td><?php echo htmlspecialchars($reservation['reservation_id']); ?></td>
                      <td><?php echo htmlspecialchars($reservation['vendor_name']); ?></td>
                      <td><?php echo htmlspecialchars($reservation['meal_time']); ?></td>
                      <td><?php echo date('d M Y', strtotime($reservation['reservation_date'])); ?></td>
                      <td><?php echo htmlspecialchars($reservation['headcount']); ?></td>
                      <td><?php echo ucfirst(htmlspecialchars($reservation['status'])); ?></td>
                      <td><?php echo htmlspecialchars($reservation['items']); ?></td>
                      <td>
                        <?php if ($reservation['status'] === 'Pending'): ?>
                          <button class="btn btn-info btn-sm edit-slot-btn"
                            data-reservation-id="<?php echo $reservation['reservation_id']; ?>"
                            data-vendor-id="<?php echo $reservation['vendor_id']; ?>"
                            data-headcount="<?php echo $reservation['headcount']; ?>"
                            data-bs-toggle="modal"
                            data-bs-target="#editSlotModal">
                            Edit Slot
                          </button>
                          <form method="POST" style="display:inline;">
                            <input type="hidden" name="reservation_id" value="<?php echo $reservation['reservation_id']; ?>">
                            <!-- <button type="submit" name="cancel_reservation" class="btn btn-danger btn-sm">Cancel</button> -->
                          </form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="reservation_id" value="<?php echo $reservation['reservation_id']; ?>">
                          <button type="submit" name="generate_receipt" class="btn btn-primary btn-sm">Download Receipt</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- Edit Slot Modal -->
  <div class="modal fade" id="editSlotModal" tabindex="-1" aria-labelledby="editSlotModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editSlotModalLabel">Edit Reservation Slot</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST">
          <div class="modal-body">
            <input type="hidden" name="reservation_id" id="editReservationId">
            <div class="mb-3">
              <label for="slotSelect" class="form-label">Select New Slot</label>
              <select class="form-control" id="slotSelect" name="slot_id" required>
                <option value="">Choose a slot</option>
              </select>
            </div>
            <button type="button" class="btn btn-primary" id="randomSlotBtn">Select Random Slot</button>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" name="edit_slot" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <footer>
    <div class="container">
      <p>© <?php echo date('Y'); ?> FoodieHub. All rights reserved.</p>
    </div>
  </footer>

  <!-- Bootstrap JS -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
  <!-- SweetAlert2 JS -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
          }
        });
      }, {
        threshold: 0.1
      });

      document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

      const hash = window.location.hash;
      if (hash) {
        const tab = document.querySelector(`[data-bs-target="${hash}"]`);
        if (tab) {
          const tabInstance = new bootstrap.Tab(tab);
          tabInstance.show();
        }
      }

      const successAlert = document.querySelector('#successAlert');
      const errorAlert = document.querySelector('#errorAlert');
      if (successAlert) {
        Swal.fire({
          icon: 'success',
          title: 'Success',
          text: successAlert.dataset.message,
          timer: 3000,
          showConfirmButton: false
        });
      }
      if (errorAlert) {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: errorAlert.dataset.message,
          confirmButtonText: 'OK'
        });
      }

      document.querySelectorAll('button[name="cancel_subscription"], button[name="cancel_reservation"]').forEach(button => {
        button.addEventListener('click', function(e) {
          e.preventDefault();
          const isReservation = e.target.name === 'cancel_reservation';
          Swal.fire({
            title: 'Are you sure?',
            text: `Do you want to cancel this ${isReservation ? 'reservation' : 'subscription'}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ff6b35',
            cancelButtonColor: '#e74c3c',
            confirmButtonText: `Yes, cancel ${isReservation ? 'reservation' : 'subscription'}!`
          }).then((result) => {
            if (result.isConfirmed) {
              e.target.closest('form').submit();
            }
          });
        });
      });

      const editSlotModal = document.getElementById('editSlotModal');
      const slotSelect = document.getElementById('slotSelect');
      const editReservationId = document.getElementById('editReservationId');
      const randomSlotBtn = document.getElementById('randomSlotBtn');
      const availableSlots = <?php echo json_encode($available_slots, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

      document.querySelectorAll('.edit-slot-btn').forEach(button => {
        button.addEventListener('click', function() {
          const reservationId = this.dataset.reservationId;
          editReservationId.value = reservationId;
          const slots = availableSlots[reservationId] || [];
          slotSelect.innerHTML = '<option value="">Choose a slot</option>';
          slots.forEach(slot => {
            const option = document.createElement('option');
            option.value = slot.id;
            option.textContent = `${slot.slot_date} ${slot.slot_time} (Capacity: ${slot.capacity})`;
            slotSelect.appendChild(option);
          });
        });
      });

      randomSlotBtn.addEventListener('click', function() {
        const reservationId = editReservationId.value;
        const slots = availableSlots[reservationId] || [];
        if (slots.length > 0) {
          const randomIndex = Math.floor(Math.random() * slots.length);
          slotSelect.value = slots[randomIndex].id;
          Swal.fire({
            icon: 'success',
            title: 'Random Slot Selected',
            text: `Selected slot: ${slots[randomIndex].slot_date} ${slots[randomIndex].slot_time}`,
            timer: 1500,
            showConfirmButton: false
          });
        } else {
          Swal.fire({
            icon: 'error',
            title: 'No Slots Available',
            text: 'No available slots found for this reservation.',
            confirmButtonText: 'OK'
          });
        }
      });

      editSlotModal.addEventListener('submit', function(e) {
        const slotId = slotSelect.value;
        if (!slotId) {
          e.preventDefault();
          Swal.fire({
            icon: 'error',
            title: 'No Slot Selected',
            text: 'Please select a slot or choose a random one.',
            confirmButtonText: 'OK'
          });
        }
      });
    });
  </script>
</body>

</html>