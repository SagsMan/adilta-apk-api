<?php
$conn = mysqli_connect("localhost", "adiliqgs_adildata", "adildata2026", "adiliqgs_adildata");
if (!$conn) {
    http_response_code(503);
    die(json_encode(["success" => false, "message" => "Database connection failed"]));
}
?>
