<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
  header("Location: login.php?error=Please log in to access this page");
  exit;
}
require_once '../config/db.php';

// Total Orders
$total_orders_query = "SELECT COUNT(*) as total FROM orders";
$total_orders_result = $conn->query($total_orders_query);
$total_orders = $total_orders_result->fetch_assoc()['total'];

// Total Revenue
$revenue_query = "SELECT SUM(total) as total_revenue FROM orders";
$revenue_result = $conn->query($revenue_query);
$total_revenue = $revenue_result->fetch_assoc()['total_revenue'] ?? 0;

// Active Restaurants
$restaurants_query = "SELECT COUNT(*) as total FROM vendors";
$restaurants_result = $conn->query($restaurants_query);
$active_restaurants = $restaurants_result->fetch_assoc()['total'];

// Average Delivery Time (Placeholder)
$avg_delivery_time = 30; // No delivery time data in schema
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FoodHub - Admin Dashboard</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/index.css">
</head>

<body>
  <!-- sidebar -->
  <?php include 'includes/sidebar.php'; ?>

  <!-- Main Content -->
  <div class="main-content" id="mainContent">
    <!-- Header -->
    <?php include 'includes/header.php'; ?>

    <!-- Dashboard Content -->
    <div class="dashboard-content">
      <h1 class="page-title">Dashboard Overview</h1>
      <p class="page-subtitle">Welcome back, John! Here's what's happening with FoodHub today.</p>

      <!-- Stats Grid -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-header">
            <div class="stat-title">Total Orders</div>
            <div class="stat-icon primary"><i class="fas fa-shopping-cart"></i></div>
          </div>
          <div class="stat-value"><?php echo htmlspecialchars($total_orders); ?></div>
          <div class="stat-change positive"><i class="fas fa-arrow-up"></i> +<?php echo rand(5, 15); ?>% from last month</div>
        </div>

        <div class="stat-card success">
          <div class="stat-header">
            <div class="stat-title">Revenue</div>
            <div class="stat-icon success"><i class="fas fa-dollar-sign"></i></div>
          </div>
          <div class="stat-value">$<?php echo number_format($total_revenue, 2); ?></div>
          <div class="stat-change positive"><i class="fas fa-arrow-up"></i> +<?php echo rand(5, 10); ?>% from last month</div>
        </div>

        <div class="stat-card warning">
          <div class="stat-header">
            <div class="stat-title">Active Restaurants</div>
            <div class="stat-icon warning"><i class="fas fa-store"></i></div>
          </div>
          <div class="stat-value"><?php echo htmlspecialchars($active_restaurants); ?></div>
          <div class="stat-change positive"><i class="fas fa-arrow-up"></i> +<?php echo rand(1, 3); ?> new this week</div>
        </div>

        <div class="stat-card info">
          <div class="stat-header">
            <div class="stat-title">Delivery Time</div>
            <div class="stat-icon info"><i class="fas fa-clock"></i></div>
          </div>
          <div class="stat-value"><?php echo htmlspecialchars($avg_delivery_time); ?> min</div>
          <div class="stat-change positive"><i class="fas fa-arrow-down"></i> -<?php echo rand(1, 5); ?> min improvement</div>
        </div>
      </div>
      <?php include 'includes/contact-section.php' ?>
    </div>
    <?php include 'includes/approve_vendor.php' ?>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script>
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const userDropdown = document.querySelector('.user-dropdown');
    const dropdownMenu = document.querySelector('.dropdown-menu');
    const searchInput = document.querySelector('.search-bar input');
    const notificationBell = document.querySelector('.notification-bell');

    // Toggle sidebar
    sidebarToggle.addEventListener('click', function() {
      sidebar.classList.toggle('collapsed');
      mainContent.classList.toggle('expanded');
    });

    // Search input handling
    if (searchInput) {
      searchInput.addEventListener('input', function() {
        console.log('Searching for:', this.value.toLowerCase());
      });
    }

    // Notification bell click
    if (notificationBell) {
      notificationBell.addEventListener('click', function() {
        console.log('Notifications clicked');
      });
    }

    // Toggle dropdown menu
    if (userDropdown && dropdownMenu) {
      userDropdown.addEventListener('click', function(e) {
        e.stopPropagation(); // Prevent click from bubbling to document
        dropdownMenu.classList.toggle('show');
      });

      // Close dropdown when clicking outside
      document.addEventListener('click', function(e) {
        if (!userDropdown.contains(e.target)) {
          dropdownMenu.classList.remove('show');
        }
      });
    }

    // Stat card animation
    document.addEventListener('DOMContentLoaded', function() {
      const statCards = document.querySelectorAll('.stat-card');
      statCards.forEach((card, index) => {
        setTimeout(() => {
          card.style.opacity = '0';
          card.style.transform = 'translateY(20px)';
          card.style.transition = 'all 0.5s ease';
          setTimeout(() => {
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
          }, 100);
        }, index * 100);
      });
    });
  </script>
</body>

</html>
