<?php
include 'config/dbconfig.php';
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: http://localhost:3000"); // Allow only your React app
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE"); // Allow HTTP methods
header("Access-Control-Allow-Headers: Content-Type, Authorization"); // Allow headers
header("Access-Control-Allow-Credentials: true"); // If needed for cookies/auth

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');


if (isset($obj->phone_number) && isset($obj->password) && isset($obj->company_id)) {

    $phone_number = $obj->phone_number;
    $password = $obj->password;
    $company_id = $obj->company_id;

    if (!empty($phone_number) && !empty($password) && !empty($company_id)) {

        if (numericCheck($phone_number) && strlen($phone_number) == 10) {

            // <<<<<<<<<<===================== Checking the user table =====================>>>>>>>>>>
            $result = $conn->query("SELECT * FROM `users` WHERE `phone_number`='$phone_number' AND `delete_at` = 0");
            if ($result->num_rows > 0) {
                if ($row = $result->fetch_assoc()) {

                    if ($row['password'] == $password) {
                        $output["head"]["code"] = 200;
                        $output["head"]["msg"] = "Success";
                        $output["body"]["data"] = $row;
                    } else {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = "Invalid Credentials";
                    }
                }
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "User not found";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Invalid Mobile Number.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
} else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter is Mismatch";
}


echo json_encode($output, JSON_NUMERIC_CHECK);
