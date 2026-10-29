<?php
include("config.php");
http_response_code(405);
header('Allow: POST');
exit('Super Admin accounts cannot be deleted from the application.');
$aid = $_GET['id'];
$sql = "DELETE FROM admin WHERE aid = {$aid}";
$result = mysqli_query($con, $sql);
if($result == true)
{
	$msg="<p class='alert alert-success'>Admin Deleted</p>";
	header("Location:adminlist.php?msg=$msg");
}
else{
	$msg="<p class='alert alert-warning'>Admin Not Deleted</p>";
	header("Location:adminlist.php?msg=$msg");
}
mysqli_close($con);
?>
