<?php
include("config.php");
http_response_code(405);
header('Allow: POST');
exit('User accounts must be deactivated, not deleted.');

$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// ❌ If no image field in schema, skip this section
// If you DO have an image field, uncomment and adjust this block:
/*
$sql = "SELECT image FROM user WHERE user_id = $user_id";
$result = mysqli_query($con, $sql);
if ($row = mysqli_fetch_assoc($result)) {
    $img = $row['image'];
    if (!empty($img)) {
        @unlink("user/" . $img);
    }
}
*/

$msg = "";

// ✅ Delete agent by user_id
$sql = "DELETE FROM user WHERE user_id = $user_id AND role = 'agent'";
$result = mysqli_query($con, $sql);

if ($result === true) {
    $msg = "<p class='alert alert-success'>Agent Deleted</p>";
} else {
    $msg = "<p class='alert alert-warning'>Agent Not Deleted</p>";
}

header("Location: useragent.php?msg=" . urlencode($msg));
mysqli_close($con);
exit();
?>
