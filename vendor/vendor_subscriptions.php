<?php
session_name('vendor_session');
session_start();
include '../config/db.php';

if (!isset($_SESSION['vendor_id'])) {
  header("Location: vendor_login.php");
  exit();
}

// Set timezone to PKT
date_default_timezone_set('Asia/Karachi');

// Fetch vendor ID
$vendor_id = (int)$_SESSION['vendor_id'];

// Handle filters for reservation slots
$slot_filters = [];
$slot_query_conditions = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['filter_slots'])) {
  if (!empty($_GET['slot_date'])) {
    $slot_date = mysqli_real_escape_string($conn, $_GET['slot_date']);
    $slot_query_conditions[] = "slot_date = '$slot_date'";
    $slot_filters['slot_date'] = $slot_date;
  }
  if (!empty($_GET['slot_status']) && in_array($_GET['slot_status'], ['available', 'fully_booked'])) {
    $slot_status = mysqli_real_escape_string($conn, $_GET['slot_status']);
    $slot_query_conditions[] = "status = '$slot_status'";
    $slot_filters['slot_status'] = $slot_status;
  }
}

// Handle filters for user reservations
$reservation_filters = [];
$reservation_query_conditions = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['filter_reservations'])) {
  if (!empty($_GET['start_date'])) {
    $start_date = mysqli_real_escape_string($conn, $_GET['start_date']);
    $reservation_query_conditions[] = "reservation_date >= '$start_date'";
    $reservation_filters['start_date'] = $start_date;
  }
  if (!empty($_GET['end_date'])) {
    $end_date = mysqli_real_escape_string($conn, $_GET['end_date']);
    $reservation_query_conditions[] = "reservation_date <= '$end_date'";
    $reservation_filters['end_date'] = $end_date;
  }
  if (!empty($_GET['reservation_status']) && in_array($_GET['reservation_status'], ['Pending', 'Confirmed', 'Cancelled'])) {
    $reservation_status = mysqli_real_escape_string($conn, $_GET['reservation_status']);
    $reservation_query_conditions[] = "sr.status = '$reservation_status'";
    $reservation_filters['reservation_status'] = $reservation_status;
  }
}

// Fetch subscribed users
$subscribedUsersQuery = "SELECT us.*, s.plan_type, s.dish_limit, s.meal_times, s.price, s.discount_percentage, s.validity_period, s.validity_unit, s.min_headcount, s.max_headcount, u.first_name, u.last_name, u.email, u.phone, u.address, u.city, u.postal_code
                         FROM user_subscriptions us
                         LEFT JOIN subscriptions s ON us.subscription_id = s.id
                         LEFT JOIN users u ON us.user_id = u.id
                         WHERE s.vendor_id = $vendor_id AND us.status = 'active'";
$subscribedUsersResult = mysqli_query($conn, $subscribedUsersQuery);
$subscribedUsers = [];
if ($subscribedUsersResult) {
  while ($row = mysqli_fetch_assoc($subscribedUsersResult)) {
    $subscribedUsers[] = $row;
  }
}

// Fetch pending subscription requests
$pendingSubscriptionsQuery = "SELECT us.*, s.plan_type, s.dish_limit, s.meal_times, s.price, s.discount_percentage, s.validity_period, s.validity_unit, u.first_name, u.last_name, u.email, u.phone, u.address, u.city, u.postal_code
                            FROM user_subscriptions us
                            LEFT JOIN subscriptions s ON us.subscription_id = s.id
                            LEFT JOIN users u ON us.user_id = u.id
                            WHERE s.vendor_id = $vendor_id AND us.status = 'pending'";
$pendingSubscriptionsResult = mysqli_query($conn, $pendingSubscriptionsQuery);
$pendingSubscriptions = [];
if ($pendingSubscriptionsResult) {
  while ($row = mysqli_fetch_assoc($pendingSubscriptionsResult)) {
    $pendingSubscriptions[] = $row;
  }
}

// Handle subscription approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_subscription'])) {
  $user_subscription_id = (int)$_POST['user_subscription_id'];
  $updateQuery = "UPDATE user_subscriptions SET status = 'active', updated_at = NOW() WHERE id = $user_subscription_id AND subscription_id IN (SELECT id FROM subscriptions WHERE vendor_id = $vendor_id)";
  if (mysqli_query($conn, $updateQuery)) {
    $_SESSION['subscription_success'] = "Subscription approved successfully!";
    header("Location: vendor_subscriptions.php");
    exit();
  } else {
    $subscription_error = "Error approving subscription: " . mysqli_error($conn);
  }
}

