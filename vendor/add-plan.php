<?php
session_name('vendor_session');
session_start();
if (!isset($_SESSION['vendor_id'])) {
  header("Location: vendor_login.php");
  exit();
}

// Set timezone to PKT
date_default_timezone_set('Asia/Karachi');

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "foodiehub";

try {
  $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  die("Connection failed: " . $e->getMessage());
}

// Initialize dashboard data variables
$total_orders = "N/A";
$revenue = "N/A";
$pending_orders = "N/A";
$total_subscriptions = "N/A";

try {
  $vendor_id = $_SESSION['vendor_id'];

  // Fetch total orders
  $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE vendor_id = :vendor_id");
  $stmt->bindParam(':vendor_id', $vendor_id);
  $stmt->execute();
  $result = $stmt->fetch(PDO::FETCH_ASSOC);
  $total_orders = ($result['total'] > 0) ? $result['total'] : "N/A";

  // Fetch revenue
  $stmt = $conn->prepare("SELECT SUM(total) AS total_revenue FROM orders WHERE vendor_id = :vendor_id");
  $stmt->bindParam(':vendor_id', $vendor_id);
  $stmt->execute();
  $result = $stmt->fetch(PDO::FETCH_ASSOC);
  $revenue = (!empty($result['total_revenue']) && $result['total_revenue'] > 0) ? "PKR " . number_format($result['total_revenue'], 2) : "0.00";

  // Fetch pending orders
  $stmt = $conn->prepare("SELECT COUNT(*) AS pending FROM orders WHERE vendor_id = :vendor_id AND status = 'pending'");
  $stmt->bindParam(':vendor_id', $vendor_id);
  $stmt->execute();
  $result = $stmt->fetch(PDO::FETCH_ASSOC);
  $pending_orders = ($result['pending'] > 0) ? $result['pending'] : "0";

  // Fetch total active subscriptions
  $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM subscriptions WHERE vendor_id = :vendor_id AND status = 'active'");
  $stmt->bindParam(':vendor_id', $vendor_id);
  $stmt->execute();
  $result = $stmt->fetch(PDO::FETCH_ASSOC);
  $total_subscriptions = ($result['total'] > 0) ? $result['total'] : "0";
} catch (PDOException $e) {
  error_log("Error fetching dashboard data: " . $e->getMessage());
  $total_orders = "N/A";
  $revenue = "N/A";
  $pending_orders = "N/A";
  $total_subscriptions = "N/A";
}

