<?php
// Start the vendor session
session_name('vendor_session');
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['vendor_id'])) {
  header("Location: vendor-login.php");
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
  $conn = new pdo("mysql:host=$servername;dbname=$dbname", $username, $password);
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  die("Connection failed: " . $e->getMessage());
}

// Fetch orders
$vendor_id = $_SESSION['vendor_id'];
$orders_query = "
    SELECT o.id, o.order_type, o.user_id, o.total, o.delivery_address, o.order_date, o.status, 
           u.first_name, u.last_name, oi.menu_item_id, oi.quantity, oi.price, oi.subtotal, m.name as item_name
    FROM orders o
    JOIN users u ON o.user_id = u.id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN menu_items m ON oi.menu_item_id = m.id
    WHERE o.vendor_id = :vendor_id
    ORDER BY o.order_date DESC";
$stmt = $conn->prepare($orders_query);
$stmt->bindParam(':vendor_id', $vendor_id, PDO::PARAM_INT);
$stmt->execute();
$orders_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize orders data
$orders = [];
foreach ($orders_data as $row) {
  $order_id = $row['id'];
  if (!isset($orders[$order_id])) {
    $orders[$order_id] = [
      'id' => $order_id,
      'order_type' => $row['order_type'],
      'customer' => $row['first_name'] . ' ' . $row['last_name'],
      'total' => $row['total'],
      'delivery_address' => $row['delivery_address'],
      'status' => $row['status'],
      'date' => $row['order_date'],
      'items' => []
    ];
  }
  $orders[$order_id]['items'][] = [
    'name' => $row['item_name'],
    'quantity' => $row['quantity'],
    'price' => $row['price']
  ];
}
$orders = array_values($orders);

// Fetch subscription reservations
$subscriptions_query = "
    SELECT sr.id, sr.user_id, sr.subscription_id, sr.meal_time, sr.reservation_date, sr.status,
           u.first_name, u.last_name, s.plan_type, s.dish_limit, s.meal_times
    FROM subscription_reservations sr
    JOIN users u ON sr.user_id = u.id
    JOIN subscriptions s ON sr.subscription_id = s.id
    WHERE sr.vendor_id = :vendor_id
    ORDER BY sr.reservation_date DESC";
