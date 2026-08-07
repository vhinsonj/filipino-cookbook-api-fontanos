<?php
session_start();

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';  //Load external libraries

$app = AppFactory::create();

// Set the base path so Slim knows where the routes start
$app->setBasePath('/filipino-cookbook-api/public');

// Middleware to parse JSON requests
$app->addBodyParsingMiddleware();                   //Slim automatically read incoming JSON data
$app->addRoutingMiddleware();                       //To match the incoming HTTP request URL to the defined routes
$app->addErrorMiddleware(false, true, true);        // (displayErrorDetails, logErrors, logErrorDetails)

// ====== DATABASE CONNECTION (PDO) ======
function getDB() {
    $dbhost = 'localhost';
    $dbuser = 'YOUR_DATABASE_USERNAME'; 
    $dbpass = 'YOUR_DATABASE_PASSWORD';     
    $dbname = 'filipino_cookbook_api';

    $mysql_conn_string = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
    $dbConnection = new PDO($mysql_conn_string, $dbuser, $dbpass);
    $dbConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);         // Set PDO to throw exceptions on errors
    $dbConnection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);    // Return database results as associative arrays by default
    
    return $dbConnection;
}

// ====== RATE LIMITING MIDDLEWARE ======
$rateLimitMiddleware = function (Request $request, RequestHandler $handler) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $time = time();
    $limit = 60; // Max 60 requests
    $window = 60; // Per 60 seconds

    // Checks if the rate limit array for the specific IP address already exists in the session.
    if (!isset($_SESSION['rate_limit'][$ip])) {
        $_SESSION['rate_limit'][$ip] = [];  //If not, it will create a new empty array for the IP address/user
    }

    // Filter out requests older than the 60-second window
    $_SESSION['rate_limit'][$ip] = array_filter($_SESSION['rate_limit'][$ip], function($timestamp) use ($time, $window) {
        return ($time - $timestamp) < $window;
    });

    // Block request if over the limit
    if (count($_SESSION['rate_limit'][$ip]) >= $limit) {
        $response = new \Slim\Psr7\Response();
        $errorPayload = json_encode(["status" => "error", "message" => "Too many requests. Please try again later."]);
        $response->getBody()->write($errorPayload);
        return $response->withHeader('Content-Type', 'application/json')->withStatus(429);
    }

    // Log this request timestamp
    $_SESSION['rate_limit'][$ip][] = $time;
    return $handler->handle($request);
};

// Apply rate limiting to the entire app
$app->add($rateLimitMiddleware);

// ====== ROLE-BASED ENDPOINT ACCESS MIDDLEWARE ====== 
$tokenAuthMiddleware = function (Request $request, RequestHandler $handler) {
    $authHeader = $request->getHeaderLine('Authorization');
    
    // Define Roles
    $adminToken = 'Bearer YOUR_ACCESS_TOKEN';
    $userToken = 'Bearer YOUR_ACCESS_TOKEN';

    $role = null;

    if ($authHeader === $adminToken) {
        $role = 'admin';
    } elseif ($authHeader === $userToken) {
        $role = 'user';
    }

    if (!$role) {
        $response = new \Slim\Psr7\Response();
        $errorPayload = json_encode(["status" => "error", "message" => "Unauthorized access. Valid API token is required."]);
        $response->getBody()->write($errorPayload);
        return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
    }

    // Attach the assigned role to the request so downstream routes know who is calling
    $request = $request->withAttribute('role', $role);
    
    return $handler->handle($request);
};

// ====== Public Welcome Route ======
// This route does not require a token
$app->get('/', function (Request $request, Response $response, $args) {
    $payload = json_encode([
        "message" => "Welcome to the Secured Filipino Cookbook API", 
        "note" => "Use a valid Bearer token to access /api endpoints." 
    ]);
    $response->getBody()->write($payload);
    return $response->withHeader('Content-Type', 'application/json');
});