// Handle subscription creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_subscription'])) {
  try {
    $plan_type = $_POST['plan_type'];
    $custom_plan = isset($_POST['custom_plan']) ? 1 : 0;
    $custom_plan_name = $custom_plan ? trim(htmlspecialchars($_POST['custom_plan_name'], ENT_QUOTES, 'UTF-8')) : null;
    $per_head = isset($_POST['per_head']) ? 1 : 0;
    $min_headcount = $per_head ? (int)$_POST['min_headcount'] : null;
    $max_headcount = $per_head ? (int)$_POST['max_headcount'] : null;
    $dish_limit = (int)$_POST['dish_limit'];
    $meal_times = !empty($_POST['meal_times']) ? implode(',', $_POST['meal_times']) : '';
    $price = (float)$_POST['price'];
    $validity_period = (int)$_POST['validity_period'];
    $validity_unit = $_POST['validity_unit'];
    $dietary_preferences = !empty($_POST['dietary_preferences']) ? implode(',', $_POST['dietary_preferences']) : null;
    $delivery_frequency = $_POST['delivery_frequency'];
    $description = trim(htmlspecialchars($_POST['description'], ENT_QUOTES, 'UTF-8'));
    $non_subscriber_delivery_fee = (float)$_POST['non_subscriber_delivery_fee'];
    $status = 'active';

    // Validation
    if ($custom_plan && empty($custom_plan_name)) {
      throw new Exception("Custom plan name is required for custom plans.");
    }
    if ($per_head && ($min_headcount <= 0 || $max_headcount < $min_headcount)) {
      throw new Exception("Invalid headcount range for per head plan.");
    }
    if ($dish_limit <= 0 || $price <= 0 || $validity_period <= 0 || empty($meal_times)) {
      throw new Exception("Dish limit, price, validity period, and meal times are required.");
    }

    // Set discount percentage
    $discount_percentage = $custom_plan ? (float)$_POST['discount_percentage'] : match ($plan_type) {
      'Basic' => 10.00,
      'Standard' => 15.00,
      'Premium' => 20.00,
      default => 0.00
    };

    // Assign plan_type to a variable to avoid ternary in bindParam
    $effective_plan_type = $custom_plan ? 'Custom' : $plan_type;

    $stmt = $conn->prepare("
            INSERT INTO subscriptions (
                vendor_id, per_head, min_headcount, max_headcount, custom_plan, custom_plan_name,
                plan_type, dish_limit, meal_times, price, validity_period, validity_unit,
                discount_percentage, non_subscriber_delivery_fee, dietary_preferences,
                delivery_frequency, description, status, created_at
            ) VALUES (
                :vendor_id, :per_head, :min_headcount, :max_headcount, :custom_plan, :custom_plan_name,
                :plan_type, :dish_limit, :meal_times, :price, :validity_period, :validity_unit,
                :discount_percentage, :non_subscriber_delivery_fee, :dietary_preferences,
                :delivery_frequency, :description, :status, NOW()
            )
        ");
    $stmt->bindParam(':vendor_id', $vendor_id, PDO::PARAM_INT);
    $stmt->bindParam(':per_head', $per_head, PDO::PARAM_INT);
    $stmt->bindParam(':min_headcount', $min_headcount, PDO::PARAM_INT);
    $stmt->bindParam(':max_headcount', $max_headcount, PDO::PARAM_INT);
    $stmt->bindParam(':custom_plan', $custom_plan, PDO::PARAM_INT);
    $stmt->bindParam(':custom_plan_name', $custom_plan_name, PDO::PARAM_STR);
    $stmt->bindParam(':plan_type', $effective_plan_type, PDO::PARAM_STR); // Use variable instead of ternary
    $stmt->bindParam(':dish_limit', $dish_limit, PDO::PARAM_INT);
    $stmt->bindParam(':meal_times', $meal_times, PDO::PARAM_STR);
    $stmt->bindParam(':price', $price, PDO::PARAM_STR);
    $stmt->bindParam(':validity_period', $validity_period, PDO::PARAM_INT);
    $stmt->bindParam(':validity_unit', $validity_unit, PDO::PARAM_STR);
    $stmt->bindParam(':discount_percentage', $discount_percentage, PDO::PARAM_STR);
    $stmt->bindParam(':non_subscriber_delivery_fee', $non_subscriber_delivery_fee, PDO::PARAM_STR);
    $stmt->bindParam(':dietary_preferences', $dietary_preferences, PDO::PARAM_STR);
    $stmt->bindParam(':delivery_frequency', $delivery_frequency, PDO::PARAM_STR);
    $stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $stmt->bindParam(':status', $status, PDO::PARAM_STR);
    $stmt->execute();
  } catch (Exception $e) {
    error_log("Error creating subscription: " . $e->getMessage());
    $error_message = $e->getMessage();
  }
}
// Handle subscription update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_subscription'])) {
  try {
    $subscription_id = (int)$_POST['subscription_id'];
    $plan_type = $_POST['plan_type'];
    $custom_plan = isset($_POST['custom_plan']) ? 1 : 0;
    $custom_plan_name = $custom_plan ? trim(htmlspecialchars($_POST['custom_plan_name'], ENT_QUOTES, 'UTF-8')) : null;
    $per_head = isset($_POST['per_head']) ? 1 : 0;
    $min_headcount = $per_head ? (int)$_POST['min_headcount'] : null;
    $max_headcount = $per_head ? (int)$_POST['max_headcount'] : null;
    $dish_limit = (int)$_POST['dish_limit'];
    $meal_times = !empty($_POST['meal_times']) ? implode(',', $_POST['meal_times']) : '';
    $price = (float)$_POST['price'];
    $validity_period = (int)$_POST['validity_period'];
    $validity_unit = $_POST['validity_unit'];
    $dietary_preferences = !empty($_POST['dietary_preferences']) ? implode(',', $_POST['dietary_preferences']) : null;
    $delivery_frequency = $_POST['delivery_frequency'];
    $description = trim(htmlspecialchars($_POST['description'], ENT_QUOTES, 'UTF-8'));
    $non_subscriber_delivery_fee = (float)$_POST['non_subscriber_delivery_fee'];
    $status = $_POST['status'];

    // Validation
    if ($custom_plan && empty($custom_plan_name)) {
      throw new Exception("Custom plan name is required for custom plans.");
    }
    if ($per_head && ($min_headcount <= 0 || $max_headcount < $min_headcount)) {
      throw new Exception("Invalid headcount range for per head plan.");
    }
    if ($dish_limit <= 0 || $price <= 0 || $validity_period <= 0 || empty($meal_times)) {
      throw new Exception("Dish limit, price, validity period, and meal times are required.");
    }

    // Set discount percentage
    $discount_percentage = $custom_plan ? (float)$_POST['discount_percentage'] : match ($plan_type) {
      'Basic' => 10.00,
      'Standard' => 15.00,
      'Premium' => 20.00,
      default => 0.00
    };

    // Assign plan_type to a variable to avoid ternary in bindParam
    $effective_plan_type = $custom_plan ? 'Custom' : $plan_type;

    $stmt = $conn->prepare("
            UPDATE subscriptions 
            SET per_head = :per_head, 
                min_headcount = :min_headcount, 
                max_headcount = :max_headcount, 
                custom_plan = :custom_plan, 
                custom_plan_name = :custom_plan_name, 
                plan_type = :plan_type, 
                dish_limit = :dish_limit, 
                meal_times = :meal_times, 
                price = :price, 
                validity_period = :validity_period, 
                validity_unit = :validity_unit, 
                discount_percentage = :discount_percentage, 
                non_subscriber_delivery_fee = :non_subscriber_delivery_fee, 
                dietary_preferences = :dietary_preferences, 
                delivery_frequency = :delivery_frequency, 
                description = :description, 
                status = :status, 
                updated_at = NOW()
            WHERE id = :subscription_id AND vendor_id = :vendor_id
        ");
    $stmt->bindParam(':subscription_id', $subscription_id, PDO::PARAM_INT);
    $stmt->bindParam(':vendor_id', $vendor_id, PDO::PARAM_INT);
    $stmt->bindParam(':per_head', $per_head, PDO::PARAM_INT);
    $stmt->bindParam(':min_headcount', $min_headcount, PDO::PARAM_INT);
    $stmt->bindParam(':max_headcount', $max_headcount, PDO::PARAM_INT);
    $stmt->bindParam(':custom_plan', $custom_plan, PDO::PARAM_INT);
    $stmt->bindParam(':custom_plan_name', $custom_plan_name, PDO::PARAM_STR);
    $stmt->bindParam(':plan_type', $effective_plan_type, PDO::PARAM_STR); // Use variable instead of ternary
    $stmt->bindParam(':dish_limit', $dish_limit, PDO::PARAM_INT);
    $stmt->bindParam(':meal_times', $meal_times, PDO::PARAM_STR);
    $stmt->bindParam(':price', $price, PDO::PARAM_STR);
    $stmt->bindParam(':validity_period', $validity_period, PDO::PARAM_INT);
    $stmt->bindParam(':validity_unit', $validity_unit, PDO::PARAM_STR);
    $stmt->bindParam(':discount_percentage', $discount_percentage, PDO::PARAM_STR);
    $stmt->bindParam(':non_subscriber_delivery_fee', $non_subscriber_delivery_fee, PDO::PARAM_STR);
    $stmt->bindParam(':dietary_preferences', $dietary_preferences, PDO::PARAM_STR);
    $stmt->bindParam(':delivery_frequency', $delivery_frequency, PDO::PARAM_STR);
    $stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $stmt->bindParam(':status', $status, PDO::PARAM_STR);
    $stmt->execute();
  } catch (Exception $e) {
    error_log("Error updating subscription: " . $e->getMessage());
    $error_message = $e->getMessage();
  }
}

