<?php
session_start();
include 'config/db.php';

// Get order ID from URL
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

// Fetch order details
$orderQuery = "SELECT o.*, v.restaurant_name 
               FROM orders o 
               LEFT JOIN vendors v ON o.vendor_id = v.id 
               WHERE o.id = $order_id";
$orderResult = mysqli_query($conn, $orderQuery);
$order = mysqli_fetch_assoc($orderResult);

// Fetch order items
$itemsQuery = "SELECT oi.*, mi.name, mi.category 
               FROM order_items oi 
               LEFT JOIN menu_items mi ON oi.menu_item_id = mi.id 
               WHERE oi.order_id = $order_id";
$itemsResult = mysqli_query($conn, $itemsQuery);
$orderItems = [];

if ($itemsResult) {
  while ($row = mysqli_fetch_assoc($itemsResult)) {
    $orderItems[] = $row;
  }
}

// Check if order exists
if (!$order) {
  header("Location: menu.php?vendor_id=" . ($order['vendor_id'] ?? 0));
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Confirmation - FoodieHub</title>
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

    .order-confirmation {
      padding: 3rem 0;
    }

    .order-card {
      background: var(--white);
      border-radius: var(--border-radius);
      box-shadow: var(--shadow);
      padding: 2rem;
    }

    .order-header {
      border-bottom: 2px solid var(--gray-300);
      padding-bottom: 1rem;
      margin-bottom: 1.5rem;
    }

    .order-header h2 {
      color: var(--primary-color);
      font-weight: 700;
    }

    .order-details p {
      margin-bottom: 0.5rem;
      font-size: 1rem;
    }

    .order-details strong {
      color: var(--dark-color);
      font-weight: 600;
    }

    .order-items table {
      width: 100%;
      margin-top: 1rem;
    }

    .order-items th,
    .order-items td {
      padding: 0.75rem;
      border-bottom: 1px solid var(--gray-200);
    }

    .order-items th {
      background: var(--gray-100);
      font-weight: 600;
      color: var(--dark-color);
    }

    .order-total {
      margin-top: 1.5rem;
      font-size: 1.2rem;
      font-weight: 700;
      text-align: right;
      color: var(--primary-color);
    }

    .btn-back {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      border: none;
      color: var(--white);
      padding: 1rem 2rem;
      border-radius: 25px;
      font-weight: 600;
      transition: var(--transition);
      margin-top: 2rem;
      display: inline-block;
    }

    .btn-back:hover {
      color: var(--white);
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }

    @media (max-width: 768px) {
      .page-header h1 {
        font-size: 2rem;
      }

      .order-card {
        padding: 1.5rem;
      }

      .order-items table {
        font-size: 0.9rem;
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
          <h1>Order Confirmation</h1>
          <p>Thank you for your order! Here's your order summary</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Order Confirmation -->
  <section class="order-confirmation">
    <div class="container">
      <div class="row">
        <div class="col-lg-8 offset-lg-2">
          <div class="order-card fade-in">
            <div class="order-header">
              <h2>Order #<?php echo $order['id']; ?></h2>
            </div>
            <div class="order-details">
              <p><strong>Restaurant:</strong> <?php echo htmlspecialchars($order['restaurant_name']); ?></p>
              <p><strong>Order Date:</strong> <?php echo date('M d, Y H:i', strtotime($order['order_date'])); ?></p>
              <p><strong>Delivery Address:</strong> <?php echo htmlspecialchars($order['delivery_address']); ?></p>
              <p><strong>Payment Method:</strong> <?php echo htmlspecialchars($order['payment_method']); ?></p>
              <p><strong>Status:</strong> <?php echo htmlspecialchars($order['status']); ?></p>
            </div>
            <div class="order-items">
              <table>
                <thead>
                  <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Quantity</th>
                    <th>Price</th>
                    <th>Subtotal</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($orderItems as $item): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($item['name']); ?></td>
                      <td><?php echo htmlspecialchars($item['category']); ?></td>
                      <td><?php echo $item['quantity']; ?></td>
                      <td>pkr <?php echo number_format($item['price'], 2); ?></td>
                      <td>pkr <?php echo number_format($item['subtotal'], 2); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="order-total">
              Total: pkr <?php echo number_format($order['total'], 2); ?>
            </div>
            <a href="menu.php?vendor_id=<?php echo $order['vendor_id']; ?>" class="btn-back">
              <i class="fas fa-arrow-left me-2"></i>Back to Menu
            </a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Bootstrap JS -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>

  <!-- Custom JavaScript -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Trigger fade-in animation
      setTimeout(() => {
        document.querySelectorAll('.fade-in').forEach(el => {
          el.classList.add('visible');
        });
      }, 100);
    });
  </script>
</body>

</html>