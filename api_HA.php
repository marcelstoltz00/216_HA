<?php
session_start();
require_once __DIR__ . 'config_HA.php';

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

        require_once __DIR__ . 'config_HA.php';

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

            $stelling = $this->conndb->prepare("SELECT password, sout, api_key, name FROM Users WHERE email = ?");
            $stelling->bind_param("s", $email);
            $stelling->execute();
            $resultaat = $stelling->get_result();

            if ($userData = $resultaat->fetch_assoc()) {

                $hpw = hash("sha512", $password . $userData['sout']);

                if ($hpw === $userData['password']) {
                    $stelling->close();
                    $this->response(true, "", [["apikey" => $userData['api_key'], "username" => $userData['name']]], 200);
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
        $stuur_sql = "SELECT product_id, quantity FROM u24566552_carts WHERE customer_id = ?";
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
    
        $sql_vir_orders = "SELECT order_id, state, delivery_date, created_at FROM u24566552_orders WHERE customer_id = ?";
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
        $sqlOrderPr = "SELECT order_id, product_id, quantity FROM u24566552_order_products WHERE order_id IN ($stel_in)";
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
   
        $stuur_sql = "INSERT INTO u24566552_carts (customer_id, product_id, quantity, created_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE quantity = quantity + 1";
        $boodskap = "Product added to cart.";
    } else { 
        $stuur_sql = "DELETE FROM u24566552_carts WHERE customer_id = ? AND product_id = ?";
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
   
        return;
    }


    $this->conndb->begin_transaction();

    try {

        $item_treasure_chest = [];
        $sql_getC = "SELECT product_id, quantity FROM u24566552_carts WHERE customer_id = ?";
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

   
        $state = "Storage"; 
        $deliveryDate = "2025-05-19"; 

        $sqlIO = "INSERT INTO u24566552_orders (customer_id, state, delivery_date, created_at) VALUES (?, ?, ?, NOW())";
        $stmntIO = $this->conndb->prepare($sqlIO);

         if ($stmntIO === false) {
             throw new Exception("DB prepare insert order error: " . $this->conndb->error);
         }

        $stmntIO->bind_param("sss", $apiKey, $state, $deliveryDate);
        $stmntIO->execute();
        $orderId = $this->conndb->insert_id;
        $stmntIO->close();

      
        $sqlIOP = "INSERT INTO u24566552_order_products (order_id, product_id, quantity) VALUES (?, ?, ?)";
        $stmtIOP = $this->conndb->prepare($sqlIOP);

      

        foreach ($item_treasure_chest as $item) {
            $stmtIOP->bind_param("iii", $orderId, $item['product_id'], $item['quantity']);
            $stmtIOP->execute();

        }
        $stmtIOP->close();



        $sql_leeg = "DELETE FROM u24566552_carts WHERE customer_id = ?";
        $stmtn_leeg = $this->conndb->prepare($sql_leeg);

   
        $stmtn_leeg->bind_param("s", $apiKey);
        $stmtn_leeg->execute();
        $stmtn_leeg->close();


        $this->conndb->commit();
        $this->response(true, "Order placed successfully.", ['order_id' => $orderId], 200);

    } catch (Exception $e) {
        
        $this->conndb->rollback();
        error_log("Order placement failed: " . $e->getMessage());
        $this->response(false, "Failed to place order.", [], 500);
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

        if (! in_array(trim($data['user_type']), ["Customer", "Courier", "Inventory Manager"])) {
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

            $stelling = $this->conndb->prepare(
                "INSERT INTO Users (name, surname, email, password, sout, type,api_key)
                VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stelling->bind_param("sssssss",
                $data['name'],
                $data['surname'],
                $data['email'],
                $hashed_pw,
                $sout,
                $data['user_type'],
                $FF_key
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
                $this->pref_table($FF_key);

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




    public function getProducts($data): void
    {
        //     $headers = getallheaders();
        //   //  logApiActivity(['headers' => $headers], null, 'debug');

        //     if (! isset($headers['Authorization'])) {
        //         $this->response(false, "Missing Authorization header");
        //         return;
        //     }

        //     $authHeader = $headers['Authorization'];
        //     if (strpos($authHeader, 'Bearer ') !== 0) {
        //         $this->response(false, "Invalid Authorization format");
        //         return;
        //     }

        //     $apiKey = trim(substr($authHeader, 7));
        //    // logApiActivity($data, ['extracted_api_key' => $apiKey], 'debug');
        //     if (! $this->checkApi4key($apiKey)) {
        //         $this->response(false, "Invalid API key");
        //         return;
        //     }

        $headers = getallheaders();
        //  logApiActivity(['headers' => $headers], null, 'debug');

        if (! isset($headers['X-Auth'])) {                // Changed header name to X-Auth
            $this->response(false, "Missing X-Auth header"); // Updated error boodskap
            return;
        }

        $authHeader = $headers['X-Auth']; // Changed variable name to reflect the new header
        if (strpos($authHeader, 'Bearer ') !== 0) {
            $this->response(false, "Invalid X-Auth format"); // Updated error boodskap
            return;
        }

        $apiKey = trim(substr($authHeader, 7));
        // logApiActivity($data, ['extracted_api_key' => $apiKey], 'debug');

        if (! $this->checkApi4key($apiKey)) {
            $this->response(false, "Invalid API key");
            return;
        }

        try {

            $limit = isset($data['limit']) ? max(1, min(500, intval($data['limit']))) : 50;

            $sort = isset($data['sort']) ? $data['sort'] : 'id';

            $af = ['id', 'title', 'brand', 'initial_price', 'final_price',
                'date_first_available', 'manufacturer', 'department'];

            if (! in_array($sort, $af)) {
                $this->response(false, "Invalid 'sort' fields.");
                return;
            }

            $order = isset($data['order']) ? strtoupper($data['order']) : 'ASC';
            if ($order !== 'ASC' && $order !== 'DESC') {
                $this->response(false, "Invalid 'order' field.");
                return;
            }

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

            $fuzzy    = isset($data['fuzzy']) ? $data['fuzzy'] : true;
            $search   = isset($data['search']) && $this->checkSoek($data['search']) ? $data['search'] : null;
            $products = $this->queryProducts($keysreturn, $search, $fuzzy, $sort, $order, $limit);
            $products = $this->convertPricesToRand($products);

            $this->response(true, "Success", $products);

        } catch (Exception $e) {
            $this->response(false, "Failed to retrieve products: " . $e->getMessage());
            exit;
        }
    }

    public function checkSoek($search): bool
    {
        $magDieWees = [
            'id',
            'title',
            'brand',
            'categories',
            'department',
            'manufacturer',
            'features',
            'price_min',
            'price_max',
        ];

        if (is_null($search) || empty($search)) {
            return true;
        }

        if (! is_array($search)) {
            return false;
        }

        foreach ($search as $kolom => $searchTerm) {
            if (! in_array($kolom, $magDieWees)) {
                return false;
            }

        }

        return true;
    }

    private function queryProducts($awaitReturn, $search, $fuzzy, $sort, $order, $limit): array
    {

        $sql_statementtt = "SELECT ";

        if (in_array('*', $awaitReturn)) {
            $sql_statementtt .= "* ";
        } else {

            if (! in_array('id', $awaitReturn)) {
                $awaitReturn[] = 'id';
            }
            if (! in_array('initial_price', $awaitReturn) && ! in_array('final_price', $awaitReturn)) {
                $awaitReturn[] = 'initial_price';
                $awaitReturn[] = 'final_price';
            }

            $magwees = [
                'id',
                'title',
                'brand',
                'description',
                'initial_price',
                'final_price',
                'categories',
                'image_url',
                'product_dimensions',
                'date_first_available', 'currency',
                'manufacturer',
                'department',
                'features',
                'is_available',
                'images',
                'country_of_origin',
            ];
            $maakSkoone = array_filter($awaitReturn, function ($field) use ($magwees) {
                return in_array($field, $magwees);
            });

            if (empty($maakSkoone)) {
                throw new Exception("Invalid fields in 'return' parameter.");
            }

            $sql_statementtt .= implode(', ', $maakSkoone);
        }

        $sql_statementtt .= " FROM products WHERE 1=1";

        $kategorie = [];
        $tipes     = '';

        if ($search) {
            foreach ($search as $kolom => $value) {

                $magDieWees = ['id', 'title', 'categories', 'brand', 'manufacturer', 'department', 'features', 'price_min', 'price_max'];
                if (! in_array($kolom, $magDieWees)) {
                    throw new Exception("Invalid search kolom: $kolom");
                }

                if ($kolom === 'price_min') {
                    $sql_statementtt .= " AND final_price >= ?";
                    $kategorie[] = $value;
                    $tipes .= 'd';
                } elseif ($kolom === 'price_max') {
                    $sql_statementtt .= " AND final_price <= ?";
                    $kategorie[] = $value;
                    $tipes .= 'd';
                } else {
                    if ($fuzzy) {
                        $sql_statementtt .= " AND $kolom LIKE ?";
                        $value = "%" . $value . "%";
                    } else {
                        $sql_statementtt .= " AND $kolom = ?";
                    }
                    $kategorie[] = $value;
                    $tipes .= 's'; // 's' for strin
                }

            }
        }

        $laatDieToe = ['id', 'title', 'brand', 'initial_price', 'final_price', 'date_first_available', 'manufacturer', 'department'];
        if (! in_array($sort, $laatDieToe)) {
            throw new Exception("Invalid sort kolom: $sort");
        }
        $sql_statementtt .= " ORDER BY $sort $order";

        $sql_statementtt .= " LIMIT ?";
        $kategorie[] = $limit;
        $tipes .= 'i';

        //    logApiActivity($sql_statementtt, "", "");

        try {

            $sqlQuery = $this->conndb->prepare($sql_statementtt);

            if (! empty($kategorie)) {
                $sqlQuery->bind_param($tipes, ...$kategorie);
            }
            //  logApiActivity($sqlQuery, "", "");
            $sqlQuery->execute();
            $resultaat = $sqlQuery->get_result();
            $products  = [];

            while ($rye = $resultaat->fetch_assoc()) {
                $products[] = $rye;
            }

            $sqlQuery->close();
            if (isset($search['price_min']) || isset($search['price_max'])) {
                $minPrice = isset($search['price_min']) ? $search['price_min'] : 0;
                $maxPrice = isset($search['price_max']) ? $search['price_max'] : 0;

                $products = array_filter($products, function ($produk) use ($minPrice, $maxPrice) {
                    $price = $produk['final_price'];

                    if ($minPrice !== null && $price < $minPrice) {
                        return false;
                    }

                    if ($maxPrice !== null && $price > $maxPrice) {
                        return false;
                    }

                    return true;
                });
            }

            $products = array_map(function ($produk) {
                unset($produk['created_at'], $produk['updated_at']);
                return $produk;
            }, $products);
            return $products;
        } catch (mysqli_sql_exception $e) {
            throw new Exception("Database query failed: " . $e->getMessage());
        }
    }

    public function verifyAr(array $keysreturn): bool
    {
        $magwees = [
            'id',
            'title',
            'brand',
            'description',
            'initial_price',
            'final_price',
            'categories',
            'image_url',
            'product_dimensions',
            'date_first_available', 'currency',
            'manufacturer',
            'department',
            'features',
            'is_available',
            'images',
            'country_of_origin',
        ];

        if (in_array('*', $keysreturn) || (is_string($keysreturn) && $keysreturn === '*')) {
            return true;
        }

        if (! is_array($keysreturn)) {
            return false; // Handle cases where it's not an array or '*'
        }

        foreach ($keysreturn as $field) {
            if (! in_array($field, $magwees)) {
                return false;
            }
        }

        return true;
    }

    private function convertPricesToRand($produkte): array
    {

        $RatesInfo = $this->getCurrencyRates();

        if (! $RatesInfo || ! isset($RatesInfo['data'])) {

            return $produkte;
        }
        $rates = $RatesInfo['data'];
        foreach ($produkte as &$produk) {

            $currency = isset($produk['currency']) ? $produk['currency'] : 'USD';

            if ($currency === 'ZAR') {
                continue;
            }

            if (! isset($rates[$currency])) {
                continue;
            }

            $currencyToZarRate = $rates['ZAR'] / $rates[$currency];

            if (isset($produk['initial_price'])) {
                $produk['initial_price'] = $this->convertToRand($produk['initial_price'], $currencyToZarRate);
            }

            if (isset($produk['final_price'])) {
                $produk['final_price'] = $this->convertToRand($produk['final_price'], $currencyToZarRate);
            }

            $produk['currency'] = 'ZAR';
        }

        return $produkte;
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
