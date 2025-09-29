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

// List Sale Invoices
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    $search_text = $obj['search_text'];
    $from_Date = $obj['from_date'];
    $to_date = $obj['to_date'];

    // SQL Query
    $sql = "SELECT *
        FROM invoice 
        WHERE delete_at = '0' 
        AND company_id = ? 
        AND ((bill_date BETWEEN ? AND ?) 
        OR party_name LIKE ? 
        OR bill_no LIKE ?) 
        ORDER BY id DESC";

    // Prepare parameters
    $params = [$compID, $from_Date, $to_date, "%$search_text%", "%$search_text%"];
    $invoices = fetchQuery($conn, $sql, $params);

    if (count($invoices) > 0) {
        foreach ($invoices as &$invoice) {
            $invoice['bill_date'] = date('Y-m-d', strtotime($invoice['bill_date']));
            $invoice['party_name'] = $invoice['party_name'];
            $invoice['product'] = json_decode($invoice['product'], true);
            $invoice['company_details'] = json_decode($invoice['company_details'], true);
        }

        $output['status'] = 200;
        $output['msg'] = 'Success';
        $output['data'] = $invoices;
    } else {
        $output['status'] = 400;
        $output['msg'] = 'No Records Found';
    }
}


// Create Sale Invoice
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['party_name'])) {
    $party_name = $obj['party_name'];
    $bill_date = $obj['bill_date'];
    $eway_no = $obj['eway_no'];
    $vechile_no = $obj['vechile_no'];
    $address = $obj['address'];
    $product = $obj['product'];
    $total = $obj['total'];
    $paid = isset($obj['paid']) && $obj['paid'] !== '' ? $obj['paid'] : 0;
    $balance_amount = isset($obj['balance_amount']) && $obj['balance_amount'] !== '' ? $obj['balance_amount'] : 0;
    $mobile_number = $obj['mobile_number'];
    $state_of_supply = $obj['state_of_supply'];
    $payment_method = $obj['payment_method'];
    $remark = isset($obj['remark']) && $obj['remark'] !== '' ? $obj['remark'] : '';
    $discount = isset($obj['discount']) && $obj['discount'] !== '' ? $obj['discount'] : 0;
    $discount_amount = isset($obj['discount_amount']) && $obj['discount_amount'] !== '' ? $obj['discount_amount'] : 0;
    $discount_type = $obj['discount_type'];
    $gst_type = $obj['gst_type'];
    $gst_amount = isset($obj['gst_amount']) && $obj['gst_amount'] !== '' ? $obj['gst_amount'] : 0;
    $subtotal = $obj['subtotal'];
    $payment_method_json = json_encode($payment_method, true);

    try {
        // Log the incoming request data for debugging
        file_put_contents("debug_log.txt", "Received data: " . print_r($obj, true), FILE_APPEND);

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

        $sum_total = 0;
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
                file_put_contents("debug_log.txt", "Product not found: product_id: " . $element['product_id'] . "\n", FILE_APPEND);
                echo json_encode(['status' => 400, 'msg' => 'Product Details Not Found']);
                exit();
            }
        
            // Tax excluded amount calculation
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
        
            // Save back updated product to original array
            $product[$i] = $element;
        }

        $companyData = json_encode($companyDetailsresult[0]);
       foreach ($product as $key => $pro) {
    if (isset($pro['product_name']) && $pro['product_name'] !== null) {
        $product[$key]['product_name'] = str_replace('"', '\"', $pro['product_name']);
    }
}

    
       $product_json = json_encode($product, JSON_UNESCAPED_UNICODE);
       
        $billDate = date('Y-m-d', strtotime($bill_date));

        // Log the invoice data
        file_put_contents("debug_log.txt", "Preparing to insert invoice with data: product_json: $product_json, sum_total: $sum_total", FILE_APPEND);

        // Insert invoice into database
        $sqlinvoice = "INSERT INTO invoice (company_id, party_name, bill_date, product, sub_total, discount,discount_amount,discount_type,gst_type,gst_amount, total, paid, balance, delete_at, eway_no, vechile_no, address, mobile_number, company_details, sum_total, state_of_supply, remark, payment_method) 
               VALUES (
                   '$compID', 
                   '$party_name', 
                   '$billDate', 
                   '$product_json',
                   '$subtotal',
                   '$discount',
                   '$discount_amount',
                   '$discount_type',
                   '$gst_type',
                   '$gst_amount',
                   '$total', 
                   '" . ($paid ?: '0') . "', 
                   '" . strval($balance_amount) . "', 
                   '0', 
                   '$eway_no', 
                   '$vechile_no', 
                   '$address', 
                   '" . strval($mobile_number) . "', 
                   '$companyData', 
                   '" . strval($sum_total) . "', 
                   '$state_of_supply',
                   '$remark',
                   '$payment_method_json'
               )";

        // Execute the query and log the result
        if ($conn->query($sqlinvoice) === TRUE) {
            $id = $conn->insert_id;
            file_put_contents("debug_log.txt", "Invoice created successfully. Invoice ID: $id", FILE_APPEND);
        } else {
            file_put_contents("debug_log.txt", "Invoice creation failed: " . $conn->error, FILE_APPEND);
            echo json_encode(['status' => 400, 'msg' => 'Invoice Creation Failed: ' . $conn->error]);
            exit();
        }

        // Generate unique invoice ID
        $uniqueID = uniqueID("invoice", $id);
        file_put_contents("debug_log.txt", "Generated unique invoice ID: $uniqueID", FILE_APPEND);

        // Fetch the last bill number from the database
        $lastBillSql = "SELECT bill_no FROM invoice WHERE company_id = ? AND bill_no IS NOT NULL ORDER BY id DESC LIMIT 1";
        $resultLastBill = fetchQuery($conn, $lastBillSql, [$compID]);

        // Fetch bill prefix from the company settings
        $billPrefixSql = "SELECT bill_prefix FROM company WHERE company_id = ?";
        $resultBillPrefix = fetchQuery($conn, $billPrefixSql, [$compID]);

        // Determine the fiscal year
        $year = date('y');
        $fiscal_year = ($year . '-' . ($year + 1));

        // Initialize the bill number
        $billcount = 1;

        if (!empty($resultLastBill[0]['bill_no'])) {
            preg_match('/\/(\d+)\/\d{2}-\d{2}$/', $resultLastBill[0]['bill_no'], $matches);
            if (isset($matches[1])) { // Fixed: Changed matches1 to matches[1]
                $billcount = (int) $matches[1] + 1;
            }
        }

        $billcountFormatted = str_pad($billcount, 3, '0', STR_PAD_LEFT);
        $bill_no = $resultBillPrefix[0]['bill_prefix'] . '/' . $billcountFormatted . '/' . $fiscal_year;

        file_put_contents("debug_log.txt", "Generated bill number: $bill_no", FILE_APPEND);

        // Update the invoice with generated ID and new bill number
        $sqlUpdate = "UPDATE invoice SET invoice_id = ?, bill_no = ? WHERE id = ? AND company_id = ?";
        $stmtUpdate = $conn->prepare($sqlUpdate);
        $stmtUpdate->bind_param("ssis", $uniqueID, $bill_no, $id, $compID);

        if (!$stmtUpdate->execute()) {
            file_put_contents("debug_log.txt", "Invoice update failed: " . $stmtUpdate->error, FILE_APPEND);
            echo json_encode(['status' => 400, 'msg' => 'Invoice Update Failed']);
            exit();
        }
