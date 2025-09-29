<?php
include 'headers.php';

$json = file_get_contents('php://input');
$compID = $_GET['id'] ?? '';
$obj = json_decode($json, true);
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

// Query Function
function fetchQuery($conn, $sql, $params) {
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param(str_repeat("s", count($params)), ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Create or Update Product
if (
    isset($obj['edit_product_id']) && isset($obj['category_id']) && isset($obj['product_name']) && 
    isset($obj['product_img']) && isset($obj['product_code']) && isset($obj['product_content']) && 
    isset($obj['qr_code']) && isset($obj['price']) && isset($obj['video_url']) && 
    isset($obj['discount_lock']) && isset($obj['active']) && isset($obj['current_user_id']) && 
    isset($obj['hsn_no']) && isset($obj['item_gst']) && isset($obj['unit_id']) && 
    isset($obj['subunit_id']) && isset($obj['unit_rate']) && isset($obj['opening_stock']) && 
    isset($obj['opening_date']) && isset($obj['min_stock']) && isset($obj['crt_stock'])
) {
    $edit_product_id = $obj['edit_product_id'];
    $category_id = $obj['category_id'];
    $product_name = $obj['product_name'];
    $product_img = $obj['product_img'];
    $product_code = $obj['product_code'];
    $product_content = $obj['product_content'];
    $qr_code = $obj['qr_code'];
    $price = $obj['price'];
    $video_url = $obj['video_url'];
    $discount_lock = (int)$obj['discount_lock'];
    $active = (int)$obj['active'];
    $current_user_id = $obj['current_user_id'];
    $hsn_no = $obj['hsn_no'];
    $item_gst = $obj['item_gst'];
    $unit_id = $obj['unit_id'];
    $subunit_id = $obj['subunit_id'];
    $unit_rate = (float)$obj['unit_rate'];
    $opening_stock = (float)$obj['opening_stock'];
    $opening_date = $obj['opening_date'];
    $min_stock = (float)$obj['min_stock'];
    $crt_stock = (float)$obj['crt_stock'];

    $qr_code_value = !empty($qr_code) ? $qr_code : null;

    if (!empty($category_id) && !empty($product_name) && !empty($product_content) && !empty($price)) {
        $category_name = getCategoryName($category_id);
        if (!empty($category_name)) {
            $current_user_name = getUserName($current_user_id);
            if (!empty($current_user_name)) {
                $name = convertUniqueName($product_name);
                $timestamp = date("Y-m-d H:i:s");

                if (!empty($edit_product_id)) {
                    // Update Query
                    if (!empty($product_img)) {
                        $outputFilePath = "../Uploads/products/";
                        $profile_path = pngImageToWebP($product_img, $outputFilePath);
                        if ($profile_path === false) {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Failed to process product image.";
                            echo json_encode($output, JSON_NUMERIC_CHECK);
                            exit;
                        }
                        $update_sql = "UPDATE `products` SET 
                            `product_name`=?, 
                            `img`=?, 
                            `category_id`=?, 
                            `product_content`=?, 
                            `product_code`=?, 
                            `hsn_no`=?, 
                            `item_gst`=?, 
                            `unit_id`=?, 
                            `subunit_id`=?, 
                            `unit_rate`=?, 
                            `opening_stock`=?, 
                            `opening_date`=?, 
                            `min_stock`=?, 
                            `crt_stock`=?, 
                            `price`=?, 
                            `qr_code`=?, 
                            `video_url`=?, 
                            `name`=?, 
                            `discount_lock`=?, 
                            `active`=?, 
                            `company_id`=?
                            WHERE `id`=?";
                        $params = [
                            $product_name, $profile_path, $category_id, $product_content, $product_code,
                            $hsn_no, $item_gst, $unit_id, $subunit_id, $unit_rate, $opening_stock,
                            $opening_date, $min_stock, $crt_stock, $price, $qr_code_value, $video_url,
                            $name, $discount_lock, $active, $compID, $edit_product_id
                        ];
                    } else {
                        $update_sql = "UPDATE `products` SET 
                            `product_name`=?, 
                            `category_id`=?, 
                            `product_content`=?, 
                            `product_code`=?, 
                            `hsn_no`=?, 
                            `item_gst`=?, 
                            `unit_id`=?, 
                            `subunit_id`=?, 
                            `unit_rate`=?, 
                            `opening_stock`=?, 
                            `opening_date`=?, 
                            `min_stock`=?, 
                            `crt_stock`=?, 
                            `price`=?, 
                            `qr_code`=?, 
                            `video_url`=?, 
                            `name`=?, 
                            `discount_lock`=?, 
                            `active`=?, 
                            `company_id`=?
                            WHERE `id`=?";
                        $params = [
                            $product_name, $category_id, $product_content, $product_code,
                            $hsn_no, $item_gst, $unit_id, $subunit_id, $unit_rate, $opening_stock,
                            $opening_date, $min_stock, $crt_stock, $price, $qr_code_value, $video_url,
                            $name, $discount_lock, $active, $compID, $edit_product_id
                        ];
                    }

                    $stmt = $conn->prepare($update_sql);
                    if ($stmt === false) {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = "Failed to prepare SQL: " . $conn->error;
                        echo json_encode($output, JSON_NUMERIC_CHECK);
                        exit;
                    }
                    $stmt->bind_param(str_repeat("s", count($params)), ...$params);
                    if ($stmt->execute()) {
                        $output["head"]["code"] = 200;
                        $output["head"]["msg"] = "Product Updated Successfully";
                    } else {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = $conn->error;
                    }
                } else {
                    // Generate unique product_id
                    $get_last_id_result = $conn->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'");
                    $row = $get_last_id_result->fetch_assoc();
                    $auto_increment_id = $row['AUTO_INCREMENT'];
                    $product_id = uniqueID("product", $auto_increment_id);

                    if (!empty($product_img)) {
                        $outputFilePath = "../Uploads/products/";
                        $profile_path = pngImageToWebP($product_img, $outputFilePath);
                        if ($profile_path === false) {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Failed to process product image.";
                            echo json_encode($output, JSON_NUMERIC_CHECK);
                            exit;
                        }
                        $insert_sql = "INSERT INTO `products` (
                            `product_id`, `product_name`, `category_id`, `product_content`, `product_code`, 
                            `hsn_no`, `item_gst`, `unit_id`, `subunit_id`, `unit_rate`, `opening_stock`, 
                            `opening_date`, `min_stock`, `crt_stock`, `price`, `qr_code`, `video_url`, 
                            `name`, `position`, `deleted_at`, `created_by`, `created_name`, `created_time`, 
                            `discount_lock`, `active`, `img`, `company_id`
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?, 'false',?,?,?, ?,?,?,?)";
                        $params = [
                            $product_id, $product_name, $category_id, $product_content, $product_code,
                            $hsn_no, $item_gst, $unit_id, $subunit_id, $unit_rate, $opening_stock,
                            $opening_date, $min_stock, $crt_stock, $price, $qr_code_value, $video_url,
                            $name, '0', $current_user_id, $current_user_name, $timestamp, $discount_lock, $active, $profile_path, $compID
                        ];
                    } else {
                        $insert_sql = "INSERT INTO `products` (
                            `product_id`, `product_name`, `category_id`, `product_content`, `product_code`, 
                            `hsn_no`, `item_gst`, `unit_id`, `subunit_id`, `unit_rate`, `opening_stock`, 
                            `opening_date`, `min_stock`, `crt_stock`, `price`, `qr_code`, `video_url`, 
                            `name`, `position`, `deleted_at`, `created_by`, `created_name`, `created_time`, 
                            `discount_lock`, `active`, `company_id`
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?, 'false',?,?,?, ?,?,?)";
                        $params = [
                            $product_id, $product_name, $category_id, $product_content, $product_code,
                            $hsn_no, $item_gst, $unit_id, $subunit_id, $unit_rate, $opening_stock,
                            $opening_date, $min_stock, $crt_stock, $price, $qr_code_value, $video_url,
                            $name, '0', $current_user_id, $current_user_name, $timestamp, $discount_lock, $active, $compID
                        ];
                    }

                    $stmt = $conn->prepare($insert_sql);
                    if ($stmt === false) {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = "Failed to prepare SQL: " . $conn->error;
                        echo json_encode($output, JSON_NUMERIC_CHECK);
                        exit;
                    }
                    $stmt->bind_param(str_repeat("s", count($params)), ...$params);
                    if ($stmt->execute()) {
                        $output["head"]["code"] = 200;
                        $output["head"]["msg"] = "Product Created Successfully";
                    } else {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = $conn->error;
                    }
                }
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "User not found.";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Category not found.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Required fields missing.";
    }
}
// List Products for a Company
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    $search_text = $obj['search_text'] ?? '';

    $sql = "SELECT product_id, product_name, unit_rate, opening_stock, crt_stock, opening_date 
            FROM products 
            WHERE deleted_at = true 
              AND company_id = ? 
              AND product_name LIKE ? 
            ORDER BY product_name ASC";

    $products = fetchQuery($conn, $sql, [$compID, "%$search_text%"]);

    if (count($products) > 0) {
        $output['status'] = 200;
        $output['msg'] = 'Success';
        $output['data'] = $products;
    } else {
        $output['status'] = 400;
        $output['msg'] = 'No Products Found';
    }
} else if (isset($obj['search_text'])) {
    $search_text = $obj['search_text'];
    $category_id = isset($obj['category_id']) ? $obj['category_id'] : "";
    $type = isset($obj['type']) ? $obj['type'] : "";

    $sql = "SELECT * FROM `category` WHERE `deleted_at` = 0";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $output["body"]["category"][] = $row;
        }
    } else {
        $output["body"]["category"] = [];
    }
    if ($type === "billing") {
        if ($category_id == "") {
            $sql = "SELECT * FROM `products` WHERE `deleted_at` = 'false' AND (`product_name` LIKE ? OR `name` LIKE ?)";
            $params = ["%$search_text%", "%$search_text%"];
        } else {
            $sql = "SELECT * FROM `products` WHERE `deleted_at` = 'false' AND (`product_name` LIKE ? OR `name` LIKE ?) AND category_id = ?";
            $params = ["%$search_text%", "%$search_text%", $category_id];
        }
    } else {
        if ($category_id == "") {
            $sql = "SELECT * FROM `products` WHERE `deleted_at` = 'false' AND `active`= 1 AND (`product_name` LIKE ? OR `name` LIKE ?)";
            $params = ["%$search_text%", "%$search_text%"];
        } else {
            $sql = "SELECT * FROM `products` WHERE `deleted_at` = 'false' AND `active`= 1 AND (`product_name` LIKE ? OR `name` LIKE ?) AND category_id = ?";
            $params = ["%$search_text%", "%$search_text%", $category_id];
        }
    }

    $products = fetchQuery($conn, $sql, $params);
    if (count($products) > 0) {
        $count = 0;
        foreach ($products as $row) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Success";
            $output["body"]["products"][$count] = $row;
            $imgLink = null;
            if ($row["img"] != null && $row["img"] != 'null' && strlen($row["img"]) > 0) {
                $imgLink = "https://" . $_SERVER['SERVER_NAME'] . "/Uploads/products/" . $row["img"];
                $output["body"]["products"][$count]["img"] = $imgLink;
            } else {
                $output["body"]["products"][$count]["img"] = $imgLink;
            }
            $count++;
        }
    } else {
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Success";
        $output["body"]["products"] = [];
    }
} else if (isset($obj['delete_product_id']) && isset($obj['current_user_id']) && isset($obj['image_delete'])) {
    $delete_product_id = $obj['delete_product_id'];
    $current_user_id = $obj['current_user_id'];
    $image_delete = $obj['image_delete'];

    if (!empty($delete_product_id) && !empty($current_user_id)) {
        $current_user_name = getUserName($current_user_id);
        if (!empty($current_user_name)) {
            if ($image_delete === true) {
                $status = ImageRemove('product', $delete_product_id);
                if ($status == "products Image Removed Successfully") {
                    $output["head"]["code"] = 200;
                    $output["head"]["msg"] = "Successfully deleted product image.";
                } else {
                    $output["head"]["code"] = 400;
                    $output["head"]["msg"] = "Failed to delete image. Please try again.";
                }
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "Parameter is Mismatch";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "User not found.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
} else if (isset($obj['delete_product_id']) && isset($obj['current_user_id'])) {
    $delete_product_id = $obj['delete_product_id'];
    $current_user_id = $obj['current_user_id'];

    if (!empty($delete_product_id) && !empty($current_user_id)) {
        if (numericCheck($delete_product_id) && numericCheck($current_user_id)) {
            $update_sql = "UPDATE `products` SET `deleted_at`=1 WHERE `id`=?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("s", $delete_product_id);
            if ($stmt->execute()) {
                $output["head"]["code"] = 200;
                $output["head"]["msg"] = "Product Deleted Successfully!";
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "Failed to connect. Please try again.";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Invalid data Product.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
} else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Invalid request parameters.";
}

echo json_encode($output, JSON_NUMERIC_CHECK);
?>