// Handle subscription deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_subscription'])) {
  try {
    $subscription_id = (int)$_POST['subscription_id'];
    $stmt = $conn->prepare("DELETE FROM subscriptions WHERE id = :subscription_id AND vendor_id = :vendor_id");
    $stmt->bindParam(':subscription_id', $subscription_id);
    $stmt->bindParam(':vendor_id', $vendor_id);
    $stmt->execute();
  } catch (PDOException $e) {
    error_log("Error deleting subscription: " . $e->getMessage());
    $error_message = "Error deleting subscription.";
  }
}

// Fetch subscription plans
$subscriptions = [];
try {
  $stmt = $conn->prepare("SELECT * FROM subscriptions WHERE vendor_id = :vendor_id");
  $stmt->bindParam(':vendor_id', $vendor_id);
  $stmt->execute();
  $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log("Error fetching subscriptions: " . $e->getMessage());
  $error = "";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Vendor Dashboard - FoodieHub</title>
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

    .btn-logout {
      background: linear-gradient(135deg, var(--danger-color), #c0392b);
      border: none;
      color: var(--white);
      padding: 0.5rem 1.5rem;
      border-radius: 25px;
      font-weight: 500;
      transition: var(--transition);
    }

    .btn-logout:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
      color: var(--white);
    }

    .sidebar {
      position: fixed;
      top: 76px;
      left: 0;
      width: 250px;
      height: calc(100vh - 76px);
      background: var(--white);
      box-shadow: var(--shadow);
      padding: 2rem;
      overflow-y: auto;
      transition: var(--transition);
    }

    .sidebar .nav-link {
      display: flex;
      align-items: center;
      padding: 0.75rem 1rem;
      margin-bottom: 0.5rem;
      border-radius: var(--border-radius);
      color: var(--dark-color);
      font-weight: 500;
    }

    .sidebar .nav-link:hover,
    .sidebar .nav-link.active {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      color: var(--white) !important;
    }

    .sidebar .nav-link i {
      margin-right: 0.75rem;
    }

    .main-content {
      margin-left: 250px;
      padding: 2rem;
      margin-top: 76px;
    }

    .dashboard-card {
      background: var(--white);
      border-radius: var(--border-radius);
      box-shadow: var(--shadow);
      padding: 1.5rem;
      transition: var(--transition);
      height: 100%;
    }

    .dashboard-card:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-hover);
    }

    .dashboard-card h5 {
      font-size: 1.25rem;
      font-weight: 700;
      color: var(--dark-color);
      margin-bottom: 1rem;
    }

    .subscription-item {
      background: var(--white);
      border-radius: var(--border-radius);
      padding: 1.5rem;
      margin-bottom: 1rem;
      box-shadow: var(--shadow);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .subscription-item-info {
      flex: 1;
    }

    .subscription-item-title {
      font-size: 1.1rem;
      font-weight: 600;
      margin-bottom: 0.5rem;
    }

    .subscription-item-details {
      color: var(--gray-800);
      font-size: 0.9rem;
    }

    .subscription-item-actions button {
      margin-left: 0.5rem;
    }

    .modal-content {
      border-radius: var(--border-radius);
      box-shadow: var(--shadow);
    }

    .modal-header {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      color: var(--white);
      border-radius: var(--border-radius) var(--border-radius) 0 0;
    }

    .modal-footer {
      border-top: none;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      border: none;
      border-radius: 25px;
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }

    .btn-danger {
      background: var(--danger-color);
      border: none;
      border-radius: 25px;
    }

    .btn-danger:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }

    .error-message {
      color: var(--danger-color);
      font-size: 0.9rem;
      margin-bottom: 1rem;
      text-align: center;
      display: <?php echo !empty($error_message) ? 'block' : 'none'; ?>;
    }

    @media (max-width: 992px) {
      .sidebar {
        width: 100%;
        left: -100%;
        top: 0;
        z-index: 1000;
      }

      .sidebar.active {
        left: 0;
      }

      .main-content {
        margin-left: 0;
      }

      .sidebar-toggle {
        display: block;
        position: fixed;
        top: 90px;
        left: 1rem;
        background: var(--primary-color);
        color: var(--white);
        border-radius: 50%;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1001;
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
  <?php include 'includes/sidebar.php'; ?>

  <!-- Sidebar Toggle for Mobile -->
  <button class="sidebar-toggle d-none" id="sidebarToggle">
    <i class="fas fa-bars"></i>
  </button>

  <!-- Main Content -->
  <div class="main-content">
    <!-- Subscription Management Section -->
    <section id="subscriptions" class="section mt-5">
      <div class="container">
        <div class="error-message"><?php echo htmlspecialchars($error_message ?? ''); ?></div>
        <div class="d-flex justify-content-between align-items-center mb-4">
          <h2>Manage Subscriptions</h2>
          <div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubscriptionModal">
              <i class="fas fa-plus"></i> Add Subscription
            </button>
            <button class="btn btn-primary">
              <i class="fas fa-plus"></i> <a href="vendor_subscriptions.php" class="text-decoration-none text-white">Check Subscribers</a>
            </button>
          </div>
        </div>
        <div class="row">
          <div class="col-12">
            <?php if (empty($subscriptions)): ?>
              <p class="text-center text-muted">No subscriptions found. Create one to get started.</p>
            <?php else: ?>
              <?php foreach ($subscriptions as $subscription): ?>
                <div class="subscription-item fade-in">
                  <div class="subscription-item-info">
                    <h5 class="subscription-item-title">
                      <?php echo htmlspecialchars($subscription['custom_plan'] ? $subscription['custom_plan_name'] : $subscription['plan_type']); ?> Plan
                      <?php if ($subscription['per_head']): ?>(Per Head)<?php endif; ?>
                    </h5>
                    <p class="subscription-item-details">
                      <?php if ($subscription['per_head']): ?>
                        Headcount: <?php echo htmlspecialchars($subscription['min_headcount']) . ' - ' . htmlspecialchars($subscription['max_headcount']); ?><br>
                      <?php endif; ?>
                      Dishes: <?php echo htmlspecialchars($subscription['dish_limit']); ?> per meal<br>
                      Meal Times: <?php echo htmlspecialchars($subscription['meal_times']); ?><br>
                      Price: PKR <?php echo number_format($subscription['price'], 2); ?><?php echo $subscription['per_head'] ? ' per head' : ''; ?><br>
                      Validity: <?php echo htmlspecialchars($subscription['validity_period']) . ' ' . htmlspecialchars($subscription['validity_unit']); ?><br>
                      Discount: <?php echo number_format($subscription['discount_percentage'], 2); ?>%<br>
                      Non-Subscriber Delivery Fee: PKR <?php echo number_format($subscription['non_subscriber_delivery_fee'], 2); ?><br>
                      Dietary Preferences: <?php echo htmlspecialchars($subscription['dietary_preferences'] ?: 'None'); ?><br>
                      Delivery Frequency: <?php echo htmlspecialchars($subscription['delivery_frequency']); ?><br>
                      Description: <?php echo htmlspecialchars($subscription['description'] ?: 'No description'); ?><br>
                      Status: <?php echo htmlspecialchars($subscription['status']); ?>
                    </p>
                  </div>
                  <div class="subscription-item-actions">
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editSubscriptionModal<?php echo $subscription['id']; ?>"><i class="fas fa-edit"></i> Edit</button>
                    <form method="POST" style="display: inline;">
                      <input type="hidden" name="subscription_id" value="<?php echo $subscription['id']; ?>">
                      <button type="submit" name="delete_subscription" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this subscription?');"><i class="fas fa-trash"></i> Delete</button>
                    </form>
                  </div>
                </div>

                <!-- Edit Subscription Modal -->
                <div class="modal fade" id="editSubscriptionModal<?php echo $subscription['id']; ?>" tabindex="-1" aria-labelledby="editSubscriptionModalLabel<?php echo $subscription['id']; ?>" aria-hidden="true">
                  <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title" id="editSubscriptionModalLabel<?php echo $subscription['id']; ?>">Edit Subscription</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <form method="POST">
                        <div class="modal-body">
                          <input type="hidden" name="subscription_id" value="<?php echo $subscription['id']; ?>">
                          <div class="mb-3">
                            <div class="form-check">
                              <input class="form-check-input" type="checkbox" id="custom_plan_<?php echo $subscription['id']; ?>" name="custom_plan" <?php echo $subscription['custom_plan'] ? 'checked' : ''; ?> onchange="toggleCustomFields('<?php echo $subscription['id']; ?>')">
                              <label class="form-check-label" for="custom_plan_<?php echo $subscription['id']; ?>">Custom Plan</label>
                            </div>
                          </div>
                          <div class="mb-3" id="custom_plan_name_field_<?php echo $subscription['id']; ?>" style="display: <?php echo $subscription['custom_plan'] ? 'block' : 'none'; ?>;">
                            <label for="custom_plan_name_<?php echo $subscription['id']; ?>" class="form-label">Custom Plan Name</label>
                            <input type="text" class="form-control" id="custom_plan_name_<?php echo $subscription['id']; ?>" name="custom_plan_name" value="<?php echo htmlspecialchars($subscription['custom_plan_name']); ?>" <?php echo $subscription['custom_plan'] ? 'required' : ''; ?>>
                          </div>
                          <div class="mb-3" id="plan_type_field_<?php echo $subscription['id']; ?>" style="display: <?php echo $subscription['custom_plan'] ? 'none' : 'block'; ?>;">
                            <label for="plan_type_<?php echo $subscription['id']; ?>" class="form-label">Plan Type</label>
                            <select class="form-select" id="plan_type_<?php echo $subscription['id']; ?>" name="plan_type" <?php echo $subscription['custom_plan'] ? '' : 'required'; ?>>
                              <option value="Basic" <?php echo $subscription['plan_type'] == 'Basic' ? 'selected' : ''; ?>>Basic</option>
                              <option value="Standard" <?php echo $subscription['plan_type'] == 'Standard' ? 'selected' : ''; ?>>Standard</option>
                              <option value="Premium" <?php echo $subscription['plan_type'] == 'Premium' ? 'selected' : ''; ?>>Premium</option>
                            </select>
                          </div>
                          <div class="mb-3" id="discount_percentage_field_<?php echo $subscription['id']; ?>" style="display: <?php echo $subscription['custom_plan'] ? 'block' : 'none'; ?>;">
                            <label for="discount_percentage_<?php echo $subscription['id']; ?>" class="form-label">Discount Percentage (%)</label>
                            <input type="number" step="0.01" class="form-control" id="discount_percentage_<?php echo $subscription['id']; ?>" name="discount_percentage" min="0" max="100" value="<?php echo htmlspecialchars($subscription['discount_percentage']); ?>" <?php echo $subscription['custom_plan'] ? 'required' : ''; ?>>
                          </div>
                          <div class="mb-3">
                            <div class="form-check">
                              <input class="form-check-input" type="checkbox" id="per_head_<?php echo $subscription['id']; ?>" name="per_head" <?php echo $subscription['per_head'] ? 'checked' : ''; ?> onchange="togglePerHeadFields('<?php echo $subscription['id']; ?>')">
                              <label class="form-check-label" for="per_head_<?php echo $subscription['id']; ?>">Per Head Pricing</label>
                            </div>
                          </div>
                          <div class="mb-3" id="headcount_fields_<?php echo $subscription['id']; ?>" style="display: <?php echo $subscription['per_head'] ? 'block' : 'none'; ?>;">
                            <div class="row">
                              <div class="col-md-6">
                                <label for="min_headcount_<?php echo $subscription['id']; ?>" class="form-label">Minimum Headcount</label>
                                <input type="number" class="form-control" id="min_headcount_<?php echo $subscription['id']; ?>" name="min_headcount" min="1" value="<?php echo htmlspecialchars($subscription['min_headcount']); ?>" <?php echo $subscription['per_head'] ? 'required' : ''; ?>>
                              </div>
                              <div class="col-md-6">
                                <label for="max_headcount_<?php echo $subscription['id']; ?>" class="form-label">Maximum Headcount</label>
                                <input type="number" class="form-control" id="max_headcount_<?php echo $subscription['id']; ?>" name="max_headcount" min="1" value="<?php echo htmlspecialchars($subscription['max_headcount']); ?>" <?php echo $subscription['per_head'] ? 'required' : ''; ?>>
                              </div>
                            </div>
                          </div>
                          <div class="mb-3">
                            <label for="dish_limit_<?php echo $subscription['id']; ?>" class="form-label">Dish Limit per Meal</label>
                            <input type="number" class="form-control" id="dish_limit_<?php echo $subscription['id']; ?>" name="dish_limit" min="1" value="<?php echo htmlspecialchars($subscription['dish_limit']); ?>" required>
                          </div>
                          <div class="mb-3">
                            <label for="meal_times_<?php echo $subscription['id']; ?>" class="form-label">Meal Times</label>
                            <div>
                              <?php
                              $meal_times = explode(',', $subscription['meal_times']);
                              ?>
                              <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="morning_<?php echo $subscription['id']; ?>" name="meal_times[]" value="Morning" <?php echo in_array('Morning', $meal_times) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="morning_<?php echo $subscription['id']; ?>">Morning</label>
                              </div>
                              <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="afternoon_<?php echo $subscription['id']; ?>" name="meal_times[]" value="Afternoon" <?php echo in_array('Afternoon', $meal_times) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="afternoon_<?php echo $subscription['id']; ?>">Afternoon</label>
                              </div>
                              <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="evening_<?php echo $subscription['id']; ?>" name="meal_times[]" value="Evening" <?php echo in_array('Evening', $meal_times) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="evening_<?php echo $subscription['id']; ?>">Evening</label>
                              </div>
                            </div>
                          </div>
                          <div class="mb-3">
                            <label for="price_<?php echo $subscription['id']; ?>" class="form-label">Price (PKR<?php echo $subscription['per_head'] ? ' per head' : ''; ?>)</label>
                            <input type="number" step="0.01" class="form-control" id="price_<?php echo $subscription['id']; ?>" name="price" min="0" value="<?php echo htmlspecialchars($subscription['price']); ?>" required>
                          </div>
                          <div class="mb-3">
                            <label for="validity_period_<?php echo $subscription['id']; ?>" class="form-label">Validity Period</label>
                            <div class="input-group">
                              <input type="number" class="form-control" id="validity_period_<?php echo $subscription['id']; ?>" name="validity_period" min="1" value="<?php echo htmlspecialchars($subscription['validity_period']); ?>" required>
                              <select class="form-select" id="validity_unit_<?php echo $subscription['id']; ?>" name="validity_unit" required>
                                <option value="Days" <?php echo $subscription['validity_unit'] == 'Days' ? 'selected' : ''; ?>>Days</option>
                                <option value="Weeks" <?php echo $subscription['validity_unit'] == 'Weeks' ? 'selected' : ''; ?>>Weeks</option>
                                <option value="Months" <?php echo $subscription['validity_unit'] == 'Months' ? 'selected' : ''; ?>>Months</option>
                              </select>
                            </div>
                          </div>
                          <div class="mb-3">
                            <label for="dietary_preferences_<?php echo $subscription['id']; ?>" class="form-label">Dietary Preferences</label>
                            <div>
                              <?php
                              $dietary_prefs = explode(',', $subscription['dietary_preferences'] ?? '');
                              ?>
                              <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="vegetarian_<?php echo $subscription['id']; ?>" name="dietary_preferences[]" value="Vegetarian" <?php echo in_array('Vegetarian', $dietary_prefs) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="vegetarian_<?php echo $subscription['id']; ?>">Vegetarian</label>
                              </div>
                              <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="vegan_<?php echo $subscription['id']; ?>" name="dietary_preferences[]" value="Vegan" <?php echo in_array('Vegan', $dietary_prefs) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="vegan_<?php echo $subscription['id']; ?>">Vegan</label>
                              </div>
                              <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="halal_<?php echo $subscription['id']; ?>" name="dietary_preferences[]" value="Halal" <?php echo in_array('Halal', $dietary_prefs) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="halal_<?php echo $subscription['id']; ?>">Halal</label>
                              </div>
                              <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="gluten_free_<?php echo $subscription['id']; ?>" name="dietary_preferences[]" value="Gluten-Free" <?php echo in_array('Gluten-Free', $dietary_prefs) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="gluten_free_<?php echo $subscription['id']; ?>">Gluten-Free</label>
                              </div>
                            </div>
                          </div>
                          <div class="mb-3">
                            <label for="delivery_frequency_<?php echo $subscription['id']; ?>" class="form-label">Delivery Frequency</label>
                            <select class="form-select" id="delivery_frequency_<?php echo $subscription['id']; ?>" name="delivery_frequency" required>
                              <option value="Daily" <?php echo $subscription['delivery_frequency'] == 'Daily' ? 'selected' : ''; ?>>Daily</option>
                              <option value="Weekly" <?php echo $subscription['delivery_frequency'] == 'Weekly' ? 'selected' : ''; ?>>Weekly</option>
                              <option value="Biweekly" <?php echo $subscription['delivery_frequency'] == 'Biweekly' ? 'selected' : ''; ?>>Biweekly</option>
                            </select>
                          </div>
                          <div class="mb-3">
                            <label for="description_<?php echo $subscription['id']; ?>" class="form-label">Description</label>
                            <textarea class="form-control" id="description_<?php echo $subscription['id']; ?>" name="description" rows="4"><?php echo htmlspecialchars($subscription['description']); ?></textarea>
                          </div>
                          <div class="mb-3">
                            <label for="non_subscriber_delivery_fee_<?php echo $subscription['id']; ?>" class="form-label">Non-Subscriber Delivery Fee (PKR)</label>
                            <input type="number" step="0.01" class="form-control" id="non_subscriber_delivery_fee_<?php echo $subscription['id']; ?>" name="non_subscriber_delivery_fee" min="0" value="<?php echo htmlspecialchars($subscription['non_subscriber_delivery_fee']); ?>" required>
                          </div>
                          <div class="mb-3">
                            <label for="status_<?php echo $subscription['id']; ?>" class="form-label">Status</label>
                            <select class="form-select" id="status_<?php echo $subscription['id']; ?>" name="status" required>
                              <option value="active" <?php echo $subscription['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                              <option value="inactive" <?php echo $subscription['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                          <button type="submit" name="update_subscription" class="btn btn-primary">Update Subscription</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- Add Subscription Modal -->
    <div class="modal fade" id="addSubscriptionModal" tabindex="-1" aria-labelledby="addSubscriptionModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="addSubscriptionModalLabel">Add New Subscription</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form method="POST">
            <div class="modal-body">
              <div class="mb-3">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="custom_plan" name="custom_plan">
                  <label class="form-check-label" for="custom_plan">Custom Plan</label>
                </div>
              </div>
              <div class="mb-3" id="custom_plan_name_field" style="display: none;">
                <label for="custom_plan_name" class="form-label">Custom Plan Name</label>
                <input type="text" class="form-control" id="custom_plan_name" name="custom_plan_name">
              </div>
              <div class="mb-3" id="plan_type_field">
                <label for="plan_type" class="form-label">Plan Type</label>
                <select class="form-select" id="plan_type" name="plan_type" required>
                  <option value="Basic">Basic (10% Discount, Free Delivery)</option>
                  <option value="Standard">Standard (15% Discount, Free Delivery)</option>
                  <option value="Premium">Premium (20% Discount, Free Delivery)</option>
                </select>
              </div>
              <div class="mb-3" id="discount_percentage_field" style="display: none;">
                <label for="discount_percentage" class="form-label">Discount Percentage (%)</label>
                <input type="number" step="0.01" class="form-control" id="discount_percentage" name="discount_percentage" min="0" max="100">
              </div>
              <div class="mb-3">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="per_head" name="per_head">
                  <label class="form-check-label" for="per_head">Per Head Pricing</label>
                </div>
              </div>
              <div class="mb-3" id="headcount_fields" style="display: none;">
                <div class="row">
                  <div class="col-md-6">
                    <label for="min_headcount" class="form-label">Minimum Headcount</label>
                    <input type="number" class="form-control" id="min_headcount" name="min_headcount" min="1">
                  </div>
                  <div class="col-md-6">
                    <label for="max_headcount" class="form-label">Maximum Headcount</label>
                    <input type="number" class="form-control" id="max_headcount" name="max_headcount" min="1">
                  </div>
                </div>
              </div>
              <div class="mb-3">
                <label for="dish_limit" class="form-label">Dish Limit per Meal</label>
                <input type="number" class="form-control" id="dish_limit" name="dish_limit" min="1" required>
              </div>
              <div class="mb-3">
                <label for="meal_times" class="form-label">Meal Times</label>
                <div>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="morning" name="meal_times[]" value="Morning">
                    <label class="form-check-label" for="morning">Morning</label>
                  </div>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="afternoon" name="meal_times[]" value="Afternoon">
                    <label class="form-check-label" for="afternoon">Afternoon</label>
                  </div>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="evening" name="meal_times[]" value="Evening">
                    <label class="form-check-label" for="evening">Evening</label>
                  </div>
                </div>
              </div>
              <div class="mb-3">
                <label for="price" class="form-label">Price (PKR)</label>
                <input type="number" step="0.01" class="form-control" id="price" name="price" min="0" required>
              </div>
              <div class="mb-3">
                <label for="validity_period" class="form-label">Validity Period</label>
                <div class="input-group">
                  <input type="number" class="form-control" id="validity_period" name="validity_period" min="1" required>
                  <select class="form-select" id="validity_unit" name="validity_unit" required>
                    <option value="Days">Days</option>
                    <option value="Weeks">Weeks</option>
                    <option value="Months">Months</option>
                  </select>
                </div>
              </div>
              <div class="mb-3">
                <label for="dietary_preferences" class="form-label">Dietary Preferences</label>
                <div>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="vegetarian" name="dietary_preferences[]" value="Vegetarian">
                    <label class="form-check-label" for="vegetarian">Vegetarian</label>
                  </div>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="vegan" name="dietary_preferences[]" value="Vegan">
                    <label class="form-check-label" for="vegan">Vegan</label>
                  </div>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="halal" name="dietary_preferences[]" value="Halal">
                    <label class="form-check-label" for="halal">Halal</label>
                  </div>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="gluten_free" name="dietary_preferences[]" value="Gluten-Free">
                    <label class="form-check-label" for="gluten_free">Gluten-Free</label>
                  </div>
                </div>
              </div>
              <div class="mb-3">
                <label for="delivery_frequency" class="form-label">Delivery Frequency</label>
                <select class="form-select" id="delivery_frequency" name="delivery_frequency" required>
                  <option value="Daily">Daily</option>
                  <option value="Weekly">Weekly</option>
                  <option value="Biweekly">Biweekly</option>
                </select>
              </div>
              <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea class="form-control" id="description" name="description" rows="4"></textarea>
              </div>
              <div class="mb-3">
                <label for="non_subscriber_delivery_fee" class="form-label">Non-Subscriber Delivery Fee (PKR)</label>
                <input type="number" step="0.01" class="form-control" id="non_subscriber_delivery_fee" name="non_subscriber_delivery_fee" min="0" value="250.00" required>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" name="create_subscription" class="btn btn-primary">Create Subscription</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
  <script>
    // DOM Elements
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');

    // Toggle custom plan fields
    function toggleCustomFields(id) {
      const isAddModal = id === 'add';
      const prefix = isAddModal ? '' : `_${id}`;
      const customPlanCheckbox = document.getElementById(`custom_plan${prefix}`);
      const customPlanNameField = document.getElementById(`custom_plan_name_field${prefix}`);
      const planTypeField = document.getElementById(`plan_type_field${prefix}`);
      const discountPercentageField = document.getElementById(`discount_percentage_field${prefix}`);
      const customPlanNameInput = document.getElementById(`custom_plan_name${prefix}`);
      const planTypeSelect = document.getElementById(`plan_type${prefix}`);
      const discountPercentageInput = document.getElementById(`discount_percentage${prefix}`);

      if (!customPlanCheckbox || !customPlanNameField || !planTypeField || !discountPercentageField ||
        !customPlanNameInput || !planTypeSelect || !discountPercentageInput) {
        console.error(`Missing elements for toggleCustomFields with id: ${id}`);
        return;
      }

      if (customPlanCheckbox.checked) {
        customPlanNameField.style.display = 'block';
        planTypeField.style.display = 'none';
        discountPercentageField.style.display = 'block';
        customPlanNameInput.required = true;
        planTypeSelect.required = false;
        discountPercentageInput.required = true;
      } else {
        customPlanNameField.style.display = 'none';
        planTypeField.style.display = 'block';
        discountPercentageField.style.display = 'none';
        customPlanNameInput.required = false;
        planTypeSelect.required = true;
        discountPercentageInput.required = false;
      }
    }

    // Toggle per head fields
    function togglePerHeadFields(id) {
      const isAddModal = id === 'add';
      const prefix = isAddModal ? '' : `_${id}`;
      const perHeadCheckbox = document.getElementById(`per_head${prefix}`);
      const headcountFields = document.getElementById(`headcount_fields${prefix}`);
      const minHeadcountInput = document.getElementById(`min_headcount${prefix}`);
      const maxHeadcountInput = document.getElementById(`max_headcount${prefix}`);
      const priceLabel = document.querySelector(`label[for="price${prefix}"]`);

      if (!perHeadCheckbox || !headcountFields || !minHeadcountInput || !maxHeadcountInput || !priceLabel) {
        console.error(`Missing elements for togglePerHeadFields with id: ${id}`);
        return;
      }

      if (perHeadCheckbox.checked) {
        headcountFields.style.display = 'block';
        minHeadcountInput.required = true;
        maxHeadcountInput.required = true;
        priceLabel.textContent = 'Price (PKR per head)';
      } else {
        headcountFields.style.display = 'none';
        minHeadcountInput.required = false;
        maxHeadcountInput.required = false;
        priceLabel.textContent = 'Price (PKR)';
      }
    }

    // Initialize page
    document.addEventListener('DOMContentLoaded', function() {
      // Highlight active sidebar link
      const currentPage = window.location.pathname.split('/').pop() || 'vendor.php';
      document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
          link.classList.add('active');
        } else {
          link.classList.remove('active');
        }
      });

      // Toggle sidebar on mobile
      if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
          sidebar.classList.toggle('active');
        });
      }

      // Show sidebar toggle on mobile
      if (window.innerWidth <= 992 && sidebarToggle) {
        sidebarToggle.classList.remove('d-none');
      }

      // Fade-in animations
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

      // Initialize custom and per head fields for add modal
      const addCustomPlanCheckbox = document.getElementById('custom_plan');
      const addPerHeadCheckbox = document.getElementById('per_head');

      if (addCustomPlanCheckbox) {
        addCustomPlanCheckbox.addEventListener('change', () => toggleCustomFields('add'));
        // Initialize state
        toggleCustomFields('add');
      } else {
        console.warn('Custom Plan checkbox not found in Add Subscription modal');
      }

      if (addPerHeadCheckbox) {
        addPerHeadCheckbox.addEventListener('change', () => togglePerHeadFields('add'));
        // Initialize state
        togglePerHeadFields('add');
      } else {
        console.warn('Per Head checkbox not found in Add Subscription modal');
      }

      // Initialize edit modals
      document.querySelectorAll('[id^="editSubscriptionModal"]').forEach(modal => {
        const id = modal.id.match(/editSubscriptionModal(\d+)/)?.[1];
        if (id) {
          const customPlanCheckbox = document.getElementById(`custom_plan_${id}`);
          const perHeadCheckbox = document.getElementById(`per_head_${id}`);
          if (customPlanCheckbox) {
            customPlanCheckbox.addEventListener('change', () => toggleCustomFields(id));
            toggleCustomFields(id);
          }
          if (perHeadCheckbox) {
            perHeadCheckbox.addEventListener('change', () => togglePerHeadFields(id));
            togglePerHeadFields(id);
          }
        }
      });
    });

    // Navbar scroll effect
    window.addEventListener('scroll', function() {
      const navbar = document.querySelector('.navbar');
      if (navbar) {
        if (window.scrollY > 50) {
          navbar.style.background = 'rgba(255, 255, 255, 0.95)';
          navbar.style.backdropFilter = 'blur(10px)';
        } else {
          navbar.style.background = '#ffffff';
          navbar.style.backdropFilter = 'none';
        }
      }
    });
  </script>
</body>

</html>