// Check and create sales party if not exists
if (!empty($mobile_number)) {
    $checkPartySql = "SELECT id FROM sales_party WHERE mobile_number = ? AND company_id = ? AND delete_at = 0";
    $checkPartyStmt = $conn->prepare($checkPartySql);
    $checkPartyStmt->bind_param("ss", $mobile_number, $compID);
    $checkPartyStmt->execute();
    $checkPartyStmt->store_result();
    

    if ($checkPartyStmt->num_rows === 0) {
        $party_id = uniqueID("sales_party", $conn->insert_id + 1);
        // Party not found, insert new
        $insertPartySql = "INSERT INTO sales_party (company_id,party_id, party_name, mobile_number, alter_number, email, company_name, gst_no, billing_address, shipp_address, opening_balance, opening_date, ac_type, city, state, delete_at) 
        VALUES (?, ?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '0')";

        $stmtInsertParty = $conn->prepare($insertPartySql);

        // You can adjust these variables or set defaults as per your form data
        $alter_number = '';  // Set default or from $obj
        $email = '';         // Set default or from $obj
        $company_name = '';  // Set default or from $obj
        $gst_no = '';        // Set default or from $obj
        $billing_address = $address;  // Using invoice address
        $shipp_address = $address;
        $opening_balance = '0';
        $opening_date = date('Y-m-d');
        $ac_type = 'CR';  // Default account type
        $city = '';           // Set default or from $obj
        $state = $state_of_supply; // Using supply state

        $stmtInsertParty->bind_param(
            "sssssssssssssss", 
            $compID,$party_id, $party_name, $mobile_number, $alter_number, $email, 
            $company_name, $gst_no, $billing_address, $shipp_address, 
            $opening_balance, $opening_date, $ac_type, $city, $state
        );

        if ($stmtInsertParty->execute()) {
            file_put_contents("debug_log.txt", "Sales party created for mobile: $mobile_number\n", FILE_APPEND);
        } else {
            file_put_contents("debug_log.txt", "Sales party creation failed: " . $stmtInsertParty->error . "\n", FILE_APPEND);
        }

        $stmtInsertParty->close();
    } else {
        file_put_contents("debug_log.txt", "Sales party already exists for mobile: $mobile_number\n", FILE_APPEND);
    }

    $checkPartyStmt->close();
} else {
    file_put_contents("debug_log.txt", "Mobile number is missing. Party creation skipped.\n", FILE_APPEND);
}

        // Update product stock and log stock history
        foreach ($product as $element) {
            $productId = $element['product_id'];
            $quantity = (int) $element['qty'];


 $remoteApiUrl = "https://pothigaicrackers.com/api/get_product_details.php";

// Prepare payload
$payload = [
    'product_id' => $element['product_id'],
    'company_id' => $compID
];

// Call remote API
$productData = getRemoteProductData($remoteApiUrl, $payload);
$quantity_purchased = 0;
 if ($productData && isset($productData['status']) && $productData['status'] === 200 && isset($productData['data'])) {
                    
    $remoteProduct = $productData['data'];

    $quantity_purchased = (int)$remoteProduct['crt_stock'] - $quantity;

   
 }
             $remotePayload = [
                'product_id' => $element['product_id'],
                'company_id' => $compID,
                'crt_stock' => $quantity_purchased,
                'bill_no' => $bill_no,
                'quantity' => $quantity,
                'action' => 'STACKOUT',
                'bill_date' => $bill_date
            ];

            // Call remote API to update stock
            $remoteApiUrl = "https://pothigaicrackers.com/api/update_product_stock.php";
            $apiResponse = callRemoteApi($remoteApiUrl, $remotePayload);

            if (!$apiResponse['success']) {
                // Log error or take corrective action
                error_log("Remote stock update failed for product_id: " . $element['product_id'] . " — " . $apiResponse['error']);
            }

            // Log stock history
           $stockSql = "INSERT INTO stock_history (stock_type, bill_no, product_id, product_name, quantity, company_id, bill_id) 
             VALUES (?, ?, ?, ?, ?, ?, ?)";
$stmtStock = $conn->prepare($stockSql);
$stmtStock->bind_param("sssdsis", $stock_type, $bill_no, $productId, $product_name, $quantity, $compID, $uniqueID);

$stock_type = 'STACKOUT';
$product_name = $element['product_name'];

if ($stmtStock->execute()) {
    // Success
} else {
    echo json_encode(['status' => 400, 'msg' => 'Stock History Insertion Failed: ' . $stmtStock->error]);
    exit();
}

        }

        echo json_encode([
            'status' => 200,
            'msg' => 'Invoice Created Successfully',
            'data' => ['invoice_id' => $uniqueID],
        ]);
        exit(); // Stop execution after sending the final response
    } catch (Exception $error) {
        file_put_contents("debug_log.txt", "Error caught: " . $error->getMessage(), FILE_APPEND);
        echo json_encode([
            'status' => 500,
            'msg' => 'An error occurred: ' . $error->getMessage(),
        ]);
        exit();
    }
}

