<?php
include 'config/dbconfig.php';
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json, true);
$output = array();
$compID = $_GET['id'];
date_default_timezone_set('Asia/Calcutta');

function callRemoteApi($url, $data)
{
    $curl = curl_init($url);

    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($curl);

    if (curl_errno($curl)) {
        $error_msg = curl_error($curl);
        curl_close($curl);
        return ['success' => false, 'error' => $error_msg];
    }

    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($http_status == 200) {
        return ['success' => true, 'data' => $response];
    } else {
        return ['success' => false, 'error' => "HTTP Status: $http_status, Response: $response"];
    }
}

function getRemoteProductData($url, $payload)
{
    $curl = curl_init($url);

    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($curl);

    if (curl_errno($curl)) {
        error_log("cURL error while fetching product data: " . curl_error($curl));
        curl_close($curl);
        return null;
    }

    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($http_status === 200) {
        return json_decode($response, true);
    } else {
        error_log("Remote product API returned HTTP $http_status. Response: $response");
        return null;
    }
}

function fetchQuery($conn, $sql, $params) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false; // Return false if prepare fails
    }

    // Bind parameters if any
    if ($params) {
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    }

    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        return false; // Return false if execute fails
    }

    $result = $stmt->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : []; // Return results or an empty array
}


// List Purchase Bills
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    try {
        $search_text = $obj['search_text'] ?? null;
        $party_id = $obj['party_id'] ?? null;
        $from_date = $obj['from_date'] ?? null;
        $to_date = $obj['to_date'] ?? null;

        $conditions = ["delete_at = '0'", "company_id = ?"];
        $parameters = [$compID];

        if ($search_text) {
            $conditions[] = "(JSON_EXTRACT(party_details, '$.party_name') LIKE ? OR bill_no LIKE ?)";
            $parameters[] = "%$search_text%";
            $parameters[] = "%$search_text%";
        }

        if ($party_id) {
            $conditions[] = "party_id = ?";
            $parameters[] = $party_id;
        }

        if ($from_date && $to_date) {
            $conditions[] = "bill_date BETWEEN ? AND ?";
            $parameters[] = $from_date;
            $parameters[] = $to_date;
        }

        $sql = "SELECT party_id, purchase_id, party_details, bill_no, 
                    DATE_FORMAT(bill_date, '%Y-%m-%d') as bill_date, 
                    DATE_FORMAT(stock_date, '%Y-%m-%d') as stock_date,
                    sum_total, product, purchase_gst, purchasemobile_no,
                    total, paid, balance, company_details,created_date
                FROM purchase WHERE " . implode(' AND ', $conditions);

        $result = fetchQuery($conn, $sql, $parameters);

        if ($result) {
            foreach ($result as &$row) {
                $row['bill_date'] = date('d-m-Y', strtotime($row['bill_date']));
                $row['stock_date'] = date('d-m-Y', strtotime($row['stock_date']));
                $row['party_details'] = json_decode($row['party_details'], true);
                $row['product'] = json_decode($row['product'], true);
                $row['company_details'] = json_decode($row['company_details'], true);
            }

            $output = [
                'status' => 200,
                'msg' => 'Success',
                'data' => $result
            ];
        } else {
            $output = ['status' => 400, 'msg' => 'No records found'];
        }

    } catch (Exception $e) {
        $output = ['status' => 500, 'msg' => 'Internal Server Error'];
    }


}
else if ($_SERVER['REQUEST_METHOD'] == 'PUT' && isset($obj['purchase_id'])) {
    try {
        $purchase_id = $obj['purchase_id'];
        $party_id = $obj['party_id'];
        $bill_date = $obj['bill_date'];
        $stock_date = $obj['stock_date'];
        $sum_total = 0;
        $product = $obj['product'];
        $purchase_gst = $obj['purchase_gst'];
        $purchasemobile_no = $obj['purchasemobile_no'];
        $total = $obj['total'];
        $paid = $obj['paid'];
        $balance = isset($obj['balance']) ? $obj['balance'] : 0;
        

        if (!$purchase_id || !$party_id || !$bill_date || !$stock_date || !$product || !$total || !$paid || !$balance) {
            $output = ['status' => 400, 'msg' => 'Parameter Mismatch'];
           echo json_encode($output);
            exit;
        }

        // Fetch party details
        $sqlparty = "SELECT * FROM purchase_party WHERE party_id = ? AND company_id = ?";
        $party_details = fetchQuery($conn, $sqlparty, [$party_id, $compID]);

        if ($party_details) {
            foreach ($product as &$item) {
                $item['without_tax_amount'] = ($item['qty'] * $item['price_unit']) - ($item['discount_amt'] ?? 0);
                $sum_total += $item['without_tax_amount'];

                // Fetch product details
                // $sqlProduct = "SELECT * FROM product WHERE product_id = ? AND company_id = ?";
                // $productData = fetchQuery($conn, $sqlProduct, [$item['product_id'], $compID]);

                // if ($productData) {
                //     $item['hsn_no'] = $productData[0]['hsn_no'];
                //     $item['item_code'] = $productData[0]['item_code'];
                //     $item['product_name'] = $productData[0]['product_name'];
                // } else {
                //     $output = ['status' => 400, 'msg' => 'Product Details Not Found'];
                // echo json_encode($output);
                // exit;
                // }
            }

            // Update purchase bill
            $party_details_json = json_encode($party_details[0]);
            $product_json = json_encode($product);

            $sql = "UPDATE purchase SET party_id = ?, party_details = ?, bill_date = ?, stock_date = ?, total = ?, paid = ?, balance = ?, sum_total = ?, product = ?, purchase_gst = ?, purchasemobile_no = ? 
                    WHERE purchase_id = ? AND company_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sssssssssssss', $party_id, $party_details_json, $bill_date, $stock_date, $total, $paid, $balance, $sum_total, $product_json, $purchase_gst, $purchasemobile_no, $purchase_id, $compID);

            if ($stmt->execute()) {
                $output = ['status' => 200, 'msg' => 'Purchase Bill Updated Successfully'];
            } else {
                $output = ['status' => 400, 'msg' => 'Failed to Update Purchase Bill'];
            }

        } else {
            $output = ['status' => 400, 'msg' => 'Party Details Not Found'];
        }



    } catch (Exception $e) {
        $output = ['status' => 500, 'msg' => 'Internal Server Error'];

    }
}
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['party_id'])) {
    try {
        $party_id = $obj['party_id'];
        $bill_no = $obj['bill_no'];
        $bill_date = $obj['bill_date'];
        $stock_date = $obj['stock_date'];
        $sum_total = 0;
        $product = $obj['product'];
        $purchase_gst = $obj['purchase_gst'];
        $purchasemobile_no = $obj['purchasemobile_no'];
        $total = $obj['total'];
      $paid = isset($obj['paid']) && $obj['paid'] !== '' ? $obj['paid'] : 0;
        $balance = isset($obj['balance_amount']) ? $obj['balance_amount'] : 0;
        $delete_at = 0;

        // Validate required parameters
        if (!$party_id || !$bill_no || !$bill_date || !$product || !$total) {
            $output = ['status' => 400, 'msg' => 'Parameter Mismatch'];
            echo json_encode($output);
            exit;
        }

        // Check party details
        $sqlparty = "SELECT * FROM purchase_party WHERE party_id = ? AND company_id = ?";
        $party_details = fetchQuery($conn, $sqlparty, [$party_id, $compID]);

        if ($party_details) {
            foreach ($product as &$item) {
                $item['without_tax_amount'] = ($item['qty'] * $item['price_unit']) - ($item['discount_amt'] ?? 0);
                $sum_total += $item['without_tax_amount'];

              $remoteApiUrl = "https://pothigaicrackers.com/api/get_product_details.php";

// Prepare payload
$payload = [
    'product_id' => $item['product_id'],
    'company_id' => $compID
];

// Call remote API
$productData = getRemoteProductData($remoteApiUrl, $payload);

//var_dump($productData);

                if ($productData && isset($productData['status']) && $productData['status'] === 200 && isset($productData['data'])) {
                    
    $remoteProduct = $productData['data'];

    $item['hsn_no'] = $remoteProduct['hsn_no'];
    $item['item_code'] = $remoteProduct['product_code'];
    $item['product_name'] = $remoteProduct['product_name'];

    $quantity = (int)$item['qty'];
    $unit_id = $item['unit'];
    $db_unit_id = $remoteProduct['unit_id'];

    $new_stock = (int)$remoteProduct['crt_stock'] + $quantity;

    if (isset($item['subunit_id']) && $item['subunit_id'] !== null && $item['subunit_id'] !== "") {
        if ($unit_id !== $db_unit_id) {
            // Implement conversion logic if needed
            $new_stock = (int)$remoteProduct['crt_stock'] + $quantity;
        }
    }

            // Prepare payload for remote server
            $remotePayload = [
                'product_id' => $item['product_id'],
                'company_id' => $compID,
                'crt_stock' => $new_stock,
                'bill_no' => $bill_no,
                'quantity' => $quantity,
                'action' => 'STACKIN',
                'bill_date' => $bill_date
            ];

            // Call remote API to update stock
            $remoteApiUrl = "https://pothigaicrackers.com/api/update_product_stock.php";
            $apiResponse = callRemoteApi($remoteApiUrl, $remotePayload);

            if (!$apiResponse['success']) {
                // Log error or take corrective action
                error_log("Remote stock update failed for product_id: " . $item['product_id'] . " — " . $apiResponse['error']);
            }
                    // Update the product stock in the database
                    // $updateStockSql = "UPDATE product SET crt_stock = ? WHERE product_id = ? AND company_id = ?";
                    // fetchQuery($conn, $updateStockSql, [$new_stock, $item['product_id'], $compID]);

                    // Log stock history
                    $stockSql = "INSERT INTO stock_history (stock_type, bill_no, product_id, product_name, quantity, company_id, bill_id, bill_date, delete_at) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, '0')";
                    fetchQuery($conn, $stockSql, ['STACKIN', $bill_no, $item['product_id'], $item['product_name'], $quantity, $compID, 'UNIQUE_BILL_ID', $bill_date]);
                } else {
                    $output = ['status' => 400, 'msg' => 'Product Details Not Found'];
                  
                }
            }

            // Insert purchase bill
            $party_details_json = json_encode($party_details[0]);
            $product_json = json_encode($product);
            $company_details_json = json_encode(fetchQuery($conn, "SELECT * FROM company WHERE company_id = ?", [$compID])[0]);

            $sql = "INSERT INTO purchase (company_id, party_id, party_details, bill_date, stock_date, bill_no, total, paid, balance, sum_total, product, purchase_gst, purchasemobile_no, company_details, delete_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sssssssssssssss', $compID, $party_id, $party_details_json, $bill_date, $stock_date, $bill_no, $total, $paid, $balance, $sum_total, $product_json, $purchase_gst, $purchasemobile_no, $company_details_json, $delete_at);

            if ($stmt->execute()) {
                $insertId = $conn->insert_id;
                $uniqueID = uniqueID("purchase", $insertId);
                $sqlUpdate = "UPDATE purchase SET purchase_id = ? WHERE id = ? AND company_id = ?";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->bind_param('sis', $uniqueID, $insertId, $compID);

                if ($stmtUpdate->execute()) {
                    $output = ['status' => 200, 'msg' => 'Purchase Bill Created Successfully', 'data' => ['invoice_id' => $uniqueID]];
                } else {
                    $output = ['status' => 400, 'msg' => 'Error updating purchase ID'];
                }
            } else {
                $output = ['status' => 400, 'msg' => 'Error creating purchase bill'];
            }
        } else {
            $output = ['status' => 400, 'msg' => 'Party Details Not Found'];
        }

       
    } catch (Exception $e) {
        $output = ['status' => 500, 'msg' => 'Internal Server Error: ' . $e->getMessage()];
       
    }
}


// Delete Purchase Bill (Soft delete)
else if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $purchase_id = $obj['purchase_id'];

    if (!$purchase_id) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    } else {
        $sql = "UPDATE purchase SET delete_at = '1' WHERE purchase_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $purchase_id, $compID);

        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = 'purchase Deleted Successfully';
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error deleting purchase';
        }
    }
} else {
    $output = ['status' => 400, 'msg' => 'Parameter Mismatch'];
}
echo json_encode($output);
?>