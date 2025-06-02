<?php
 
// Database connection
$host = "localhost";
$db = "foodiehub";
$user = "root";
$pass = "";

try {
    $conn = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo '<div class="alert alert-danger text-center">Connection failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit();
}

// Handle accept/reject actions
$success_message = '';
$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['accept'])) {
        $vendor_id = $_POST['vendor_id'];
        try {
            $stmt = $conn->prepare("UPDATE vendors SET status = 'active' WHERE id = :vendor_id");
            $stmt->bindParam(':vendor_id', $vendor_id, PDO::PARAM_INT);
            $stmt->execute();
            $success_message = "Vendor ID $vendor_id approved successfully.";
        } catch (PDOException $e) {
            $error_message = "Error approving vendor: " . htmlspecialchars($e->getMessage());
        }
    } elseif (isset($_POST['reject'])) {
        $vendor_id = $_POST['vendor_id'];
        try {
            $stmt = $conn->prepare("UPDATE vendors SET status = 'rejected' WHERE id = :vendor_id");
            $stmt->bindParam(':vendor_id', $vendor_id, PDO::PARAM_INT);
            $stmt->execute();
            $success_message = "Vendor ID $vendor_id rejected successfully.";
        } catch (PDOException $e) {
            $error_message = "Error rejecting vendor: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Fetch all vendors
try {
    $stmt = $conn->prepare("SELECT id, restaurant_name, email, category, contact_number, status, created_at, license FROM vendors ORDER BY created_at DESC");
    $stmt->execute();
    $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching vendors: " . htmlspecialchars($e->getMessage());
}
?>

<!-- Vendor Management Section -->
<div class="admin-card">
    <h2><i class="fas fa-utensils me-2"></i>Manage Vendors</h2>
    <?php if ($success_message): ?>
        <div class="alert alert-success text-center"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-danger text-center"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Restaurant Name</th>
                    <th>Email</th>
                    <th>Category</th>
                    <th>Contact Number</th>
                    <th>License Number</th>
                    <th>Status</th>
                    <th>Registered At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendors)): ?>
                    <tr>
                        <td colspan="9" class="text-center">No vendors found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($vendors as $vendor): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($vendor['id']); ?></td>
                            <td><?php echo htmlspecialchars($vendor['restaurant_name']); ?></td>
                            <td><?php echo htmlspecialchars($vendor['email']); ?></td>
                            <td><?php echo htmlspecialchars($vendor['category']); ?></td>
                            <td><?php echo htmlspecialchars($vendor['contact_number']); ?></td>
                            <td><?php echo htmlspecialchars($vendor['license']); ?></td>
                            <td class="status-<?php echo strtolower($vendor['status']); ?>">
                                <?php echo htmlspecialchars(ucfirst($vendor['status'])); ?>
                            </td>
                            <td><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($vendor['created_at']))); ?></td>
                            <td>
                                <?php if ($vendor['status'] === 'pending'): ?>
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="vendor_id" value="<?php echo htmlspecialchars($vendor['id']); ?>">
                                        <button type="submit" name="accept" class="btn btn-approve btn-sm">
                                            <i class="fas fa-check me-1"></i>Accept
                                        </button>
                                    </form>
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="vendor_id" value="<?php echo htmlspecialchars($vendor['id']); ?>">
                                        <button type="submit" name="reject" class="btn btn-reject btn-sm ms-1">
                                            <i class="fas fa-times me-1"></i>Reject
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted"><?php echo ucfirst($vendor['status']); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
    .admin-card {
        background: var(--white);
        border-radius: var(--border-radius);
        box-shadow: var(--shadow);
        padding: 2rem;
        margin: 0 auto;
        max-width: 1200px;
    }

    .admin-card h2 {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--dark-color);
        margin-bottom: 1.5rem;
        text-align: center;
    }

    .table {
        border-radius: var(--border-radius);
        overflow: hidden;
    }

    .table th {
        background: var(--primary-color);
        color: var(--white);
        font-weight: 500;
    }

    .table td {
        vertical-align: middle;
    }

    .btn-approve {
        background: var(--success-color);
        border: none;
        color: var(--white);
        padding: 0.5rem 1rem;
        border-radius: var(--border-radius);
        transition: var(--transition);
    }

    .btn-approve:hover {
        background: var(--accent-color);
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    .btn-reject {
        background: var(--danger-color);
        border: none;
        color: var(--white);
        padding: 0.5rem 1rem;
        border-radius: var(--border-radius);
        transition: var(--transition);
    }

    .btn-reject:hover {
        background: #c0392b;
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    .status-pending {
        color: var(--warning-color);
        font-weight: 500;
    }

    .status-active {
        color: var(--success-color);
        font-weight: 500;
    }

    .status-rejected {
        color: var(--danger-color);
        font-weight: 500;
    }
</style>