$stmt = $conn->prepare($subscriptions_query);
$stmt->bindParam(':vendor_id', $vendor_id, PDO::PARAM_INT);
$stmt->execute();
$subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Management - FoodieHub</title>
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

    .order-item,
    .subscription-item {
      background: var(--white);
      border-radius: var(--border-radius);
      padding: 1.5rem;
      margin-bottom: 1rem;
      box-shadow: var(--shadow);
    }

    .order-item-header,
    .subscription-item-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1rem;
    }

    .order-status,
    .subscription-status {
      padding: 0.25rem 0.75rem;
      border-radius: 25px;
      font-size: 0.85rem;
      font-weight: 600;
    }

    .order-status.pending,
    .subscription-status.Pending {
      background: var(--warning-color);
      color: var(--white);
    }

    .order-status.Processing,
    .subscription-status.Confirmed {
      background: var(--success-color);
      color: var(--white);
    }

    .order-status.Delivered,
    .subscription-status.Delivered {
      background: var(--info-color);
      color: var(--white);
    }

    .order-status.Cancelled,
    .subscription-status.Cancelled {
      background: var(--danger-color);
      color: var(--white);
    }

    .order-items,
    .subscription-details {
      margin-bottom: 1rem;
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

  <div class="main-content">
    <section class="section">
      <div class="container">
        <h2 class="mb-4">Order Management</h2>
        <h3 class="mb-3">Orders</h3>
        <div id="ordersList">
          <?php if (empty($orders)): ?>
            <p>No orders found.</p>
          <?php else: ?>
            <?php foreach ($orders as $order): ?>
              <div class="order-item fade-in">
                <div class="order-item-header">
                  <div>
                    <strong>Order #<?php echo htmlspecialchars($order['id']); ?></strong> - <?php echo htmlspecialchars($order['customer']); ?>
                    <div class="text-muted"><?php echo htmlspecialchars($order['date']); ?></div>
                    <?php if ($order['order_type'] === 'Order'): ?>
                      <div class="text-muted">Delivery Address: <?php echo htmlspecialchars($order['delivery_address']); ?></div>
                    <?php endif; ?>
                  </div>
                  <div class="order-status <?php echo htmlspecialchars($order['status']); ?>">
                    <?php echo htmlspecialchars($order['status']); ?>
                  </div>
                </div>
                <div class="order-items">
                  <?php foreach ($order['items'] as $item): ?>
                    <div><?php echo htmlspecialchars($item['name']); ?> x<?php echo $item['quantity']; ?> - $<?php echo number_format($item['price'] * $item['quantity'], 2); ?></div>
                  <?php endforeach; ?>
                </div>
                <div class="order-total">Total: $<?php echo number_format($order['total'], 2); ?></div>
                <div class="mt-2">
                  <select onchange="updateOrderStatus(<?php echo $order['id']; ?>, this.value)">
                    <option value="Pending" <?php echo $order['status'] === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Processing" <?php echo $order['status'] === 'Processing' ? 'selected' : ''; ?>>Processing</option>
                    <option value="Delivered" <?php echo $order['status'] === 'Delivered' ? 'selected' : ''; ?>>Delivered</option>
                    <option value="Cancelled" <?php echo $order['status'] === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                  </select>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <h3 class="mb-3 mt-5">Subscription Reservations</h3>
        <div id="subscriptionsList">
          <?php if (empty($subscriptions)): ?>
            <p>No subscription reservations found.</p>
          <?php else: ?>
            <?php foreach ($subscriptions as $subscription): ?>
              <div class="subscription-item fade-in">
                <div class="subscription-item-header">
                  <div>
                    <strong>Reservation #<?php echo htmlspecialchars($subscription['id']); ?></strong> - <?php echo htmlspecialchars($subscription['first_name'] . ' ' . $subscription['last_name']); ?>
                    <div class="text-muted"><?php echo htmlspecialchars($subscription['reservation_date']); ?> (<?php echo htmlspecialchars($subscription['meal_time']); ?>)</div>
                    <div class="text-muted">Plan: <?php echo htmlspecialchars($subscription['plan_type']); ?> (<?php echo $subscription['dish_limit']; ?> dishes)</div>
                  </div>
                  <div class="subscription-status <?php echo htmlspecialchars($subscription['status']); ?>">
                    <?php echo htmlspecialchars($subscription['status']); ?>
                  </div>
                </div>
                <div class="subscription-details">
                  <div>Meal Time: <?php echo htmlspecialchars($subscription['meal_time']); ?></div>
                  <div>Plan Type: <?php echo htmlspecialchars($subscription['plan_type']); ?></div>
                  <div>Dish Limit: <?php echo $subscription['dish_limit']; ?></div>
                </div>
                <div class="mt-2">
                  <select onchange="updateSubscriptionStatus(<?php echo $subscription['id']; ?>, this.value)">
                    <option value="Pending" <?php echo $subscription['status'] === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Confirmed" <?php echo $subscription['status'] === 'Confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="Cancelled" <?php echo $subscription['status'] === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                  </select>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>

  <script>
    // Initialize page
    document.addEventListener('DOMContentLoaded', function() {
      // Highlight active sidebar link
      const currentPage = window.location.pathname.split('/').pop() || 'vendor-orders.php';
      document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
          link.classList.add('active');
        } else {
          link.classList.remove('active');
        }
      });

      // Toggle sidebar on mobile
      const sidebar = document.getElementById('sidebar');
      const sidebarToggle = document.getElementById('sidebarToggle');
      if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
          sidebar.classList.toggle('active');
        });

        // Show sidebar toggle on mobile
        if (window.innerWidth <= 992) {
          sidebarToggle.classList.remove('d-none');
        }
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

    function updateOrderStatus(orderId, status) {
      fetch('update-order-status.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `order_id=${orderId}&status=${status}`
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert(`Order ${orderId} status updated to ${status}!`);
            location.reload();
          } else {
            alert('Failed to update order status.');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while updating the order status.');
        });
    }

    function updateSubscriptionStatus(reservationId, status) {
      fetch('update-subscription-status.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `reservation_id=${reservationId}&status=${status}`
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert(`Reservation ${reservationId} status updated to ${status}!`);
            location.reload();
          } else {
            alert('Failed to update reservation status.');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while updating the reservation status.');
        });
    }

    window.addEventListener('scroll', function() {
      const navbar = document.querySelector('.navbar');
      if (window.scrollY > 50) {
        navbar.style.background = 'rgba(255, 255, 255, 0.95)';
        navbar.style.backdropFilter = 'blur(10px)';
      } else {
        navbar.style.background = '#ffffff';
        navbar.style.backdropFilter = 'none';
      }
    });
  </script>
</body>

</html>