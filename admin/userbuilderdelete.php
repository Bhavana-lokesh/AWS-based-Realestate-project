<?php
include("config.php");
http_response_code(410);
header('Location: userlist.php');
exit('Seller accounts are managed as Agent accounts; no user data was deleted.');

// Sanitize input
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$msg = "";

// ✅ Ensure only Seller gets deleted
$sql = "DELETE FROM user WHERE user_id = $user_id AND role = 'Seller'";
$result = mysqli_query($con, $sql);

if ($result && mysqli_affected_rows($con) > 0) {
    $msg = "<p class='alert alert-success'>Seller Deleted Successfully.</p>";
} else {
    $msg = "<p class='alert alert-warning'>Seller Not Deleted or User Not Found.</p>";
}

// ✅ Redirect back to the Seller list page
header("Location: userseller.php?msg=" . urlencode($msg));
mysqli_close($con);
exit();
?>
