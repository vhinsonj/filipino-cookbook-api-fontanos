<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';  //Load external libraries

$app = AppFactory::create();

// Set the base path so Slim knows where the routes start
$app->setBasePath('/filipino-cookbook-api/public');

// Middleware to parse JSON requests
$app->addBodyParsingMiddleware();               //Slim automatically read incoming JSON data
$app->addRoutingMiddleware();                   //Slim matches the incoming URL to the defined routes
$app->addErrorMiddleware(true, true, true);     // (displayErrorDetails, logErrors, logErrorDetails)

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

// ====== TOKEN-BASED SECURITY MIDDLEWARE ======
$tokenAuthMiddleware = function (Request $request, RequestHandler $handler) {
    // Check for the Authorization header
    $authHeader = $request->getHeaderLine('Authorization');
    $validToken = 'Bearer dmmmsu-cookbook-token-2026';

    // If token is missing or incorrect
    if (empty($authHeader) || $authHeader !== $validToken) {
        $response = new \Slim\Psr7\Response();
        
        $errorPayload = json_encode([
            "status" => "error",
            "message" => "Unauthorized access. Valid API token is required."
        ]);

        $response->getBody()->write($errorPayload);
        
        // Return 401 Unauthorized status and ensure JSON format 
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }

    // If token is valid, proceed to the actual route
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
        $db = getDB();
        $data = $request->getParsedBody(); // Gets the required JSON request body 
        
        $stmt = $db->prepare("INSERT INTO foods (food_name, category_id, origin_id, instructions) VALUES (:food_name, :category_id, :origin_id, :instructions)");
        $stmt->execute([
            'food_name' => $data['food_name'],
            'category_id' => $data['category_id'],
            'origin_id' => $data['origin_id'],
            'instructions' => $data['instructions']
        ]);
        
        // Grab the ID of the food record that was just inserted
        $foodId = $db->lastInsertId();
        
        // Loop through the provided array of ingredient_ids and insert them into the relational table 
        if (!empty($data['ingredient_ids'])) {
            $ingStmt = $db->prepare("INSERT INTO food_ingredients (food_id, ingredient_id) VALUES (:food_id, :ingredient_id)");
            foreach ($data['ingredient_ids'] as $ingId) {
                $ingStmt->execute(['food_id' => $foodId, 'ingredient_id' => $ingId]);
            }
        }

        $payload = json_encode(["status" => "success", "message" => "Food added successfully."]); 
        $response->getBody()->write($payload);
        // Return 201 Created status 
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201); 
    });

})->add($tokenAuthMiddleware); // Applies the token security check to all routes inside this group

$app->run();