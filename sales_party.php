<?php
include 'config/dbconfig.php'; // Database connection
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

// List Sales Parties
if (isset($obj->search_text)) {
    $compID = $obj->company_id;
    $searchText = $obj->search_text;

    $sql = "SELECT  party_name,party_id, mobile_number, alter_number, email, company_name, gst_no, billing_address, shipp_address, opening_balance, 
                   DATE_FORMAT(opening_date, '%Y-%m-%d') as opening_date, ac_type, city, state 
            FROM sales_party 
            WHERE delete_at = '0' AND company_id = ? 
            AND (party_name LIKE ? OR mobile_number LIKE ? OR alter_number LIKE ? OR email LIKE ? OR company_name LIKE ? OR gst_no LIKE ?)";

    $stmt = $conn->prepare($sql);
    $searchParam = '%' . $searchText . '%';
    $stmt->bind_param('issssss', $compID, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $data = array();
        while ($row = $result->fetch_assoc()) {
            $party_name = $row['party_name'];

            // Fetch invoice data
            $invoice_sql = "SELECT invoice_id, bill_no, bill_date, total, created_date,paid,balance FROM invoice WHERE party_name LIKE ? AND delete_at = '0' AND company_id = ?";
            $stmt_invoice = $conn->prepare($invoice_sql);
            $stmt_invoice->bind_param('ss', $party_name, $compID);
            $stmt_invoice->execute();
            $invoiceResult = $stmt_invoice->get_result();
            $transactions = array();
            while ($invoice = $invoiceResult->fetch_assoc()) {
                $transactions[] = [
                    'id' => $invoice['invoice_id'],
                    'type' => 'Invoice',
                    'date' => $invoice['bill_date'],
                    'receipt_no' => $invoice['bill_no'],
                    'amount' => $invoice['total'],
                    'create_date' => $invoice['created_date'],
                       'paid' => $invoice['paid'],
                    'balance' => $invoice['balance']
                ];
            }

            // Fetch payin data
            $payin_sql = "SELECT payin_id, receipt_no, receipt_date, paid, created_date FROM payin WHERE party_id = ? AND delete_at = '0' AND company_id = ?";
            $stmt_payin = $conn->prepare($payin_sql);
            $stmt_payin->bind_param('ss', $party_name, $compID);
            $stmt_payin->execute();
            $payinResult = $stmt_payin->get_result();
            while ($payin = $payinResult->fetch_assoc()) {
                $transactions[] = [
                    'id' => $payin['payin_id'],
                    'type' => 'Payin',
                    'date' => $payin['receipt_date'],
                    'receipt_no' => $payin['receipt_no'],
                    'amount' => $payin['paid'],
                    'create_date' => $payin['created_date'],
                     'paid' => $payin['paid'],
                    'balance' => "0"
                ];
            }

            // Sort transactions by created date
            usort($transactions, function ($a, $b) {
                return strtotime($b['create_date']) - strtotime($a['create_date']);
            });

            $row['transactions'] = $transactions;
            $data[] = $row;
        }

        $output['code'] = 200;
        $output['msg'] = 'Success';
        $output['data'] = $data;
    } else {
        $output['code'] = 400;
        $output['msg'] = 'No Records Found';
    }
} else if (!empty($obj->company_id) && !empty($obj->edit_party_id)) {
    $compID = $obj->company_id;
    $edit_party_id = $obj->edit_party_id;
    $party_name = $obj->party_name;
    $mobile_number = $obj->mobile_number;
    $alter_number = $obj->alter_number;
    $email = $obj->email;
    $company_name = $obj->company_name;
    $gst_no = $obj->gst_no;
    $billing_address = $obj->billing_address;
    $shipp_address = $obj->shipp_address;
    $opening_balance = $obj->opening_balance;
    $opening_date = $obj->opening_date;
    $ac_type = $obj->ac_type;
    $city = $obj->city;
    $state = $obj->state;
$billing_address = preg_replace('/\s+/', ' ', $billing_address);
$shipp_address = preg_replace('/\s+/', ' ', $shipp_address);
    // Correct UPDATE query without using insert_id since it's not needed
    $sql = "UPDATE sales_party 
            SET party_name = ?, mobile_number = ?, alter_number = ?, email = ?, company_name = ?, 
                gst_no = ?, billing_address = ?, shipp_address = ?, opening_balance = ?, opening_date = ?, 
                ac_type = ?, city = ?, state = ? 
            WHERE party_id = ? AND company_id = ?";

    // Prepare and bind the statement with correct types (s = string, i = integer, etc.)
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'sssssssssssssss',
        $party_name,
        $mobile_number,
        $alter_number,
        $email,
        $company_name,
        $gst_no,
        $billing_address,
        $shipp_address,
        $opening_balance,
        $opening_date,
        $ac_type,
        $city,
        $state,
        $edit_party_id,
        $compID
    );

    // Execute and handle the result
    if ($stmt->execute()) {
        $output['code'] = 200;
        $output['msg'] = 'Sales Party Updated Successfully';
    } else {
        $output['code'] = 400;
        $output['msg'] = 'Error updating Sales Party';
    }
} else if (!empty($obj->company_id) && !empty($obj->party_name)) {
    $compID = $obj->company_id;
    $party_name = $obj->party_name;
    $mobile_number = $obj->mobile_number;
    $alter_number = $obj->alter_number;
    $email = $obj->email;
    $company_name = $obj->company_name;
    $gst_no = $obj->gst_no;
    $billing_address = $obj->billing_address;
    $shipp_address = $obj->shipp_address;
    $opening_balance = !empty($obj->opening_balance) ? $obj->opening_balance : '0.00';
     $opening_date = !empty($obj->opening_date) ? $obj->opening_date : date('Y-m-d H:i:s');
    $ac_type = $obj->ac_type;
    $city = !empty($obj->city) ? $obj->city : '';
    $state = !empty($obj->state) ? $obj->state : '';
$billing_address = preg_replace('/\s+/', ' ', $billing_address);
$shipp_address = preg_replace('/\s+/', ' ', $shipp_address);

    // Insert query with the correct number of columns and values
    $sql = "INSERT INTO sales_party (company_id, party_name, mobile_number, alter_number, email, company_name, gst_no, billing_address, shipp_address, opening_balance, opening_date, ac_type, city, state, delete_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '0')";

    // Prepare and bind the statement
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssssssssss', $compID, $party_name, $mobile_number, $alter_number, $email, $company_name, $gst_no, $billing_address, $shipp_address, $opening_balance, $opening_date, $ac_type, $city, $state);

    // Execute and handle the result
    if ($stmt->execute()) {
        $id = $conn->insert_id;
        $uniqueID = uniqueID("sales_party", $id);
        $update_sql = "UPDATE sales_party SET party_id = ? WHERE id = ? AND company_id = ?";
        $stmt_update = $conn->prepare($update_sql);
        $stmt_update->bind_param('sis', $uniqueID, $id, $compID);
        $stmt_update->execute();

        $output['code'] = 200;
        $output['msg'] = 'Sales Party Created Successfully';
        $output['data'] = ['party_id' => $uniqueID];
    } else {
        $output['code'] = 400;
        $output['msg'] = 'Error creating Sales Party';
    }
} else if (isset($obj->delete_party_id) && isset($obj->company_id)) {
    $party_id = $conn->real_escape_string($obj->delete_party_id);
    $company_id = $obj->company_id;

    // Delete query
    $sql = "UPDATE sales_party SET delete_at = '1' WHERE party_id = ? AND company_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $party_id, $company_id); // Assuming party_id is an integer

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $output["status"] = 200;
            $output["msg"] = "sales Party Deleted Successfully";
        } else {
            $output["status"] = 400;
            $output["msg"] = "No record found to delete";
            $output["data"] = $obj;
        }
    } else {
        $output["status"] = 400;
        $output["msg"] = "Error deleting record";
    }

    $stmt->close();
} else {
    $output['code'] = 400;
    $output['msg'] = 'Required parameters not set';
}

echo json_encode($output, JSON_NUMERIC_CHECK);
