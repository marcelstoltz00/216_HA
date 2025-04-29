<?php
session_start();
require_once __DIR__ . '/config_HA.php';

$data = json_decode(file_get_contents("php://input"), true);

/***************************************************************************************************************************
 SINGLETON
***************************************************************************************************************************/

class FF_API
{
    private $conndb = null;

    public static function instance()
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new FF_API();
        }

        return $instance;
    }

    private function __construct()
    {

        require_once __DIR__ . '/config_HA.php';

        global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $WHEATLEY_STUDENTNUM, $WHEATLEY_APIKEY, $WHEATLEY_BASEURL;

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $this->conndb = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
            if ($this->conndb->connect_error) {
                die("Connection failed: " . $this->conndb->connect_error);
            }
            $this->conndb->set_charset("utf8mb4");
        } catch (mysqli_sql_exception $e) {
            header("HTTP/1.1 500 Internal Server Error");
            header("Content-Type: application/json");
            echo json_encode([
                "success" => false,
                "boodskap" => "Database connection failed. Please check configuration.",
            ]);
            exit;
            $this->conndb = null;
        }
    }

    public function __destruct()
    {
        if ($this->conndb) {
            $this->conndb->close();
            $this->conndb = null;
        }
    }


    public function login($email, $password)
    {
        if (! $this->conndb) {
            $this->response(false, "Database connection failed");
            return;
        }

        try {

            $stelling = $this->conndb->prepare("SELECT password, sout, api_key, name,cart_amount,type FROM Users WHERE email = ?");
            $stelling->bind_param("s", $email);
            $stelling->execute();
            $resultaat = $stelling->get_result();

            if ($userData = $resultaat->fetch_assoc()) {

                $hpw = hash("sha512", $password . $userData['sout']);

                if ($hpw === $userData['password']) {
                    $stelling->close();
                    $this->response(true, "", [["type"=>$userData['type'],"apikey" => $userData['api_key'], "username" => $userData['name'],"amount"=>$userData["cart_amount"]]], 200);
                    return;
                }
            }

            $stelling->close();
            $this->response(false, "Invalid password or email", [], 401); 
            return;
        } catch (mysqli_sql_exception $e) {
            $this->response(false, "Database error: " . $e->getMessage(), [], 500); 
            return;
        }
    }

  



   
    public function getCart(string $api_key): ?array
    {
        if (! $this->conndb) {
            $this->response(false, "DB connection failed.");
            return null;
        }
        $stuur_sql = "SELECT product_id, quantity FROM Carts WHERE customer_id = ?";
        $stelling = $this->conndb->prepare($stuur_sql);
    
        if ($stelling === false) {
            error_log("DB prepare error (get cart): " . $this->conndb->error);
            $this->response(false, "Internal error.", [], 500);
            return null;
        }
    
        $stelling->bind_param("s", $api_key);
    
        if ($stelling->execute()) {
            $resultaat = $stelling->get_result();
            $items = [];
            while ($rye = $resultaat->fetch_assoc()) {
                $items[] = $rye;
            }
            $resultaat->free();
            $stelling->close();
    
             if (empty($items)) {
                 $this->response(true, "No cart items found.", [], 200);
            } else {
                $this->response(true, "Cart items retrieved.", $items, 200);
            }
    
            return $items;
    
        } else {
            error_log("DB execute error (get cart): " . $stelling->error);
            $stelling->close();
            $this->response(false, "Internal error.", [], 500);
            return null;
        }
    }

    public function getOrders(string $api_key): ?array
    {
        if (! $this->conndb) {
            $this->response(false, "DB connection failed.", [], 500);
            return null;
        }
    
        $sql_vir_orders = "SELECT order_id, state, delivery_date, created_at FROM Orders WHERE customer_id = ?";
        $stmtOrders = $this->conndb->prepare($sql_vir_orders);
    
        if ($stmtOrders === false) {
            error_log("DB prepare error (get orders headers): " . $this->conndb->error);
            $this->response(false, "Internal error.", [], 500);
            return null;
        }
    
        $stmtOrders->bind_param("s", $api_key);
    
        if (! $stmtOrders->execute()) {
            error_log("DB execute error (get orders headers): " . $stmtOrders->error);
            $stmtOrders->close();
            $this->response(false, "Internal error.", [], 500);
            return null;
        }
    
        $resultaat_van_orders = $stmtOrders->get_result();
    
        $orderssss = [];
        $orderIds = [];
    
        while ($rye = $resultaat_van_orders->fetch_assoc()) {
            $orderssss[$rye['order_id']] = $rye;
            $orderssss[$rye['order_id']]['products'] = [];
            $orderIds[] = $rye['order_id'];
        }
        $resultaat_van_orders->free();
        $stmtOrders->close();
    
        if (empty($orderssss)) {
            $this->response(true, "No orders found.", [], 200);
            return [];
        }
    
        $stel_in = implode(',', array_fill(0, count($orderIds), '?'));
        $sqlOrderPr = "SELECT order_id, product_id, quantity FROM Order_products WHERE order_id IN ($stel_in)";
        $stmtOrPr = $this->conndb->prepare($sqlOrderPr);
    
        if ($stmtOrPr === false) {
            error_log("DB prepare error (get order products): " . $this->conndb->error);
            $this->response(false, "Internal error.", [], 500);
            return null;
        }
    
        $paramTypes = str_repeat('i', count($orderIds));
        $stmtOrPr->bind_param($paramTypes, ...$orderIds);
    
        if (! $stmtOrPr->execute()) {
             error_log("DB execute error (get order products): " . $stmtOrPr->error);
             $stmtOrPr->close();
             $this->response(false, "Internal error.", [], 500);
             return null;
        }
    
        $resultOrderProducts = $stmtOrPr->get_result();
    
        while ($productRow = $resultOrderProducts->fetch_assoc()) {
            $orderId = $productRow['order_id'];
            unset($productRow['order_id']);
            $orderssss[$orderId]['products'][] = $productRow;
        }
        $resultOrderProducts->free();
        $stmtOrPr->close();
    
        $ordersList = array_values($orderssss);
    
        $this->response(true, "Orders retrieved with products.", $ordersList, 200);
    
        return $ordersList;
    }


    public function checkAmount($apikey): bool
    {


        if (! $this->conndb) {
            $this->response(false, "DB connection failed.");
            return null;
        }
        $stuur_sql = "SELECT cart_amount FROM Users WHERE api_key = ?";
        $stelling = $this->conndb->prepare($stuur_sql);

        if ($stelling === false) {

            $this->response(false, "Internal error.", [], 500);
            return null;
        }

        $stelling->bind_param("s", $apikey);

        if ($stelling->execute()) {
         
                $resultaat = $stelling->get_result();
        
                if ($resultaat && $resultaat->num_rows > 0) {
                    $row = $resultaat->fetch_assoc();
                    $cartAmount = $row['cart_amount'];
        
                    // error_log("amount:".$cartAmount);
        
                    if ($cartAmount <= 6) {
                        $resultaat->free();
                        $stelling->close();
                        return true;
                    } else {
                        $resultaat->free();
                        $stelling->close();
                        return false;
                    }
                } else {
                    $stelling->close();
                    return false;
                }
            


        } else {
            error_log("DB execute error (get cart): " . $stelling->error);
            $stelling->close();
            $this->response(false, "Internal error.", [], 500);
            return null;
        }




    }

    public function increaseAmount($apikey): bool
    {
        if (! $this->conndb) {
            $this->response(false, "DB connection failed.");
            return false;
        }

        $amount = 1;
      

        $stuur_sql = "UPDATE Users SET cart_amount = cart_amount + ? WHERE api_key = ?";
        $stelling = $this->conndb->prepare($stuur_sql);

        if ($stelling === false) {
            error_log("DB prepare error (increase cart): " . $this->conndb->error);
            $this->response(false, "Internal error.", [], 500);
            return false;
        }

        $stelling->bind_param("is", $amount, $apikey);

        if ($stelling->execute()) {
            $affected_rows = $stelling->affected_rows;

            $stelling->close();

            if ($affected_rows > 0) {
                return true;
            } else {
                return false;
            }

        } else {
            error_log("DB execute error (increase cart): " . $stelling->error);
            $stelling->close();
            $this->response(false, "Internal error.", [], 500);
            return false;
        }
    }






