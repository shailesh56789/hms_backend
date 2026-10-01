<?php
require_once __DIR__ . '/config/app.php';
apply_cors_headers();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Request.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/DashboardController.php';
require_once __DIR__ . '/controllers/PatientController.php';
require_once __DIR__ . '/controllers/DoctorController.php';
require_once __DIR__ . '/controllers/UserController.php';
require_once __DIR__ . '/controllers/ProductController.php';
require_once __DIR__ . '/controllers/DiagnosisController.php';

// Initialize Google Sheets DB
$db = SheetsDB::getInstance();

// Route parsing
$scriptDir  = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path       = substr($requestUri, strlen($scriptDir));
$path       = '/' . trim($path, '/');
$method     = $_SERVER['REQUEST_METHOD'];
$segments   = array_values(array_filter(explode('/', $path)));

if (empty($segments) || $segments[0] !== 'api') {
    Response::notFound("Endpoint not found");
}

$resource = $segments[1] ?? null;
$sub1     = $segments[2] ?? null;
$sub2     = $segments[3] ?? null;

try {
    switch ($resource) {

        case 'auth':
            $controller = new AuthController($db);
            switch (true) {
                case $sub1 === 'login'           && $method === 'POST': $controller->login(); break;
                case $sub1 === 'register'        && $method === 'POST': $controller->register(); break;
                case $sub1 === 'logout'          && $method === 'POST': $controller->logout(); break;
                case $sub1 === 'profile'         && $method === 'GET':  $controller->profile(); break;
                case $sub1 === 'profile'         && $method === 'PUT':  $controller->updateProfile(); break;
                case $sub1 === 'change-password' && $method === 'POST': $controller->changePassword(); break;
                case $sub1 === 'forgot-password' && $method === 'POST': $controller->forgotPassword(); break;
                case $sub1 === 'reset-password'  && $method === 'POST': $controller->resetPassword(); break;
                default: Response::notFound("Auth endpoint not found");
            }
            break;

        case 'dashboard':
            $controller = new DashboardController($db);
            switch (true) {
                case $sub1 === 'admin'   && $method === 'GET': $controller->admin(); break;
                case $sub1 === 'doctor'  && $method === 'GET': $controller->doctor(); break;
                case $sub1 === 'patient' && $method === 'GET': $controller->patient(); break;
                default: Response::notFound("Dashboard endpoint not found");
            }
            break;

        case 'patients':
            $controller = new PatientController($db);
            switch (true) {
                case $sub1 === 'by-mobile'                         && $method === 'GET':    $controller->byMobile(); break;
                case $sub1 === null                                && $method === 'GET':    $controller->index(); break;
                case $sub1 === null                                && $method === 'POST':   $controller->store(); break;
                case $sub1 !== null && $sub2 === 'diagnoses'       && $method === 'GET':    $controller->diagnoses($sub1); break;
                case $sub1 !== null && $sub2 === null              && $method === 'GET':    $controller->show($sub1); break;
                case $sub1 !== null && $sub2 === null              && $method === 'PUT':    $controller->update($sub1); break;
                case $sub1 !== null && $sub2 === null              && $method === 'DELETE': $controller->destroy($sub1); break;
                default: Response::notFound("Patient endpoint not found");
            }
            break;

        case 'doctors':
            $controller = new DoctorController($db);
            switch (true) {
                case $sub1 === 'list' && $method === 'GET':    $controller->list(); break;
                case $sub1 === null   && $method === 'GET':    $controller->index(); break;
                case $sub1 === null   && $method === 'POST':   $controller->store(); break;
                case $sub1 !== null   && $method === 'GET':    $controller->show($sub1); break;
                case $sub1 !== null   && $method === 'PUT':    $controller->update($sub1); break;
                case $sub1 !== null   && $method === 'DELETE': $controller->destroy($sub1); break;
                default: Response::notFound("Doctor endpoint not found");
            }
            break;

        case 'users':
            $controller = new UserController($db);
            switch (true) {
                case $sub1 === null && $method === 'GET':    $controller->index(); break;
                case $sub1 === null && $method === 'POST':   $controller->store(); break;
                case $sub1 !== null && $method === 'GET':    $controller->show($sub1); break;
                case $sub1 !== null && $method === 'PUT':    $controller->update($sub1); break;
                case $sub1 !== null && $method === 'DELETE': $controller->destroy($sub1); break;
                default: Response::notFound("User endpoint not found");
            }
            break;

        case 'products':
            $controller = new ProductController($db);
            switch (true) {
                case $sub1 === 'list' && $method === 'GET':    $controller->list(); break;
                case $sub1 === null   && $method === 'GET':    $controller->index(); break;
                case $sub1 === null   && $method === 'POST':   $controller->store(); break;
                case $sub1 !== null   && $method === 'GET':    $controller->show($sub1); break;
                case $sub1 !== null   && $method === 'PUT':    $controller->update($sub1); break;
                case $sub1 !== null   && $method === 'DELETE': $controller->destroy($sub1); break;
                default: Response::notFound("Product endpoint not found");
            }
            break;

        case 'diagnoses':
            $controller = new DiagnosisController($db);
            switch (true) {
                case $sub1 === null && $method === 'GET':    $controller->index(); break;
                case $sub1 === null && $method === 'POST':   $controller->store(); break;
                case $sub1 !== null && $method === 'GET':    $controller->show($sub1); break;
                case $sub1 !== null && $method === 'PUT':    $controller->update($sub1); break;
                case $sub1 !== null && $method === 'DELETE': $controller->destroy($sub1); break;
                default: Response::notFound("Diagnosis endpoint not found");
            }
            break;

        default:
            Response::notFound("Resource not found");
    }
} catch (Throwable $e) {
    Response::error("Server error: " . $e->getMessage(), 500);
}
