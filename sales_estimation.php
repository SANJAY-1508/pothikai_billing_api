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

function fetchQuery($conn, $sql, $params) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
    }

    if ($params) {
        $stmt->bind_param(str_repeat("s", count($params)), ...$params);
    }

    if (!$stmt->execute()) {
        die("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
    }

    $result = $stmt->get_result();
    if (!$result) {
        error_log("Fetch failed: (" . $stmt->errno . ") " . $stmt->error);
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

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

// List Sales Estimations
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    $search_text = $obj['search_text'];
    $from_Date = $obj['from_date'];
    $to_date = $obj['to_date'];

    $sql = "SELECT *
            FROM sales_estimation 
            WHERE delete_at = '0' 
            AND company_id = ? 
            AND ((bill_date BETWEEN ? AND ?) 
            OR party_name LIKE ? 
            OR bill_no LIKE ?) 
            ORDER BY id DESC";

    $params = [$compID, $from_Date, $to_date, "%$search_text%", "%$search_text%"];
    $estimations = fetchQuery($conn, $sql, $params);

    if (count($estimations) > 0) {
        foreach ($estimations as &$estimation) {
            $estimation['bill_date'] = date('Y-m-d', strtotime($estimation['bill_date']));
            $estimation['party_name'] = $estimation['party_name'];
            $estimation['product'] = json_decode($estimation['product'], true);
            $estimation['company_details'] = json_decode($estimation['company_details'], true);
            $estimation['payment_method'] = json_decode($estimation['payment_method'], true);
        }

        $output['status'] = 200;
        $output['msg'] = 'Success';
        $output['data'] = $estimations;
    } else {
        $output['status'] = 400;
        $output['msg'] = 'No Records Found';
    }
}

// Create Sales Estimation
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['party_name'])) {
    $party_name = $obj['party_name'];
    $bill_date = $obj['bill_date'];
    $eway_no = $obj['eway_no'] ?? '';
    $vechile_no = $obj['vechile_no'] ?? ''; // Fixed typo
    $address = $obj['address'] ?? '';
    $product = $obj['product'];
    $total = $obj['total'];
    $sub_total = $obj['sub_total'] ?? 0;
    $paid = isset($obj['paid']) && $obj['paid'] !== '' ? $obj['paid'] : 0;
    $balance = isset($obj['balance']) && $obj['balance'] !== '' ? $obj['balance'] : 0;
    $mobile_number = $obj['mobile_number'] ?? '';
    $state_of_supply = $obj['state_of_supply'] ?? '';
    $payment_method = $obj['payment_method'] ?? [];
    $remark = isset($obj['remark']) && $obj['remark'] !== '' ? $obj['remark'] : '';
    $discount = isset($obj['discount']) && $obj['discount'] !== '' ? $obj['discount'] : 0;
    $discount_amount = isset($obj['discount_amount']) && $obj['discount_amount'] !== '' ? $obj['discount_amount'] : 0;
    $discount_type = $obj['discount_type'] ?? '';
    $round_off = isset($obj['round_off']) && $obj['round_off'] !== '' ? $obj['round_off'] : 0;
    $payment_method_json = json_encode($payment_method, JSON_UNESCAPED_UNICODE);
    $sum_total = 0;

    try {
        // Parameter validation
        if (!$party_name || !$bill_date || !$product || !isset($total)) {
            file_put_contents("debug_log.txt", "Parameter mismatch: party_name: $party_name, bill_date: $bill_date, product: " . print_r($product, true) . ", total: $total", FILE_APPEND);
            echo json_encode([
                'status' => 400,
                'msg' => "Parameter Mismatch",
            ]);
            exit();
        }

        // Get company details
        $companyDetailsSQL = "SELECT * FROM company WHERE company_id = ?";
        $companyDetailsresult = fetchQuery($conn, $companyDetailsSQL, [$compID]);

        if (empty($companyDetailsresult)) {
            file_put_contents("debug_log.txt", "Company details not found for company_id: $compID", FILE_APPEND);
            echo json_encode(['status' => 400, 'msg' => 'Company Details Not Found']);
            exit();
        }

        // Calculate sum_total
        foreach ($product as &$element) {
            $qty = floatval($element['qty']);
            $price = floatval($element['price_unit']);
            $product_discount_amt = !empty($element['discount_amt']) ? floatval($element['discount_amt']) : 0;
            $element['without_tax_amount'] = ($qty * $price) - $product_discount_amt;
            $sum_total += $element['without_tax_amount'];

            // Fetch unit name
            $sqlunit = "SELECT unit_name FROM unit WHERE unit_id = ? AND delete_at = 0";
            $unitData = fetchQuery($conn, $sqlunit, [$element['unit']]);
            if (!empty($unitData)) {
                $element['unit_name'] = $unitData[0]['unit_name'];
            }
        }

        $companyData = json_encode($companyDetailsresult[0], JSON_UNESCAPED_UNICODE);
        $product_json = json_encode($product, JSON_UNESCAPED_UNICODE);
        $billDate = date('Y-m-d', strtotime($bill_date));

        // Insert estimation into database
        $sqlEstimation = "INSERT INTO sales_estimation (company_id, party_name, bill_date, product, sub_total, discount,discount_amount, discount_type, total, sum_total, round_off, paid, balance, delete_at, eway_no, vechile_no, address, mobile_number, company_details, state_of_supply, remark, payment_method, created_date) 
                         VALUES (?, ?, ?, ?, ?, ?, ?,?, ?, ?, ?, ?, ?, '0', ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $conn->prepare($sqlEstimation);
        $stmt->bind_param(
            "ssssdsdsdddddssssssss",
            $compID,
            $party_name,
            $billDate,
            $product_json,
            $sub_total,
            $discount,
            $discount_amount,
            $discount_type,
            $total,
            $sum_total,
            $round_off,
            $paid,
            $balance,
            $eway_no,
            $vechile_no, // Fixed typo
            $address,
            $mobile_number,
            $companyData,
            $state_of_supply,
            $remark,
            $payment_method_json
        );

        if ($stmt->execute()) {
            $id = $conn->insert_id;
            file_put_contents("debug_log.txt", "Estimation created successfully. Estimation ID: $id", FILE_APPEND);

            // Generate unique estimation ID
            $uniqueID = uniqueID("estimation", $id);

            // Fetch the last bill number
            $lastBillSql = "SELECT bill_no FROM sales_estimation WHERE company_id = ? AND bill_no IS NOT NULL ORDER BY id DESC LIMIT 1";
            $resultLastBill = fetchQuery($conn, $lastBillSql, [$compID]);

            // Fetch bill prefix
            $billPrefixSql = "SELECT bill_prefix FROM company WHERE company_id = ?";
            $resultBillPrefix = fetchQuery($conn, $billPrefixSql, [$compID]);

            // Determine fiscal year
            $year = date('y');
            $fiscal_year = ($year . '-' . ($year + 1));
            $billcount = 1;

            if (!empty($resultLastBill[0]['bill_no'])) {
                preg_match('/\/(\d+)\/\d{2}-\d{2}$/', $resultLastBill[0]['bill_no'], $matches);
                if (isset($matches[1])) {
                    $billcount = (int) $matches[1] + 1;
                }
            }

            $billcountFormatted = str_pad($billcount, 3, '0', STR_PAD_LEFT);
            $bill_no = $resultBillPrefix[0]['bill_prefix'] . '/' . $billcountFormatted . '/' . $fiscal_year;

            // Update estimation with generated ID and bill number
            $sqlUpdate = "UPDATE sales_estimation SET sales_estimation_id = ?, bill_no = ? WHERE id = ? AND company_id = ?";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->bind_param("ssis", $uniqueID, $bill_no, $id, $compID);

            if ($stmtUpdate->execute()) {
                echo json_encode([
                    'status' => 200,
                    'msg' => 'Sales Estimation Created Successfully',
                    'data' => ['sales_estimation_id' => $uniqueID]
                ]);
            } else {
                file_put_contents("debug_log.txt", "Estimation update failed: " . $stmtUpdate->error, FILE_APPEND);
                echo json_encode(['status' => 400, 'msg' => 'Estimation Update Failed']);
            }
            $stmtUpdate->close();
        } else {
            file_put_contents("debug_log.txt", "Estimation creation failed: " . $stmt->error, FILE_APPEND);
            echo json_encode(['status' => 400, 'msg' => 'Estimation Creation Failed: ' . $stmt->error]);
        }
        $stmt->close();
        exit();
    } catch (Exception $error) {
        file_put_contents("debug_log.txt", "Error caught: " . $error->getMessage(), FILE_APPEND);
        echo json_encode([
            'status' => 500,
            'msg' => 'An error occurred: ' . $error->getMessage(),
        ]);
        exit();
    }
}

// Update Sales Estimation
else if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    $sales_estimation_id = $obj['sales_estimation_id'] ?? null;
    $party_name = $obj['party_name'] ?? null;
    $company_details = $obj['company_details'] ?? null;
    $bill_date = $obj['bill_date'] ?? null;
    $product = $obj['product'] ?? null;
    $eway_no = $obj['eway_no'] ?? '';
    $vechile_no = $obj['vechile_no'] ?? ''; 
    $address = $obj['address'] ?? '';
    $mobile_number = $obj['mobile_number'] ?? '';
    $total = $obj['total'] ?? null;
    $sub_total = $obj['sub_total'] ?? 0;
    $sum_total = 0;
    $paid = isset($obj['paid']) && $obj['paid'] !== '' ? $obj['paid'] : 0;
    $balance = isset($obj['balance']) && $obj['balance'] !== '' ? $obj['balance'] : 0;
    $payment_method = $obj['payment_method'] ?? [];
    $state_of_supply = $obj['state_of_supply'] ?? '';
    $discount = isset($obj['discount']) && $obj['discount'] !== '' ? $obj['discount'] : 0;
    $discount_amount = isset($obj['discount_amount']) && $obj['discount_amount'] !== '' ? $obj['discount_amount'] : 0;
    $discount_type = $obj['discount_type'] ?? '';
    $remark = isset($obj['remark']) && $obj['remark'] !== '' ? $obj['remark'] : '';
    $round_off = isset($obj['round_off']) && $obj['round_off'] !== '' ? $obj['round_off'] : 0;
    $payment_method_json = json_encode($payment_method, JSON_UNESCAPED_UNICODE);

    // Validate required fields
    if (!$sales_estimation_id || !$party_name || !$bill_date || !$product || !isset($total)) {
        $output = ['status' => 400, 'msg' => 'Parameter Mismatch'];
        echo json_encode($output);
        exit;
    }

    // Fetch existing estimation
    $sqlEstimation = "SELECT * FROM sales_estimation WHERE sales_estimation_id = ? AND company_id = ?";
    $resultEstimation = fetchQuery($conn, $sqlEstimation, [$sales_estimation_id, $compID]);

    if (empty($resultEstimation)) {
        $output = ['status' => 400, 'msg' => 'Sales Estimation Not Found'];
        echo json_encode($output);
        exit;
    }

    // Calculate sum_total
    foreach ($product as &$element) {
        $qty = floatval($element['qty']);
        $price = floatval($element['price_unit']);
        $product_discount_amt = !empty($element['discount_amt']) ? floatval($element['discount_amt']) : 0;
        $element['without_tax_amount'] = ($qty * $price) - $product_discount_amt;
        $sum_total += $element['without_tax_amount'];

        // Fetch unit name
        $sqlunit = "SELECT unit_name FROM unit WHERE unit_id = ? AND delete_at = 0";
        $unitData = fetchQuery($conn, $sqlunit, [$element['unit']]);
        if (!empty($unitData)) {
            $element['unit_name'] = $unitData[0]['unit_name'];
        }
    }

    $product_json = json_encode($product, JSON_UNESCAPED_UNICODE);
    $billDate = date('Y-m-d', strtotime($bill_date));
    $company_details_json = json_encode($company_details, JSON_UNESCAPED_UNICODE);

    // Update estimation
    $sqlUpdateEstimation = "UPDATE sales_estimation SET 
                           party_name = ?, company_details = ?, bill_date = ?, product = ?, 
                           eway_no = ?, vechile_no = ?, address = ?, mobile_number = ?, 
                           total = ?, sum_total = ?, sub_total = ?, round_off = ?, 
                           paid = ?, balance = ?, payment_method = ?, state_of_supply = ?, 
                           discount = ?,discount_amount = ?, discount_type = ?, remark = ?
                           WHERE sales_estimation_id = ? AND company_id = ?";

    $paramsUpdate = [
        $party_name,
        $company_details_json,
        $billDate,
        $product_json,
        $eway_no,
        $vechile_no, 
        $address,
        $mobile_number,
        $total,
        $sum_total,
        $sub_total,
        $round_off,
        $paid,
        $balance,
        $payment_method_json,
        $state_of_supply,
        $discount,
        $discount_amount,
        $discount_type,
        $remark,
        $sales_estimation_id,
        $compID
    ];

    $stmtUpdate = $conn->prepare($sqlUpdateEstimation);
    $stmtUpdate->bind_param(
        "ssssssssddddddsssdssss",
        ...$paramsUpdate
    );

    if ($stmtUpdate->execute()) {
        $output = [
            'status' => 200,
            'msg' => 'Sales Estimation Updated Successfully',
            'data' => ['sales_estimation_id' => $sales_estimation_id]
        ];
    } else {
        $output = ['status' => 400, 'msg' => 'Sales Estimation Update Failed: ' . $stmtUpdate->error];
    }
    $stmtUpdate->close();
    echo json_encode($output);
    exit;
}