public function cart(array $data): void
{
    if (! $this->conndb) {
        $this->response(false, "DB connection failed.", [], 500);
        return;
    }

    $apiKey = $data['api_key'] ?? null;
    $productId = $data['product_id'] ?? null;
    $action = $data['action'] ?? null;

    if (empty($apiKey) || empty($productId) || !in_array($action, ['add', 'remove'])) {
        $this->response(false, "Invalid cart data.", [], 400);
        return;
    }

    $stuur_sql = "";
    $boodskap = "";

    if ($action === 'add') {
        if ($this->checkAmount($apiKey)===true){
            $stuur_sql = "INSERT INTO Carts (customer_id, product_id, quantity, created_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE quantity = quantity + 1";
            $boodskap = "Product added to cart.";
        $this->increaseAmount($apiKey);
   

        }else
        {

            $this->response(false, "Cart Max Capacity", [], 409);
            return;
    
        }

   
  



    } else { 
        $stuur_sql = "DELETE FROM Carts WHERE customer_id = ? AND product_id = ?";
        $boodskap = "Product removed from cart.";
    }

    $stmt = $this->conndb->prepare($stuur_sql);

    if ($stmt === false) {
        // error_log("DB prepare error: " . $this->conndb->error);
         $this->response(false, "Internal error.", [], 500);
         return;
    }


    $types_of_param = "si";
    $bind_param = [$apiKey, $productId];


    $stmt->bind_param($types_of_param, ...$bind_param);


    if ($stmt->execute()) {
        $stmt->close();
        $this->response(true, $boodskap, [], 200);
    } else {
        error_log("DB execute error: " . $stmt->error);
        $stmt->close();
        $this->response(false, "Internal error.", [], 500);
    }
}