// Handle slot creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_slot'])) {
  $slot_date = mysqli_real_escape_string($conn, $_POST['slot_date']);
  $slot_time = mysqli_real_escape_string($conn, $_POST['slot_time']);
  $capacity = (int)$_POST['capacity'];
  $status = 'available';

  $insertQuery = "INSERT INTO reservation_slots (vendor_id, slot_date, slot_time, capacity, status, created_at)
                  VALUES ($vendor_id, '$slot_date', '$slot_time', $capacity, '$status', NOW())";
  if (mysqli_query($conn, $insertQuery)) {
    $_SESSION['slot_success'] = "Reservation slot created successfully!";
    header("Location: vendor_subscriptions.php");
    exit();
  } else {
    $slot_error = "Error creating slot: " . mysqli_error($conn);
  }
}

// Handle slot update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_slot'])) {
  $slot_id = (int)$_POST['slot_id'];
  $slot_date = mysqli_real_escape_string($conn, $_POST['slot_date']);
  $slot_time = mysqli_real_escape_string($conn, $_POST['slot_time']);
  $capacity = (int)$_POST['capacity'];
  $status = mysqli_real_escape_string($conn, $_POST['status']);

  $updateQuery = "UPDATE reservation_slots 
                  SET slot_date = '$slot_date', slot_time = '$slot_time', capacity = $capacity, status = '$status', updated_at = NOW()
                  WHERE id = $slot_id AND vendor_id = $vendor_id";
  if (mysqli_query($conn, $updateQuery)) {
    $_SESSION['slot_success'] = "Reservation slot updated successfully!";
    header("Location: vendor_subscriptions.php");
    exit();
  } else {
    $slot_error = "Error updating slot: " . mysqli_error($conn);
  }
}

// Handle slot deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_slot'])) {
  $slot_id = (int)$_POST['slot_id'];
  $deleteQuery = "DELETE FROM reservation_slots WHERE id = $slot_id AND vendor_id = $vendor_id";
  if (mysqli_query($conn, $deleteQuery)) {
    $_SESSION['slot_success'] = "Reservation slot deleted successfully!";
    header("Location: vendor_subscriptions.php");
    exit();
  } else {
    $slot_error = "Error deleting slot: " . mysqli_error($conn);
  }
}
// Fetch reservation slots with filters, excluding reserved slots
$slotsQuery = "SELECT * FROM reservation_slots WHERE vendor_id = $vendor_id AND status != 'reserved'";
if (!empty($slot_query_conditions)) {
  $slotsQuery .= " AND " . implode(" AND ", $slot_query_conditions);
}
$slotsQuery .= " ORDER BY slot_date, slot_time";
$slotsResult = mysqli_query($conn, $slotsQuery);
$slots = [];
if ($slotsResult) {
  while ($row = mysqli_fetch_assoc($slotsResult)) {
    $slots[] = $row;
  }
}

// Fetch user reservations with filters
$reservationsQuery = "SELECT sr.*, u.first_name, u.last_name, s.plan_type, GROUP_CONCAT(CONCAT(ri.quantity, ' x ', mi.name) SEPARATOR ', ') AS items
                     FROM subscription_reservations sr
                     LEFT JOIN users u ON sr.user_id = u.id
                     LEFT JOIN subscriptions s ON sr.subscription_id = s.id
                     LEFT JOIN reservation_items ri ON sr.id = ri.reservation_id
                     LEFT JOIN menu_items mi ON ri.menu_item_id = mi.id
                     WHERE sr.vendor_id = $vendor_id";
