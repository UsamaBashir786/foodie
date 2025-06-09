<?php
include 'config/db.php';
$vendor_id = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
$slotQuery = "SELECT id, slot_date, slot_time, capacity 
              FROM reservation_slots 
              WHERE vendor_id = ? AND status = 'available' 
              AND slot_date >= CURDATE() 
              ORDER BY slot_date, slot_time";
$stmt = mysqli_prepare($conn, $slotQuery);
mysqli_stmt_bind_param($stmt, 'i', $vendor_id);
mysqli_stmt_execute($stmt);
$slotResult = mysqli_stmt_get_result($stmt);
$slots = [];
while ($slot = mysqli_fetch_assoc($slotResult)) {
  $slots[] = [
    'id' => $slot['id'],
    'formatted_date' => date('d M Y', strtotime($slot['slot_date'])),
    'formatted_time' => date('h:i A', strtotime($slot['slot_time'])),
    'capacity' => $slot['capacity']
  ];
}
header('Content-Type: application/json');
echo json_encode($slots);