public function placeOrder(array $data): void
{
    if (! $this->conndb) {
        $this->response(false, "DB connection failed.", [], 500);
        return;
    }

    $apiKey = $data['api_key'] ?? null;

    if (empty($apiKey)) {
        $this->response(false, "API key missing.", [], 401);
        return;
    }


    $deliveryDate = date('Y-m-d', strtotime('+2 days'));
    $LA = $data['destination_latitude'];
    $LO = $data['destination_longitude'];
    $state = "Storage";

    $this->conndb->begin_transaction();

    try {
        $item_treasure_chest = [];
        $sql_getC = "SELECT product_id, quantity FROM Carts WHERE customer_id = ?";
        $_sql_stmnt = $this->conndb->prepare($sql_getC);

        if ($_sql_stmnt === false) {
             throw new Exception("DB prepare get cart error: " . $this->conndb->error);
        }
        $_sql_stmnt->bind_param("s", $apiKey);
        $_sql_stmnt->execute();
        $resultattt = $_sql_stmnt->get_result();

        if ($resultattt->num_rows === 0) {
            $resultattt->free();
            $_sql_stmnt->close();
            $this->conndb->rollback();
            $this->response(false, "Cart is empty.", [], 400);
            return;
        }

        while ($rye = $resultattt->fetch_assoc()) {
            $item_treasure_chest[] = $rye;
        }
        $resultattt->free();
        $_sql_stmnt->close();

        $maxRetries = 5;
        $orderId = null;
        $trackingNum = '';
        $generatedAndInserted = false;

        for ($i = 0; $i < $maxRetries; $i++) {
            $hash = hash('sha256', $apiKey . microtime(true) . $i . rand());
            $randomPart = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 3);
            $trackingNum = "CS-" . substr($hash, 0, 4) . $randomPart;

             if (strlen($trackingNum) !== 10) {
                  continue;
             }

            $sqlIO = "INSERT INTO Orders (customer_id, state, delivery_date, created_at, tracking_num, destination_latitude, destination_longitude) VALUES (?, ?, ?, NOW(), ?, ?, ?)";
            $stmntIO = $this->conndb->prepare($sqlIO);

            if ($stmntIO === false) {
                 throw new Exception("DB prepare insert order error: " . $this->conndb->error);
            }

            $bindTypes = "ssssdd";
            $stmntIO->bind_param($bindTypes, $apiKey, $state, $deliveryDate, $trackingNum, $LA, $LO);

            if ($stmntIO->execute()) {
                $orderId = $this->conndb->insert_id;
                $stmntIO->close();
                $generatedAndInserted = true;
                break;
            } else {
                if ($this->conndb->errno === 1062) {
                    error_log("Duplicate tracking number generated: " . $trackingNum . ". Retrying...");
                    $stmntIO->close();
                } else {
                    $errorMsg = "DB execute insert order error: " . $stmntIO->error;
                    $stmntIO->close();
                    throw new Exception($errorMsg);
                }
            }
        }

        if (!$generatedAndInserted || $orderId === null) {
            throw new Exception("Failed to generate and insert a unique tracking number after " . $maxRetries . " retries. Possible high collision rate or DB issue.");
        }

        $sqlIOP = "INSERT INTO Order_products (order_id, product_id, quantity) VALUES (?, ?, ?)";
        $stmtIOP = $this->conndb->prepare($sqlIOP);

        if ($stmtIOP === false) {
             throw new Exception("DB prepare insert order products error: " . $this->conndb->error);
        }

        foreach ($item_treasure_chest as $item) {
            $stmtIOP->bind_param("iii", $orderId, $item['product_id'], $item['quantity']);
            if (!$stmtIOP->execute()) {
                $errorMsg = "DB execute insert order product error for product ID " . $item['product_id'] . ": " . $stmtIOP->error;
                $stmtIOP->close();
                throw new Exception($errorMsg);
            }
        }
        $stmtIOP->close();

        $sql_leeg = "DELETE FROM Carts WHERE customer_id = ?";
        $stmtn_leeg = $this->conndb->prepare($sql_leeg);

        if ($stmtn_leeg === false) {
             throw new Exception("DB prepare delete cart error: " . $this->conndb->error);
        }

        $stmtn_leeg->bind_param("s", $apiKey);
        if (!$stmtn_leeg->execute()) {
             $errorMsg = "DB execute delete cart error: " . $stmtn_leeg->error;
             $stmtn_leeg->close();
             throw new Exception($errorMsg);
        }
        $stmtn_leeg->close();

        $this->conndb->commit();
        $this->response(true, "Order placed successfully.", ['order_id' => $orderId, 'tracking_number' => $trackingNum], 200);

        $amount = 0;
      

        $stuur_sql = "UPDATE Users SET cart_amount = ? WHERE api_key = ?";
        $stelling = $this->conndb->prepare($stuur_sql);
     

        $stelling->bind_param("is", $amount, $apiKey);

    $stelling->execute();




    } catch (Exception $e) {
        $this->conndb->rollback();
        error_log("Order placement failed: " . $e->getMessage());

        $errorMessage = (strpos($e->getMessage(), "DB") === 0 || strpos($e->getMessage(), "Failed to generate") === 0)
                        ? "Failed to place order due to an internal issue."
                        : $e->getMessage();

        $this->response(false, $errorMessage, [], 500);
    }
}


   



    public function logout(): void
    {
        session_start();
        session_destroy();
        header("HTTP/1.1 200 OK");
        header("Content-Type: application/json");
        echo json_encode([
            "status"  => "success",
            "boodskap" => "Logged out successfully.",
        ]);
    }

    public function registerchecks($data): void
    {
        $messages = [];
        header("Content-Type: application/json");
        $checkthese = ['name', 'surname', 'email', 'password', 'user_type'];

        foreach ($checkthese as $kolom) {
            if (! isset($data[$kolom]) || empty(trim($data[$kolom]))) {
                $this->response(false, "Missing or empty required field: " . $kolom, "", 400);
                exit;
            }
        }

        $email = trim($data['email']);
        $regex = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';

        if (! preg_match($regex, $email)) {
            $messages[] = 'Please enter a valid email';
        }

        if (! empty($data['password'])) {
            $password       = $data['password'];
            $passwordErrors = [];

            if (strlen($password) < 8) {
                $passwordErrors[] = "at least 8 characters";
            }

            if (! preg_match('/[a-z]/', $password)) {
                $passwordErrors[] = "one lowercase letter";
            }

            if (! preg_match('/[A-Z]/', $password)) {
                $passwordErrors[] = "one uppercase letter";
            }

            if (! preg_match('/[0-9]/', $password)) {
                $passwordErrors[] = "one digit";
            }

            if (! preg_match('/[\W_]/', $password)) {
                $passwordErrors[] = "one special symbol";
            }

            if (! empty($passwordErrors)) {
                $messages[] = "Password must contain: " . implode(', ', $passwordErrors);
            }
        }

        if (! in_array(trim($data['user_type']), ["Customer", "Courier", "Distributor"])) {
            $messages[] = "Invalid user_type";
        }

        if (! empty($messages)) {
            $errorMessage = implode(" | ", $messages);
            $this->response(false, "Registration failed due to validation errors.", $errorMessage, 400);
            exit;
        }

        $this->registerdb($data);
    }

    public function registerdb($data): void
    {
        $email = $data['email'];

        if (! $this->conndb) {
            header("HTTP/1.1 500 Internal Server Error");
            header("Content-Type: application/json");
            echo json_encode([
                "status"    => "error",
                "timestamp" => round(microtime(true) * 1000),
                "boodskap"   => "Database connection error. Cannot process registration.",
            ]);
            //  $this->response(false, "Database connection error. Cannot process registration.", "", 500);
            exit;
        }

        try {
            $stelling = $this->conndb->prepare("SELECT id FROM Users WHERE email = ?");
            $stelling->bind_param("s", $email);
            $stelling->execute();
            $resultaat = $stelling->get_result();

            if ($resultaat->num_rows > 0) {
                $stelling->close();
                header("HTTP/1.1 409 Conflict");
                echo json_encode([
                    "status"    => "error",
                    "timestamp" => round(microtime(true) * 1000),
                    "boodskap"   => "Validation failed.",
                    "errors"    => ["email_exists" => "Email address already registered."],
                ]);
//send back to frontend
                exit;
            }
            $stelling->close();

            // sout en hash
            $sout      = bin2hex(random_bytes(16));
            $hashed_pw = hash("sha512", $data['password'] . $sout);
            $FF_key    = bin2hex(random_bytes(16));
            $amt=0;

            $stelling = $this->conndb->prepare(
                "INSERT INTO Users (name, surname, email, password, sout, type,api_key,cart_amount)
                VALUES (?, ?, ?, ?, ?, ?, ?,?)"
            );
            $stelling->bind_param("sssssssi",
                $data['name'],
                $data['surname'],
                $data['email'],
                $hashed_pw,
                $sout,
                $data['user_type'],
                $FF_key,$amt
            );
            $stelling->execute();

            if ($stelling->affected_rows > 0) {
                header("HTTP/1.1 201 Created");
                echo json_encode([
                    "status" => "success",
                    "apikey" => $FF_key,
                    "name"   => $data['name'],
                ]);
                $_COOKIE['loggedIn'] = true;
                $_COOKIE['username'] = $data['name'];
                $_COOKIE['apiKey']   = $FF_key;
             

            } else {
                header("HTTP/1.1 500 Internal Server Error");
                echo json_encode([
                    "status"  => "error",
                    "boodskap" => "Database insert failed.",
                ]);
            }
            $stelling->close();

        } catch (mysqli_sql_exception $e) {
            header("HTTP/1.1 500 Internal Server Error");
            header("Content-Type: application/json");
            echo json_encode([
                "status"  => "error",
                "boodskap" => "Database error: " . $e->getMessage(),
            ]);

        }
    }



    private function checkApi4key($apiKey): bool
    {
        try {
            $sqlQuery = $this->conndb->prepare("SELECT id FROM Users WHERE api_key = ?");
            if ($sqlQuery === false) {

                return false;
            }
            $sqlQuery->bind_param("s", $apiKey);
            if ($sqlQuery->errno) {

                $sqlQuery->close();
                return false;
            }
            if ($sqlQuery->execute() === false) {

                $sqlQuery->close();
                return false;
            }
            $resultaat = $sqlQuery->get_result();
            $sqlQuery->close();

            return $resultaat->num_rows > 0;
        } catch (mysqli_sql_exception $e) {

            return false;
        }
    }



    public function getProducts($data): void
    {
        $headers = getallheaders();

        if (! isset($headers['X-Auth'])) {
            $this->response(false, "Missing X-Auth header");
            return;
        }

        $authHeader = $headers['X-Auth'];
        if (strpos($authHeader, 'Bearer ') !== 0) {
            $this->response(false, "Invalid X-Auth format");
            return;
        }

        $apiKey = trim(substr($authHeader, 7));

        if (! $this->checkApi4key($apiKey)) {
            $this->response(false, "Invalid API key");
            return;
        }

        try {
            $defaultLimit = 50;

            $search = isset($data["id"]) && !empty($data["id"]) ? $data["id"] : null;

            $limit = isset($data["limit"]) && is_numeric($data["limit"]) ? (int)$data["limit"] : $defaultLimit;

            $keysreturn = isset($data['return']) ? $data['return'] : ['*'];
            if ($keysreturn === '*') {
                $keysreturn = ['*'];
            } else {
                if (! is_array($keysreturn)) {
                    $this->response(false, "Invalid 'return' parameter format. Expected an array or '*' as a string.");
                    return;
                }

                if (! $this->verifyAr($keysreturn)) {
                    $this->response(false, "Invalid 'return' fields.");
                    return;
                }
            }

            $products = $this->queryProducts($keysreturn, $search, $limit);

            $this->response(true, "Success", $products);

        } catch (Exception $e) {
            $this->response(false, "Failed to retrieve products: " . $e->getMessage());
            exit;
        }
    }


    private function queryProducts(array $awaitReturn, $search = null, int $limit): array
    {
        $allowedFields = [
            'id',
            'title',
            'brand',
            'categories',
            'image_url',
            'product_dimensions',
            'manufacturer',
            'department',
            'is_available',
        ];

        $fieldsToSelect = [];

        if (in_array('*', $awaitReturn)) {
            $fieldsToSelect = $allowedFields;
        } else {
            if (!in_array('id', $awaitReturn)) {
                $awaitReturn[] = 'id';
            }

            $fieldsToSelect = array_filter($awaitReturn, function ($field) use ($allowedFields) {
                return in_array($field, $allowedFields);
            });

            if (empty($fieldsToSelect)) {
                throw new Exception("Invalid fields in 'return' parameter.");
            }
        }

        $sql_statementtt = "SELECT " . implode(', ', $fieldsToSelect) . " FROM Products";

        $tipes = '';
        $kategorie = [];

        if (!empty($search)) {
            $sql_statementtt .= " WHERE id = ?";
            $tipes .= 'i';
            $kategorie[] = $search;
        }

        $sql_statementtt .= " LIMIT ?";
        $tipes .= 'i';
        $kategorie[] = $limit;

        $sqlQuery = $this->conndb->prepare($sql_statementtt);

        if ($sqlQuery === false) {
            throw new Exception("Database prepare failed: " . $this->conndb->error);
        }

        if (!empty($kategorie)) {
            $sqlQuery->bind_param($tipes, ...$kategorie);
        }


        $sqlQuery->execute();

        $resultaat = $sqlQuery->get_result();

        if ($resultaat === false) {
             throw new Exception("Database query execution failed: " . $sqlQuery->error);
        }

        $products = [];

        while ($rye = $resultaat->fetch_assoc()) {
            $products[] = $rye;
        }

        $sqlQuery->close();

        $products = array_map(function ($produk) {
            unset($produk['created_at'], $produk['updated_at']);
            return $produk;
        }, $products);

        return $products;
    }


    public function verifyAr(array $keysreturn): bool
    {
        $magwees = [
            'id',
            'title',
            'brand',
            'categories',
            'image_url',
            'product_dimensions',
            'is_available',
            'manufacturer',
            'department'
        ];

        if (in_array('*', $keysreturn) || (is_string($keysreturn) && $keysreturn === '*')) {
            return true;
        }

        if (! is_array($keysreturn)) {
            return false;
        }

        foreach ($keysreturn as $field) {
            if (! in_array($field, $magwees)) {
                return false;
            }
        }

        return true;
    }
  




   


    /*
    Functions to implement
    • CreateDrone - Adds a new drone to the database.
    • UpdateDrone - Updates the relevant fields in the Drones table.
    – current operator id
    – is available
    – latest latitude
    – latest longitude
    – altitude
    – battery level
    • GetAllDrones - Returns all drones in the database.


    8.2.5 Drones
    This table will contain information about the drones:
    • id
    • current operator id (null or references a userId in the Users table)
    • is available
    • latest latitude
    • latest longitude
    • altitude
    • battery level
    */

    public function CreateDrone(array $data) : void{
        if (! $this->conndb) {
            $this->response(false, "DB connection failed.", [], 500);
            return;
        }

        $current_operator_id = $data['current_operator_id'] ?? null;
        $is_available = isset($data['is_available']) ? ($data['is_available'] ? 1 : 0) : 1;
        $latest_latitude = $data['latest_latitude'] ?? null;
        $latest_longitude = $data['latest_longitude'] ?? null;
        $altitude = $data['altitude'] ?? null;
        $battery_level = $data['battery_level'] ?? null;

        //net is_available kan nie null wees nie (want id is auto increment)
        if (!isset($is_available)){
            $this->response(false, "Drone data is missing", [], 400);
            return;
        }

        $sqlStatement = "INSERT INTO Drones (current_operator_id, is_available, latest_latitude, latest_longitude, altitude, battery_level) VALUES (?, ?, ?, ?, ?, ?)";

        $stmt = $this->conndb->prepare($sqlStatement);

        //check if sql is faulty
        if ($stmt === false){
            $this->response(false, "SQL statement invalid", [], 500);
            return;
        }

        $stmt->bind_param(
            //hoe werk auto increment van ons kant af?
            "iidddd", 
            $current_operator_id, $is_available, $latest_latitude, $latest_longitude, $altitude, $battery_level
        );

        if ($stmt->execute()){
            $stmt->close();
            $this->response(true, "Drone created successfully.", [], 200);
        }else{
            error_log("Database error " . $stmt->error);
            $stmt->close();
            $this->response(false, "Error, drone not created.", [], 500);
        }

    }
  



    public function UpdateDrone(array $data) : void{
        if (! $this->conndb){
            $this->response(false, "DB connection failed.", [], 500);
            return;
        }
    
        $id = $data['id'] ?? null;
        if (empty($id)){
            $this->response(false, "Drone ID required.", [], 400);
            return;
        }
    
        $fields = [];
        $values = [];
        $types = "";

        if (isset($data['current_operator_id'])){
            $fields[] = "current_operator_id = ?";
            $values[] = $data['current_operator_id'];
            $types .= "i";
        }
        
        if (isset($data['is_available'])){
            $fields[] = "is_available = ?";
            $values[] = $data['is_available'] ? 1 : 0;
            $types .= "i";
        }
        
        if (isset($data['latest_latitude'])){
            $fields[] = "latest_latitude = ?";
            $values[] = $data['latest_latitude'];
            $types .= "d";
        }
        
        if (isset($data['latest_longitude'])){
            $fields[] = "latest_longitude = ?";
            $values[] = $data['latest_longitude'];
            $types .= "d";
        }
        
        if (isset($data['altitude'])){
            $fields[] = "altitude = ?";
            $values[] = $data['altitude'];
            $types .= "d";
        }
        
        if (isset($data['battery_level'])){
            $fields[] = "battery_level = ?";
            $values[] = $data['battery_level'];
            $types .= "d";
        }
        
        if (empty($fields)){
            $this->response(false, "No fields to update", [], 400);
            return;
        }

        $sqlStatement = "UPDATE Drones SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->conndb->prepare($sqlStatement);
        
        if ($stmt === false) {
            $this->response(false, "SQL statement preparation failed", [], 500);
            return;
        }



        $values[] = $id;
        $types .= "i";

        $stmt->bind_param($types, ...$values);
        
        if ($stmt->execute()){
            $stmt->close();
            $this->response(true, "Drone updated successfully.", [], 200);
        }else{
            error_log("Database error " . $stmt->error);
            $stmt->close();
            $this->response(false, "Error updating drone.", [], 500);
        }
    }

    public function GetAllDrones() : void{
        if (! $this->conndb){
            $this->response(false, "DB connection failed.", [], 500);
            return;
        }
    
        $sqlStatement = "SELECT * FROM Drones";
        $result = $this->conndb->query($sqlStatement);
    
        if ($result === false){
            $this->response(false, "SQL query faulty", [], 500);
            return;
        }
    
        $drones = [];

        $drones = array();

        while (true){
            $row = $result->fetch_assoc();

            if ($row === null){
                break;
            }

            $drones[] = $row;
        }
    
        $this->response(true, "Drones data retrieved successfully", $drones, 200);
    }






   
    public function response($success, $boodskap = "", $data = "", $statusCode = null)
    {

        if ($statusCode === null) {
            if ($success) {
                $statusCode = 200;
            } else {
                if (stripos($boodskap, 'not found') !== false) {
                    $statusCode = 404;
                } elseif (stripos($boodskap, 'unauthorized') !== false || stripos($boodskap, 'authentication') !== false) {
                    $statusCode = 401;
                } elseif (stripos($boodskap, 'forbidden') !== false || stripos($boodskap, 'permission') !== false) {
                    $statusCode = 403;
                } elseif (stripos($boodskap, 'invalid') !== false || stripos($boodskap, 'validation') !== false) {
                    $statusCode = 422;
                } else {
                    $statusCode = 400;
                }
            }
        }

        $timestamp = round(microtime(true) * 1000);

        if ($success) {
            $response = [
                "status"    => "success",
                "timestamp" => $timestamp,
                "data"      => $data,
            ];
        } else {
            $response = [
                "status"    => "error",
                "timestamp" => $timestamp,
                "data"      => $data ?: $boodskap,
            ];
        }

        http_response_code($statusCode);
        header("Content-Type: application/json");
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // logApiActivity(null, $response, $success ? 'success' : 'error');
    }

}