// Delete Sales Estimation
else if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $sales_estimation_id = $obj['sales_estimation_id'] ?? null;

    if (!$sales_estimation_id) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    } else {
        $sqlDelete = "UPDATE sales_estimation SET delete_at = '1' WHERE sales_estimation_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sqlDelete);
        if (!$stmt) {
            $output['status'] = 400;
            $output['msg'] = 'Prepare failed: ' . $conn->error;
        } else {
            $stmt->bind_param("ss", $sales_estimation_id, $compID);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $output['status'] = 200;
                    $output['msg'] = 'Sales Estimation Deleted Successfully';
                } else {
                    $output['status'] = 400;
                    $output['msg'] = 'No sales estimation found with the provided ID';
                }
            } else {
                $output['status'] = 400;
                $output['msg'] = 'Error deleting sales estimation: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}// Convert Sales Estimations to Invoices
// Convert Sales Estimations to Invoices
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['sales_estimation_ids'])) {
    $sales_estimation_ids = $obj['sales_estimation_ids'];
    
    if (!is_array($sales_estimation_ids) || empty($sales_estimation_ids)) {
        $output = ['status' => 400, 'msg' => 'Invalid or empty sales estimation IDs'];
        echo json_encode($output);
        exit;
    }

    $success_ids = [];
    $failed_ids = [];

    foreach ($sales_estimation_ids as $estimation_id) {
        // Fetch the sales estimation
        $sqlEstimation = "SELECT * FROM sales_estimation WHERE sales_estimation_id = ? AND company_id = ? AND delete_at = '0'";
        $estimation = fetchQuery($conn, $sqlEstimation, [$estimation_id, $compID]);

        if (empty($estimation)) {
            $failed_ids[] = ['id' => $estimation_id, 'reason' => 'Sales Estimation Not Found'];
            continue;
        }

        $estimation = $estimation[0];
        $product = json_decode($estimation['product'], JSON_UNESCAPED_UNICODE);
        $company_details = json_decode($estimation['company_details'], JSON_UNESCAPED_UNICODE);
        $payment_method = json_decode($estimation['payment_method'], JSON_UNESCAPED_UNICODE);

        // Generate unique invoice ID
        $id = $conn->insert_id + 1; // Temporary ID for uniqueID generation
        $uniqueID = uniqueID("invoice", $id);

        // Fetch the last bill number from invoice table
        $lastBillSql = "SELECT bill_no FROM invoice WHERE company_id = ? AND bill_no IS NOT NULL ORDER BY id DESC LIMIT 1";
        $resultLastBill = fetchQuery($conn, $lastBillSql, [$compID]);

        // Fetch bill prefix
        $billPrefixSql = "SELECT bill_prefix FROM company WHERE company_id = ?";
        $resultBillPrefix = fetchQuery($conn, $billPrefixSql, [$compID]);

        // Determine fiscal year
        $year = date('y');
        $fiscal_year = ($year . '-' . ($year + 1));
        $billcount = 1;

        if (!empty($resultLastBill[0]['bill_no'])) {
            preg_match('/\/(\d+)\/\d{2}-\d{2}$/', $resultLastBill[0]['bill_no'], $matches);
            if (isset($matches[1])) {
                $billcount = (int) $matches[1] + 1;
            }
        }

        $billcountFormatted = str_pad($billcount, 3, '0', STR_PAD_LEFT);
        $bill_no = $resultBillPrefix[0]['bill_prefix'] . '/' . $billcountFormatted . '/' . $fiscal_year;

        // Update product details by fetching from remote API
        foreach ($product as $i => $element) {
            $remoteApiUrl = "https://pothigaicrackers.com/api/get_product_details.php";
            $payload = ['product_id' => $element['product_id'], 'company_id' => $compID];
            $productData = getRemoteProductData($remoteApiUrl, $payload);

            if ($productData && isset($productData['status']) && $productData['status'] === 200 && isset($productData['data'])) {
                $remoteProduct = $productData['data'];
                $element['hsn_no'] = $remoteProduct['hsn_no'];
                $element['item_code'] = $remoteProduct['product_code'];
                // Prefer input name, fallback to API
                $element['product_name'] = isset($element['product_name']) && $element['product_name'] !== '' 
                    ? $element['product_name'] 
                    : $remoteProduct['product_name'];
            } else {
                file_put_contents("debug_log.txt", "Product not found during conversion: product_id: " . $element['product_id'] . "\n", FILE_APPEND);
                $failed_ids[] = ['id' => $estimation_id, 'reason' => 'Product Details Not Found for product_id: ' . $element['product_id']];
                continue 2; // Skip to next estimation
            }
        }

        $product_json = json_encode($product, JSON_UNESCAPED_UNICODE);

        // Insert into invoice table
        $sqlInvoice = "INSERT INTO invoice (company_id, invoice_id, party_name, bill_date, product, sub_total, discount,discount_amount, discount_type, total, sum_total, round_off, paid, balance, delete_at, eway_no, vechile_no, address, mobile_number, company_details, state_of_supply, remark, payment_method,gst_type,gst_amount, created_date) 
                       VALUES (?, ?, ?, ?, ?, ?,?, ?, ?, ?, ?, ?, ?, ?, '0', ?, ?, ?, ?, ?, ?, ?,?,?, ?, NOW())";

        $stmt = $conn->prepare($sqlInvoice);
        $stmt->bind_param(
            "sssssdsdsdddddsssssssssd",
            $compID,
            $uniqueID,
            $estimation['party_name'],
            $estimation['bill_date'],
            $product_json,
            $estimation['sub_total'],
            $estimation['discount'],
              $estimation['discount_amount'],
            $estimation['discount_type'],
            $estimation['total'],
            $estimation['sum_total'],
            $estimation['round_off'],
            $estimation['paid'],
            $estimation['balance'],
            $estimation['eway_no'],
            $estimation['vechile_no'],
            $estimation['address'],
            $estimation['mobile_number'],
            $estimation['company_details'],
            $estimation['state_of_supply'],
            $estimation['remark'],
            $estimation['payment_method'],
            $estimation['gst_type'],
            $estimation['gst_amount']
        );

        if ($stmt->execute()) {
            $invoice_id = $conn->insert_id;

            // Update invoice with bill number
            $sqlUpdate = "UPDATE invoice SET bill_no = ? WHERE id = ? AND company_id = ?";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->bind_param("sis", $bill_no, $invoice_id, $compID);

            if ($stmtUpdate->execute()) {
                // Update product stock and log stock history
                foreach ($product as $element) {
                    $productId = $element['product_id'];
                    $quantity = (int) $element['qty'];

                    // Fetch current stock from remote API
                    $remoteApiUrl = "https://pothigaicrackers.com/api/get_product_details.php";
                    $payload = ['product_id' => $productId, 'company_id' => $compID];
                    $productData = getRemoteProductData($remoteApiUrl, $payload);

                    if ($productData && isset($productData['status']) && $productData['status'] === 200 && isset($productData['data'])) {
                        $remoteProduct = $productData['data'];
                        $quantity_purchased = (int)$remoteProduct['crt_stock'] - $quantity;

                        // Update stock via remote API
                        $remotePayload = [
                            'product_id' => $productId,
                            'company_id' => $compID,
                            'crt_stock' => $quantity_purchased,
                            'bill_no' => $bill_no,
                            'quantity' => $quantity,
                            'action' => 'STACKOUT',
                            'bill_date' => $estimation['bill_date']
                        ];

                        $remoteApiUrl = "https://pothigaicrackers.com/api/update_product_stock.php";
                        $apiResponse = callRemoteApi($remoteApiUrl, $remotePayload);

                        if (!$apiResponse['success']) {
                            error_log("Remote stock update failed for product_id: $productId — " . $apiResponse['error']);
                            $failed_ids[] = ['id' => $estimation_id, 'reason' => 'Stock update failed for product_id: ' . $productId];
                            continue;
                        }

                        // Log stock history
                        $stockSql = "INSERT INTO stock_history (stock_type, bill_no, product_id, product_name, quantity, company_id, bill_id) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $stmtStock = $conn->prepare($stockSql);
                        $stock_type = 'STACKOUT';
                        $product_name = $element['product_name'];
                        $stmtStock->bind_param("sssdsis", $stock_type, $bill_no, $productId, $product_name, $quantity, $compID, $uniqueID);

                        if (!$stmtStock->execute()) {
                            error_log("Stock history insertion failed for product_id: $productId — " . $stmtStock->error);
                            $failed_ids[] = ['id' => $estimation_id, 'reason' => 'Stock history insertion failed for product_id: ' . $productId];
                            continue;
                        }
                        $stmtStock->close();
                    } else {
                        error_log("Failed to fetch product details for product_id: $productId");
                        $failed_ids[] = ['id' => $estimation_id, 'reason' => 'Product details not found for product_id: ' . $productId];
                        continue;
                    }
                }

                // Check and create sales party if not exists
                if (!empty($estimation['mobile_number'])) {
                    $checkPartySql = "SELECT id FROM sales_party WHERE mobile_number = ? AND company_id = ? AND delete_at = 0";
                    $checkPartyStmt = $conn->prepare($checkPartySql);
                    $checkPartyStmt->bind_param("ss", $estimation['mobile_number'], $compID);
                    $checkPartyStmt->execute();
                    $checkPartyStmt->store_result();

                    if ($checkPartyStmt->num_rows === 0) {
                        $party_id = uniqueID("sales_party", $conn->insert_id + 1);
                        $insertPartySql = "INSERT INTO sales_party (company_id, party_id, party_name, mobile_number, alter_number, email, company_name, gst_no, billing_address, shipp_address, opening_balance, opening_date, ac_type, city, state, delete_at) 
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '0')";
                        $stmtInsertParty = $conn->prepare($insertPartySql);

                        $alter_number = '';
                        $email = '';
                        $company_name = '';
                        $gst_no = '';
                        $billing_address = $estimation['address'];
                        $shipp_address = $estimation['address'];
                        $opening_balance = '0';
                        $opening_date = date('Y-m-d');
                        $ac_type = 'CR';
                        $city = '';
                        $state = $estimation['state_of_supply'];

                        $stmtInsertParty->bind_param(
                            "sssssssssssssss",
                            $compID, $party_id, $estimation['party_name'], $estimation['mobile_number'], $alter_number, $email,
                            $company_name, $gst_no, $billing_address, $shipp_address,
                            $opening_balance, $opening_date, $ac_type, $city, $state
                        );

                        if ($stmtInsertParty->execute()) {
                            file_put_contents("debug_log.txt", "Sales party created for mobile: " . $estimation['mobile_number'] . "\n", FILE_APPEND);
                        } else {
                            file_put_contents("debug_log.txt", "Sales party creation failed: " . $stmtInsertParty->error . "\n", FILE_APPEND);
                        }
                        $stmtInsertParty->close();
                    }
                    $checkPartyStmt->close();
                }

                // Mark the sales estimation as processed
                $sqlMarkProcessed = "UPDATE sales_estimation SET delete_at = '1' WHERE sales_estimation_id = ? AND company_id = ?";
                $stmtProcessed = $conn->prepare($sqlMarkProcessed);
                $stmtProcessed->bind_param("ss", $estimation_id, $compID);
                $stmtProcessed->execute();
                $stmtProcessed->close();

                $success_ids[] = ['sales_estimation_id' => $estimation_id, 'invoice_id' => $uniqueID];
            } else {
                $failed_ids[] = ['id' => $estimation_id, 'reason' => 'Failed to update bill number'];
            }
            $stmtUpdate->close();
        } else {
            $failed_ids[] = ['id' => $estimation_id, 'reason' => 'Failed to create invoice: ' . $stmt->error];
        }
        $stmt->close();
    }

    if (!empty($success_ids)) {
        $output = [
            'status' => 200,
            'msg' => 'Selected Sales Estimations Converted to Invoices Successfully',
            'data' => [
                'success' => $success_ids,
                'failed' => $failed_ids
            ]
        ];
    } else {
        $output = [
            'status' => 400,
            'msg' => 'Failed to Convert Sales Estimations to Invoices',
            'data' => ['failed' => $failed_ids]
        ];
    }
    echo json_encode($output);
    exit;
}else {
    $output['status'] = 400;
    $output['msg'] = 'Invalid Request Method';
}

echo json_encode($output);
?>