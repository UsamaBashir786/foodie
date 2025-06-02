<?php
// Start the vendor session securely
session_name('vendor_session');
session_start();

// Database connection
$host = "localhost";
$db = "foodiehub";
$user = "root";
$pass = "";

try {
    $conn = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$error_message = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['register'])) {
    // Sanitize inputs
    $restaurant_name = htmlspecialchars(trim($_POST['restaurantName'] ?? ''));
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirmPassword'] ?? '';
    $category = htmlspecialchars(trim($_POST['category'] ?? ''));
    $contact_number = htmlspecialchars(trim($_POST['contactNumber'] ?? ''));
    $license = htmlspecialchars(trim($_POST['license'] ?? ''));

    // Validation
    if (!$restaurant_name || !$email || !$password || !$confirm_password || !$category || !$contact_number || !$license) {
        $error_message = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $error_message = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } elseif (!preg_match("/^\+?\d{10,15}$/", $contact_number)) {
        $error_message = "Please enter a valid contact number.";
    } else {
        try {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM vendors WHERE email = :email");
            $stmt->bindParam(':email', $email);
            $stmt->execute();

            if ($stmt->fetch()) {
                $error_message = "Email is already registered.";
            } else {
                // Begin transaction for atomic operations
                $conn->beginTransaction();

                // Insert new vendor with status 'pending'
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $status = 'pending'; // Set default status
                $stmt = $conn->prepare("
                    INSERT INTO vendors (restaurant_name, email, password, category, contact_number, status, license) 
                    VALUES (:restaurant_name, :email, :password, :category, :contact_number, :status, :license)
                ");
                $stmt->bindParam(':restaurant_name', $restaurant_name);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':password', $hashed_password);
                $stmt->bindParam(':category', $category);
                $stmt->bindParam(':contact_number', $contact_number);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':license', $license);
                $stmt->execute();

                // Get the newly inserted vendor ID
                $vendor_id = $conn->lastInsertId();

                // Handle image uploads
                if (!empty($_FILES['restaurantImages']['name'][0])) {
                    $upload_dir = 'Uploads/';
                    // Create Uploads directory if it doesn't exist
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                    $max_size = 5 * 1024 * 1024; // 5MB
                    $uploaded_images = [];

                    foreach ($_FILES['restaurantImages']['name'] as $key => $name) {
                        if ($_FILES['restaurantImages']['error'][$key] === UPLOAD_ERR_OK) {
                            $tmp_name = $_FILES['restaurantImages']['tmp_name'][$key];
                            $type = $_FILES['restaurantImages']['type'][$key];
                            $size = $_FILES['restaurantImages']['size'][$key];

                            // Validate file type and size
                            if (!in_array($type, $allowed_types)) {
                                throw new Exception("Invalid file type for image: $name. Only JPEG, PNG, and GIF are allowed.");
                            }
                            if ($size > $max_size) {
                                throw new Exception("File size for $name exceeds 5MB.");
                            }

                            // Generate unique filename
                            $ext = pathinfo($name, PATHINFO_EXTENSION);
                            $filename = 'vendor_' . $vendor_id . '_' . uniqid() . '.' . $ext;
                            $destination = $upload_dir . $filename;

                            // Move file to Uploads directory
                            if (move_uploaded_file($tmp_name, $destination)) {
                                $uploaded_images[] = $destination;

                                // Insert into vendor_images
                                $stmt = $conn->prepare("
                                    INSERT INTO vendor_images (vendor_id, image_path, uploaded_at)
                                    VALUES (:vendor_id, :image_path, NOW())
                                ");
                                $stmt->bindParam(':vendor_id', $vendor_id, PDO::PARAM_INT);
                                $stmt->bindParam(':image_path', $destination);
                                $stmt->execute();
                            } else {
                                throw new Exception("Failed to upload image: $name.");
                            }
                        }
                    }

                    if (empty($uploaded_images)) {
                        $error_message = "No valid images were uploaded.";
                        $conn->rollBack();
                    } else {
                        $conn->commit();
                        $success_message = "🎉 Registration successful! Images uploaded. Redirecting to login...";
                        header("refresh:2;url=vendor_login.php");
                    }
                } else {
                    // No images uploaded, still commit vendor registration
                    $conn->commit();
                    $success_message = "🎉 Registration successful! No images uploaded. Redirecting to login...";
                    header("refresh:2;url=vendor_login.php");
                }
            }
        } catch (Exception $e) {
            $conn->rollBack();
            $error_message = "Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Registration - FoodieHub</title>
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 2rem 0;
        }

        .register-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 2rem;
            width: 100%;
            max-width: 500px;
            transition: var(--transition);
        }

        .register-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .register-card h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .form-control {
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-300);
            padding: 0.75rem;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
        }

        .form-select {
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-300);
            padding: 0.75rem;
            transition: var(--transition);
        }

        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
        }

        .btn-register {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            color: var(--white);
            padding: 0.75rem;
            border-radius: 25px;
            font-weight: 500;
            width: 100%;
            transition: var(--transition);
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            color: var(--white);
        }

        .error-message {
            color: var(--danger-color);
            font-size: 0.9rem;
            margin-top: 0.5rem;
            text-align: center;
            <?php echo !empty($error_message) ? 'display: block;' : 'display: none;'; ?>
        }

        .success-message {
            color: var(--success-color);
            font-size: 0.9rem;
            margin-top: 0.5rem;
            text-align: center;
            <?php echo !empty($success_message) ? 'display: block;' : 'display: none;'; ?>
        }

        .login-link,
        .home-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .login-link:hover,
        .home-link:hover {
            color: var(--secondary-color);
            text-decoration: underline;
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

        @media (max-width: 576px) {
            .register-card {
                margin: 1rem;
                padding: 1.5rem;
            }
        }
    </style>
</head>

<body>
    <!-- Registration Form -->
    <div class="register-card fade-in">
        <h2><i class="fas fa-utensils me-2"></i>Vendor Registration</h2>
        <div id="errorMessage" class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
        <div id="successMessage" class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
        <form method="POST" action="" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="restaurantName" class="form-label">Restaurant Name</label>
                <input type="text" class="form-control" id="restaurantName" name="restaurantName" placeholder="Enter restaurant name" value="<?php echo isset($_POST['restaurantName']) ? htmlspecialchars($_POST['restaurantName']) : ''; ?>" required>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
            </div>
            <div class="mb-3">
                <label for="confirmPassword" class="form-label">Confirm Password</label>
                <input type="password" class="form-control" id="confirmPassword" name="confirmPassword" placeholder="Confirm your password" required>
            </div>
            <div class="mb-3">
                <label for="license" class="form-label">License Number</label>
                <input type="text" class="form-control" id="license" name="license" placeholder="Enter license number" value="<?php echo isset($_POST['license']) ? htmlspecialchars($_POST['license']) : ''; ?>" required>
            </div>
            <div class="mb-3">
                <label for="restaurantImages" class="form-label">Upload Restaurant Images (4-5)</label>
                <input type="file" class="form-control" name="restaurantImages[]" id="restaurantImages" multiple accept="image/jpeg,image/png,image/gif" required>
            </div>
            <div class="mb-3">
                <label for="category" class="form-label">Restaurant Category</label>
                <select class="form-select" id="category" name="category" required>
                    <option value="" disabled <?php echo !isset($_POST['category']) ? 'selected' : ''; ?>>Select category</option>
                    <option value="Pizza" <?php echo isset($_POST['category']) && $_POST['category'] == 'Pizza' ? 'selected' : ''; ?>>Pizza</option>
                    <option value="Burgers" <?php echo isset($_POST['category']) && $_POST['category'] == 'Burgers' ? 'selected' : ''; ?>>Burgers</option>
                    <option value="Sushi" <?php echo isset($_POST['category']) && $_POST['category'] == 'Sushi' ? 'selected' : ''; ?>>Sushi</option>
                    <option value="Italian" <?php echo isset($_POST['category']) && $_POST['category'] == 'Italian' ? 'selected' : ''; ?>>Italian</option>
                    <option value="Desserts" <?php echo isset($_POST['category']) && $_POST['category'] == 'Desserts' ? 'selected' : ''; ?>>Desserts</option>
                    <option value="Fast Food" <?php echo isset($_POST['category']) && $_POST['category'] == 'Fast Food' ? 'selected' : ''; ?>>Fast Food</option>
                </select>
            </div>
            <div class="mb-3">
                <label for="contactNumber" class="form-label">Contact Number</label>
                <input type="tel" class="form-control" id="contactNumber" name="contactNumber" placeholder="Enter contact number" value="<?php echo isset($_POST['contactNumber']) ? htmlspecialchars($_POST['contactNumber']) : ''; ?>" required>
            </div>
            <button type="submit" name="register" class="btn btn-register">Register</button>
        </form>
        <div class="text-center mt-3">
            <p>Already have an account? <a href="vendor_login.php" class="login-link">Login here</a></p>
            <p><a href="index.php" class="home-link">Back to Home</a></p>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>

    <!-- Custom JavaScript -->
    <script>
        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Fade-in animation
            const registerCard = document.querySelector('.register-card');
            registerCard.classList.add('visible');

            // Allow registration on Enter key
            document.getElementById('confirmPassword').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    document.querySelector('form').submit();
                }
            });
        });
    </script>
</body>

</html>