// Update Sale Invoice
else if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    $json = file_get_contents('php://input');
    $obj = json_decode($json, true);

    // Extract input data
    $invoice_id = $obj['invoice_id'] ?? null;
    $party_name = $obj['party_name'] ?? null;
    $company_details = $obj['company_details'] ?? null;
    $bill_date = $obj['bill_date'] ?? null;
    $product = $obj['product'] ?? null;
    $eway_no = $obj['eway_no'] ?? null;
    $vechile_no = $obj['vechile_no'] ?? null;
    $address = $obj['address'] ?? null;
    $mobile_number = $obj['mobile_number'] ?? null;
    $total = $obj['total'] ?? null;
    $sum_total = $obj['sum_total'] ?? null;
    $paid = $obj['paid'] ?? null;
    $balance = $obj['balance'] ?? null;
    $payment_method = $obj['payment_method'];
    $state_of_supply = $obj['state_of_supply'] ?? null;
    $discount = $obj['discount'] ?? null;
    $discount_amount = $obj['discount_amount'] ?? null;
    $discount_type = $obj['discount_type'] ?? null;
    $gst_type = $obj['gst_type'] ?? null;
    $gst_amount = isset($obj['gst_amount']) && $obj['gst_amount'] !== '' ? $obj['gst_amount'] : 0;
    $remark = isset($obj['remark']) && $obj['remark'] !== '' ? $obj['remark'] : '';
    $payment_method_json = json_encode($payment_method, true);
    

    // Validate required fields
    if (!$invoice_id) {
        $output = ['status' => 400, 'msg' => 'Parameter Mismatch'];
        header('Content-Type: application/json');
        echo json_encode($output);
        exit; // Ensure script stops after sending response
    }

    // Fetch the old invoice details
    $sqlInvoice = "SELECT * FROM invoice WHERE invoice_id = ? AND company_id = ?";
    $resultInvoice = fetchQuery($conn, $sqlInvoice, [$invoice_id, $compID]);

    if (empty($resultInvoice)) {
        $output = ['status' => 400, 'msg' => 'Invoice Not Found'];
        header('Content-Type: application/json');
        echo json_encode($output);
        exit;
    }

    $oldProductList = json_decode($resultInvoice[0]['product'], true);

    //Revert the stock changes made by the old invoice
    foreach ($oldProductList as $oldProduct) {
        $oldQty = (int) $oldProduct['qty'];
        
        $remoteApiUrl = "https://pothigaicrackers.com/api/get_product_details.php";

// Prepare payload
$payload = [
    'product_id' => $oldProduct['product_id'],
    'company_id' => $compID
];

// Call remote API
$productData = getRemoteProductData($remoteApiUrl, $payload);
$quantity_purchased = 0;
 if ($productData && isset($productData['status']) && $productData['status'] === 200 && isset($productData['data'])) {
                    
    $remoteProduct = $productData['data'];

    $quantity_purchased = (int)$remoteProduct['crt_stock'] + $oldQty;


$remotePayload = [
                'product_id' => $oldProduct['product_id'],
                'company_id' => $compID,
                'crt_stock' => $quantity_purchased,
                'quantity' => $oldQty,
                'action' => 'STACKOUT',
                'bill_date' => $bill_date
            ];

            // Call remote API to update stock
            $remoteApiUrl = "https://pothigaicrackers.com/api/update_product_stock.php";
            $apiResponse = callRemoteApi($remoteApiUrl, $remotePayload);

            if (!$apiResponse['success']) {
                // Log error or take corrective action
                error_log("Remote stock update failed for product_id: " . $element['product_id'] . " — " . $apiResponse['error']);
            }

   
 }
       
    }
    
    
    // In the PUT section
        $sum_total = 0;
        foreach ($product as &$element) {
            
            $remoteApiUrl = "https://pothigaicrackers.com/api/get_product_details.php";

            // Prepare payload
            $payload = [
                'product_id' => $element['product_id'],
                'company_id' => $compID
            ];
            
            // Call remote API
            $productData = getRemoteProductData($remoteApiUrl, $payload);
                      
                        
             $remoteProduct = $productData['data'];
             
            $element['without_tax_amount'] = (floatval($element['qty']) * floatval($element['price_unit'])) - (empty($element['discount_amt']) ? 0 : floatval($element['discount_amt']));
            $sum_total += $element['without_tax_amount'];
            $element['product_name'] = $remoteProduct['product_name'];
            
            
        }
        $product_json = json_encode($product);

    // Update the invoice details
    $product_json = json_encode($product);
    $billDate = date('Y-m-d', strtotime($bill_date));

    $sqlUpdateInvoice = "UPDATE invoice SET party_name = ?, company_details = ?, 
                         bill_date = ?, product = ?, eway_no = ?, vechile_no = ?, 
                         address = ?, mobile_number = ?, total = ?, sum_total = ?, 
                         paid = ?, balance = ?, payment_method = ?,state_of_supply = ?, discount = ?,discount_amount = ?, discount_type = ?,gst_type = ?,gst_amount = ?,sub_total = ?,remark = ?
                         WHERE invoice_id = ? AND company_id = ?";

    $paramsUpdate = [
        $party_name,
        json_encode($company_details),
        $billDate,
        $product_json,
        $eway_no,
        $vechile_no,
        $address,
        $mobile_number,
        $total,
        $sum_total,
        $paid,
        $balance,
        $payment_method_json,
        $state_of_supply,
        $discount,
        $discount_amount,
        $discount_type,
        $gst_type,
        $gst_amount,
        $obj['sub_total'],
        $remark,
        $invoice_id,
        $compID
    ];

    $resultUpdateInvoice = fetchQuery($conn, $sqlUpdateInvoice, $paramsUpdate);

    // if (count($resultUpdateInvoice) === 0) {
    //     $output['status'] = 400;
    //     $output['msg'] = 'Invoice Update Failed';
    //     echo json_encode($output);
    //     return;
    // }

    // Update stock based on the new invoice details
    foreach ($product as $newProduct) {
        $productId = $newProduct['product_id'];
        $quantity = (int) $newProduct['qty'];
      
        
         $remoteApiUrl = "https://pothigaicrackers.com/api/get_product_details.php";

// Prepare payload
$payload = [
    'product_id' => $productId,
    'company_id' => $compID
];

// Call remote API
$productData = getRemoteProductData($remoteApiUrl, $payload);
$quantity_purchased = 0;
 if ($productData && isset($productData['status']) && $productData['status'] === 200 && isset($productData['data'])) {
                    
    $remoteProduct = $productData['data'];

    $quantity_purchased = (int)$remoteProduct['crt_stock'] - $quantity;


$remotePayload = [
                'product_id' => $productId,
                'company_id' => $compID,
                'crt_stock' => $quantity_purchased,
                'quantity' => $oldQty,
                'action' => 'STACKOUT',
                'bill_date' => $bill_date
            ];

            // Call remote API to update stock
            $remoteApiUrl = "https://pothigaicrackers.com/api/update_product_stock.php";
            $apiResponse = callRemoteApi($remoteApiUrl, $remotePayload);

            if (!$apiResponse['success']) {
                // Log error or take corrective action
                error_log("Remote stock update failed for product_id: " . $element['product_id'] . " — " . $apiResponse['error']);
            }

   
 }
       
    

            // Log stock history
            $stockSql = "INSERT INTO stock_history (stock_type, product_id, quantity, company_id, bill_id) VALUES (?, ?, ?, ?, ?)";
            fetchQuery($conn, $stockSql, ['STACKOUT', $productId, $quantity, $compID, $invoice_id]);
       
    }

    $output = [
        'status' => 200,
        'msg' => 'Invoice Updated Successfully',
        'data' => ['invoice_id' => $invoice_id]
    ];
     echo json_encode($output);
    exit;
}

// Delete Sale Invoice
else if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $invoice_id = $obj['invoice_id'];

    if (!$invoice_id) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    } else {
        $sqlDelete = "UPDATE invoice SET delete_at = '1' WHERE invoice_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sqlDelete);
        if (!$stmt) {
            $output['status'] = 400;
            $output['msg'] = 'Prepare failed: ' . $conn->error;
        } else {
            $stmt->bind_param("ss", $invoice_id, $compID);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $output['status'] = 200;
                    $output['msg'] = 'Invoice Deleted Successfully';
                } else {
                    $output['status'] = 400;
                    $output['msg'] = 'No invoice found with the provided ID';
                }
            } else {
                $output['status'] = 400;
                $output['msg'] = 'Error deleting invoice: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
} else {
    $output['status'] = 400;
    $output['msg'] = 'Invalid Request Method';
}

echo json_encode($output);
?>