/***************************************************************************************************************************
 REQUESTS
***************************************************************************************************************************/

$api = FF_API::instance();
// $data = json_decode(file_get_contents("php://input"), true);
if (isset($data["type"])) {
    if (($data["type"] == "Register")) {
        $api->registerchecks($data);
        exit;
    } else {
        if ($data["type"] == "GetAllProducts") {
            $api->getProducts($data);
            exit;
        } else {

            if ($data["type"] == "Login") {
                $api->login($data['email'], $data['password']);
                exit;
            } else { if ($data["type"] == "cart") {
                                $api->cart($data);
                                exit;
                            } else {
                                if ($data["type"] == "getcart") {
                                    $api->getCart($data["api_key"]);
                                    exit;
                                } else {
                                    if ($data["type"] == "getorders") {
                                        $api->getOrders($data["api_key"]);
                                        exit;
                                    } else {
                                            //J: create drone
                                            if ($data["type"] == "createdrone"){
                                                $api->CreateDrone($data);
                                                exit;
                                            }
                                            
                                            //J: update drone
                                            if ($data["type"] == "updatedrone"){
                                                $api->UpdateDrone($data);
                                                exit;
                                            }

                                            //J: get all drones
                                            if ($data["type"] == "getalldrones"){
                                                $api->GetAllDrones();
                                                exit;
                                            }

                                            if ($data["type"] == "order") {
                                                $api->placeOrder($data);
                                                exit;
                                            } else {
                                               
                                                    $api->response(false, "missing type parameter", "", 400);
                                                    exit;
                                                
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }



$api->response(false, "Invalid request or missing required parameters.");
