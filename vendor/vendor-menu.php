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
  $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  die("Connection failed: " . $e->getMessage());
}

// Initialize messages
$error_message = '';
$success_message = '';

// Fetch today's schedule
try {
  $vendor_id = $_SESSION['vendor_id'];
  $today = date('Y-m-d'); // e.g., 2025-06-02
  $stmt = $conn->prepare("SELECT start_time, end_time FROM vendor_schedules WHERE vendor_id = :vendor_id AND schedule_date = :today");
  $stmt->bindParam(':vendor_id', $vendor_id);
  $stmt->bindParam(':today', $today);
  $stmt->execute();
  $today_schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $error_message = "Error fetching today's schedule: " . $e->getMessage();
  $today_schedule = [];
}

// Upload directory
$upload_dir = 'Uploads/';
if (!is_dir($upload_dir)) {
  mkdir($upload_dir, 0755, true);
}

// Handle add menu item
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_menu_item'])) {
  $name = trim(htmlspecialchars($_POST['itemName'], ENT_QUOTES, 'UTF-8'));
  $category = $_POST['itemCategory'];
  $description = trim(htmlspecialchars($_POST['itemDescription'], ENT_QUOTES, 'UTF-8'));
  $price = floatval($_POST['itemPrice']);
  $vendor_id = $_SESSION['vendor_id'];
  $image_path = '';

  // Validate inputs
  if (empty($name) || empty($category) || empty($price)) {
    $error_message = "Item name, category, and price are required.";
  } elseif ($price <= 0) {
    $error_message = "Price must be greater than zero.";
  } elseif (!in_array($category, [
    'Pizzas', 'Burgers', 'Sides', 'Drinks', 'Desserts', 'Salads', 'Appetizers', 'Soups',
    'Sandwiches', 'Breakfast', 'Seafood', 'Vegetarian', 'Vegan', 'Kids Menu', 'Specials',
    'Alcoholic Beverages', 'Coffee & Tea', 'Smoothies'
])) {
    $error_message = "Invalid category selected.";
  } else {
    // Handle image upload
    if (isset($_FILES['itemImage']) && $_FILES['itemImage']['error'] == UPLOAD_ERR_OK) {
      $file = $_FILES['itemImage'];
      $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
      $max_size = 2 * 1024 * 1024; // 2MB

      if (!in_array($file['type'], $allowed_types)) {
        $error_message = "Only JPG, JPEG, and PNG images are allowed.";
      } elseif ($file['size'] > $max_size) {
        $error_message = "Image size must not exceed 2MB.";
      } else {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('menu_', true) . '.' . $ext;
        $destination = $upload_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
          $image_path = $destination;
        } else {
          $error_message = "Failed to upload image.";
        }
      }
    }

    // Insert into database if no errors
    if (empty($error_message)) {
      try {
        $stmt = $conn->prepare("INSERT INTO menu_items (vendor_id, name, category, description, price, image) VALUES (:vendor_id, :name, :category, :description, :price, :image)");
        $stmt->bindParam(':vendor_id', $vendor_id);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':category', $category);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':price', $price);
        $stmt->bindParam(':image', $image_path);
        $stmt->execute();
        $success_message = "Menu item added successfully!";
      } catch (PDOException $e) {
        $error_message = "Error adding item: " . $e->getMessage();
      }
    }
  }
}

// Handle edit menu item
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_menu_item'])) {
  $item_id = intval($_POST['itemId']);
  $name = trim(htmlspecialchars($_POST['itemName'], ENT_QUOTES, 'UTF-8'));
  $category = $_POST['itemCategory'];
  $description = trim(htmlspecialchars($_POST['itemDescription'], ENT_QUOTES, 'UTF-8'));
  $price = floatval($_POST['itemPrice']);
  $vendor_id = $_SESSION['vendor_id'];
  $image_path = $_POST['existingImage'] ?? '';

  // Validate inputs
  if (empty($name) || empty($category) || empty($price)) {
    $error_message = "Item name, category, and price are required.";
  } elseif ($price <= 0) {
    $error_message = "Price must be greater than zero.";
  } elseif (!in_array($category, ['Pizzas', 'Sides', 'Drinks', 'Desserts'])) {
    $error_message = "Invalid category selected.";
  } else {
    // Handle image upload
    if (isset($_FILES['itemImage']) && $_FILES['itemImage']['error'] == UPLOAD_ERR_OK) {
      $file = $_FILES['itemImage'];
      $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
      $max_size = 2 * 1024 * 1024; // 2MB

      if (!in_array($file['type'], $allowed_types)) {
        $error_message = "Only JPG, JPEG, and PNG images are allowed.";
      } elseif ($file['size'] > $max_size) {
        $error_message = "Image size must not exceed 2MB.";
      } else {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('menu_', true) . '.' . $ext;
        $destination = $upload_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
          $image_path = $destination;
          // Delete old image if it exists
          if (!empty($_POST['existingImage']) && file_exists($_POST['existingImage'])) {
            unlink($_POST['existingImage']);
          }
        } else {
          $error_message = "Failed to upload image.";
        }
      }
    }

    // Update database if no errors
    if (empty($error_message)) {
      try {
        $stmt = $conn->prepare("UPDATE menu_items SET name = :name, category = :category, description = :description, price = :price, image = :image WHERE id = :item_id AND vendor_id = :vendor_id");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':category', $category);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':price', $price);
        $stmt->bindParam(':image', $image_path);
        $stmt->bindParam(':item_id', $item_id);
        $stmt->bindParam(':vendor_id', $vendor_id);
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
          $success_message = "Menu item updated successfully!";
        } else {
          $error_message = "No item found or you don't have permission to edit this item.";
        }
      } catch (PDOException $e) {
        $error_message = "Error updating item: " . $e->getMessage();
      }
    }
  }
}