if (!empty($reservation_query_conditions)) {
  $reservationsQuery .= " AND " . implode(" AND ", $reservation_query_conditions);
}
$reservationsQuery .= " GROUP BY sr.id ORDER BY sr.reservation_date DESC";
$reservationsResult = mysqli_query($conn, $reservationsQuery);
$reservations = [];
if ($reservationsResult) {
  while ($row = mysqli_fetch_assoc($reservationsResult)) {
    $reservations[] = $row;
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Subscriptions & Reservations - FoodieHub</title>
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

    .section-card {
      background: var(--white);
      border-radius: var(--border-radius);
      padding: 2rem;
      margin-bottom: 2rem;
      box-shadow: var(--shadow);
      border-left: 5px solid var(--primary-color);
    }

    .section-card h2 {
      color: var(--primary-color);
      font-weight: 700;
      margin-bottom: 1.5rem;
    }

    .subscription-request-card,
    .reservation-slot-card,
    .reservation-card,
    .subscribed-user-card {
      background: var(--gray-100);
      border-radius: var(--border-radius);
      padding: 1.5rem;
      margin-bottom: 1rem;
      box-shadow: var(--shadow);
      transition: var(--transition);
    }

    .subscription-request-card:hover,
    .reservation-slot-card:hover,
    .reservation-card:hover,
    .subscribed-user-card:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-hover);
    }

    .subscription-request-card h5,
    .reservation-slot-card h5,
    .reservation-card h5,
    .subscribed-user-card h5 {
      color: var(--primary-color);
      font-weight: 600;
    }

    .subscription-status,
    .reservation-status {
      font-weight: 600;
    }

    .subscription-status.pending {
      color: var(--warning-color);
    }

    .subscription-status.active {
      color: var(--success-color);
    }

    .subscription-status.cancelled {
      color: var(--danger-color);
    }

    .reservation-status.Pending {
      color: var(--warning-color);
    }

    .reservation-status.Confirmed {
      color: var(--success-color);
    }

    .reservation-status.Cancelled {
      color: var(--danger-color);
    }

    .btn-approve,
    .btn-create-slot {
      background: linear-gradient(135deg, var(--success-color), var(--accent-color));
      border: none;
      color: var(--white);
      padding: 0.75rem 1.5rem;
      border-radius: 25px;
      font-weight: 600;
      transition: var(--transition);
    }

    .btn-approve:hover,
    .btn-create-slot:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }

    .btn-edit,
    .btn-delete,
    .btn-filter,
    .btn-reset {
      padding: 0.5rem 1rem;
      border-radius: 25px;
      font-weight: 500;
    }

    .btn-edit {
      background: var(--info-color);
      color: var(--white);
    }

    .btn-delete {
      background: var(--danger-color);
      color: var(--white);
    }

    .btn-filter {
      background: var(--primary-color);
      color: var(--white);
    }

    .btn-reset {
      background: var(--gray-500);
      color: var(--white);
    }

    .filter-form {
      background: var(--gray-200);
      padding: 1rem;
      border-radius: var(--border-radius);
      margin-bottom: 1.5rem;
    }

    .quick-links {
      position: sticky;
      top: 90px;
      background: var(--white);
      padding: 1rem;
      border-radius: var(--border-radius);
      box-shadow: var(--shadow);
      margin-bottom: 2rem;
      z-index: 100;
    }

    .quick-links a {
      margin-right: 1rem;
      font-weight: 500;
      color: var(--primary-color);
    }

    .quick-links a:hover {
      text-decoration: underline;
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

      .quick-links {
        position: static;
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
    <!-- Quick Links -->
    <div class="quick-links">
      <a href="#subscribed-users">Subscribed Users</a>
      <a href="#subscription-requests">Subscription Requests</a>
      <a href="#reservation-slots">Reservation Slots</a>
      <a href="#user-reservations">User Reservations</a>
    </div>

    <!-- Subscribed Users Section -->
    <div class="section-card" id="subscribed-users">
      <h2>Subscribed Users (<?php echo count($subscribedUsers); ?>)</h2>
      <div class="row">
        <div class="col-12">
          <?php if (empty($subscribedUsers)): ?>
            <p class="text-center text-muted">No active subscriptions found.</p>
          <?php else: ?>
            <?php foreach ($subscribedUsers as $user): ?>
              <div class="subscribed-user-card fade-in">
                <h5><?php echo htmlspecialchars($user['plan_type']); ?> Subscription</h5>
                <p><strong>User:</strong> <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                <p><strong>Phone:</strong> <?php echo htmlspecialchars($user['phone'] ?: 'Not provided'); ?></p>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($user['address'] ?: 'Not provided'); ?></p>
                <p><strong>City:</strong> <?php echo htmlspecialchars($user['city'] ?: 'Not provided'); ?></p>
                <p><strong>Postal Code:</strong> <?php echo htmlspecialchars($user['postal_code'] ?: 'Not provided'); ?></p>
                <p><strong>Dishes:</strong> <?php echo htmlspecialchars($user['dish_limit']); ?> per meal</p>
                <p><strong>Meal Times:</strong> <?php echo htmlspecialchars($user['meal_times']); ?></p>
                <p><strong>Headcount:</strong> <?php echo htmlspecialchars($user['min_headcount'] . ' to ' . $user['max_headcount']); ?> people</p>
                <p><strong>Price:</strong> PKR <?php echo number_format($user['price'], 2); ?></p>
                <p><strong>Discount:</strong> <?php echo number_format($user['discount_percentage'], 2); ?>%</p>
                <p><strong>Validity:</strong> <?php echo htmlspecialchars($user['validity_period'] . ' ' . $user['validity_unit']); ?></p>
                <p><strong>Start Date:</strong> <?php echo date('M d, Y', strtotime($user['start_date'])); ?></p>
                <p><strong>End Date:</strong> <?php echo date('M d, Y', strtotime($user['end_date'])); ?></p>
                <p><strong>Status:</strong> <span class="subscription-status <?php echo strtolower($user['status']); ?>"><?php echo htmlspecialchars($user['status']); ?></span></p>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Subscription Requests Section -->
    <div class="section-card" id="subscription-requests">
      <h2>Subscription Requests (<?php echo count($pendingSubscriptions); ?>)</h2>
      <?php if (isset($_SESSION['subscription_success'])): ?>
        <div class="alert alert-success">
          <?php echo $_SESSION['subscription_success'];
          unset($_SESSION['subscription_success']); ?>
        </div>
      <?php endif; ?>
      <?php if (isset($subscription_error)): ?>
        <div class="alert alert-danger">
          <?php echo $subscription_error; ?>
        </div>
      <?php endif; ?>
      <div class="row">
        <div class="col-12">
          <?php if (empty($pendingSubscriptions)): ?>
            <p class="text-center text-muted">No pending subscription requests.</p>
          <?php else: ?>
            <?php foreach ($pendingSubscriptions as $sub): ?>
              <div class="subscription-request-card fade-in">
                <h5><?php echo htmlspecialchars($sub['plan_type']); ?> Subscription Request</h5>
                <p><strong>User:</strong> <?php echo htmlspecialchars($sub['first_name'] . ' ' . $sub['last_name']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($sub['email']); ?></p>
                <p><strong>Phone:</strong> <?php echo htmlspecialchars($sub['phone'] ?: 'Not provided'); ?></p>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($sub['address'] ?: 'Not provided'); ?></p>
                <p><strong>City:</strong> <?php echo htmlspecialchars($sub['city'] ?: 'Not provided'); ?></p>
                <p><strong>Postal Code:</strong> <?php echo htmlspecialchars($sub['postal_code'] ?: 'Not provided'); ?></p>
                <p><strong>Dishes:</strong> <?php echo htmlspecialchars($sub['dish_limit']); ?> per meal</p>
                <p><strong>Meal Times:</strong> <?php echo htmlspecialchars($sub['meal_times']); ?></p>
                <p><strong>Price:</strong> PKR <?php echo number_format($sub['price'], 2); ?></p>
                <p><strong>Discount:</strong> <?php echo number_format($sub['discount_percentage'], 2); ?>%</p>
                <p><strong>Delivery:</strong> Free</p>
                <p><strong>Validity:</strong> <?php echo htmlspecialchars($sub['validity_period'] . ' ' . $sub['validity_unit']); ?></p>
                <p><strong>Status:</strong> <span class="subscription-status <?php echo strtolower($sub['status']); ?>"><?php echo htmlspecialchars($sub['status']); ?></span></p>
                <form method="POST" style="display: inline;">
                  <input type="hidden" name="user_subscription_id" value="<?php echo $sub['id']; ?>">
                  <button type="submit" name="approve_subscription" class="btn-approve">Approve Subscription</button>
                </form>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Reservation Slots Section -->
    <div class="section-card" id="reservation-slots">
      <h2>Reservation Slots (<?php echo count($slots); ?>)</h2>
      <!-- Slot Filters -->
      <form class="filter-form" method="GET">
        <div class="row g-3">
          <div class="col-md-4">
            <label for="slot_date" class="form-label">Date</label>
            <input type="date" class="form-control" id="slot_date" name="slot_date" value="<?php echo isset($slot_filters['slot_date']) ? htmlspecialchars($slot_filters['slot_date']) : ''; ?>">
          </div>
          <div class="col-md-4">
            <label for="slot_status" class="form-label">Status</label>
            <select class="form-select" id="slot_status" name="slot_status">
              <option value="">All</option>
              <option value="available" <?php echo isset($slot_filters['slot_status']) && $slot_filters['slot_status'] == 'available' ? 'selected' : ''; ?>>Available</option>
              <option value="fully_booked" <?php echo isset($slot_filters['slot_status']) && $slot_filters['slot_status'] == 'fully_booked' ? 'selected' : ''; ?>>Fully Booked</option>
            </select>
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <button type="submit" name="filter_slots" class="btn btn-filter me-2"><i class="fas fa-filter"></i> Filter</button>
            <a href="vendor_subscriptions.php#reservation-slots" class="btn btn-reset"><i class="fas fa-undo"></i> Reset</a>
          </div>
        </div>
      </form>

      <div class="d-flex justify-content-between align-items-center mb-4">
        <h3>Manage Slots</h3>
        <button class="btn btn-create-slot" data-bs-toggle="modal" data-bs-target="#addSlotModal">
          <i class="fas fa-plus"></i> Add Slot
        </button>
      </div>
      <?php if (isset($_SESSION['slot_success'])): ?>
        <div class="alert alert-success">
          <?php echo $_SESSION['slot_success'];
          unset($_SESSION['slot_success']); ?>
        </div>
      <?php endif; ?>
      <?php if (isset($slot_error)): ?>
        <div class="alert alert-danger">
          <?php echo $slot_error; ?>
        </div>
      <?php endif; ?>
      <div class="row">
        <div class="col-12">
          <?php if (empty($slots)): ?>
            <p class="text-center text-muted">No reservation slots found. Create one to get started.</p>
          <?php else: ?>
            <?php foreach ($slots as $slot): ?>
              <div class="reservation-slot-card fade-in">
                <h5><?php echo date('M d, Y', strtotime($slot['slot_date'])) . ' at ' . date('H:i', strtotime($slot['slot_time'])); ?></h5>
                <p><strong>Capacity:</strong> <?php echo htmlspecialchars($slot['capacity']); ?> people</p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars($slot['status']); ?></p>
                <div>
                  <button class="btn btn-edit" data-bs-toggle="modal" data-bs-target="#editSlotModal<?php echo $slot['id']; ?>"><i class="fas fa-edit"></i> Edit</button>
                  <form method="POST" style="display: inline;">
                    <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                    <button type="submit" name="delete_slot" class="btn btn-delete" onclick="return confirm('Are you sure you want to delete this slot?');"><i class="fas fa-trash"></i> Delete</button>
                  </form>
                </div>
              </div>

              <!-- Edit Slot Modal -->
              <div class="modal fade" id="editSlotModal<?php echo $slot['id']; ?>" tabindex="-1" aria-labelledby="editSlotModalLabel<?php echo $slot['id']; ?>" aria-hidden="true">
                <div class="modal-dialog">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title" id="editSlotModalLabel<?php echo $slot['id']; ?>">Edit Reservation Slot</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST">
                      <div class="modal-body">
                        <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                        <div class="mb-3">
                          <label for="slot_date_<?php echo $slot['id']; ?>" class="form-label">Date</label>
                          <input type="date" class="form-control" id="slot_date_<?php echo $slot['id']; ?>" name="slot_date" value="<?php echo $slot['slot_date']; ?>" required>
                        </div>
                        <div class="mb-3">
                          <label for="slot_time_<?php echo $slot['id']; ?>" class="form-label">Time</label>
                          <input type="time" class="form-control" id="slot_time_<?php echo $slot['id']; ?>" name="slot_time" value="<?php echo $slot['slot_time']; ?>" required>
                        </div>
                        <div class="mb-3">
                          <label for="capacity_<?php echo $slot['id']; ?>" class="form-label">Capacity (People)</label>
                          <input type="number" class="form-control" id="capacity_<?php echo $slot['id']; ?>" name="capacity" min="1" value="<?php echo $slot['capacity']; ?>" required>
                        </div>
                        <div class="mb-3">
                          <label for="status_<?php echo $slot['id']; ?>" class="form-label">Status</label>
                          <select class="form-select" id="status_<?php echo $slot['id']; ?>" name="status" required>
                            <option value="available" <?php echo $slot['status'] == 'available' ? 'selected' : ''; ?>>Available</option>
                            <option value="fully_booked" <?php echo $slot['status'] == 'fully_booked' ? 'selected' : ''; ?>>Fully Booked</option>
                          </select>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="update_slot" class="btn btn-primary">Update Slot</button>
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

    <!-- Add Slot Modal -->
    <div class="modal fade" id="addSlotModal" tabindex="-1" aria-labelledby="addSlotModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="addSlotModalLabel">Add New Reservation Slot</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form method="POST">
            <div class="modal-body">
              <div class="mb-3">
                <label for="slot_date" class="form-label">Date</label>
                <input type="date" class="form-control" id="slot_date" name="slot_date" required>
              </div>
              <div class="mb-3">
                <label for="slot_time" class="form-label">Time</label>
                <input type="time" class="form-control" id="slot_time" name="slot_time" required>
              </div>
              <div class="mb-3">
                <label for="capacity" class="form-label">Capacity (People)</label>
                <input type="number" class="form-control" id="capacity" name="capacity" min="1" required>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" name="create_slot" class="btn btn-primary">Create Slot</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- User Reservations Section -->
    <div class="section-card" id="user-reservations">
      <h2>User Reservations (<?php echo count($reservations); ?>)</h2>
      <!-- Reservation Filters -->
      <form class="filter-form" method="GET">
        <div class="row g-3">
          <div class="col-md-3">
            <label for="start_date" class="form-label">Start Date</label>
            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo isset($reservation_filters['start_date']) ? htmlspecialchars($reservation_filters['start_date']) : ''; ?>">
          </div>
          <div class="col-md-3">
            <label for="end_date" class="form-label">End Date</label>
            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo isset($reservation_filters['end_date']) ? htmlspecialchars($reservation_filters['end_date']) : ''; ?>">
          </div>
          <div class="col-md-3">
            <label for="reservation_status" class="form-label">Status</label>
            <select class="form-select" id="reservation_status" name="reservation_status">
              <option value="">All</option>
              <option value="Pending" <?php echo isset($reservation_filters['reservation_status']) && $reservation_filters['reservation_status'] == 'Pending' ? 'selected' : ''; ?>>Pending</option>
              <option value="Confirmed" <?php echo isset($reservation_filters['reservation_status']) && $reservation_filters['reservation_status'] == 'Confirmed' ? 'selected' : ''; ?>>Confirmed</option>
              <option value="Cancelled" <?php echo isset($reservation_filters['reservation_status']) && $reservation_filters['reservation_status'] == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <button type="submit" name="filter_reservations" class="btn btn-filter me-2"><i class="fas fa-filter"></i> Filter</button>
            <a href="vendor_subscriptions.php#user-reservations" class="btn btn-reset"><i class="fas fa-undo"></i> Reset</a>
          </div>
        </div>
      </form>

      <div class="row">
        <div class="col-12">
          <?php if (empty($reservations)): ?>
            <p class="text-center text-muted">No reservations found.</p>
          <?php else: ?>
            <?php foreach ($reservations as $reservation): ?>
              <div class="reservation-card fade-in">
                <h5>Reservation by <?php echo htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name']); ?></h5>
                <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($reservation['reservation_date'])); ?></p>
                <p><strong>Meal Time:</strong> <?php echo htmlspecialchars($reservation['meal_time']); ?></p>
                <p><strong>Headcount:</strong> <?php echo htmlspecialchars($reservation['headcount']); ?> people</p>
                <p><strong>Subscription:</strong> <?php echo htmlspecialchars($reservation['plan_type'] ?: 'None'); ?></p>
                <p><strong>Items:</strong> <?php echo htmlspecialchars($reservation['items'] ?: 'None'); ?></p>
                <p><strong>Status:</strong> <span class="reservation-status <?php echo htmlspecialchars($reservation['status']); ?>"><?php echo htmlspecialchars($reservation['status']); ?></span></p>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>

  <!-- Custom JavaScript -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const sidebar = document.getElementById('sidebar');
      const sidebarToggle = document.getElementById('sidebarToggle');

      // Highlight active sidebar link
      const currentPage = window.location.pathname.split('/').pop() || 'vendor_subscriptions.php';
      document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
          link.classList.add('active');
        } else {
          link.classList.remove('active');
        }
      });

      // Toggle sidebar on mobile
      sidebarToggle.addEventListener('click', function() {
        sidebar.classList.toggle('active');
      });

      // Show sidebar toggle on mobile
      if (window.innerWidth <= 992) {
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
    });
  </script>
</body>

</html>