<?php
require_once '../config/db.php';

// Fetch contact messages
$query = "SELECT id, first_name, last_name, email, phone, subject, message, created_at FROM contact_messages ORDER BY created_at DESC";
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .table-responsive {
      margin: 20px 0;
    }

    .table th,
    .table td {
      vertical-align: middle;
    }

    .table th {
      background-color: #f8f9fa;
    }
  </style>
</head>

<body>
  <div class="table-responsive">
    <h3>Contact Messages</h3>
    <table class="table table-striped table-bordered">
      <thead>
        <tr>
          <th>ID</th>
          <th>First Name</th>
          <th>Last Name</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Subject</th>
          <th>Message</th>
          <th>Created At</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($result && $result->num_rows > 0): ?>
          <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
              <td><?php echo htmlspecialchars($row['id']); ?></td>
              <td><?php echo htmlspecialchars($row['first_name']); ?></td>
              <td><?php echo htmlspecialchars($row['last_name']); ?></td>
              <td><?php echo htmlspecialchars($row['email']); ?></td>
              <td><?php echo htmlspecialchars($row['phone'] ?? 'N/A'); ?></td>
              <td><?php echo htmlspecialchars($row['subject']); ?></td>
              <td><?php echo htmlspecialchars($row['message']); ?></td>
              <td><?php echo htmlspecialchars($row['created_at']); ?></td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr>
            <td colspan="8" class="text-center">No contact messages found.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</body>

</html>