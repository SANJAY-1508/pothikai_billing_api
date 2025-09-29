<?php
include 'config/dbconfig.php';
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json, true);
$output = [];
$compID = $_GET['id'] ?? null;
date_default_timezone_set('Asia/Calcutta');

function fetchQuery($conn, $sql, $params)
{
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param(str_repeat("s", count($params)), ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// List Expenses Categories
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    $search_text = $obj['search_text'];
    $sql = "SELECT category_id, category_name FROM expenses_category WHERE delete_at = '0' AND company_id = ? AND category_name LIKE ?";
    $categories = fetchQuery($conn, $sql, [$compID, "%$search_text%"]);

    if ($categories) {
        $output['status'] = 200;
        $output['msg'] = 'Success';
        $output['data'] = $categories;
    } else {
        $output['status'] = 400;
        $output['msg'] = 'No Records Found';
    }
}

// Create Expenses Category
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['category_name'])) {
    $category_name = $obj['category_name'];

    if (empty($category_name)) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    } else {
        $sql = "INSERT INTO expenses_category (company_id, category_name, delete_at) VALUES (?, ?, '0')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $compID, $category_name);

        if ($stmt->execute()) {
            $insertId = $conn->insert_id;
            $uniqueID = uniqueID("expenses_category", $insertId);
            $sqlUpdate = "UPDATE expenses_category SET category_id = ? WHERE id = ? AND company_id = ?";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->bind_param('sis', $uniqueID, $insertId, $compID);

            if ($stmtUpdate->execute()) {
                $output['status'] = 200;
                $output['msg'] = 'Expenses Category Created Successfully';
                $output['data'] = ['category_id' => $uniqueID];
            } else {
                $output['status'] = 400;
                $output['msg'] = 'Error updating category';
            }
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error creating category';
        }
    }
}

// Update Expenses Category
else if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    $category_id = $obj['category_id'] ?? null;
    $category_name = $obj['category_name'] ?? null;

    if (empty($category_id) || empty($category_name)) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    } else {
        $sql = "UPDATE expenses_category SET category_name = ? WHERE category_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sss', $category_name, $category_id, $compID);

        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = 'Expenses Category Updated Successfully';
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error updating category';
        }
    }
}

// Delete Expenses Category
else if ($_SERVER['REQUEST_METHOD'] == 'DELETE' && isset($obj['delete_category_id'])) {
    $category_id = $obj['delete_category_id'] ?? null;

    if (empty($category_id)) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    } else {
        $sql = "UPDATE expenses_category SET delete_at = '1' WHERE category_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $category_id, $compID);

        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = 'Expenses Category Deleted Successfully';
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error deleting category';
        }
    }
} else {
    $output['status'] = 400;
    $output['msg'] = 'Invalid request';
}

echo json_encode($output, JSON_NUMERIC_CHECK);