// Handle delete menu item
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {
  $item_id = intval($_GET['delete_id']);
  $vendor_id = $_SESSION['vendor_id'];
  try {
    // Fetch image path to delete file
    $stmt = $conn->prepare("SELECT image FROM menu_items WHERE id = :item_id AND vendor_id = :vendor_id");
    $stmt->bindParam(':item_id', $item_id);
    $stmt->bindParam(':vendor_id', $vendor_id);
    $stmt->execute();
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    // Delete from database
    $stmt = $conn->prepare("DELETE FROM menu_items WHERE id = :item_id AND vendor_id = :vendor_id");
    $stmt->bindParam(':item_id', $item_id);
    $stmt->bindParam(':vendor_id', $vendor_id);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
      // Delete image file if it exists
      if (!empty($item['image']) && file_exists($item['image'])) {
        unlink($item['image']);
      }
      $success_message = "Menu item deleted successfully!";
    } else {
      $error_message = "No item found or you don't have permission to delete this item.";
    }
  } catch (PDOException $e) {
    $error_message = "Error deleting item: " . $e->getMessage();
  }
}

// Fetch menu items for the vendor
try {
  $stmt = $conn->prepare("SELECT id, name, category, description, price, image FROM menu_items WHERE vendor_id = :vendor_id ORDER BY created_at DESC");
  $stmt->bindParam(':vendor_id', $_SESSION['vendor_id']);
  $stmt->execute();
  $menu_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $error_message = "Error fetching menu items: " . $e->getMessage();
  $menu_items = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Menu Management - FoodieHub</title>
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

    /* Navbar Styles */
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

    /* Sidebar */
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

    /* Main Content */
    .main-content {
      margin-left: 250px;
      padding: 2rem;
      margin-top: 76px;
    }

    /* Menu Container */
    .menu-container {
      background: var(--white);
      border-radius: var(--border-radius);
      box-shadow: var(--shadow);
      padding: 2rem;
      margin: 3rem 0;
    }

    .menu-title {
      font-size: 2rem;
      font-weight: 700;
      color: var(--dark-color);
      margin-bottom: 1.5rem;
    }

    .schedule-info {
      background: var(--gray-100);
      border-radius: var(--border-radius);
      padding: 1rem;
      margin-bottom: 2rem;
      font-size: 1rem;
      color: var(--dark-color);
      border-left: 4px solid var(--primary-color);
    }

    .menu-item {
      display: flex;
      align-items: center;
      padding: 1rem;
      border-bottom: 1px solid var(--gray-200);
      margin-bottom: 1rem;
    }

    .menu-item img {
      width: 80px;
      height: 80px;
      object-fit: cover;
      border-radius: var(--border-radius);
      margin-right: 1rem;
    }

    .menu-item-info {
      flex: 1;
    }

    .menu-item-title {
      font-size: 1rem;
      font-weight: 600;
      margin-bottom: 0.25rem;
    }

    .menu-item-price {
      color: var(--primary-color);
      font-weight: 600;
    }

    .btn {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      border: none;
      color: var(--white);
      padding: 0.75rem 1.5rem;
      border-radius: 25px;
      font-weight: 600;
      transition: var(--transition);
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
      color: var(--white);
    }

    .btn-danger {
      background: linear-gradient(135deg, var(--danger-color), #c0392b);
    }

    .form-group label {
      font-weight: 600;
      color: var(--gray-800);
      margin-bottom: 0.5rem;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
      border: 2px solid var(--gray-300);
      border-radius: 8px;
      padding: 0.75rem;
      font-size: 1rem;
      transition: var(--transition);
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
      outline: none;
    }

    /* Modal */
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

    /* Messages */
    .error-message {
      color: var(--danger-color);
      font-size: 0.9rem;
      margin-bottom: 1rem;
      text-align: center;
      display: <?php echo !empty($error_message) ? 'block' : 'none'; ?>;
    }

    .success-message {
      color: var(--success-color);
      font-size: 0.9rem;
      margin-bottom: 1rem;
      text-align: center;
      display: <?php echo !empty($success_message) ? 'block' : 'none'; ?>;
    }

    /* Responsive */
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

      .menu-container {
        padding: 1.5rem;
      }

      .menu-item {
        flex-direction: column;
        align-items: flex-start;
      }

      .menu-item img {
        margin-bottom: 1rem;
      }

      .schedule-info {
        font-size: 0.9rem;
      }
    }

    /* Fade In Animation */
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

  <!-- Main Content -->
  <div class="main-content">
    <section class="section">
      <div class="container">
        <h2 class="mb-4">Menu Management</h2>
        <div class="menu-container fade-in">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="menu-title">Menu Items</h2>
            <button class="btn" data-bs-toggle="modal" data-bs-target="#addMenuItemModal">Add New Item</button>
          </div>
          <!-- Today's Schedule -->
          <div class="schedule-info fade-in">
            <h5>Today's Schedule (<?php echo date('F j, Y'); ?>)</h5>
            <?php if (empty($today_schedule)): ?>
              <p>No availability set for today. <a href="vendor-schedule.php">Set schedule</a>.</p>
            <?php else: ?>
              <?php foreach ($today_schedule as $schedule): ?>
                <p>
                  Open: <?php echo date('h:i A', strtotime($schedule['start_time'])); ?> -
                  <?php echo date('h:i A', strtotime($schedule['end_time'])); ?>
                </p>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
          <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
          <div id="menuItems">
            <?php if (empty($menu_items)): ?>
              <p class="text-center">No menu items added yet.</p>
            <?php else: ?>
              <?php foreach ($menu_items as $item): ?>
                <div class="menu-item fade-in">
                  <img src="<?php echo htmlspecialchars($item['image'] ?: 'data:image/svg+xml,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 300 180\"><rect fill=\"%23ff6b35\" width=\"300\" height=\"180\"/><circle fill=\"%23ffa726\" cx=\"150\" cy=\"90\" r=\"50\"/><text x=\"150\" y=\"100\" text-anchor=\"middle\" fill=\"white\" font-size=\"28\">🍽️</text></svg>'); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                  <div class="menu-item-info">
                    <div class="menu-item-title"><?php echo htmlspecialchars($item['name']); ?></div>
                    <div class="menu-item-price">PKR <?php echo number_format($item['price'], 2); ?></div>
                    <div><?php echo htmlspecialchars($item['category']); ?></div>
                    <div><?php echo htmlspecialchars($item['description'] ?: 'No description available.'); ?></div>
                  </div>
                  <div>
                    <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#editMenuItemModal" onclick="populateEditForm(<?php echo $item['id']; ?>, '<?php echo addslashes(htmlspecialchars($item['name'])); ?>', '<?php echo htmlspecialchars($item['category']); ?>', '<?php echo addslashes(htmlspecialchars($item['description'])); ?>', <?php echo $item['price']; ?>, '<?php echo addslashes(htmlspecialchars($item['image'])); ?>')">Edit</button>
                    <a href="vendor-menu.php?delete_id=<?php echo $item['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this item?')">Delete</a>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div class="text-center mt-4">
            <a href="vendor-schedule.php" class="btn" onclick="return validateMenuItems()">Proceed to Schedule</a>
          </div>
        </div>
      </div>
    </section>
  </div>

  <!-- Add Menu Item Modal -->
  <div class="modal fade" id="addMenuItemModal" tabindex="-1" aria-labelledby="addMenuItemModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="addMenuItemModalLabel">Add Menu Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data">
          <div class="modal-body">
            <div class="form-group mb-3">
              <label for="itemName">Item Name</label>
              <input type="text" class="form-control" id="itemName" name="itemName" placeholder="Enter item name" required>
            </div>
            <div class="form-group mb-3">
              <label for="itemCategory">Category</label>
              <select class="form-control" id="itemCategory" name="itemCategory" required>
                <option value="">-- Select Category --</option>
                <option value="Pizzas">Pizzas</option>
                <option value="Burgers">Burgers</option>
                <option value="Sides">Sides</option>
                <option value="Drinks">Drinks</option>
                <option value="Desserts">Desserts</option>
                <option value="Salads">Salads</option>
                <option value="Appetizers">Appetizers</option>
                <option value="Soups">Soups</option>
                <option value="Sandwiches">Sandwiches</option>
                <option value="Breakfast">Breakfast</option>
                <option value="Seafood">Seafood</option>
                <option value="Vegetarian">Vegetarian</option>
                <option value="Vegan">Vegan</option>
                <option value="Kids Menu">Kids Menu</option>
                <option value="Specials">Specials</option>
                <option value="Alcoholic Beverages">Alcoholic Beverages</option>
                <option value="Coffee & Tea">Coffee & Tea</option>
                <option value="Smoothies">Smoothies</option>
              </select>
            </div>


            <!-- Hidden by default: shows only if "Add Custom Category" is selected -->
            <div class="form-group mb-3" id="customCategoryWrapper" style="display: none;">
              <label for="customCategory">Custom Category</label>
              <input type="text" class="form-control" id="customCategory" name="customCategory" placeholder="Enter custom category">
            </div>
            <script>
              function toggleCustomCategory(select) {
                const customWrapper = document.getElementById('customCategoryWrapper');
                if (select.value === 'custom') {
                  customWrapper.style.display = 'block';
                  document.getElementById('customCategory').required = true;
                } else {
                  customWrapper.style.display = 'none';
                  document.getElementById('customCategory').required = false;
                }
              }
            </script>


            <div class="form-group mb-3">
              <label for="itemDescription">Description</label>
              <textarea class="form-control" id="itemDescription" name="itemDescription" placeholder="Enter description"></textarea>
            </div>
            <div class="form-group mb-3">
              <label for="itemPrice">Price (PKR)</label>
              <input type="number" class="form-control" id="itemPrice" name="itemPrice" step="0.01" placeholder="Enter price" required>
            </div>
            <div class="form-group mb-3">
              <label for="itemImage">Image</label>
              <input type="file" class="form-control" id="itemImage" name="itemImage" accept="image/jpeg,image/png,image/jpg">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-primary" name="add_menu_item">Add Item</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Edit Menu Item Modal -->
  <div class="modal fade" id="editMenuItemModal" tabindex="-1" aria-labelledby="editMenuItemModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editMenuItemModalLabel">Edit Menu Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data">
          <div class="modal-body">
            <input type="hidden" id="editItemId" name="itemId">
            <input type="hidden" id="existingImage" name="existingImage">
            <div class="form-group mb-3">
              <label for="editItemName">Item Name</label>
              <input type="text" class="form-control" id="editItemName" name="itemName" placeholder="Enter item name" required>
            </div>
            <div class="form-group mb-3">
              <label for="editItemCategory">Category</label>
              <select class="form-control" id="editItemCategory" name="itemCategory" required>
                <option value="Pizzas">Pizzas</option>
                <option value="Sides">Sides</option>
                <option value="Drinks">Drinks</option>
                <option value="Desserts">Desserts</option>
              </select>
            </div>
            <div class="form-group mb-3">
              <label for="editItemDescription">Description</label>
              <textarea class="form-control" id="editItemDescription" name="itemDescription" placeholder="Enter description"></textarea>
            </div>
            <div class="form-group mb-3">
              <label for="editItemPrice">Price (PKR)</label>
              <input type="number" class="form-control" id="editItemPrice" name="itemPrice" step="0.01" placeholder="Enter price" required>
            </div>
            <div class="form-group mb-3">
              <label for="editItemImage">Image</label>
              <input type="file" class="form-control" id="editItemImage" name="itemImage" accept="image/jpeg,image/png,image/jpg">
              <small class="form-text text-muted">Leave blank to keep existing image.</small>
            </div>
            <div class="form-group mb-3">
              <label>Current Image</label><br>
              <img id="currentImage" src="" alt="Current Image" style="max-width: 100px; max-height: 100px; border-radius: 8px;">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-primary" name="edit_menu_item">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>

  <!-- Custom JavaScript -->
  <script>
    // Initialize page
    document.addEventListener('DOMContentLoaded', function() {
      // Toggle sidebar on mobile
      const sidebar = document.getElementById('sidebar');
      const sidebarToggle = document.getElementById('sidebarToggle');
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

      // Navbar scroll effect
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
    });

    // Populate edit form
    function populateEditForm(id, name, category, description, price, image) {
      document.getElementById('editItemId').value = id;
      document.getElementById('editItemName').value = name;
      document.getElementById('editItemCategory').value = category;
      document.getElementById('editItemDescription').value = description || '';
      document.getElementById('editItemPrice').value = price;
      document.getElementById('existingImage').value = image || '';
      const currentImage = document.getElementById('currentImage');
      currentImage.src = image || 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 180"><rect fill="%23ff6b35" width="300" height="180"/><circle fill="%23ffa726" cx="150" cy="90" r="50"/><text x="150" y="100" text-anchor="middle" fill="white" font-size="28">🍽️</text></svg>';
    }

    // Validate menu items before proceeding
    function validateMenuItems() {
      const menuItems = <?php echo json_encode($menu_items); ?>;
      if (menuItems.length === 0) {
        alert('Please add at least one menu item before proceeding.');
        return false;
      }
      return true;
    }
  </script>
</body>

</html>