// ====== SECURED /api ROUTES ======
$app->group('/api', function (\Slim\Routing\RouteCollectorProxy $group) {
    
    // ====== Get All Foods ======
    $group->get('/foods', function (Request $request, Response $response, $args) {
        $db = getDB();
        // Retrieve food records with category and origin 
        $stmt = $db->query("SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions 
                            FROM foods f 
                            JOIN categories c ON f.category_id = c.category_id 
                            JOIN origins o ON f.origin_id = o.origin_id");
        $foods = $stmt->fetchAll();

        // Loop to attach ingredients to each food record
        foreach ($foods as &$food) {
            $ingStmt = $db->prepare("SELECT i.ingredient_name 
                                     FROM ingredients i 
                                     JOIN food_ingredients fi ON i.ingredient_id = fi.ingredient_id 
                                     WHERE fi.food_id = :food_id");
            $ingStmt->execute(['food_id' => $food['food_id']]);
            // Extract just the column values into a flat array
            $food['ingredients'] = $ingStmt->fetchAll(PDO::FETCH_COLUMN); 
        }

        $response->getBody()->write(json_encode($foods));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ====== Get Food by ID ======
    $group->get('/foods/{id}', function (Request $request, Response $response, $args) {
        $id = $args['id'];
        $db = getDB();
        
        $stmt = $db->prepare("SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions 
                              FROM foods f 
                              JOIN categories c ON f.category_id = c.category_id 
                              JOIN origins o ON f.origin_id = o.origin_id 
                              WHERE f.food_id = :id");
        $stmt->execute(['id' => $id]);
        $food = $stmt->fetch();

        if (!$food) {
            // Return 404 if food ID does not exist
            $errorPayload = json_encode(["status" => "error", "message" => "Food not found"]);
            $response->getBody()->write($errorPayload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $ingStmt = $db->prepare("SELECT i.ingredient_name FROM ingredients i JOIN food_ingredients fi ON i.ingredient_id = fi.ingredient_id WHERE fi.food_id = :id");
        $ingStmt->execute(['id' => $id]);
        $food['ingredients'] = $ingStmt->fetchAll(PDO::FETCH_COLUMN);

        $response->getBody()->write(json_encode($food));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ====== Search Food by Name ======
    $group->get('/foods/search/{name}', function (Request $request, Response $response, $args) {
        // Use wildcards for a partial search match
        $name = "%" . $args['name'] . "%"; 
        $db = getDB();
        
        $stmt = $db->prepare("SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions 
                              FROM foods f 
                              JOIN categories c ON f.category_id = c.category_id 
                              JOIN origins o ON f.origin_id = o.origin_id 
                              WHERE f.food_name LIKE :name");
        $stmt->execute(['name' => $name]);
        $foods = $stmt->fetchAll();

         if (empty($foods)) {
            // Return 404 if food name does not exist
            $errorPayload = json_encode(["status" => "error", "message" => "Food not found"]);
            $response->getBody()->write($errorPayload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        foreach ($foods as &$food) {      
            $ingStmt = $db->prepare("SELECT i.ingredient_name FROM ingredients i JOIN food_ingredients fi ON i.ingredient_id = fi.ingredient_id WHERE fi.food_id = :id");
            $ingStmt->execute(['id' => $food['food_id']]);
            $food['ingredients'] = $ingStmt->fetchAll(PDO::FETCH_COLUMN);
        }   

        unset($food);

        $response->getBody()->write(json_encode($foods));
        return $response->withHeader('Content-Type', 'application/json');
    });

    //====== Get All Categories ======
    $group->get('/categories', function (Request $request, Response $response, $args) {
        $db = getDB();
        $stmt = $db->query("SELECT * FROM categories");
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ====== Get All Ingredients ======
    $group->get('/ingredients', function (Request $request, Response $response, $args) {
        $db = getDB();
        $stmt = $db->query("SELECT * FROM ingredients");
        $response->getBody()->write(json_encode($stmt->fetchAll()));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ====== Add New Food ======
    $group->post('/foods', function (Request $request, Response $response, $args) {
        // --- ROLE-BASED ACCESS CHECK ---
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            $response->getBody()->write(json_encode(["status" => "error", "message" => "Forbidden. Only administrators can add new foods."]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }
        
        $data = $request->getParsedBody(); // Gets the required JSON request body

        // --- INPUT VALIDATION ---
        if (empty($data['food_name']) || empty($data['category_id']) || empty($data['origin_id']) || empty($data['instructions'])) {
            $response->getBody()->write(json_encode(["status" => "error", "message" => "Missing required fields (food_name, category_id, origin_id, instructions)."]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // --- INPUT SANITIZATION ---
        // Strip HTML tags and convert special characters to prevent Cross-Site Scripting (XSS)
        $clean_food_name = htmlspecialchars(strip_tags($data['food_name']));
        $clean_instructions = htmlspecialchars(strip_tags($data['instructions']));
        
        // Ensure IDs are strictly integers
        $clean_category_id = filter_var($data['category_id'], FILTER_VALIDATE_INT);
        $clean_origin_id = filter_var($data['origin_id'], FILTER_VALIDATE_INT);

        if (!$clean_category_id || !$clean_origin_id) {
            $response->getBody()->write(json_encode(["status" => "error", "message" => "Category and Origin IDs must be valid numbers."]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $db = getDB();
          
        
        // Use sanitized variables for database execution
        $stmt = $db->prepare("INSERT INTO foods (food_name, category_id, origin_id, instructions) VALUES (:food_name, :category_id, :origin_id, :instructions)");
        $stmt->execute([
            'food_name' => $clean_food_name,
            'category_id' => $clean_category_id,
            'origin_id' => $clean_origin_id,
            'instructions' => $clean_instructions
        ]);
        
        // Grab the ID of the food record that was just inserted
        $foodId = $db->lastInsertId();
        
        if (!empty($data['ingredient_ids'])) {
            $ingStmt = $db->prepare("INSERT INTO food_ingredients (food_id, ingredient_id) VALUES (:food_id, :ingredient_id)");
            foreach ($data['ingredient_ids'] as $ingId) {
                // Ensure ingredient ID is also an integer before inserting
                $clean_ing_id = filter_var($ingId, FILTER_VALIDATE_INT);
                if ($clean_ing_id) {
                    $ingStmt->execute(['food_id' => $foodId, 'ingredient_id' => $clean_ing_id]);
                }
            }
        }

        $response->getBody()->write(json_encode(["status" => "success", "message" => "Food added successfully."])); 
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201); 
    });

})->add($tokenAuthMiddleware); // Applies the token security check to all routes inside this group

$app->run();