<?php
session_start();
unset($_SESSION['staff_id']);
unset($_SESSION['staff_name']);
unset($_SESSION['company_id']);
unset($_SESSION['company_name']);
unset($_SESSION['user_type']);

header("Location: ../auth/staff_login.php");
exit();
?>
