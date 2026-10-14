<?php
/*
 * Charter - single-file diagram editor for flowcharts, DFDs and ERDs.
 * Application version 0.0.2
 * Copyright (c) 2026 Simon Walker & Timothy Lever, Department of Education, Queensland, Australia
 * -------------------------
 *
 * Keep charter-config.php in the same directory as this file.
 * PHP 5.5+ and the MySQLi extension are required.
 */

require(__DIR__ . DIRECTORY_SEPARATOR . 'charter-config.php');

if (!isset($urlRoot)) {
    $urlRoot = '';
}

/* -------------------------------------------------------------------------
 * Same-file JSON actions
 * ---------------------------------------------------------------------- */
$charterAction = isset($_GET['charter_action'])
    ? trim((string)$_GET['charter_action'])
    : '';

if ($charterAction !== '') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
    ob_start();
}

$charterResponseSent = false;

function charter_clear_output()
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

function charter_json_response($data, $status)
{
    global $charterResponseSent;
    $charterResponseSent = true;

    if (is_array($data) && !isset($data['httpStatus'])) {
        $data['httpStatus'] = (int)$status;
    }

    charter_clear_output();
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Charter-Status: ' . (int)$status);

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{"ok":false,"error":"The server could not create a JSON response.","httpStatus":500}';
    }

    echo $json;
    exit;
}

if ($charterAction !== '') {
    register_shutdown_function(function () {
        global $charterResponseSent;
        if ($charterResponseSent) {
            return;
        }

        $error = error_get_last();
        if (!$error) {
            return;
        }

        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        charter_clear_output();
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Charter-Status: 500');
        echo json_encode(array(
            'ok' => false,
            'error' => 'PHP fatal error: ' . $error['message'],
            'errorFile' => basename($error['file']),
            'errorLine' => (int)$error['line'],
            'httpStatus' => 500
        ));
    });
}

function charter_valid_key($value)
{
    return is_string($value) && preg_match('/^[a-f0-9]{32}$/i', $value) === 1;
}

function charter_database_connection()
{
    global $dbHost, $dbName, $dbUser, $dbPassword;

    foreach (array('dbHost', 'dbName', 'dbUser', 'dbPassword') as $variable) {
        if (!isset($GLOBALS[$variable])) {
            throw new RuntimeException('charter-config.php is missing $' . $variable . '.');
        }
    }

    if (trim((string)$dbHost) === '' || trim((string)$dbName) === '' || trim((string)$dbUser) === '') {
        throw new RuntimeException('charter-config.php contains an empty database host, name or user.');
    }

    if (!extension_loaded('mysqli')) {
        throw new RuntimeException('The PHP MySQLi extension is not enabled on this server.');
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = @new mysqli($dbHost, $dbUser, $dbPassword, $dbName);

    if ($mysqli->connect_errno) {
        throw new RuntimeException(
            'Database connection failed. MySQL error ' .
            $mysqli->connect_errno . ': ' . $mysqli->connect_error
        );
    }

    if (!$mysqli->set_charset('utf8mb4') && !$mysqli->set_charset('utf8')) {
        $number = $mysqli->errno;
        $message = $mysqli->error;
        $mysqli->close();
        throw new RuntimeException(
            'The database character set could not be configured. MySQL error ' .
            $number . ': ' . $message
        );
    }

    return $mysqli;
}

function charter_database_prepare($mysqli, $sql)
{
    $statement = $mysqli->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException(
            'Database statement preparation failed. MySQL error ' .
            $mysqli->errno . ': ' . $mysqli->error
        );
    }
    return $statement;
}

function charter_database_execute($statement)
{
    if (!$statement->execute()) {
        throw new RuntimeException(
            'Database statement execution failed. MySQL error ' .
            $statement->errno . ': ' . $statement->error
        );
    }
}

function charter_project_key_exists($mysqli, $shareKey)
{
    $statement = charter_database_prepare(
        $mysqli,
        'SELECT share_key FROM charter_projects WHERE share_key = ? LIMIT 1'
    );
    if (!$statement->bind_param('s', $shareKey)) {
        throw new RuntimeException('The share-key parameter could not be bound.');
    }
    charter_database_execute($statement);
    if (!$statement->bind_result($foundKey)) {
        throw new RuntimeException('The share-key result could not be read.');
    }
    $exists = $statement->fetch() === true;
    $statement->close();
    return $exists;
}

function charter_template_key_exists($mysqli, $templateKey)
{
    $statement = charter_database_prepare(
        $mysqli,
        'SELECT template_key FROM charter_templates WHERE template_key = ? LIMIT 1'
    );
    if (!$statement->bind_param('s', $templateKey)) {
        throw new RuntimeException('The template-key parameter could not be bound.');
    }
    charter_database_execute($statement);
    if (!$statement->bind_result($foundKey)) {
        throw new RuntimeException('The template-key result could not be read.');
    }
    $exists = $statement->fetch() === true;
    $statement->close();
    return $exists;
}

function charter_generate_key()
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(16));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        $strong = false;
        $bytes = openssl_random_pseudo_bytes(16, $strong);
        if ($bytes !== false && strlen($bytes) === 16) {
            return bin2hex($bytes);
        }
    }
    if (function_exists('mcrypt_create_iv') && defined('MCRYPT_DEV_URANDOM')) {
        $bytes = mcrypt_create_iv(16, MCRYPT_DEV_URANDOM);
        if ($bytes !== false && strlen($bytes) === 16) {
            return bin2hex($bytes);
        }
    }
    return substr(hash('sha256', uniqid((string)mt_rand(), true) . microtime(true)), 0, 32);
}

function charter_generate_project_id()
{
    $hex = charter_generate_key();
    return substr($hex, 0, 8) . '-' .
        substr($hex, 8, 4) . '-4' .
        substr($hex, 13, 3) . '-a' .
        substr($hex, 17, 3) . '-' .
        substr($hex, 20, 12);
}

function charter_secure_equals($known, $provided)
{
    $known = (string)$known;
    $provided = (string)$provided;
    if (function_exists('hash_equals')) {
        return hash_equals($known, $provided);
    }
    if (strlen($known) !== strlen($provided)) {
        return false;
    }
    $difference = 0;
    for ($index = 0, $length = strlen($known); $index < $length; $index++) {
        $difference |= ord($known[$index]) ^ ord($provided[$index]);
    }
    return $difference === 0;
}

function charter_html_escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function charter_application_url()
{
    global $urlRoot;
    if (isset($urlRoot) && trim((string)$urlRoot) !== '') {
        return rtrim((string)$urlRoot, '/') . '/' . rawurlencode(basename(__FILE__));
    }

    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $path = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/' . basename(__FILE__);
    return $scheme . '://' . $host . $path;
}

function charter_share_url($shareKey)
{
    return charter_application_url() . '?share=' . rawurlencode($shareKey);
}

function charter_new_session_url($shareKey)
{
    return charter_application_url() . '?new_session=' . rawurlencode($shareKey);
}

function charter_read_request_body()
{
    if (isset($_POST['payload'])) {
        return json_decode((string)$_POST['payload'], true);
    }
    return json_decode(file_get_contents('php://input'), true);
}

function charter_default_sources()
{
    return array(
        'flowchart' => array(
            'type' => 'flowchart',
            'sourceType' => 'flowgorithm',
            'title' => 'My Flow Diagram',
            'source' => "title My Flow Diagram

# you can double-click on the
# line between the Start and End
# in order to add a new item
terminal Start
terminal End

Start -> End






# @visual-layout positions for the designer:"
        ),
        'flowchart-flowgorithm' => array(
            'type' => 'flowchart',
            'sourceType' => 'flowgorithm',
            'title' => 'My Flow Diagram',
            'source' => "title My Flow Diagram

# you can double-click on the
# line between the Start and End
# in order to add a new item
terminal Start
terminal End

Start -> End






# @visual-layout positions for the designer:"
        ),
        'flowchart-python' => array(
            'type' => 'flowchart',
            'sourceType' => 'python',
            'title' => 'Circle area',
            'source' => "radius = float(input(\"Radius: \"))\narea = 3.14159 * radius * radius\nif area >= 100:\n    print(\"Large circle\")\nelse:\n    print(\"Small circle\")"
        ),
        'flowchart-qcaa' => array(
            'type' => 'flowchart',
            'sourceType' => 'qcaa',
            'title' => 'Circle area',
            'source' => "BEGIN\n    INPUT radius\n    CALCULATE area = 3.14159 * radius * radius\n    IF area >= 100 THEN\n        OUTPUT \"Large circle\"\n    ELSE\n        OUTPUT \"Small circle\"\n    ENDIF\nEND"
        ),
        'flowchart-gatton' => array(
            'type' => 'flowchart',
            'sourceType' => 'gatton',
            'title' => 'Circle area',
            'source' => "START\n    GET radius\n    SET area = 3.14159 * radius * radius\n    IF area >= 100 THEN\n        DISPLAY \"Large circle\"\n    ELSE\n        DISPLAY \"Small circle\"\n    ENDIF\nSTOP"
        ),
        'flowchart-javascript' => array(
            'type' => 'flowchart',
            'sourceType' => 'javascript',
            'title' => 'Circle area',
            'source' => "const radius = Number(prompt(\"Radius: \"));\nconst area = 3.14159 * radius * radius;\nif (area >= 100) {\n    console.log(\"Large circle\");\n} else {\n    console.log(\"Small circle\");\n}"
        ),
        'dfd' => array(
            'type' => 'dfd',
            'sourceType' => 'dfd',
            'title' => 'Online ordering system',
            'source' => "title: Online ordering system\n\nentity Customer \"Customer\"\nentity PaymentGateway \"Payment gateway\"\nprocess CaptureOrder \"Capture order\"\nprocess TakePayment \"Take payment\"\nprocess PrepareOrder \"Prepare order\"\nstore D1 \"Orders\"\nstore D2 \"Products\"\n\nCustomer -> CaptureOrder : order details\nCaptureOrder -> D2 : product query\nD2 -> CaptureOrder : product details\nCaptureOrder -> D1 : new order\nCaptureOrder -> TakePayment : payment request\nTakePayment -> PaymentGateway : card transaction\nPaymentGateway -> TakePayment : payment result\nTakePayment -> PrepareOrder : paid order\nPrepareOrder -> Customer : collection notice"
        ),
        'erd-sql' => array(
            'type' => 'erd',
            'sourceType' => 'sql',
            'title' => 'School enrolments',
            'source' => "CREATE TABLE students (\n    student_id INT PRIMARY KEY,\n    given_name VARCHAR(80) NOT NULL,\n    family_name VARCHAR(80) NOT NULL,\n    email VARCHAR(160) UNIQUE\n);\n\nCREATE TABLE subjects (\n    subject_id INT PRIMARY KEY,\n    subject_code VARCHAR(12) NOT NULL,\n    subject_name VARCHAR(120) NOT NULL\n);\n\nCREATE TABLE enrolments (\n    enrolment_id INT PRIMARY KEY,\n    student_id INT NOT NULL,\n    subject_id INT NOT NULL,\n    enrolled_on DATE NOT NULL,\n    final_grade VARCHAR(4),\n    FOREIGN KEY (student_id) REFERENCES students(student_id),\n    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id)\n);"
        ),
        'erd-prisma' => array(
            'type' => 'erd',
            'sourceType' => 'prisma',
            'title' => 'Blog schema',
            'source' => "model User {\n  id        Int      @id @default(autoincrement())\n  email     String   @unique\n  name      String?\n  posts     Post[]\n}\n\nmodel Post {\n  id        Int      @id @default(autoincrement())\n  title     String\n  content   String?\n  published Boolean  @default(false)\n  authorId  Int\n  author    User     @relation(fields: [authorId], references: [id])\n}"
        ),
        'erd-quickdbd' => array(
            'type' => 'erd',
            'sourceType' => 'quickdbd',
            'title' => 'Library database',
            'source' => "Members\n-\nmember_id int PK\nname varchar\nemail varchar UNIQUE\n\nBooks\n-\nbook_id int PK\ntitle varchar\nisbn varchar UNIQUE\n\nLoans\n-\nloan_id int PK\nmember_id int FK >- Members.member_id\nbook_id int FK >- Books.book_id\nborrowed_on date\nreturned_on date NULL"
        ),
        'erd-mapping' => array(
            'type' => 'erd',
            'sourceType' => 'mapping',
            'title' => 'Clinic data model',
            'source' => "entity Patient {\n  patient_id: integer PK\n  name: text\n  date_of_birth: date\n}\n\nentity Practitioner {\n  practitioner_id: integer PK\n  name: text\n  speciality: text\n}\n\nentity Appointment {\n  appointment_id: integer PK\n  patient_id: integer FK\n  practitioner_id: integer FK\n  starts_at: datetime\n}\n\nPatient.patient_id 1--* Appointment.patient_id\nPractitioner.practitioner_id 1--* Appointment.practitioner_id"
        )
    );
}

function charter_builtin_templates()
{
    $defaults = charter_default_sources();
    return array(
        'basic-flowchart' => array(
            'title' => 'Basic Flowchart', 'category' => 'Flowchart',
            'description' => 'A simple process with a decision and two possible outcomes.',
            'type' => 'flowchart', 'sourceType' => 'qcaa',
            'source' => "BEGIN\n    INPUT value\n    IF value >= 0 THEN\n        CALCULATE result = value * 2\n        OUTPUT result\n    ELSE\n        OUTPUT \"Invalid value\"\n    ENDIF\nEND"
        ),
        'software-login' => array(
            'title' => 'Software Login', 'category' => 'Flowchart',
            'description' => 'Map username, password and account-locking decisions.',
            'type' => 'flowchart', 'sourceType' => 'qcaa',
            'source' => "BEGIN\n    INPUT username, password\n    CALCULATE attempts = 0\n    IF credentials_valid = TRUE THEN\n        CALL create_session\n        OUTPUT \"Dashboard\"\n    ELSE\n        CALCULATE attempts = attempts + 1\n        IF attempts >= 3 THEN\n            OUTPUT \"Account locked\"\n        ELSE\n            OUTPUT \"Try again\"\n        ENDIF\n    ENDIF\nEND"
        ),
        'approval-process' => array(
            'title' => 'Approval Process', 'category' => 'Flowchart',
            'description' => 'A reusable review, revision and approval workflow.',
            'type' => 'flowchart', 'sourceType' => 'qcaa',
            'source' => "BEGIN\n    CALL review_submission\n    WHILE approved = FALSE\n        OUTPUT \"Return feedback\"\n        CALL revise_submission\n        CALL review_submission\n    ENDWHILE\n    CALL publish_submission\nEND"
        ),
        'troubleshooting' => array(
            'title' => 'Troubleshooting', 'category' => 'Flowchart',
            'description' => 'Diagnose a problem by testing likely causes in sequence.',
            'type' => 'flowchart', 'sourceType' => 'qcaa',
            'source' => "BEGIN\n    INPUT problem_details\n    IF reproducible = TRUE THEN\n        IF recent_change = TRUE THEN\n            CALL test_rollback\n        ELSE\n            CALL inspect_logs\n            CALL apply_fix\n        ENDIF\n        IF fixed = FALSE THEN\n            CALL escalate_investigation\n        ENDIF\n    ELSE\n        OUTPUT \"Collect more information\"\n    ENDIF\nEND"
        ),
        'context-dfd' => array(
            'title' => 'Student Portal Overview DFD', 'category' => 'DFD',
            'description' => 'A Level 1 overview DFD with automatically numbered processes, external entities and data stores.',
            'type' => 'dfd', 'sourceType' => 'dfd',
            'source' => "title: Student portal overview\n\nentity Student \"Student\"\nentity Teacher \"Teacher\"\nentity Identity \"Identity service\"\nprocess Authenticate \"Authenticate user\"\nprocess ViewProgress \"View progress\"\nprocess RecordResults \"Record results\"\nstore D1 \"Student records\"\nstore D2 \"Results\"\n\nStudent -> Authenticate : credentials\nAuthenticate -> Identity : authentication request\nIdentity -> Authenticate : authentication result\nAuthenticate -> ViewProgress : verified student\nViewProgress -> D1 : student query\nD1 -> ViewProgress : student details\nD2 -> ViewProgress : result data\nViewProgress -> Student : progress information\nTeacher -> RecordResults : results and comments\nRecordResults -> D2 : updated results"
        ),
        'order-dfd' => array(
            'title' => 'Order System DFD', 'category' => 'DFD',
            'description' => 'A level-one DFD for customers, orders, stock and payments.',
            'type' => 'dfd', 'sourceType' => 'dfd',
            'source' => $defaults['dfd']['source']
        ),
        'library-dfd' => array(
            'title' => 'Library DFD', 'category' => 'DFD',
            'description' => 'Track searches, loans, returns and member records.',
            'type' => 'dfd', 'sourceType' => 'dfd',
            'source' => "title: Library borrowing system\n\nentity Member \"Member\"\nentity Librarian \"Librarian\"\nprocess SearchCatalogue \"Search catalogue\"\nprocess IssueLoan \"Issue loan\"\nprocess ReturnItem \"Return item\"\nstore D1 \"Catalogue\"\nstore D2 \"Members\"\nstore D3 \"Loans\"\n\nMember -> SearchCatalogue : search terms\nSearchCatalogue -> D1 : catalogue query\nD1 -> SearchCatalogue : matching books\nSearchCatalogue -> Member : search results\nLibrarian -> IssueLoan : member and item\nIssueLoan -> D2 : member query\nD2 -> IssueLoan : member status\nIssueLoan -> D3 : new loan\nIssueLoan -> Member : loan receipt\nLibrarian -> ReturnItem : returned item\nReturnItem -> D3 : close loan"
        ),
        'school-dfd' => array(
            'title' => 'School Enrolment DFD', 'category' => 'DFD',
            'description' => 'Show enrolment data moving between families, staff and stores.',
            'type' => 'dfd', 'sourceType' => 'dfd',
            'source' => "title: School enrolment system\n\nentity Family \"Family\"\nentity SchoolOffice \"School office\"\nentity Department \"Education department\"\nprocess ReceiveApplication \"Receive application\"\nprocess VerifyDocuments \"Verify documents\"\nprocess CreateEnrolment \"Create enrolment\"\nstore D1 \"Applications\"\nstore D2 \"Student records\"\n\nFamily -> ReceiveApplication : application and documents\nReceiveApplication -> D1 : application record\nSchoolOffice -> VerifyDocuments : review request\nD1 -> VerifyDocuments : submitted application\nVerifyDocuments -> Department : identity check\nDepartment -> VerifyDocuments : verification result\nVerifyDocuments -> CreateEnrolment : approved application\nCreateEnrolment -> D2 : student record\nCreateEnrolment -> Family : enrolment confirmation"
        ),
        'school-sql' => array(
            'title' => 'School ERD (SQL)', 'category' => 'ERD · SQL',
            'description' => 'Students, subjects and enrolments expressed as SQL DDL.',
            'type' => 'erd', 'sourceType' => 'sql',
            'source' => $defaults['erd-sql']['source']
        ),
        'commerce-sql' => array(
            'title' => 'E-commerce ERD (SQL)', 'category' => 'ERD · SQL',
            'description' => 'Customers, products, orders and order lines.',
            'type' => 'erd', 'sourceType' => 'sql',
            'source' => "CREATE TABLE customers (\n  customer_id INT PRIMARY KEY,\n  name VARCHAR(120) NOT NULL,\n  email VARCHAR(180) UNIQUE\n);\n\nCREATE TABLE products (\n  product_id INT PRIMARY KEY,\n  name VARCHAR(160) NOT NULL,\n  price DECIMAL(10,2) NOT NULL\n);\n\nCREATE TABLE orders (\n  order_id INT PRIMARY KEY,\n  customer_id INT NOT NULL REFERENCES customers(customer_id),\n  ordered_at DATETIME NOT NULL,\n  status VARCHAR(30) NOT NULL\n);\n\nCREATE TABLE order_items (\n  order_item_id INT PRIMARY KEY,\n  order_id INT NOT NULL REFERENCES orders(order_id),\n  product_id INT NOT NULL REFERENCES products(product_id),\n  quantity INT NOT NULL,\n  unit_price DECIMAL(10,2) NOT NULL\n);"
        ),
        'blog-prisma' => array(
            'title' => 'Blog ERD (Prisma)', 'category' => 'ERD · Prisma',
            'description' => 'Users and posts represented with Prisma models and relations.',
            'type' => 'erd', 'sourceType' => 'prisma',
            'source' => $defaults['erd-prisma']['source']
        ),
        'commerce-prisma' => array(
            'title' => 'Commerce ERD (Prisma)', 'category' => 'ERD · Prisma',
            'description' => 'A Prisma model for customers, orders and order items.',
            'type' => 'erd', 'sourceType' => 'prisma',
            'source' => "model Customer {\n  id      Int     @id @default(autoincrement())\n  name    String\n  email   String  @unique\n  orders  Order[]\n}\n\nmodel Order {\n  id          Int         @id @default(autoincrement())\n  orderedAt   DateTime    @default(now())\n  customerId  Int\n  customer    Customer    @relation(fields: [customerId], references: [id])\n  items       OrderItem[]\n}\n\nmodel Product {\n  id      Int         @id @default(autoincrement())\n  name    String\n  price   Decimal\n  items   OrderItem[]\n}\n\nmodel OrderItem {\n  id         Int      @id @default(autoincrement())\n  quantity   Int\n  orderId    Int\n  productId  Int\n  order      Order    @relation(fields: [orderId], references: [id])\n  product    Product  @relation(fields: [productId], references: [id])\n}"
        ),
        'library-quickdbd' => array(
            'title' => 'Library ERD (QuickDBD)', 'category' => 'ERD · QuickDBD',
            'description' => 'A compact QuickDBD-style schema for books and loans.',
            'type' => 'erd', 'sourceType' => 'quickdbd',
            'source' => $defaults['erd-quickdbd']['source']
        ),
        'assessment-quickdbd' => array(
            'title' => 'Assessment ERD (QuickDBD)', 'category' => 'ERD · QuickDBD',
            'description' => 'Classes, students, assessments and results.',
            'type' => 'erd', 'sourceType' => 'quickdbd',
            'source' => "Classes\n-\nclass_id int PK\nclass_name varchar\n\nStudents\n-\nstudent_id int PK\nname varchar\nclass_id int FK >- Classes.class_id\n\nAssessments\n-\nassessment_id int PK\ntitle varchar\nmaximum_mark int\n\nResults\n-\nresult_id int PK\nstudent_id int FK >- Students.student_id\nassessment_id int FK >- Assessments.assessment_id\nmark decimal\nfeedback text"
        ),
        'clinic-mapping' => array(
            'title' => 'Clinic ERD (Mappings)', 'category' => 'ERD · Mapping',
            'description' => 'Entity blocks and explicit cardinality mappings.',
            'type' => 'erd', 'sourceType' => 'mapping',
            'source' => $defaults['erd-mapping']['source']
        ),
        'course-mapping' => array(
            'title' => 'Course ERD (Mappings)', 'category' => 'ERD · Mapping',
            'description' => 'Courses, units and student registrations using entity mappings.',
            'type' => 'erd', 'sourceType' => 'mapping',
            'source' => "entity Student {\n  student_id: integer PK\n  name: text\n  email: text UNIQUE\n}\n\nentity Course {\n  course_id: integer PK\n  course_name: text\n}\n\nentity Registration {\n  registration_id: integer PK\n  student_id: integer FK\n  course_id: integer FK\n  registered_on: date\n}\n\nStudent.student_id 1--* Registration.student_id\nCourse.course_id 1--* Registration.course_id"
        )
    );
}

function charter_make_project($title, $type, $sourceType, $source)
{
    $milliseconds = round(microtime(true) * 1000);
    return array(
        'id' => charter_generate_project_id(),
        'title' => (string)$title,
        'diagramType' => (string)$type,
        'sourceType' => (string)$sourceType,
        'source' => (string)$source,
        'direction' => 'TB',
        'layout' => array('positions' => array(), 'positionsByKey' => array(), 'links' => array(), 'nodeStyles' => array(), 'nodeStylesByKey' => array(), 'textboxes' => array(), 'hiddenNodes' => array(), 'hiddenEdges' => array()),
        'settings' => array(
            'showGrid' => true,
            'showEdgeLabels' => true,
            'fontSize' => 14,
            'lineType' => 'orthogonal'
        ),
        'dates' => array('created' => $milliseconds, 'modified' => $milliseconds)
    );
}

function charter_normalise_project($project)
{
    if (!is_array($project)) {
        throw new RuntimeException('The project is not valid.');
    }

    $defaults = charter_default_sources();
    $diagramType = isset($project['diagramType']) ? (string)$project['diagramType'] : 'flowchart';
    if (!in_array($diagramType, array('flowchart', 'dfd', 'erd'), true)) {
        $diagramType = 'flowchart';
    }

    $sourceType = isset($project['sourceType']) ? (string)$project['sourceType'] : '';
    $allowedSources = array(
        'flowchart' => array('flowgorithm', 'python', 'qcaa', 'gatton', 'javascript'),
        'dfd' => array('dfd'),
        'erd' => array('sql', 'prisma', 'quickdbd', 'mapping')
    );
    if (!in_array($sourceType, $allowedSources[$diagramType], true)) {
        $sourceType = $diagramType === 'erd' ? 'sql' : ($diagramType === 'dfd' ? 'dfd' : 'flowgorithm');
    }

    $defaultKey = $diagramType === 'erd' ? 'erd-' . $sourceType : ($diagramType === 'flowchart' ? 'flowchart-' . $sourceType : $diagramType);
    if (!isset($defaults[$defaultKey])) {
        $defaultKey = $diagramType === 'erd' ? 'erd-sql' : ($diagramType === 'flowchart' ? 'flowchart-flowgorithm' : $diagramType);
    }

    if (!isset($project['id']) || trim((string)$project['id']) === '') {
        $project['id'] = charter_generate_project_id();
    }
    if (!isset($project['title']) || trim((string)$project['title']) === '') {
        $project['title'] = $defaults[$defaultKey]['title'];
    }
    $project['diagramType'] = $diagramType;
    $project['sourceType'] = $sourceType;
    if (!isset($project['source'])) {
        $project['source'] = $defaults[$defaultKey]['source'];
    }
    if (!isset($project['direction']) || !in_array($project['direction'], array('LR', 'TB', 'GRID'), true)) {
        $project['direction'] = 'TB';
    }
    if (!isset($project['layout']) || !is_array($project['layout'])) {
        $project['layout'] = array();
    }
    if (!isset($project['layout']['positions']) || !is_array($project['layout']['positions'])) {
        $project['layout']['positions'] = array();
    }
    if (!isset($project['layout']['positionsByKey']) || !is_array($project['layout']['positionsByKey'])) {
        $project['layout']['positionsByKey'] = array();
    }
    if (!isset($project['layout']['links']) || !is_array($project['layout']['links'])) {
        $project['layout']['links'] = array();
    }
    if (!isset($project['layout']['nodeStyles']) || !is_array($project['layout']['nodeStyles'])) {
        $project['layout']['nodeStyles'] = array();
    }
    if (!isset($project['layout']['nodeStylesByKey']) || !is_array($project['layout']['nodeStylesByKey'])) {
        $project['layout']['nodeStylesByKey'] = array();
    }
    if (!isset($project['layout']['textboxes']) || !is_array($project['layout']['textboxes'])) {
        $project['layout']['textboxes'] = array();
    }
    if (!isset($project['layout']['hiddenNodes']) || !is_array($project['layout']['hiddenNodes'])) {
        $project['layout']['hiddenNodes'] = array();
    }
    if (!isset($project['layout']['hiddenEdges']) || !is_array($project['layout']['hiddenEdges'])) {
        $project['layout']['hiddenEdges'] = array();
    }
    if (!isset($project['settings']) || !is_array($project['settings'])) {
        $project['settings'] = array();
    }
    $project['settings'] = array_merge(array(
        'showGrid' => true,
        'showEdgeLabels' => true,
        'fontSize' => 14,
        'lineType' => 'orthogonal'
    ), $project['settings']);
    if (!in_array($project['settings']['lineType'], array('orthogonal', 'direct', 'curved'), true)) {
        $project['settings']['lineType'] = 'orthogonal';
    }
    if (!isset($project['dates']) || !is_array($project['dates'])) {
        $project['dates'] = array();
    }
    $milliseconds = round(microtime(true) * 1000);
    if (!isset($project['dates']['created'])) {
        $project['dates']['created'] = $milliseconds;
    }
    $project['dates']['modified'] = isset($project['dates']['modified'])
        ? $project['dates']['modified']
        : $project['dates']['created'];

    return $project;
}

function charter_prepare_template_copy($project)
{
    $project = charter_normalise_project($project);
    $milliseconds = round(microtime(true) * 1000);
    $project['id'] = charter_generate_project_id();
    $project['dates']['created'] = $milliseconds;
    $project['dates']['modified'] = $milliseconds;
    return $project;
}

/* The default DFD is based on this saved Charter project. Loading it directly
 * from charter_projects preserves its object positions, colours and captions
 * without depending on an HTTP request back to this application. */
function charter_default_dfd_project($fallbackDefinition)
{
    $defaultShareKey = 'd6260ccb991f264a2e20a8345b8ef7ff';
    try {
        $mysqli = charter_database_connection();
        $statement = charter_database_prepare(
            $mysqli,
            'SELECT project_json FROM charter_projects WHERE share_key = ? LIMIT 1'
        );
        if (!$statement->bind_param('s', $defaultShareKey)) {
            throw new RuntimeException('The default DFD share key could not be bound.');
        }
        charter_database_execute($statement);
        if (!$statement->bind_result($projectJson)) {
            throw new RuntimeException('The default DFD project could not be read.');
        }
        $found = $statement->fetch() === true;
        $statement->close();
        $mysqli->close();
        if ($found) {
            $decoded = json_decode($projectJson, true);
            if (is_array($decoded)) {
                $project = charter_prepare_template_copy($decoded);
                if ($project['diagramType'] === 'dfd') {
                    /* Textboxes in the saved source project are deliberately not
                     * part of the built-in default DFD template. */
                    $project['layout']['textboxes'] = array();
                    return $project;
                }
            }
        }
    } catch (Exception $error) {
        /* Keep Charter usable when the saved template or database is temporarily
         * unavailable; the built-in source below remains the fallback. */
    }

    $project = charter_make_project(
        $fallbackDefinition['title'],
        $fallbackDefinition['type'],
        $fallbackDefinition['sourceType'],
        $fallbackDefinition['source']
    );
    $project['layout']['textboxes'] = array();
    return $project;
}

/* -------------------------------------------------------------------------
 * Server actions: load, clone, save and save_template
 * ---------------------------------------------------------------------- */
if ($charterAction !== '') {
    try {
        if ($charterAction === 'load') {
            $shareKey = isset($_GET['share']) ? strtolower(trim((string)$_GET['share'])) : '';
            if (!charter_valid_key($shareKey)) {
                charter_json_response(array('ok' => false, 'error' => 'The shared-diagram link is not valid.'), 400);
            }

            $mysqli = charter_database_connection();
            $statement = charter_database_prepare(
                $mysqli,
                'SELECT share_key, title, project_json, created_at, updated_at FROM charter_projects WHERE share_key = ? LIMIT 1'
            );
            if (!$statement->bind_param('s', $shareKey)) {
                throw new RuntimeException('The shared-diagram parameter could not be bound.');
            }
            charter_database_execute($statement);
            if (!$statement->bind_result($rowKey, $rowTitle, $rowJson, $rowCreated, $rowUpdated)) {
                throw new RuntimeException('The shared-diagram result could not be read.');
            }
            if ($statement->fetch() !== true) {
                $statement->close();
                $mysqli->close();
                charter_json_response(array('ok' => false, 'error' => 'The shared diagram could not be found.'), 404);
            }
            $statement->close();
            $mysqli->close();

            $project = json_decode($rowJson, true);
            if (!is_array($project)) {
                charter_json_response(array('ok' => false, 'error' => 'The saved diagram contains invalid project data.'), 500);
            }
            $project = charter_normalise_project($project);
            charter_json_response(array(
                'ok' => true,
                'shareId' => $rowKey,
                'shareUrl' => charter_share_url($rowKey),
                'newSessionUrl' => charter_new_session_url($rowKey),
                'project' => $project,
                'created' => $rowCreated,
                'modified' => $rowUpdated
            ), 200);
        }

        if ($charterAction === 'clone' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = charter_read_request_body();
            $sourceKey = is_array($body) && isset($body['shareId'])
                ? strtolower(trim((string)$body['shareId']))
                : '';
            if (!charter_valid_key($sourceKey)) {
                charter_json_response(array('ok' => false, 'error' => 'The new-session link is not valid.'), 400);
            }

            $mysqli = charter_database_connection();
            $statement = charter_database_prepare(
                $mysqli,
                'SELECT title, project_json FROM charter_projects WHERE share_key = ? LIMIT 1'
            );
            if (!$statement->bind_param('s', $sourceKey)) {
                throw new RuntimeException('The source-diagram parameter could not be bound.');
            }
            charter_database_execute($statement);
            if (!$statement->bind_result($sourceTitle, $sourceJson)) {
                throw new RuntimeException('The source-diagram result could not be read.');
            }
            if ($statement->fetch() !== true) {
                $statement->close();
                $mysqli->close();
                charter_json_response(array('ok' => false, 'error' => 'The source diagram could not be found.'), 404);
            }
            $statement->close();

            $project = json_decode($sourceJson, true);
            if (!is_array($project)) {
                $mysqli->close();
                charter_json_response(array('ok' => false, 'error' => 'The source diagram contains invalid project data.'), 500);
            }
            $project = charter_prepare_template_copy($project);
            $encoded = json_encode($project, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                $mysqli->close();
                charter_json_response(array('ok' => false, 'error' => 'The new session could not be converted to JSON.'), 500);
            }

            do {
                $shareKey = charter_generate_key();
            } while (charter_project_key_exists($mysqli, $shareKey));

            $timestamp = date('Y-m-d H:i:s');
            $statement = charter_database_prepare(
                $mysqli,
                'INSERT INTO charter_projects (share_key, title, project_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
            );
            if (!$statement->bind_param('sssss', $shareKey, $sourceTitle, $encoded, $timestamp, $timestamp)) {
                throw new RuntimeException('The new-session parameters could not be bound.');
            }
            charter_database_execute($statement);
            $statement->close();
            $mysqli->close();

            charter_json_response(array(
                'ok' => true,
                'shareId' => $shareKey,
                'shareUrl' => charter_share_url($shareKey),
                'newSessionUrl' => charter_new_session_url($sourceKey),
                'sourceShareId' => $sourceKey,
                'project' => $project,
                'created' => $timestamp,
                'modified' => $timestamp
            ), 200);
        }

        if ($charterAction === 'save' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = charter_read_request_body();
            if (!is_array($body) || !isset($body['project']) || !is_array($body['project'])) {
                charter_json_response(array('ok' => false, 'error' => 'No valid project was supplied.'), 400);
            }

            $forceNew = !empty($body['forceNew']);
            $providedKey = isset($body['shareId']) ? strtolower(trim((string)$body['shareId'])) : '';
            if ($forceNew) {
                $providedKey = '';
            }
            if ($providedKey !== '' && !charter_valid_key($providedKey)) {
                charter_json_response(array('ok' => false, 'error' => 'The shared-diagram identifier is not valid.'), 400);
            }

            $project = charter_normalise_project($body['project']);
            $project['dates']['modified'] = round(microtime(true) * 1000);
            $encoded = json_encode($project, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                charter_json_response(array('ok' => false, 'error' => 'The project could not be converted to JSON.'), 400);
            }

            $title = trim((string)$project['title']);
            if ($title === '') {
                $title = 'Untitled diagram';
            }
            $title = function_exists('mb_substr') ? mb_substr($title, 0, 255, 'UTF-8') : substr($title, 0, 255);

            $mysqli = charter_database_connection();
            $timestamp = date('Y-m-d H:i:s');
            $shareKey = $providedKey;
            $updatedExisting = false;

            if ($shareKey !== '') {
                $statement = charter_database_prepare(
                    $mysqli,
                    'UPDATE charter_projects SET title = ?, project_json = ?, updated_at = ? WHERE share_key = ?'
                );
                if (!$statement->bind_param('ssss', $title, $encoded, $timestamp, $shareKey)) {
                    throw new RuntimeException('The update parameters could not be bound.');
                }
                charter_database_execute($statement);
                $updatedExisting = $statement->affected_rows > 0;
                $statement->close();
                if (!$updatedExisting) {
                    $updatedExisting = charter_project_key_exists($mysqli, $shareKey);
                }
            }

            if (!$updatedExisting) {
                do {
                    $shareKey = charter_generate_key();
                } while (charter_project_key_exists($mysqli, $shareKey));

                $statement = charter_database_prepare(
                    $mysqli,
                    'INSERT INTO charter_projects (share_key, title, project_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
                );
                if (!$statement->bind_param('sssss', $shareKey, $title, $encoded, $timestamp, $timestamp)) {
                    throw new RuntimeException('The insert parameters could not be bound.');
                }
                charter_database_execute($statement);
                $statement->close();
            }

            $mysqli->close();
            charter_json_response(array(
                'ok' => true,
                'shareId' => $shareKey,
                'shareUrl' => charter_share_url($shareKey),
                'newSessionUrl' => charter_new_session_url($shareKey),
                'independentCopy' => $forceNew,
                'modified' => $timestamp,
                'project' => $project
            ), 200);
        }

        if ($charterAction === 'save_template' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            global $adminPassword;
            $body = charter_read_request_body();
            if (!is_array($body) || !isset($body['project']) || !is_array($body['project'])) {
                charter_json_response(array('ok' => false, 'error' => 'No valid project was supplied.'), 400);
            }
            if (!isset($adminPassword) || trim((string)$adminPassword) === '') {
                charter_json_response(array('ok' => false, 'error' => 'charter-config.php does not define a usable $adminPassword.'), 500);
            }
            $providedPassword = isset($body['password']) ? (string)$body['password'] : '';
            if (!charter_secure_equals((string)$adminPassword, $providedPassword)) {
                charter_json_response(array('ok' => false, 'error' => 'The administrator password is not correct.'), 403);
            }

            $project = charter_prepare_template_copy($body['project']);
            $title = isset($body['title']) ? trim((string)$body['title']) : trim((string)$project['title']);
            if ($title === '') {
                $title = 'Charter template';
            }
            $title = function_exists('mb_substr') ? mb_substr($title, 0, 255, 'UTF-8') : substr($title, 0, 255);
            $description = isset($body['description']) ? trim((string)$body['description']) : '';
            if ($description === '') {
                $description = 'A community-created Charter diagram template.';
            }
            $description = function_exists('mb_substr') ? mb_substr($description, 0, 500, 'UTF-8') : substr($description, 0, 500);
            $project['title'] = $title;

            $encoded = json_encode($project, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                charter_json_response(array('ok' => false, 'error' => 'The template could not be converted to JSON.'), 400);
            }

            $mysqli = charter_database_connection();
            do {
                $templateKey = charter_generate_key();
            } while (charter_template_key_exists($mysqli, $templateKey));

            $timestamp = date('Y-m-d H:i:s');
            $diagramType = (string)$project['diagramType'];
            $sourceType = (string)$project['sourceType'];
            $statement = charter_database_prepare(
                $mysqli,
                'INSERT INTO charter_templates (template_key, title, description, diagram_type, source_type, project_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$statement->bind_param('sssssss', $templateKey, $title, $description, $diagramType, $sourceType, $encoded, $timestamp)) {
                throw new RuntimeException('The template parameters could not be bound.');
            }
            charter_database_execute($statement);
            $statement->close();
            $mysqli->close();

            charter_json_response(array(
                'ok' => true,
                'templateKey' => $templateKey,
                'templateUrl' => charter_application_url() . '?template=' . rawurlencode($templateKey),
                'landingUrl' => charter_application_url()
            ), 200);
        }

        charter_json_response(array('ok' => false, 'error' => 'Unknown server action.'), 404);
    } catch (Exception $error) {
        if (function_exists('error_log')) {
            @error_log('Charter server error: ' . $error->getMessage());
        }
        charter_json_response(array('ok' => false, 'error' => $error->getMessage()), 500);
    }
}

/* -------------------------------------------------------------------------
 * Initial page selection and template landing page
 * ---------------------------------------------------------------------- */
$initialShareId = isset($_GET['share']) && charter_valid_key(strtolower(trim((string)$_GET['share'])))
    ? strtolower(trim((string)$_GET['share']))
    : '';
$initialNewSessionId = isset($_GET['new_session']) && charter_valid_key(strtolower(trim((string)$_GET['new_session'])))
    ? strtolower(trim((string)$_GET['new_session']))
    : '';
$builtInTemplates = charter_builtin_templates();
$defaultSources = charter_default_sources();
$defaultDfdProject = charter_default_dfd_project($defaultSources['dfd']);
$initialProject = null;
$templateDatabaseError = '';
$savedTemplates = array();

if (isset($_GET['template'])) {
    $requestedTemplate = strtolower(trim((string)$_GET['template']));
    if ($requestedTemplate === 'order-dfd') {
        $initialProject = charter_prepare_template_copy($defaultDfdProject);
        $initialProject['layout']['textboxes'] = array();
    } elseif (isset($builtInTemplates[$requestedTemplate])) {
        $definition = $builtInTemplates[$requestedTemplate];
        $initialProject = charter_make_project(
            $definition['title'],
            $definition['type'],
            $definition['sourceType'],
            $definition['source']
        );
    } elseif (charter_valid_key($requestedTemplate)) {
        try {
            $mysqli = charter_database_connection();
            $statement = charter_database_prepare(
                $mysqli,
                'SELECT title, project_json FROM charter_templates WHERE template_key = ? LIMIT 1'
            );
            if (!$statement->bind_param('s', $requestedTemplate)) {
                throw new RuntimeException('The template parameter could not be bound.');
            }
            charter_database_execute($statement);
            if (!$statement->bind_result($templateTitle, $templateJson)) {
                throw new RuntimeException('The template result could not be read.');
            }
            if ($statement->fetch() === true) {
                $decoded = json_decode($templateJson, true);
                if (is_array($decoded)) {
                    $initialProject = charter_prepare_template_copy($decoded);
                    $initialProject['title'] = $templateTitle;
                }
            }
            $statement->close();
            $mysqli->close();
        } catch (Exception $error) {
            $templateDatabaseError = $error->getMessage();
        }
    }
}

if ($initialProject === null && isset($_GET['new'])) {
    $newType = strtolower(trim((string)$_GET['new']));
    $syntax = isset($_GET['syntax']) ? strtolower(trim((string)$_GET['syntax'])) : '';
    if ($newType === 'dfd') {
        $initialProject = charter_prepare_template_copy($defaultDfdProject);
        $initialProject['layout']['textboxes'] = array();
    } else {
        if ($newType === 'flowchart') {
            $flowSyntax = in_array($syntax, array('flowgorithm', 'python', 'qcaa', 'gatton', 'javascript'), true) ? $syntax : 'flowgorithm';
            $definition = $defaultSources['flowchart-' . $flowSyntax];
        } else {
            $key = in_array($syntax, array('sql', 'prisma', 'quickdbd', 'mapping'), true)
                ? 'erd-' . $syntax
                : 'erd-sql';
            $definition = $defaultSources[$key];
        }
        $initialProject = charter_make_project(
            $definition['title'],
            $definition['type'],
            $definition['sourceType'],
            $definition['source']
        );
    }
}

// A requested URL layout takes precedence over a saved template's layout as well.
$requestedLayout = strtolower(trim((string)($_GET['layout'] ?? $_GET['mode'] ?? '')));
$layoutDirections = array('fixed-vertical' => 'FV', 'fixed_vertical' => 'FV', 'fv' => 'FV', 'tb' => 'TB', 'top-bottom' => 'TB', 'top-to-bottom' => 'TB', 'lr' => 'LR', 'left-right' => 'LR', 'left-to-right' => 'LR', 'grid' => 'GRID');
if ($initialProject !== null && isset($layoutDirections[$requestedLayout])) {
    $initialProject['direction'] = $layoutDirections[$requestedLayout];
}

$hasEditorParameter = $initialShareId !== '' || $initialNewSessionId !== '' || isset($_GET['template']) || isset($_GET['new']);
$showLanding = !$hasEditorParameter;

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

if ($showLanding) {
    try {
        $mysqli = charter_database_connection();
        $result = $mysqli->query(
            'SELECT template_key, title, description, diagram_type, source_type, created_at FROM charter_templates ORDER BY created_at DESC LIMIT 100'
        );
        if ($result === false) {
            throw new RuntimeException('Saved templates could not be listed. MySQL error ' . $mysqli->errno . ': ' . $mysqli->error);
        }
        while ($row = $result->fetch_assoc()) {
            $savedTemplates[] = $row;
        }
        $result->free();
        $mysqli->close();
    } catch (Exception $error) {
        $templateDatabaseError = $error->getMessage();
    }
}

if ($initialProject === null) {
    $definition = $defaultSources['flowchart'];
    $initialProject = charter_make_project(
        $definition['title'],
        $definition['type'],
        $definition['sourceType'],
        $definition['source']
    );
}

if ($showLanding) {
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Charter Diagram Studio</title>
<style>
:root{--ink:#17212b;--muted:#687582;--line:#d8dee5;--panel:#fff;--soft:#f3f6f8;--blue:#246b9a;--blue2:#194e72;--accent:#ef9b29;--shadow:0 12px 38px rgba(16,28,40,.14)}
*{box-sizing:border-box}body{margin:0;font-family:Inter,"Segoe UI",Arial,sans-serif;color:var(--ink);background:linear-gradient(145deg,#eef3f6,#f9fbfc 48%,#edf2f5);min-height:100vh}.hero{background:linear-gradient(130deg,#17212b,#284052);color:#fff;padding:44px 24px 54px}.hero-inner{max-width:1180px;margin:auto}.brand{display:flex;align-items:center;gap:12px;font-size:30px;font-weight:800}.brand-mark{width:42px;height:42px;border-radius:10px;background:linear-gradient(145deg,#ffbf57,#e8830f);position:relative;box-shadow:inset 0 0 0 2px rgba(255,255,255,.25)}.brand-mark:before,.brand-mark:after{content:"";position:absolute;background:#fff;border-radius:3px}.brand-mark:before{width:24px;height:4px;left:9px;top:12px;box-shadow:0 8px 0 #fff,0 16px 0 #fff}.brand-mark:after{width:4px;height:24px;left:19px;top:9px}.hero h1{font-size:clamp(34px,5vw,58px);margin:32px 0 12px;max-width:850px;line-height:1.04}.hero p{font-size:18px;color:#d6e0e7;max-width:760px;line-height:1.55}.quick{display:flex;gap:10px;flex-wrap:wrap;margin-top:25px}.quick a{color:#fff;text-decoration:none;padding:11px 15px;border-radius:6px;background:#2477aa;border:1px solid rgba(255,255,255,.2);font-weight:700}.quick a:hover{background:#2d87bd}.quick a.secondary{background:rgba(255,255,255,.08)}main{max-width:1180px;margin:-24px auto 50px;padding:0 18px}.section{background:rgba(255,255,255,.96);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);padding:24px;margin-bottom:22px}.section h2{margin:0 0 7px}.section-intro{color:var(--muted);margin:0 0 20px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:13px}.card{display:flex;flex-direction:column;min-height:175px;padding:17px;border:1px solid var(--line);border-radius:9px;background:#fff;text-decoration:none;color:inherit;transition:.15s}.card:hover{transform:translateY(-2px);border-color:#8eb5cf;box-shadow:0 8px 24px rgba(28,64,88,.12)}.badge{align-self:flex-start;background:#e9f2f8;color:#245f87;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px}.card h3{margin:13px 0 7px;font-size:18px}.card p{color:var(--muted);line-height:1.45;margin:0 0 14px}.open{margin-top:auto;color:var(--blue);font-weight:750}.error{background:#fff2f0;border:1px solid #efc1bb;color:#8e3127;padding:12px;border-radius:7px;margin-bottom:18px}footer{text-align:center;color:var(--muted);padding:0 20px 30px}@media(max-width:600px){.hero{padding-top:28px}.section{padding:17px}}




/* Flowchart execution inspector and active node */
#runProgramBtn.running{background:#fbe6e6;color:#9b1717;border-color:#da8c8c}
.node.run-active .body{filter:drop-shadow(0 0 5px #ff8800) drop-shadow(0 0 12px #ff9d00) drop-shadow(0 0 22px rgba(255,126,0,.95))!important}
.node.run-error .node-shape,.node.run-error rect,.node.run-error polygon,.node.run-error path,.node.run-error ellipse{filter:drop-shadow(0 0 7px rgba(230,25,35,.95)) drop-shadow(0 0 17px rgba(230,25,35,.85))!important}
.node.run-active .body{stroke:#ff8400!important;stroke-width:5px!important}
.node.run-error .node-shape,.node.run-error rect,.node.run-error polygon,.node.run-error path,.node.run-error ellipse{stroke:#d91628!important;stroke-width:4px!important}
.run-output{background:#111820;color:#b9f7c7;font:13px/1.5 Consolas,monospace;white-space:pre-wrap;overflow:auto;min-height:160px;max-height:42vh;padding:12px;border-radius:4px}
.run-variables{font:13px/1.5 Consolas,monospace;max-height:240px;overflow:auto}
.run-variables table{border-collapse:collapse;width:100%}.run-variables td{padding:5px;border-bottom:1px solid #dae1e7;overflow-wrap:anywhere}
.run-runtime{padding:9px;font-size:12px;color:#526575}.run-runtime button{margin-right:6px}
/* Keep the source and controls titlebars at identical heights. */
.source-panel>.panel-head,.controls-panel>.panel-head{min-height:38px;height:38px;box-sizing:border-box;flex:0 0 38px}
/* Reverse the OUTPUT parallelogram to match its diagram geometry. */
.flowgorithm-palette .control-icon.fg-output{transform:skew(24deg)!important}
.flowgorithm-palette .control-icon.fg-output span{transform:skew(-24deg)!important}
</style>
</head>
<body>
<header class="hero"><div class="hero-inner">
<div class="brand"><span class="brand-mark"></span><span>Charter</span></div>
<h1>Turn structured text into clear technical diagrams.</h1>
<p>Create Flowgorithm-style flowcharts from an interactive Flowgorithm diagram notation, Python, QCAA Pseudocode, Gatton Pseudocode or JavaScript, plus data-flow diagrams and entity-relationship diagrams from editable text.</p>
<div class="quick">
<a href="?new=flowchart">New flowchart</a>
<a href="?new=dfd">New DFD</a>
<a href="?new=erd&syntax=sql">New SQL ERD</a>
<a class="secondary" href="?new=erd&syntax=prisma">New Prisma ERD</a>
<a class="secondary" href="?new=erd&syntax=quickdbd">New QuickDBD ERD</a>
</div></div></header>
<main>
<section class="section">
<h2>Starter templates</h2>
<p class="section-intro">Choose a starting point. Each template opens as an independent diagram.</p>
<?php if ($templateDatabaseError !== '') { ?><div class="error"><?php echo charter_html_escape($templateDatabaseError); ?></div><?php } ?>
<div class="cards">
<?php foreach ($builtInTemplates as $templateKey => $template) { ?>
<a class="card" href="?template=<?php echo rawurlencode($templateKey); ?>">
<span class="badge"><?php echo charter_html_escape($template['category']); ?></span>
<h3><?php echo charter_html_escape($template['title']); ?></h3>
<p><?php echo charter_html_escape($template['description']); ?></p>
<span class="open">Open template →</span>
</a>
<?php } ?>
</div>
</section>
<?php if (count($savedTemplates) > 0) { ?>
<section class="section">
<h2>Saved templates</h2>
<p class="section-intro">Password-authorised templates saved anonymously from Charter.</p>
<div class="cards">
<?php foreach ($savedTemplates as $template) { ?>
<a class="card" href="?template=<?php echo rawurlencode($template['template_key']); ?>">
<span class="badge"><?php echo charter_html_escape(strtoupper($template['diagram_type']) . ' · ' . $template['source_type']); ?></span>
<h3><?php echo charter_html_escape($template['title']); ?></h3>
<p><?php echo charter_html_escape($template['description']); ?></p>
<span class="open">Open template →</span>
</a>
<?php } ?>
</div>
</section>
<?php } ?>
</main>
<footer>Charter stores shared diagrams in the database configured by <code>charter-config.php</code>.</footer>
</body>
</html>
<?php
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Charter Diagram Studio</title>
<style>
:root{--top:#1d2832;--top2:#131b22;--panel:#f8fafb;--line:#cbd3da;--canvas:#edf1f4;--ink:#1b2731;--muted:#66737f;--blue:#2475a8;--blue-dark:#1b5e88;--accent:#ed981f;--danger:#b33a2f;--shadow:0 4px 18px rgba(0,0,0,.18);--source-width:390px}
*{box-sizing:border-box}html,body{width:100%;height:100%;margin:0;overflow:hidden}body{font-family:Inter,"Segoe UI",Arial,sans-serif;color:var(--ink);background:var(--canvas);font-size:14px}button,input,select,textarea{font:inherit}.hidden{display:none!important}.app{height:100%;display:grid;grid-template-rows:48px 45px minmax(0,1fr) 28px}.topbar{display:flex;align-items:center;gap:14px;padding:0 13px;color:#fff;background:linear-gradient(180deg,var(--top),var(--top2));box-shadow:0 1px 5px rgba(0,0,0,.38);z-index:30}.brand{display:flex;align-items:center;gap:8px;font-weight:800;font-size:16px}.brand-mark{width:28px;height:28px;border-radius:7px;background:linear-gradient(145deg,#ffbd52,#e4800c);position:relative}.brand-mark:before{content:"";position:absolute;width:16px;height:3px;left:6px;top:7px;background:#fff;box-shadow:0 6px 0 #fff,0 12px 0 #fff}.brand-mark:after{content:"";position:absolute;width:3px;height:16px;left:12px;top:6px;background:#fff}.menu-strip{display:flex;height:100%}.menu{position:relative}.menu>button{height:100%;border:0;background:transparent;color:#e8edf1;padding:0 10px;cursor:pointer}.menu>button:hover,.menu.open>button{background:rgba(255,255,255,.1)}.dropdown{display:none;position:absolute;top:45px;left:0;min-width:240px;padding:6px;background:#fff;color:var(--ink);border:1px solid #c7ced5;border-radius:5px;box-shadow:var(--shadow);z-index:200}.menu.open .dropdown{display:block}.dropdown button{display:flex;justify-content:space-between;width:100%;border:0;background:transparent;padding:8px 10px;border-radius:4px;text-align:left;color:var(--ink);cursor:pointer}.dropdown button:hover{background:#edf2f6}.dropdown hr{border:0;border-top:1px solid #e1e5e9;margin:5px 0}.nested-menu{position:relative}.nested-menu>button:after{content:'›';font-size:18px;line-height:1}.nested-menu-panel{display:none;position:absolute;left:100%;top:-6px;min-width:220px;padding:6px;background:#fff;border:1px solid #c7ced5;border-radius:5px;box-shadow:var(--shadow);z-index:210}.nested-menu:hover>.nested-menu-panel,.nested-menu:focus-within>.nested-menu-panel{display:block}kbd{font-size:10px;color:#69747f;border:1px solid #d2d7dc;border-bottom-width:2px;border-radius:3px;background:#f1f3f5;padding:1px 4px}.title-wrap{margin-left:auto;display:flex;align-items:center;gap:8px;min-width:200px;max-width:470px;flex:1;justify-content:flex-end}.unsaved-dot{width:8px;height:8px;border-radius:50%;background:#ffb43e;opacity:0}.unsaved-dot.show{opacity:1}#diagramTitle{width:min(100%,350px);background:rgba(255,255,255,.09);color:#fff;border:1px solid rgba(255,255,255,.18);border-radius:4px;padding:6px 9px;outline:none}.toolbar{display:flex;align-items:center;gap:5px;padding:5px 8px;background:#e7ecf0;border-bottom:1px solid #c4ccd3;overflow-x:auto;z-index:20}.toolbar label{font-size:11px;color:#53616d}.toolbar select{height:33px;border:1px solid #b9c3cb;border-radius:4px;background:#fff;padding:0 7px}.tool-btn{height:33px;min-width:34px;border:1px solid transparent;border-radius:4px;background:transparent;color:#26333e;cursor:pointer;padding:0 9px}.tool-btn:hover{background:#fff;border-color:#c4cbd2}.tool-btn:disabled{opacity:.45;cursor:not-allowed}.sep{width:1px;height:27px;background:#c4ccd2;margin:0 4px}.zoom-readout{min-width:49px;text-align:center;font-size:12px;color:#53616d}.workspace{min-height:0;display:grid;grid-template-columns:var(--source-width) var(--controls-width,190px) minmax(320px,1fr) var(--inspector-width,270px)}.panel{min-height:0;background:var(--panel);z-index:10}.source-panel{border-right:1px solid var(--line);display:flex;flex-direction:column;position:relative}.source-resize-handle{position:absolute;top:0;right:-5px;bottom:0;width:10px;cursor:col-resize;z-index:25;background:transparent}.source-resize-handle:hover,.source-resize-handle.active{background:rgba(36,117,168,.18)}.controls-panel{border-right:1px solid var(--line);display:flex;flex-direction:column;overflow:hidden;position:relative}.controls-resize-handle{position:absolute;top:0;right:-5px;bottom:0;width:10px;cursor:col-resize;z-index:25;background:transparent}.controls-resize-handle:hover,.controls-resize-handle.active{background:rgba(36,117,168,.18)}.control-palette{padding:8px;overflow:auto;display:flex;flex-direction:column;gap:7px}.control-item{display:grid;grid-template-columns:42px minmax(0,1fr);gap:8px;align-items:center;width:100%;padding:7px;border:1px solid #cbd3da;border-radius:6px;background:#fff;color:#263640;text-align:left;cursor:grab;user-select:none}.control-item:hover{background:#eef5fa;border-color:#8eb5cf}.control-item:active{cursor:grabbing}.control-palette.flowgorithm-palette{display:block;padding:8px 7px 12px}.flowgorithm-statement-header{background:#a9c9f2;border:1px solid #6187b4;padding:7px 9px;margin-bottom:10px;font-size:12px;color:#172b41}.flowgorithm-groups{display:grid;grid-template-columns:repeat(4,minmax(72px,1fr));gap:8px}.flow-control-group{min-width:0}.flow-control-group-title{text-align:center;font-size:11px;color:#192633;margin:0 0 8px;white-space:nowrap}.flowgorithm-palette .control-item{display:flex;flex-direction:column;align-items:center;justify-content:flex-start;gap:4px;border:0;background:transparent;padding:3px 1px;cursor:grab}.flowgorithm-palette .control-item:hover{background:#f0f4f7;border-color:transparent}.flowgorithm-palette .control-label{display:none}.flowgorithm-palette .control-icon{width:78px;height:34px;font-size:11px;font-weight:500;color:#111}.flowgorithm-palette .control-icon.fg-input{border-width:2px;background:#b0e0ff;border-color:#5080a0;transform:skew(-24deg)}.flowgorithm-palette .control-icon.fg-input span{transform:skew(24deg)}.flowgorithm-palette .control-icon.fg-output{border-width:2px;background:#d0ffd0;border-color:#70a070;transform:skew(-24deg)}.flowgorithm-palette .control-icon.fg-output span{transform:skew(24deg)}.flowgorithm-palette .control-icon.fg-declare{width:78px;height:34px;clip-path:none;border:2px solid #a0a070;background:#ffffd0}.flowgorithm-palette .control-icon.fg-declare:before{content:"";position:absolute;left:8px;top:0;bottom:0;border-left:1px solid #a0a070}.flowgorithm-palette .control-icon.fg-assign{width:78px;height:34px;border:2px solid #a0a070;background:#ffffd0}.flowgorithm-palette .control-icon.fg-if{width:58px;height:34px;border:1px solid #a07070;background:#ffd0d0;clip-path:polygon(50% 0,100% 50%,50% 100%,0 50%);transform:none;margin-left:0}.flowgorithm-palette .control-icon.fg-if span{transform:none}.flowgorithm-palette .control-icon.fg-call{border:1px solid #9070a0;background:#f0e0ff}.flowgorithm-palette .control-icon.fg-call:before,.flowgorithm-palette .control-icon.fg-call:after{background:#9070a0;width:1px}.flowgorithm-palette .control-icon.fg-loop{border:1px solid #a08040;background:#ffe0a0;clip-path:polygon(14% 0,86% 0,100% 50%,86% 100%,14% 100%,0 50%)}.control-palette.flowgorithm-palette{display:flex;flex-direction:column;gap:8px;padding:8px}.flowgorithm-groups{display:flex;flex-direction:column;gap:10px}.flow-control-group{display:flex;flex-direction:column;gap:5px}.flow-control-group-title{text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.35px;color:#65727d;margin:2px 2px 1px}.flowgorithm-palette .control-item{display:grid;grid-template-columns:84px minmax(0,1fr);gap:8px;align-items:center;justify-content:stretch;width:100%;padding:6px 7px;border:1px solid #d1d8de;border-radius:6px;background:#fff;cursor:grab}.flowgorithm-palette .control-item:hover{background:#f0f4f7;border-color:#9bb6c8}.flowgorithm-palette .control-label{display:block}.flowgorithm-palette .control-label strong{font-size:12px;font-weight:600}.flowgorithm-palette .control-icon{justify-self:center}.control-icon{width:38px;height:31px;display:flex;align-items:center;justify-content:center;position:relative;color:#29485d;font-weight:800}.control-icon.process{border:2px solid #58758a;border-radius:3px;background:#fff}.control-icon.decision{width:27px;height:27px;border:2px solid #c27b18;background:#fff8e8;transform:rotate(45deg);margin-left:5px}.control-icon.decision span{transform:rotate(-45deg)}.control-icon.io{width:34px;height:27px;border:2px solid #3d83b6;background:#eef7ff;transform:skew(-12deg)}.control-icon.terminator{border:2px solid #358d57;border-radius:18px;background:#edf9f1}.control-icon.stop-sign{width:38px;height:38px;border:2px solid #8b2b25;background:#ffd7d3;clip-path:polygon(30% 0,70% 0,100% 30%,100% 70%,70% 100%,30% 100%,0 70%,0 30%)}.control-icon.rounded{border:2px solid #7255a5;border-radius:8px;background:#f6f2ff}.control-icon.dfd-process{border:2px solid #222;border-radius:8px;background:#d3d3d3}.control-icon.entity{border:2px solid #222;background:#d0d0d0;box-shadow:-5px -5px 0 -2px #d0d0d0,-5px -5px 0 0 #222}.control-icon.store{border:2px solid #222;background:#fff}.control-icon.store:before{content:attr(data-kind);width:12px;border-right:2px solid #222;height:100%;display:flex;align-items:center;justify-content:center;font-size:10px}.control-icon.table{border:2px solid #496b83;border-radius:3px;background:linear-gradient(#365d78 0 10px,#fff 10px)}.control-icon.textbox{font-size:22px;border:1px dashed #8a98a3;background:#fff}.control-icon.fg-assign{border:2px solid #a0a070;border-radius:2px;background:#ffffd0}.control-icon.fg-declare{width:34px;height:27px;background:#ffffd0;border:2px solid #a0a070;clip-path:polygon(12% 0,88% 0,100% 50%,88% 100%,12% 100%,0 50%)}.control-icon.fg-input{width:34px;height:27px;border:2px solid #5080a0;background:#b0e0ff;transform:skew(-12deg)}.control-icon.fg-output{width:34px;height:27px;border:2px solid #70a070;background:#d0ffd0;transform:skew(12deg)}.control-icon.fg-if{width:27px;height:27px;border:2px solid #a07070;background:#ffd0d0;transform:rotate(45deg);margin-left:5px}.control-icon.fg-if span{transform:rotate(-45deg)}.control-icon.fg-loop{width:34px;height:27px;border:2px solid #a08040;background:#ffe0a0;clip-path:polygon(14% 0,86% 0,100% 50%,86% 100%,14% 100%,0 50%)}.control-icon.fg-call{border:2px solid #9070a0;background:#f0e0ff}.control-icon.fg-call:before,.control-icon.fg-call:after{content:"";position:absolute;top:0;bottom:0;width:2px;background:#9070a0}.control-icon.fg-call:before{left:7px}.control-icon.fg-call:after{right:7px}.control-icon.fg-comment{border:1px dashed #8c8458;background:#fffde8;font-size:10px;color:#6e6743}.control-label strong{display:block;font-size:12px}.control-label small{display:block;color:#6a7781;font-size:10px;line-height:1.25;margin-top:2px}.right-panel{border-left:1px solid var(--line);overflow:auto;position:relative}.inspector-resize-handle{position:absolute;top:0;left:-5px;bottom:0;width:10px;cursor:col-resize;z-index:25;background:transparent}.inspector-resize-handle:hover,.inspector-resize-handle.active{background:rgba(36,117,168,.18)}.panel-head{height:39px;display:flex;align-items:center;justify-content:space-between;padding:0 10px;background:#e7ebef;border-bottom:1px solid var(--line);font-weight:750;color:#34424e}.source-meta{padding:9px 10px;border-bottom:1px solid var(--line);background:#f5f7f9;color:#5d6a75;font-size:12px;line-height:1.4}.editor-wrap{position:relative;flex:1;min-height:0;background:#17212b}.line-numbers{position:absolute;left:0;top:0;bottom:0;width:43px;padding:11px 7px;color:#72808d;background:#111920;text-align:right;font:13px/1.55 Consolas,"Courier New",monospace;white-space:pre;overflow:hidden;user-select:none;border-right:1px solid #2d3943}.source-editor{position:absolute;inset:0 0 0 43px;width:calc(100% - 43px);height:100%;resize:none;border:0;outline:0;padding:11px 12px;background:#17212b;color:#e4ebf0;caret-color:#ffb33f;tab-size:2;font:13px/1.55 Consolas,"Courier New",monospace;white-space:pre;overflow:auto}.source-footer{padding:7px 9px;border-top:1px solid var(--line);background:#eef2f5}.parse-status{min-height:19px;color:#52616c;font-size:12px}.parse-status.error{color:#a8332a}.canvas-shell{position:relative;min-width:0;min-height:0;overflow:hidden;background-color:var(--canvas);background-image:radial-gradient(#c5ced6 1px,transparent 1px);background-size:20px 20px}.canvas-shell.no-grid{background-image:none}#diagramSvg{width:100%;height:100%;display:block;touch-action:none;cursor:grab;user-select:none}#diagramSvg.panning{cursor:grabbing}.node{cursor:move}.node .body{filter:drop-shadow(0 1px 1px rgba(0,0,0,.17))}.node.selected .selection{display:block}.selection{display:none;fill:none;stroke:#e74c3c;stroke-width:2;stroke-dasharray:6 4;vector-effect:non-scaling-stroke}.edge{fill:none;stroke:#000;stroke-width:3;vector-effect:non-scaling-stroke;pointer-events:none}.edge.hovered{stroke:#e74c3c}.edge.selected{stroke:#e74c3c;stroke-width:3;stroke-dasharray:8 5;animation:edgeMarch .65s linear infinite}@keyframes edgeMarch{to{stroke-dashoffset:-13}}.edge-hit{fill:none;stroke:transparent;stroke-width:16;vector-effect:non-scaling-stroke;pointer-events:stroke;cursor:pointer}.flow-insert-arrow{cursor:pointer}.flow-insert-arrow .flow-insert-halo{fill:#fff;fill-opacity:.96;stroke:#d94138;stroke-width:1.6;vector-effect:non-scaling-stroke}.flow-insert-arrow:hover .flow-insert-halo{fill:#fff1ef;stroke:#b62822;stroke-width:2}.relationship-cardinality{pointer-events:none}.relationship-cardinality.selected line,.relationship-cardinality.selected circle{stroke:#e74c3c}.edge-label{cursor:pointer}.edge-label rect{fill:rgba(255,255,255,.92);stroke:#d2d9df;stroke-width:1}.edge-label.selected rect{stroke:#e74c3c;stroke-width:1.5}.edge-label text{pointer-events:none}.canvas-hint{position:absolute;left:12px;bottom:12px;padding:6px 9px;border:1px solid #c9d1d8;border-radius:4px;background:rgba(255,255,255,.88);color:#64727d;font-size:12px;pointer-events:none}.loading-cover{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(238,242,245,.88);z-index:20;font-size:16px;font-weight:750;color:#435260}.inspector{padding:12px}.empty{padding:30px 16px;text-align:center;color:var(--muted)}.info-card{border:1px solid #d3dae0;border-radius:6px;background:#fff;margin-bottom:11px;overflow:hidden}.info-card h3{font-size:13px;margin:0;padding:9px 10px;background:#edf1f4;border-bottom:1px solid #d7dde2}.info-body{padding:10px}.field{display:grid;grid-template-columns:86px minmax(0,1fr);gap:7px;align-items:center;margin-bottom:8px}.field:last-child{margin-bottom:0}.field label{font-size:11px;color:#5d6a75}.field input,.field select,.field textarea{width:100%;border:1px solid #bcc6ce;border-radius:4px;padding:5px 6px;background:#fff}.field input[type=color]{height:31px;padding:2px}.recent-colours-block{margin-top:8px;padding-top:8px;border-top:1px solid #e0e5e9}.recent-colours-title{font-size:11px;color:#5d6a75;margin-bottom:6px}.recent-colours{display:flex;gap:6px;flex-wrap:wrap;min-height:25px;align-items:center}.recent-colour{width:25px!important;height:25px!important;min-width:25px;padding:0!important;border:1px solid #8f9aa3!important;border-radius:4px!important;box-shadow:inset 0 0 0 1px rgba(255,255,255,.45);cursor:pointer}.recent-colours-empty{font-size:10px;color:#84909a}.format-checks{display:flex;gap:8px;flex-wrap:wrap;font-size:11px;color:#53616d}.format-checks label{display:flex;align-items:center;gap:3px}.format-checks input{width:auto}.floating-text{cursor:move}.floating-text text{pointer-events:none}.floating-text .text-hit{fill:rgba(255,255,255,.001);stroke:none;pointer-events:all}.floating-text .text-selection{display:none;fill:none;stroke:#e74c3c;stroke-width:1.5;stroke-dasharray:5 3;vector-effect:non-scaling-stroke;pointer-events:none}.floating-text.selected .text-selection{display:block}.mini-actions{display:flex;gap:5px;flex-wrap:wrap}.mini-btn{border:1px solid #bec7cf;border-radius:4px;background:#fff;padding:6px 8px;cursor:pointer}.mini-btn:hover{background:#edf2f6}.syntax-help{font-size:12px;line-height:1.5;color:#53616d}.syntax-help code{display:block;white-space:pre-wrap;background:#17212b;color:#dce5eb;padding:8px;border-radius:4px;margin-top:6px;font-family:Consolas,"Courier New",monospace}.statusbar{display:flex;align-items:center;gap:18px;padding:0 10px;background:#dfe5e9;border-top:1px solid #c3cbd2;color:#52606b;font-size:12px}.statusbar .grow{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.modal-backdrop{position:fixed;inset:0;z-index:500;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(13,20,27,.57)}.modal{display:flex;flex-direction:column;width:min(680px,100%);max-height:min(760px,92vh);background:#fff;border-radius:8px;box-shadow:0 18px 55px rgba(0,0,0,.36);overflow:hidden}.modal.small{width:min(490px,100%)}.modal-head{display:flex;justify-content:space-between;align-items:center;padding:13px 16px;background:#edf1f4;border-bottom:1px solid #cdd4da}.modal-head h2{font-size:17px;margin:0}.modal-close{border:0;background:transparent;font-size:22px;color:#63717d;cursor:pointer}.modal-body{padding:15px 16px;overflow:auto}.modal-foot{display:flex;justify-content:flex-end;gap:8px;padding:10px 16px;border-top:1px solid #d4dae0}.modal button{border:1px solid #b9c2ca;border-radius:4px;background:#fff;padding:7px 12px;cursor:pointer}.modal button.primary{background:var(--blue);border-color:var(--blue-dark);color:#fff}.modal textarea{width:100%;min-height:130px;border:1px solid #b9c3cb;border-radius:5px;padding:8px;resize:vertical}.new-object-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:9px}.new-object-choice{display:grid!important;grid-template-columns:46px minmax(0,1fr);gap:9px;align-items:center;text-align:left!important;padding:10px!important}.new-object-choice strong{display:block}.new-object-choice small{display:block;color:#687582;font-size:11px;margin-top:3px;line-height:1.3}.share-note{color:#5c6974;line-height:1.48}.share-option{display:flex;align-items:flex-start;gap:9px;margin:13px 0;padding:10px;background:#f7f9fa;border:1px solid #d4dae0;border-radius:5px}.share-option input{margin-top:3px}.share-option label{font-weight:700}.share-option small{display:block;margin-top:3px;color:#66737e;font-weight:400;line-height:1.4}.share-link-row{display:flex;gap:7px;margin-top:7px}.share-link-row input{flex:1;min-width:0;border:1px solid #b9c3cb;border-radius:4px;padding:8px;background:#f8fafb}.share-link-row button{background:var(--blue);color:#fff;border-color:var(--blue-dark)}.share-status{min-height:20px;margin-top:8px;color:#5b6974;font-size:13px}.share-status.error{color:#a83228}.toast-stack{position:fixed;right:15px;bottom:41px;z-index:1000;display:flex;flex-direction:column;gap:8px;pointer-events:none}.toast{min-width:230px;max-width:390px;background:#25323c;color:#fff;border-radius:5px;padding:10px 12px;box-shadow:var(--shadow)}.toast.error{background:#9f332a}.toast.success{background:#267346}@media(max-width:1180px){.workspace{grid-template-columns:var(--source-width) min(var(--controls-width,156px),220px) minmax(300px,1fr) 0}.right-panel{visibility:hidden}.tool-label{display:none}}@media(max-width:820px){.workspace{grid-template-columns:126px minmax(280px,1fr)}.source-resize-handle{display:none}.source-panel{position:absolute;left:0;top:93px;bottom:28px;width:min(390px,88vw);transform:translateX(-100%);transition:.2s;box-shadow:3px 0 14px rgba(0,0,0,.2)}.source-panel.mobile-open{transform:translateX(0)}.controls-panel{grid-column:1}.menu-strip{display:none}.title-wrap{min-width:100px}}@media(max-width:560px){.workspace{grid-template-columns:minmax(280px,1fr)}.controls-panel{display:none}}@media print{.topbar,.toolbar,.source-panel,.controls-panel,.right-panel,.statusbar,.canvas-hint{display:none!important}.app,.workspace,.canvas-shell{display:block;width:100%;height:100%;background:#fff}#diagramSvg{width:100vw;height:100vh}}


/* Execution console and current-control spotlight — Charter 0.0.1 */
.run-output{
  background:#000!important;color:#fff!important;
  font-family:Consolas,"Lucida Console","Courier New",monospace!important;
  font-size:14px!important;line-height:1.55!important;font-weight:400!important;
  white-space:pre-wrap;overflow:auto;min-height:180px;max-height:42vh;
  padding:14px!important;border:1px solid #696969;border-radius:0!important;
  box-shadow:inset 0 0 0 1px #141414;
}
.run-variables{font:13px/1.5 Consolas,"Courier New",monospace;max-height:240px;overflow:auto}
.run-variables table{border-collapse:collapse;width:100%}
.run-variables td{padding:5px;border-bottom:1px solid #dae1e7;overflow-wrap:anywhere}
.run-runtime{padding:9px;font-size:12px;color:#526575}
.run-runtime button{margin-right:6px}
#runProgramBtn.running{background:#fbe6e6;color:#9b1717;border-color:#da8c8c}
@keyframes charterExecutionAura{
  0%,100%{filter:drop-shadow(0 0 7px #fff0b2) drop-shadow(0 0 20px #ffbe42) drop-shadow(0 0 42px #ff6d46) drop-shadow(0 0 72px rgba(255,70,155,.95))}
  50%{filter:drop-shadow(0 0 12px #fff3ca) drop-shadow(0 0 28px #ffaf24) drop-shadow(0 0 56px #ff477b) drop-shadow(0 0 94px rgba(255,53,140,1))}
}
.node.run-active .body{
  stroke:#ff5b7f!important;stroke-width:7px!important;
  stroke-linejoin:round!important;
  animation:charterExecutionAura 1.15s ease-in-out infinite!important;
}
.node.run-error .body{
  stroke:#e01228!important;stroke-width:5px!important;
  filter:drop-shadow(0 0 8px #ff263b) drop-shadow(0 0 20px rgba(255,0,40,.95))!important;
}
@media(prefers-reduced-motion:reduce){.node.run-active .body{animation:none!important;filter:drop-shadow(0 0 25px #ffba39) drop-shadow(0 0 60px #ff468a)!important}}

/* Flowgorithm controls — Charter 0.0.1 baseline */
.default-caption::placeholder{color:#8b949b;opacity:1;font-style:italic}
.node .body{vector-effect:non-scaling-stroke}
.flowgorithm-palette svg.control-icon,.new-object-choice svg.control-icon{display:block;background:transparent!important;border:0!important;clip-path:none!important;overflow:visible}
.flowgorithm-palette svg.control-icon polygon,.new-object-choice svg.control-icon polygon{vector-effect:non-scaling-stroke;stroke-width:3!important;stroke-linejoin:round}
.flowgorithm-palette svg.control-icon.fg-if polygon,.new-object-choice svg.control-icon.fg-if polygon{fill:#ffd0d0!important;stroke:#8f5f5f!important}
.flowgorithm-palette svg.control-icon.fg-loop polygon,.flowgorithm-palette svg.control-icon.fg-for polygon,.new-object-choice svg.control-icon.fg-loop polygon,.new-object-choice svg.control-icon.fg-for polygon{fill:#ffe0a0!important;stroke:#8b6b36!important}
.flowgorithm-palette svg.control-icon.stop-sign polygon,.new-object-choice svg.control-icon.stop-sign polygon{fill:#ffd7d3!important;stroke:#8b2b25!important}
.flowgorithm-palette svg.control-icon.fg-if{width:78px!important;height:46px!important}
.flowgorithm-palette svg.control-icon.fg-loop,.flowgorithm-palette svg.control-icon.fg-for{width:78px!important;height:40px!important}
.flowgorithm-palette svg.control-icon.stop-sign{width:46px!important;height:46px!important}
.flowgorithm-palette svg.control-icon text,.new-object-choice svg.control-icon text{fill:#111!important;font-size:15px;font-weight:700;text-anchor:middle}
.flowgorithm-palette .control-icon.fg-input,.flowgorithm-palette .control-icon.fg-output,.flowgorithm-palette .control-icon.fg-declare,.flowgorithm-palette .control-icon.fg-assign,.flowgorithm-palette .control-icon.terminator,.flowgorithm-palette .control-icon.fg-call{width:78px!important;height:34px!important;border-width:3px!important}
.flowgorithm-palette .control-icon.fg-input{background:#b0e0ff;border-color:#466f8a!important}
.flowgorithm-palette .control-icon.fg-output{background:#d0ffd0;border-color:#5f8f5f!important}
.flowgorithm-palette .control-icon.fg-declare,.flowgorithm-palette .control-icon.fg-assign{background:#ffffd0;border-color:#8d8d5f!important}
.flowgorithm-palette .control-icon.terminator{background:#edf9f1;border-color:#2f7d4d!important}
.flowgorithm-palette .control-icon.fg-call{background:#f0e0ff;border-color:#7d608d!important}
.flowgorithm-palette .control-icon.fg-declare:before{border-left-width:3px!important;border-left-color:#8d8d5f!important}

/* Source panel visibility applies to desktop grid and mobile drawer. */
body.source-panel-hidden .source-panel{display:none!important}
body.source-panel-hidden .workspace{grid-template-columns:var(--controls-width,190px) minmax(320px,1fr) var(--inspector-width,270px)}
@media(max-width:1180px){body.source-panel-hidden .workspace{grid-template-columns:min(var(--controls-width,156px),220px) minmax(300px,1fr) 0}}
@media(max-width:820px){body.source-panel-hidden .workspace{grid-template-columns:126px minmax(280px,1fr)}body:not(.source-panel-hidden) .source-panel.mobile-open{transform:translateX(0)}}
@media(max-width:560px){body.source-panel-hidden .workspace{grid-template-columns:minmax(280px,1fr)}}

/* Insertion modal: use the same icons, dimensions and colours as the Controls palette. */
.control-insert-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:9px}
.control-insert-choice.new-object-choice{display:grid;grid-template-columns:84px minmax(0,1fr);align-items:center;gap:8px;text-align:left;padding:8px;border:1px solid #d1d8de;border-radius:6px;background:#fff;min-height:56px}
.control-insert-choice .control-icon{justify-self:center;flex-shrink:0}
.control-insert-choice .control-label{display:block;font-size:12px;color:#263640}
.control-insert-choice:hover{background:#f0f4f7;border-color:#9bb6c8}
.control-insert-choice .control-icon.fg-input,.control-insert-choice .control-icon.fg-output,.control-insert-choice .control-icon.fg-declare,.control-insert-choice .control-icon.fg-assign{width:78px;height:34px;border-width:2px}
.control-insert-choice .control-icon.fg-input{transform:skew(-24deg)}
.control-insert-choice .control-icon.fg-output{transform:skew(-24deg)}
.control-insert-choice svg.control-icon.fg-if{width:58px;height:34px}
.control-insert-choice svg.control-icon.fg-loop,.control-insert-choice svg.control-icon.fg-for{width:78px;height:34px}
.control-insert-choice svg.control-icon.stop-sign{width:38px;height:38px}
/* Keep the source and controls titlebars at identical heights. */
.source-panel>.panel-head,.controls-panel>.panel-head{min-height:38px;height:38px;box-sizing:border-box;flex:0 0 38px}
/* Reverse the OUTPUT parallelogram to match its diagram geometry. */
.flowgorithm-palette .control-icon.fg-output{transform:skew(24deg)!important}
.flowgorithm-palette .control-icon.fg-output span{transform:skew(-24deg)!important}
</style>
</head>
<body>
<div class="app">
<header class="topbar">
<div class="brand"><span class="brand-mark"></span><span>Charter</span></div>
<nav class="menu-strip">
<div class="menu"><button type="button">File</button><div class="dropdown">
<button data-action="templates">Template gallery</button>
<button data-action="new-flow">New flowchart</button>
<button data-action="new-dfd">New DFD</button>
<button data-action="new-erd">New ERD</button><hr>
<button data-action="open-json">Open JSON… <kbd>Ctrl+O</kbd></button>
<button data-action="save-share">Share… <kbd>Ctrl+S</kbd></button>
<button data-action="save-template">Save as template…</button>
<button data-action="save-browser">Save in browser</button>
<button data-action="browser-projects">Browser projects…</button><hr>
<button data-action="download-json">Download JSON…</button>
<button data-action="print">Print <kbd>Ctrl+P</kbd></button>
</div></div>
<div class="menu"><button type="button">Export</button><div class="dropdown">
<div class="nested-menu"><button type="button">Export to image…</button><div class="nested-menu-panel">
<button data-action="export-svg">Export to SVG…</button>
<button data-action="export-png">Export to PNG…</button>
</div></div>
<div class="nested-menu"><button type="button">Export to code…</button><div class="nested-menu-panel">
<button data-action="export-sql">Export to SQL…</button>
<button data-action="export-python">Export to Python…</button>
<button data-action="export-qcaa">Export to QCAA Pseudocode…</button>
<button data-action="export-gatton">Export to Gatton Pseudocode…</button>
<button data-action="export-javascript">Export to JavaScript…</button>
</div></div>
</div></div>
<div class="menu"><button type="button">Diagram</button><div class="dropdown">
<button data-action="new-object">New Object…</button><hr>
<button data-action="render">Render source <kbd>Ctrl+Enter</kbd></button>
<button data-action="auto-layout">Auto-layout</button>
<button data-action="fit">Fit diagram</button>
<button data-action="toggle-grid">Toggle grid</button>
<button data-action="toggle-labels">Toggle edge labels</button>
</div></div>
<div class="menu"><button type="button">Help</button><div class="dropdown">
<button data-action="syntax-help">Notation help</button>
<button data-action="about">About Charter</button>
</div></div>
</nav>
<div class="title-wrap"><span id="unsavedDot" class="unsaved-dot" title="Unsaved changes"></span><input id="diagramTitle" aria-label="Diagram title"></div>
</header>
<div class="toolbar">
<label for="diagramType">Diagram</label><select id="diagramType"><option value="flowchart">Flowchart</option><option value="dfd">DFD</option><option value="erd">ERD</option></select>
<label for="sourceType">Code</label><select id="sourceType"></select>
<label for="direction">Layout</label><select id="direction"><option value="TB">Top → bottom</option><option value="LR">Left → right</option><option value="GRID">Grid</option><option value="FV">Fixed vertical (Flowgorithm)</option></select>
<label for="lineType">Lines</label><select id="lineType"><option value="orthogonal">90°</option><option value="direct">Direct</option><option value="curved">Curved</option></select>
<span class="sep"></span>
<button class="tool-btn" data-action="new-object" title="Add a new diagram object">＋ <span class="tool-label">New Object</span></button>
<button class="tool-btn" data-action="render" title="Render source (Ctrl+Enter)">⟳ <span class="tool-label">Render</span></button>
<button class="tool-btn" data-action="run" id="runProgramBtn" title="Run the flowchart">▶ <span class="tool-label">Run</span></button>
<button class="tool-btn" data-action="auto-layout" title="Auto-layout">⌘</button>
<button class="tool-btn" data-action="fit" title="Fit diagram">⛶</button>
<span class="sep"></span>
<button class="tool-btn" data-action="zoom-out" title="Zoom out">−</button><span id="zoomReadout" class="zoom-readout">100%</span><button class="tool-btn" data-action="zoom-in" title="Zoom in">+</button>
<span class="sep"></span>
<button class="tool-btn" data-action="save-share" title="Share this diagram">☁ <span class="tool-label">Share</span></button>
<button class="tool-btn" data-action="export-png" title="Export PNG">🖼</button>
<button class="tool-btn" data-action="toggle-source" title="Toggle source panel">☰</button>
</div>
<main class="workspace">
<aside id="sourcePanel" class="panel source-panel">
<div class="panel-head"><span>Diagram source</span><button class="mini-btn" data-action="syntax-help" title="Notation help">?</button></div>
<div id="sourceMeta" class="source-meta"></div>
<div class="editor-wrap"><div id="lineNumbers" class="line-numbers">1</div><textarea id="sourceEditor" class="source-editor" spellcheck="false" aria-label="Diagram source"></textarea></div>
<div class="source-footer"><div id="parseStatus" class="parse-status">Ready.</div></div>
<div id="sourceResizeHandle" class="source-resize-handle" title="Drag to resize the diagram source panel"></div>
</aside>
<aside id="controlsPanel" class="panel controls-panel">
<div class="panel-head"><span>Controls</span></div>
<div id="controlPalette" class="control-palette" aria-label="Diagram controls"></div>
<div id="controlsResizeHandle" class="controls-resize-handle" title="Drag to resize the Controls panel"></div>
</aside>
<section id="canvasShell" class="canvas-shell">
<svg id="diagramSvg" aria-label="Diagram canvas"><defs><marker id="arrowHead" markerWidth="9" markerHeight="9" refX="8" refY="4.5" orient="auto-start-reverse" markerUnits="strokeWidth"><path d="M0,0 L9,4.5 L0,9 z" fill="context-stroke"></path></marker></defs><g id="viewport"><g id="edges"></g><g id="edgeLabels"></g><g id="nodes"></g></g></svg>
<div id="canvasHint" class="canvas-hint">Edit the notation on the left · drag nodes to adjust positions · double-click empty canvas to add text · drag the background to pan</div>
<div id="loadingCover" class="loading-cover hidden">Loading diagram…</div>
</section>
<aside id="inspectorPanel" class="panel right-panel"><div id="inspectorResizeHandle" class="inspector-resize-handle" title="Drag to resize the Inspector panel"></div><div class="panel-head"><span>Inspector</span></div><div id="inspector" class="inspector"></div></aside>
</main>
<footer class="statusbar"><span id="statusMessage" class="grow">Ready</span><span id="objectCount">0 objects</span><span id="saveState">Browser autosave on</span></footer>
</div>
<input id="jsonInput" type="file" accept="application/json,.json" class="hidden">
<div id="modalRoot"></div><div id="toastStack" class="toast-stack"></div>
<script>
(() => {
'use strict';

const NS='http://www.w3.org/2000/svg';
const INITIAL_SHARE_ID=<?php echo json_encode($initialShareId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const INITIAL_NEW_SESSION_ID=<?php echo json_encode($initialNewSessionId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const URL_PARAMETERS=new URLSearchParams(window.location.search);
const REQUESTED_LAYOUT=({'fixed-vertical':'FV','fixed_vertical':'FV',fv:'FV',tb:'TB','top-bottom':'TB','top-to-bottom':'TB',lr:'LR','left-right':'LR','left-to-right':'LR',grid:'GRID'})[(URL_PARAMETERS.get('layout')||URL_PARAMETERS.get('mode')||'').toLowerCase()]||null;
const REQUESTED_SOURCE_VISIBILITY=(URL_PARAMETERS.get('source')||'').toLowerCase();
const INITIAL_PROJECT=<?php echo json_encode($initialProject, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const DEFAULT_PROJECTS=<?php echo json_encode($defaultSources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const DEFAULT_DFD_PROJECT=<?php echo json_encode($defaultDfdProject, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const el=id=>document.getElementById(id);
const svg=el('diagramSvg'),canvasShell=el('canvasShell'),viewport=el('viewport'),edgesLayer=el('edges'),labelsLayer=el('edgeLabels'),nodesLayer=el('nodes');
const sourceEditor=el('sourceEditor'),lineNumbers=el('lineNumbers'),inspector=el('inspector');

let project=null,currentModel={nodes:[],edges:[],warnings:[],title:''},selectedId=null,selectedEdgeId=null,selectedTextId=null,selectedItems=new Set();
let camera={x:0,y:0,scale:1},currentPositions=new Map(),nodeSizes=new Map();
let panState=null,dragState=null,renderTimer=null,autosaveTimer=null,sharedAutosaveTimer=null,dirty=false,sourceResizeState=null,controlsResizeState=null,inspectorResizeState=null,edgeClickTimer=null,lastNodePointer={id:null,time:0};
let serverShareId=null,serverShareUrl=null,serverNewSessionUrl=null,localProjectKey=null;
let changeRevision=0,serverSaveChain=Promise.resolve();
let undoHistory=[],undoRestoring=false,sourceUndoActive=false,sourceUndoTimer=null,titleUndoActive=false,titleUndoTimer=null;

function clone(value){return JSON.parse(JSON.stringify(value));}
function now(){return Date.now();}
function uuid(){if(window.crypto&&crypto.randomUUID)return crypto.randomUUID();return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const r=Math.random()*16|0;return(c==='x'?r:(r&3|8)).toString(16);});}
function escapeHtml(value){return String(value==null?'':value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function escapeAttr(value){return escapeHtml(value).replace(/`/g,'&#96;');}
function safeFilename(value){return String(value||'diagram').replace(/[\\/:*?"<>|]+/g,'-').replace(/\s+/g,' ').trim()||'diagram';}
function stableId(prefix,key){let hash=2166136261;const text=prefix+'|'+String(key).toLowerCase();for(let i=0;i<text.length;i++){hash^=text.charCodeAt(i);hash=Math.imul(hash,16777619);}return prefix+'-'+(hash>>>0).toString(16);}
function createSvg(name,attrs={}){const node=document.createElementNS(NS,name);Object.keys(attrs).forEach(k=>node.setAttribute(k,attrs[k]));return node;}
function downloadBlob(blob,name){const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=name;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);}
function setStatus(message){el('statusMessage').textContent=message;}
function toast(message,type=''){const item=document.createElement('div');item.className='toast'+(type?' '+type:'');item.textContent=message;el('toastStack').appendChild(item);setTimeout(()=>item.remove(),3800);}

const RECENT_COLOURS_KEY='charter.recentColours';
function normaliseHexColour(value){const text=String(value||'').trim().toLowerCase();return /^#[0-9a-f]{6}$/.test(text)?text:'';}
function getRecentColours(){try{const list=JSON.parse(localStorage.getItem(RECENT_COLOURS_KEY)||'[]');return Array.isArray(list)?list.map(normaliseHexColour).filter(Boolean).slice(0,6):[];}catch(error){return[];}}
function rememberRecentColour(value){const colour=normaliseHexColour(value);if(!colour)return;const list=getRecentColours().filter(item=>item!==colour);list.unshift(colour);try{localStorage.setItem(RECENT_COLOURS_KEY,JSON.stringify(list.slice(0,6)));}catch(error){}refreshRecentColourBlocks();}
function recentColoursBlockHtml(){const list=getRecentColours();return `<div class="recent-colours-block"><div class="recent-colours-title">Recently selected colours</div><div class="recent-colours" data-recent-colours>${list.length?list.map(colour=>`<button type="button" class="recent-colour" data-recent-colour="${escapeAttr(colour)}" title="${escapeAttr(colour)}" style="background:${escapeAttr(colour)}"></button>`).join(''):'<span class="recent-colours-empty">No recent colours yet.</span>'}</div></div>`;}
function refreshRecentColourBlocks(){document.querySelectorAll('[data-recent-colours]').forEach(block=>{const list=getRecentColours();block.innerHTML=list.length?list.map(colour=>`<button type="button" class="recent-colour" data-recent-colour="${escapeAttr(colour)}" title="${escapeAttr(colour)}" style="background:${escapeAttr(colour)}"></button>`).join(''):'<span class="recent-colours-empty">No recent colours yet.</span>';block.querySelectorAll('[data-recent-colour]').forEach(button=>button.onclick=()=>{const input=block.closest('.info-card')&&block.closest('.info-card').querySelector('input[type=color]');if(input){input.value=button.getAttribute('data-recent-colour');input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));}});});}

function selectionKey(kind,id){return String(kind)+':'+String(id);}
function isSelected(kind,id){return selectedItems.has(selectionKey(kind,id));}
function syncPrimarySelection(){
    selectedId=null;selectedEdgeId=null;selectedTextId=null;
    if(selectedItems.size!==1)return;
    const only=[...selectedItems][0],cut=only.indexOf(':'),kind=only.slice(0,cut),id=only.slice(cut+1);
    if(kind==='node')selectedId=id;else if(kind==='edge')selectedEdgeId=id;else if(kind==='textbox')selectedTextId=id;
}
function clearSelection(){selectedItems.clear();syncPrimarySelection();}
function selectItem(kind,id,additive=false){
    const key=selectionKey(kind,id);
    if(additive){if(selectedItems.has(key))selectedItems.delete(key);else selectedItems.add(key);}
    else{selectedItems.clear();selectedItems.add(key);}
    syncPrimarySelection();
}
function selectedMovableItems(){
    const items=[];
    selectedItems.forEach(key=>{const cut=key.indexOf(':'),kind=key.slice(0,cut),id=key.slice(cut+1);if(kind==='node'||kind==='textbox')items.push({kind,id});});
    return items;
}

function makeUndoSnapshot(){
    if(!project)return null;
    captureCurrentLayout(false);
    const snapshot=clone(project);
    snapshot.source=sourceEditor.value;
    snapshot.title=el('diagramTitle').value.trim()||snapshot.title||'Untitled diagram';
    snapshot.diagramType=el('diagramType').value||snapshot.diagramType;
    snapshot.sourceType=el('sourceType').value||snapshot.sourceType;
    snapshot.direction=el('direction').value||snapshot.direction;
    snapshot.settings=snapshot.settings||{};
    snapshot.settings.lineType=el('lineType').value||snapshot.settings.lineType||'orthogonal';
    return{project:snapshot,selectedId,selectedEdgeId,selectedTextId,selectedItems:[...selectedItems],camera:clone(camera)};
}
function pushUndoSnapshot(snapshot=null){
    if(undoRestoring)return;
    snapshot=snapshot||makeUndoSnapshot();
    if(!snapshot)return;
    const serial=JSON.stringify(snapshot.project);
    const last=undoHistory.length?undoHistory[undoHistory.length-1]:null;
    if(last&&last.serial===serial)return;
    undoHistory.push({serial,snapshot});
    if(undoHistory.length>10)undoHistory.shift();
}
function undoLastChange(){
    if(!undoHistory.length){toast('Nothing to undo.');return;}
    const entry=undoHistory.pop(),snap=entry.snapshot;
    undoRestoring=true;
    try{
        project=normaliseProject(clone(snap.project));
        el('diagramTitle').value=project.title;
        el('diagramType').value=project.diagramType;
        refreshSourceTypeOptions();
        el('sourceType').value=project.sourceType;
        el('direction').value=project.direction;
        el('lineType').value=project.settings.lineType;
        sourceEditor.value=project.source;
        updateLineNumbers();applyGrid();
        selectedItems=new Set(Array.isArray(snap.selectedItems)?snap.selectedItems:[]);if(!selectedItems.size){if(snap.selectedId)selectedItems.add(selectionKey('node',snap.selectedId));if(snap.selectedEdgeId)selectedItems.add(selectionKey('edge',snap.selectedEdgeId));if(snap.selectedTextId)selectedItems.add(selectionKey('textbox',snap.selectedTextId));}syncPrimarySelection();camera=clone(snap.camera||camera);
        renderFromSource({fit:false,markDirty:false});
        setDirty(true);saveAutosave();scheduleSharedAutosave(500);
        toast('Undid the last change.','success');
    }finally{undoRestoring=false;}
}
function beginGroupedUndo(kind){
    if(kind==='source'){
        if(!sourceUndoActive){pushUndoSnapshot();sourceUndoActive=true;}
        clearTimeout(sourceUndoTimer);sourceUndoTimer=setTimeout(()=>{sourceUndoActive=false;},900);
    }else if(kind==='title'){
        if(!titleUndoActive){pushUndoSnapshot();titleUndoActive=true;}
        clearTimeout(titleUndoTimer);titleUndoTimer=setTimeout(()=>{titleUndoActive=false;},900);
    }
}

function normaliseProject(data){
    data=data&&typeof data==='object'?data:{};
    const type=['flowchart','dfd','erd'].includes(data.diagramType)?data.diagramType:'flowchart';
    const allowed={flowchart:['flowgorithm','python','qcaa','gatton','javascript'],dfd:['dfd'],erd:['sql','prisma','quickdbd','mapping']};
    let sourceType=allowed[type].includes(data.sourceType)?data.sourceType:allowed[type][0];
    const defaultKey=type==='erd'?'erd-'+sourceType:(type==='flowchart'?'flowchart-'+sourceType:type);
    const definition=DEFAULT_PROJECTS[defaultKey]||DEFAULT_PROJECTS.flowchart;
    data.id=data.id||uuid();data.title=data.title||definition.title;data.diagramType=type;data.sourceType=sourceType;data.source=String(data.source==null?definition.source:data.source);
    data.direction=['LR','TB','GRID','FV'].includes(data.direction)?data.direction:'TB';
    data.layout=data.layout&&typeof data.layout==='object'?data.layout:{};data.layout.positions=data.layout.positions&&typeof data.layout.positions==='object'?data.layout.positions:{};data.layout.positionsByKey=data.layout.positionsByKey&&typeof data.layout.positionsByKey==='object'?data.layout.positionsByKey:{};data.layout.links=data.layout.links&&typeof data.layout.links==='object'?data.layout.links:{};data.layout.nodeStyles=data.layout.nodeStyles&&typeof data.layout.nodeStyles==='object'?data.layout.nodeStyles:{};data.layout.nodeStylesByKey=data.layout.nodeStylesByKey&&typeof data.layout.nodeStylesByKey==='object'?data.layout.nodeStylesByKey:{};data.layout.textboxes=data.layout.textboxes&&typeof data.layout.textboxes==='object'?data.layout.textboxes:{};data.layout.hiddenNodes=data.layout.hiddenNodes&&typeof data.layout.hiddenNodes==='object'?data.layout.hiddenNodes:{};data.layout.hiddenEdges=data.layout.hiddenEdges&&typeof data.layout.hiddenEdges==='object'?data.layout.hiddenEdges:{};
    data.settings=Object.assign({showGrid:true,showEdgeLabels:true,fontSize:14,lineType:'orthogonal'},data.settings||{});if(!['orthogonal','direct','curved'].includes(data.settings.lineType))data.settings.lineType='orthogonal';
    data.dates=Object.assign({created:now(),modified:now()},data.dates||{});
    return data;
}

function setProject(data,options={}){
    project=normaliseProject(clone(data));
    if(REQUESTED_LAYOUT&&project.diagramType==='flowchart'){
        const layoutChanged=project.direction!==REQUESTED_LAYOUT;
        project.direction=REQUESTED_LAYOUT;
        if(layoutChanged){project.layout.positions={};project.layout.positionsByKey={};}
    }
    serverShareId=options.serverShareId||null;serverShareUrl=options.serverShareUrl||null;serverNewSessionUrl=options.serverNewSessionUrl||null;localProjectKey=options.localProjectKey||null;
    clearSelection();camera={x:0,y:0,scale:1};undoHistory=[];sourceUndoActive=false;titleUndoActive=false;
    el('diagramTitle').value=project.title;el('diagramType').value=project.diagramType;refreshSourceTypeOptions();el('sourceType').value=project.sourceType;el('direction').value=project.direction;el('lineType').value=project.settings.lineType;
    sourceEditor.value=project.source;if(isFixedVertical())stripFixedVerticalLayoutSource();updateLineNumbers();applyGrid();renderFromSource({fit:true,markDirty:false});renderControlPalette();setDirty(false);updateShareAddress(serverShareId);
}

function updateShareAddress(shareId){if(!shareId)return;const address=new URL(window.location.href);const layout=address.searchParams.get('layout')||address.searchParams.get('mode'),source=address.searchParams.get('source');address.search='';address.hash='';address.searchParams.set('share',shareId);if(layout)address.searchParams.set('layout',layout);if(source==='show'||source==='hide')address.searchParams.set('source',source);history.replaceState({},'',address.pathname+address.search);}
function nodeLayoutKey(node){
    if(!node)return'';
    const key=String(node.key!=null?node.key:(node.label!=null?node.label:node.id));
    return String(node.type||node.shape||'object')+'|'+key;
}

function edgeLayoutKey(edge){
    if(!edge)return'';
    return [String(edge.from||''),String(edge.to||''),String(edge.label||''),String(edge.label2||'')].join('|');
}
function isNodeHidden(node){
    const hidden=project.layout.hiddenNodes||{};
    return !!(hidden[node.id]||hidden[nodeLayoutKey(node)]);
}
function isEdgeHidden(edge){
    const hidden=project.layout.hiddenEdges||{};
    return !!(hidden[edge.id]||hidden[edgeLayoutKey(edge)]);
}
function applyHiddenDiagramItems(model){
    const visibleNodes=(model.nodes||[]).filter(node=>!isNodeHidden(node));
    const visibleIds=new Set(visibleNodes.map(node=>node.id));
    const visibleEdges=(model.edges||[]).filter(edge=>visibleIds.has(edge.from)&&visibleIds.has(edge.to)&&!isEdgeHidden(edge));
    return Object.assign({},model,{nodes:visibleNodes,edges:visibleEdges});
}
function captureCurrentLayout(markDirtyIfChanged=false){
    if(!project)return false;
    project.layout=project.layout&&typeof project.layout==='object'?project.layout:{};
    project.layout.positions=project.layout.positions&&typeof project.layout.positions==='object'?project.layout.positions:{};
    project.layout.positionsByKey=project.layout.positionsByKey&&typeof project.layout.positionsByKey==='object'?project.layout.positionsByKey:{};
    project.layout.links=project.layout.links&&typeof project.layout.links==='object'?project.layout.links:{};
    project.layout.nodeStyles=project.layout.nodeStyles&&typeof project.layout.nodeStyles==='object'?project.layout.nodeStyles:{};
    project.layout.nodeStylesByKey=project.layout.nodeStylesByKey&&typeof project.layout.nodeStylesByKey==='object'?project.layout.nodeStylesByKey:{};
    project.layout.textboxes=project.layout.textboxes&&typeof project.layout.textboxes==='object'?project.layout.textboxes:{};
    project.layout.hiddenNodes=project.layout.hiddenNodes&&typeof project.layout.hiddenNodes==='object'?project.layout.hiddenNodes:{};
    project.layout.hiddenEdges=project.layout.hiddenEdges&&typeof project.layout.hiddenEdges==='object'?project.layout.hiddenEdges:{};

    const oldPositions=project.layout.positions;
    const oldByKey=project.layout.positionsByKey;
    const exactPositions={};
    const exactByKey={};
    let savedCount=0;

    if(currentModel&&Array.isArray(currentModel.nodes)){
        currentModel.nodes.forEach(node=>{
            const point=currentPositions&&currentPositions.get(node.id);
            if(!point||!Number.isFinite(Number(point.x))||!Number.isFinite(Number(point.y)))return;
            const x=Number(point.x),y=Number(point.y);
            exactPositions[node.id]={x,y};
            exactByKey[nodeLayoutKey(node)]={id:node.id,key:String(node.key!=null?node.key:''),type:String(node.type||node.shape||'object'),x,y};
            savedCount++;
        });
    }

    /* If the model has not rendered yet, retain the last valid layout instead of
       replacing it with an empty map. */
    if(savedCount===0){
        return false;
    }

    const oldJson=JSON.stringify(oldPositions);
    const oldKeyJson=JSON.stringify(oldByKey);
    project.layout.positions=exactPositions;
    project.layout.positionsByKey=exactByKey;
    project.layout.objectCount=savedCount;
    const changed=oldJson!==JSON.stringify(exactPositions)||oldKeyJson!==JSON.stringify(exactByKey);
    if(markDirtyIfChanged&&changed&&!dirty)setDirty(true);
    return changed;
}
function syncProjectFromControls(){
    project.source=sourceEditor.value;
    project.title=el('diagramTitle').value.trim()||'Untitled diagram';
    project.diagramType=el('diagramType').value;
    project.sourceType=el('sourceType').value;
    project.direction=el('direction').value;
    project.settings.lineType=el('lineType').value;
    captureCurrentLayout(false);
}
function prepareProjectForPersistence(){
    /* A source edit may still be waiting for the 450 ms renderer. Render it now so
       the coordinates we save belong to exactly the objects represented by source. */
    if(renderTimer){
        clearTimeout(renderTimer);
        renderTimer=null;
        renderFromSource({fit:false,markDirty:false});
    }
    captureCurrentLayout(false);
    syncProjectFromControls();
    captureCurrentLayout(false);
    project.dates.modified=now();
    return clone(project);
}
function setSaveState(text){el('saveState').textContent=text;}
function setDirty(value=true){dirty=value;el('unsavedDot').classList.toggle('show',dirty);setSaveState(dirty?'Unsaved changes':'All changes saved');if(value){changeRevision++;project.dates.modified=now();clearTimeout(autosaveTimer);autosaveTimer=setTimeout(saveAutosave,450);scheduleSharedAutosave();}}
function saveAutosave(){try{syncProjectFromControls();localStorage.setItem('charter.autosave',JSON.stringify(project));if(localProjectKey)localStorage.setItem(localProjectKey,JSON.stringify(project));}catch(error){setSaveState('Browser storage full');}}
function queueProjectSave(payload){const task=serverSaveChain.then(()=>sendServerRequest('save',payload));serverSaveChain=task.catch(()=>{});return task;}
function scheduleSharedAutosave(delay=1400){if(!serverShareId)return;clearTimeout(sharedAutosaveTimer);sharedAutosaveTimer=setTimeout(()=>autosaveSharedProject(),delay);}
async function autosaveSharedProject(){
    if(!serverShareId)return;
    clearTimeout(sharedAutosaveTimer);
    const revision=changeRevision,snapshot=prepareProjectForPersistence(),shareId=serverShareId;
    setSaveState('Saving shared diagram…');
    try{
        const data=await queueProjectSave({project:snapshot,shareId,forceNew:false});
        serverShareId=data.shareId;serverShareUrl=data.shareUrl;serverNewSessionUrl=data.newSessionUrl;
        updateShareAddress(serverShareId);saveAutosave();
        if(revision===changeRevision){dirty=false;el('unsavedDot').classList.remove('show');setSaveState('Shared changes saved');}
        else{setSaveState('Unsaved changes');scheduleSharedAutosave(500);}
    }catch(error){setSaveState('Shared autosave failed');}
}
function persistLayoutSoon(){captureCurrentLayout(false);project.dates.modified=now();saveAutosave();scheduleSharedAutosave(300);}

function refreshSourceTypeOptions(){
    const type=el('diagramType').value||project.diagramType;const sourceSelect=el('sourceType'),current=project&&project.sourceType?project.sourceType:'';
    let options;
    if(type==='flowchart'){
        options=[['flowgorithm','Flowgorithm'],['qcaa','QCAA Pseudocode'],['python','Python'],['gatton','Gatton Pseudocode'],['javascript','JavaScript']];
    }else if(type==='dfd')options=[['dfd','DFD shorthand']];
    else options=[['sql','SQL DDL'],['prisma','Prisma schema'],['quickdbd','QuickDBD shorthand'],['mapping','Entity mappings']];
    sourceSelect.innerHTML=options.map(o=>`<option value="${o[0]}">${o[1]}</option>`).join('');
}

function sourceDescription(){
    const map={flowgorithm:'Flowgorithm-style shorthand. Declare each control on its own line (for example terminal Start, declare MyVar1 = 0, output ShowMyVar MyVar1), then connect controls with Start -> MyVar1. Double-click a connector to insert a control. Visual positions are mirrored into Diagram Source under # @visual-layout positions for the designer: as # @position comments.',python:'Write ordinary Python. Charter recognises assignments, input/output, calls, if/else, for, while and return statements and renders them using Flowgorithm-style symbols.',qcaa:'Write QCAA-style pseudocode using BEGIN/END, INPUT/OUTPUT, CALCULATE, IF/ELSE/ENDIF, WHILE/ENDWHILE, FOR/ENDFOR and REPEAT/UNTIL.',gatton:'Write Gatton-style pseudocode. START/STOP, GET/INPUT, DISPLAY/PRINT, SET, IF/ELSE/ENDIF, WHILE, FOR and REPEAT/UNTIL are recognised.',javascript:'Write ordinary JavaScript. Charter recognises declarations, assignments, prompt/input, console output, calls, if/else, for, while and return statements.',dfd:'Level 1 overview DFD. Declare entities, processes and stores, then connect them with labelled arrows. Connected processes are numbered automatically. Use D/M/T for digital, manual or temporary stores. Comments may use #, //, -- or /* ... */. A line beginning with ## creates a text object on the canvas.',sql:'Paste CREATE TABLE statements. Primary and foreign keys are detected automatically.',prisma:'Paste Prisma model blocks. Model references and @relation fields create links.',quickdbd:'Use a table name, a line containing -, then one field per line. Add FK >- Table.field.',mapping:'Use entity Name { field: type PK } blocks and explicit Entity.field 1--* Other.field mappings.'};
    return map[project.sourceType]||'';
}
function updateSourceMeta(){el('sourceMeta').textContent=sourceDescription();}
function updateLineNumbers(){const count=Math.max(1,sourceEditor.value.split('\n').length);lineNumbers.textContent=Array.from({length:count},(_,i)=>i+1).join('\n');lineNumbers.scrollTop=sourceEditor.scrollTop;}

function stripLineComment(line){
    let quoted=false,quote='';for(let i=0;i<line.length;i++){const c=line[i];if((c==='"'||c==="'")&&(i===0||line[i-1]!=='\\')){if(!quoted){quoted=true;quote=c;}else if(quote===c){quoted=false;quote='';}}if(!quoted&&c==='#')return line.slice(0,i);if(!quoted&&c==='/'&&line[i+1]==='/')return line.slice(0,i);}return line;
}
function stripDfdBlockComments(source){
    const text=String(source||'');let out='',inBlock=false,quote='';
    for(let i=0;i<text.length;i++){
        const c=text[i],next=text[i+1]||'';
        if(inBlock){if(c==='*'&&next==='/'){out+='  ';i++;inBlock=false;}else out+=c==='\n'?'\n':c==='\r'?'\r':' ';continue;}
        if(quote){out+=c;if(c===quote&&text[i-1]!=='\\')quote='';continue;}
        if(c==='"'||c==="'"){quote=c;out+=c;continue;}
        if(c==='/'&&next==='*'){out+='  ';i++;inBlock=true;continue;}
        out+=c;
    }
    return out;
}
function dfdLineCommentIndex(line){
    let quote='';for(let i=0;i<line.length;i++){const c=line[i],next=line[i+1]||'';if(quote){if(c===quote&&line[i-1]!=='\\')quote='';continue;}if(c==='"'||c==="'"){quote=c;continue;}if(c==='#'||(c==='/'&&next==='/')||(c==='-'&&next==='-'))return i;}return-1;
}
function stripDfdLineComment(line){const text=String(line||'');if(/^\s*##/.test(text))return text;const index=dfdLineCommentIndex(text);return index>=0?text.slice(0,index):text;}
function analyseDfdSource(source){
    const cleaned=stripDfdBlockComments(source),cleanLines=cleaned.replace(/\r/g,'').split('\n'),originalLines=String(source||'').replace(/\r/g,'').split('\n');
    return cleanLines.map((raw,lineIndex)=>{const trimmed=raw.trim(),textMatch=trimmed.match(/^##\s*(.*)$/);return{lineIndex,raw:originalLines[lineIndex]||'',cleanedRaw:raw,isText:!!textMatch,text:textMatch?textMatch[1].trim():'',code:textMatch?'':stripDfdLineComment(raw).trim()};});
}
function dfdTextRecords(source){let ordinal=0;const counts={};return analyseDfdSource(source).filter(record=>record.isText).map(record=>{const text=record.text||'Text',key=text.toLowerCase(),occurrence=(counts[key]||0)+1;counts[key]=occurrence;return Object.assign({},record,{ordinal:++ordinal,id:stableId('dfdtext',text+'|'+occurrence)});});}
function syncDfdSourceTextboxes(source){
    if(!project||project.diagramType!=='dfd')return;
    project.layout.textboxes=project.layout.textboxes&&typeof project.layout.textboxes==='object'?project.layout.textboxes:{};
    const existing=project.layout.textboxes,next={},sourceBoxes=Object.values(existing).filter(box=>box&&box.sourceBacked&&box.sourceType==='dfd');
    Object.keys(existing).forEach(id=>{const box=existing[id];if(!(box&&box.sourceBacked&&box.sourceType==='dfd'))next[id]=box;});
    dfdTextRecords(source).forEach(record=>{const prior=existing[record.id]||sourceBoxes.find(box=>Number(box.sourceOrdinal)===record.ordinal)||{},defaults={x:(record.ordinal-1)*24,y:-140+(record.ordinal-1)*38};next[record.id]=normaliseFloatingTextbox(Object.assign({},defaults,prior,{id:record.id,text:record.text||'Text',sourceBacked:true,sourceType:'dfd',sourceOrdinal:record.ordinal}));});
    project.layout.textboxes=next;
}
function replaceDfdTextSourceLine(ordinal,text){
    const records=dfdTextRecords(sourceEditor.value),record=records.find(item=>item.ordinal===Number(ordinal));if(!record)return false;
    const lines=sourceEditor.value.replace(/\r/g,'').split('\n'),indent=(lines[record.lineIndex].match(/^\s*/)||[''])[0],singleLine=String(text==null?'':text).replace(/\s*\r?\n\s*/g,' ');
    lines[record.lineIndex]=indent+'## '+singleLine;sourceEditor.value=lines.join('\n');project.source=sourceEditor.value;updateLineNumbers();return true;
}
function removeDfdTextSourceOrdinals(ordinals){
    const wanted=new Set((ordinals||[]).map(Number)),records=dfdTextRecords(sourceEditor.value),removeLines=new Set(records.filter(record=>wanted.has(record.ordinal)).map(record=>record.lineIndex));if(!removeLines.size)return false;
    const lines=sourceEditor.value.replace(/\r/g,'').split('\n').filter((line,index)=>!removeLines.has(index));sourceEditor.value=lines.join('\n');project.source=sourceEditor.value;updateLineNumbers();return true;
}
function quoteDfdLabel(value){return '"'+String(value==null?'':value).replace(/\\/g,'\\\\').replace(/"/g,'\\"')+'"';}
function dfdDeclarationForNode(node,label){const name=quoteDfdLabel(label);if(node.type==='entity')return`entity ${node.key} ${name}`;if(node.type==='store'){const mode=node.storeKind==='M'?'manual':node.storeKind==='T'?'temporary':'digital';return`store ${node.key} ${mode} ${name}`;}return`process ${node.key} ${name}`;}
function updateDfdObjectLabel(node,newLabel){
    if(!node||project.diagramType!=='dfd')return false;newLabel=String(newLabel||'').trim()||String(node.key||'Object');if(newLabel===node.label)return false;
    pushUndoSnapshot();const records=analyseDfdSource(sourceEditor.value),lines=sourceEditor.value.replace(/\r/g,'').split('\n');let found=false,insertAt=lines.length;
    for(const record of records){if(record.isText||!record.code)continue;if(parseArrowLine(record.code)&&insertAt===lines.length)insertAt=record.lineIndex;const match=record.code.match(/^(entity|external|process|store|datastore)\s+([A-Za-z_][\w.-]*)\b/i);if(!match||match[2].toLowerCase()!==String(node.key).toLowerCase())continue;const original=lines[record.lineIndex]||'',commentIndex=dfdLineCommentIndex(original),suffix=commentIndex>=0?original.slice(commentIndex).trim():'',indent=(original.match(/^\s*/)||[''])[0];lines[record.lineIndex]=indent+dfdDeclarationForNode(node,newLabel)+(suffix?' '+suffix:'');found=true;break;}
    if(!found)lines.splice(insertAt,0,dfdDeclarationForNode(node,newLabel));
    sourceEditor.value=lines.join('\n');project.source=sourceEditor.value;updateLineNumbers();renderFromSource({fit:false,markDirty:false});setDirty(true);persistLayoutSoon();return true;
}
function parseNodeToken(raw,defaultShape='process'){
    const token=String(raw||'').trim();let id='',label='',shape=defaultShape,match;
    if((match=token.match(/^([A-Za-z_][\w.-]*)\s*\(\((.*?)\)\)$/))){id=match[1];label=match[2];shape='terminator';}
    else if((match=token.match(/^([A-Za-z_][\w.-]*)\s*\{(.*?)\}$/))){id=match[1];label=match[2];shape='decision';}
    else if((match=token.match(/^([A-Za-z_][\w.-]*)\s*\[\/(.*?)\/\]$/))){id=match[1];label=match[2];shape='io';}
    else if((match=token.match(/^([A-Za-z_][\w.-]*)\s*\[(.*?)\]$/))){id=match[1];label=match[2];shape='process';}
    else if((match=token.match(/^([A-Za-z_][\w.-]*)\s*\((.*?)\)$/))){id=match[1];label=match[2];shape='rounded';}
    else if((match=token.match(/^([A-Za-z_][\w.-]*)\s+["'](.+)["']$/))){id=match[1];label=match[2];}
    else if(/^[A-Za-z_][\w.-]*$/.test(token)){id=token;label=token;}
    else{label=token.replace(/^['"]|['"]$/g,'');id=label.replace(/[^A-Za-z0-9_]+/g,'_').replace(/^_+|_+$/g,'')||'node';}
    return{key:id,label:label||id,shape};
}
function parseArrowLine(line){
    let match=line.match(/^(.*?)\s*--\s*(.*?)\s*-->\s*(.*?)$/);if(match)return{from:match[1].trim(),to:match[3].trim(),label:match[2].trim()};
    match=line.match(/^(.*?)\s*(?:-->|->|=>)\s*(.*?)(?:\s*:\s*(.+))?$/);if(match)return{from:match[1].trim(),to:match[2].trim(),label:(match[3]||'').trim()};
    return null;
}

function flowAssignParts(value){
    const text=String(value||'').trim();
    const match=text.match(/^([A-Za-z_]\w*)\s*(?:=\s*)?([\s\S]*)$/);
    return match?{variable:match[1],expression:match[2].trim()}:{variable:'',expression:text};
}
function flowgorithmKindVisual(kind,key,value,caption){
    kind=String(kind||'').toLowerCase();key=String(key||'').trim();value=String(value==null?'':value).trim();
    const explicit=String(caption||'');
    if(kind==='terminal')return{shape:'terminator',kind:'terminal',label:explicit||key};
    if(kind==='stop')return{shape:'flow-stop',kind:'stop',label:explicit||key};
    const map={input:'flow-input',output:'flow-output',declare:'flow-declare',assign:'flow-assign',if:'flow-if',call:'flow-call',function:'flow-call',while:'flow-loop',for:'flow-for',do:'flow-loop'};
    let display=explicit||value||key;
    if(!explicit&&kind==='declare')display=key+(value?' = '+value:'');
    if(!explicit&&kind==='assign'){const a=flowAssignParts(value);display=a.variable?(a.variable+' = '+a.expression):key;}
    return{shape:map[kind]||'flow-assign',kind:kind||'statement',label:display};
}
function parseFlowgorithmDeclaration(raw){
    const line=String(raw||'').trim();
    // Canonical: action name [value] [: caption]. The colon always introduces
    // the caption, never the variable or expression. Legacy terminal End "End"
    // is still accepted for projects created before this syntax revision.
    const match=line.match(/^(terminal|stop|input|output|declare|assign|if|call|function|while|for|do)\s+([A-Za-z_][\w.-]*)(?:\s+(.*))?$/i);
    if(!match)return null;
    let keyword=match[1].toLowerCase();if(keyword==='function')keyword='call';
    const key=match[2],rest=String(match[3]||'').trim();
    let colon=-1,quote='',escaped=false;
    for(let i=0;i<rest.length;i++){
        const c=rest[i];if(escaped){escaped=false;continue;}
        if(quote){if(c==='\\'){escaped=true;continue;}if(c===quote)quote='';continue;}
        if(c==='"'||c==="'"){quote=c;continue;}
        if(c===':'&&(i===0||/\s/.test(rest[i-1]))){colon=i;break;}
    }
    let value=colon<0?rest:rest.slice(0,colon).trim();
    let caption=colon<0?'':rest.slice(colon+1).trim();
    if(!caption&&colon<0&&(keyword==='terminal'||keyword==='stop')&&/^(?:"(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*')$/.test(value)){
        caption=value;value='';
    }
    const unquote=text=>{const x=String(text||'').trim();return x.length>=2&&((x[0]==='"'&&x.at(-1)==='"')||(x[0]==="'"&&x.at(-1)==="'"))?x.slice(1,-1).replace(/\\(["'])/g,'$1'):x;};
    caption=unquote(caption);
    // A declaration owns its variable name. Accept '=', whitespace, or an
    // empty value as equivalent spellings while keeping the caption separate.
    if(keyword==='declare')value=value.replace(/^=\s*/, '').trim();
    const visual=flowgorithmKindVisual(keyword,key,value,caption);
    return{keyword,key,value,quoted:caption,label:visual.label,shape:visual.shape,kind:visual.kind};
}
function flowgorithmDeclarationForType(type,key){
    if(type==='start'||type==='terminal')return `terminal ${key}`;
    if(type==='end'||type==='stop')return `stop ${key}`;
    if(type==='input')return `input ${key} value`;
    if(type==='output')return `output ${key} value`;
    if(type==='declare')return `declare ${key}`;
    if(type==='assign')return `assign ${key} value = 0`;
    if(type==='if')return `if ${key} condition`;
    if(type==='call')return `function ${key} procedure()`;
    if(type==='while')return `while ${key} condition`;
    if(type==='for')return `for ${key} iterator=i; start=0; end=9; increment=1`;
    if(type==='do')return `do ${key} condition`;
    return `assign ${key} value = 0`;
}
function parseFlowgorithmShorthand(source){
    const nodes=new Map(),edges=[],warnings=[],sourcePositions=new Map();let title='';
    const rawLines=String(source||'').replace(/\r/g,'').split('\n');
    rawLines.forEach(raw=>{
        const position=String(raw||'').match(/^\s*#\s*@position\s+([^\s]+)\s+(-?(?:\d+(?:\.\d+)?|\.\d+))\s+(-?(?:\d+(?:\.\d+)?|\.\d+))\s*$/i);
        if(position)sourcePositions.set(position[1],{x:Number(position[2]),y:Number(position[3])});
    });
    function put(parsed,lineIndex){
        if(!parsed||!parsed.key)return null;
        const id=stableId('flowgorithm',parsed.key),node={id,key:parsed.key,label:parsed.label||parsed.key,shape:parsed.shape||'flow-assign',type:'flow',flowKind:parsed.kind||'statement',sourceDeclarationLineIndex:Number.isInteger(lineIndex)?lineIndex:null,sourceKeyword:parsed.keyword||parsed.kind||'',sourceValue:parsed.value||'',sourceCaption:parsed.quoted||'',hasExplicitCaption:!!parsed.quoted};
        if(!nodes.has(parsed.key))nodes.set(parsed.key,node);else Object.assign(nodes.get(parsed.key),node);
        return nodes.get(parsed.key);
    }
    rawLines.forEach((raw,index)=>{
        if(/^\s*#\s*@position\b/i.test(raw)||/^\s*#\s*@visual-layout\b/i.test(raw))return;
        const line=stripLineComment(raw).trim();if(!line)return;
        if(/^title(?:\s*:|\s+)/i.test(line)){title=line.replace(/^title(?:\s*:|\s+)/i,'').trim();return;}
        const declaration=parseFlowgorithmDeclaration(line);if(declaration)put(declaration,index);
    });
    function addReference(token,lineIndex){
        const clean=String(token||'').trim();
        if(/^[A-Za-z_][\w.-]*$/.test(clean)&&nodes.has(clean))return nodes.get(clean);
        if(/^[A-Za-z_][\w.-]*$/.test(clean)){
            const inferred={key:clean,label:clean,shape:/^start\d*$/i.test(clean)||/^end\d*$/i.test(clean)?'terminator':'flow-assign',kind:/^start\d*$/i.test(clean)?'start':(/^end\d*$/i.test(clean)?'end':'statement')};
            return put(inferred,lineIndex);
        }
        warnings.push('Line '+(lineIndex+1)+' references an invalid control name.');return null;
    }
    rawLines.forEach((raw,index)=>{
        if(/^\s*#\s*@position\b/i.test(raw)||/^\s*#\s*@visual-layout\b/i.test(raw))return;
        const line=stripLineComment(raw).trim();if(!line||/^title(?:\s*:|\s+)/i.test(line))return;
        if(parseFlowgorithmDeclaration(line))return;
        const arrow=parseArrowLine(line);
        if(arrow){
            const from=addReference(arrow.from,index),to=addReference(arrow.to,index);if(!from||!to)return;
            const linkCaption=/^([\"'])([\s\S]*)\1$/.test(arrow.label)?arrow.label.slice(1,-1).replace(/\\([\"'\\])/g,'$1'):arrow.label;edges.push({id:stableId('edge','flowgorithm|'+from.key+'>'+to.key+'|'+linkCaption+'|'+index),from:from.id,to:to.id,label:linkCaption,fromKey:from.key,toKey:to.key,sourceLineIndex:index,sourceFromToken:from.key,sourceToToken:to.key});return;
        }
        warnings.push('Line '+(index+1)+' could not be read.');
    });
    nodes.forEach(node=>{const position=sourcePositions.get(node.key);if(position)node.sourcePosition={x:position.x,y:position.y};});
    return{nodes:[...nodes.values()],edges,warnings,title};
}

function flowStripInlineComment(line,sourceType){
    const text=String(line||'');let quote='',escaped=false;
    for(let i=0;i<text.length;i++){
        const c=text[i],next=text[i+1]||'';
        if(quote){if(escaped){escaped=false;continue;}if(c==='\\'){escaped=true;continue;}if(c===quote)quote='';continue;}
        if(c==='"'||c==="'"||c==='`'){quote=c;continue;}
        if(sourceType==='python'&&c==='#')return text.slice(0,i);
        if(sourceType==='javascript'&&c==='/'&&next==='/')return text.slice(0,i);
        if((sourceType==='qcaa'||sourceType==='gatton')&&((c==='/'&&next==='/')||c==='#'))return text.slice(0,i);
    }
    return text;
}
function flowCommentText(line,sourceType){
    const text=String(line||'').trim();
    if(sourceType==='python'&&text.startsWith('#'))return text.replace(/^#+\s*/,'');
    if(sourceType==='javascript'&&text.startsWith('//'))return text.replace(/^\/\/\s*/,'');
    if((sourceType==='qcaa'||sourceType==='gatton')&&(text.startsWith('//')||text.startsWith('#')))return text.replace(/^(?:\/\/|#+)\s*/,'');
    return'';
}
function cleanFlowExpression(value){return String(value||'').trim().replace(/\s*\{\s*$/,'').replace(/:\s*$/,'').replace(/;\s*$/,'');}
function flowSimpleFromLine(raw,sourceType,lineNo){
    let text=String(raw||'').trim();if(!text)return null;
    const comment=flowCommentText(text,sourceType);if(comment)return{type:'simple',shape:'flow-comment',label:comment,line:lineNo,kind:'comment'};
    text=flowStripInlineComment(text,sourceType).trim();if(!text)return null;
    if(sourceType==='python'){
        if(/^pass\b/.test(text)||/^(break|continue)\b/.test(text))return null;
        let m=text.match(/^return\b\s*(.*)$/);if(m)return{type:'terminal',shape:'terminator',label:m[1].trim()?'Return '+m[1].trim():'Return',line:lineNo,kind:'return'};
        m=text.match(/^([A-Za-z_]\w*)\s*:\s*([A-Za-z_][\w\[\], .]*)\s*(?:=\s*(.+))?$/);if(m)return{type:'simple',shape:'flow-declare',label:m[3]?`${m[1]} : ${m[2]} = ${m[3]}`:`${m[1]} : ${m[2]}`,line:lineNo,kind:'declare'};
        m=text.match(/^([A-Za-z_]\w*)\s*=\s*(?:[A-Za-z_]\w*\s*\()?input\s*\((.*)\)\)?$/i);if(m)return{type:'simple',shape:'flow-input',label:`Input ${m[1]}`,line:lineNo,kind:'input'};
        if(/^input\s*\(/i.test(text))return{type:'simple',shape:'flow-input',label:cleanFlowExpression(text),line:lineNo,kind:'input'};
        if(/^print\s*\(/i.test(text))return{type:'simple',shape:'flow-output',label:cleanFlowExpression(text),line:lineNo,kind:'output'};
        if(/^[A-Za-z_]\w*(?:\[[^\]]+\]|\.[A-Za-z_]\w*)?\s*(?:=|\+=|-=|\*=|\/=|%=)/.test(text))return{type:'simple',shape:'flow-assign',label:cleanFlowExpression(text),line:lineNo,kind:'assign'};
        if(/^[A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*\s*\(.*\)\s*$/.test(text))return{type:'simple',shape:'flow-call',label:cleanFlowExpression(text),line:lineNo,kind:'call'};
        return{type:'simple',shape:'flow-assign',label:cleanFlowExpression(text),line:lineNo,kind:'statement'};
    }
    if(sourceType==='javascript'){
        let m=text.match(/^return\b\s*(.*?);?$/i);if(m)return{type:'terminal',shape:'terminator',label:m[1].trim()?'Return '+m[1].replace(/;$/,'').trim():'Return',line:lineNo,kind:'return'};
        m=text.match(/^(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:Number\s*\()?prompt\s*\((.*)\)\)?\s*;?$/i);if(m)return{type:'simple',shape:'flow-input',label:`Input ${m[1]}`,line:lineNo,kind:'input'};
        if(/^(?:console\.(?:log|info|warn)|alert)\s*\(/i.test(text))return{type:'simple',shape:'flow-output',label:cleanFlowExpression(text),line:lineNo,kind:'output'};
        if(/^(?:const|let|var)\b/i.test(text))return{type:'simple',shape:'flow-declare',label:cleanFlowExpression(text),line:lineNo,kind:'declare'};
        if(/^[A-Za-z_$][\w$]*(?:\[[^\]]+\]|\.[A-Za-z_$][\w$]*)?\s*(?:=|\+=|-=|\*=|\/=|%=|\+\+|--)/.test(text))return{type:'simple',shape:'flow-assign',label:cleanFlowExpression(text),line:lineNo,kind:'assign'};
        if(/^[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*\s*\(.*\)\s*;?$/.test(text))return{type:'simple',shape:'flow-call',label:cleanFlowExpression(text),line:lineNo,kind:'call'};
        return{type:'simple',shape:'flow-assign',label:cleanFlowExpression(text),line:lineNo,kind:'statement'};
    }
    const upper=text.toUpperCase();
    if(/^(RETURN|EXIT)\b/.test(upper))return{type:'terminal',shape:'terminator',label:text,line:lineNo,kind:'return'};
    if(/^(INPUT|READ|GET|ACCEPT)\b/.test(upper))return{type:'simple',shape:'flow-input',label:text,line:lineNo,kind:'input'};
    if(/^(OUTPUT|WRITE|PRINT|DISPLAY|PUT)\b/.test(upper))return{type:'simple',shape:'flow-output',label:text,line:lineNo,kind:'output'};
    if(/^(DECLARE|INITIALISE|INITIALIZE|CONSTANT|VAR)\b/.test(upper))return{type:'simple',shape:'flow-declare',label:text,line:lineNo,kind:'declare'};
    if(/^(CALCULATE|COMPUTE|SET|ASSIGN)\b/.test(upper)||/^[A-Za-z_]\w*\s*=/.test(text))return{type:'simple',shape:'flow-assign',label:text,line:lineNo,kind:'assign'};
    if(/^CALL\b/.test(upper))return{type:'simple',shape:'flow-call',label:text.replace(/^CALL\s+/i,''),line:lineNo,kind:'call'};
    return{type:'simple',shape:'flow-call',label:text,line:lineNo,kind:'statement'};
}
function pythonFlowAst(source){
    const rows=String(source||'').replace(/\t/g,'    ').replace(/\r/g,'').split('\n').map((raw,index)=>({raw,line:index+1,indent:(raw.match(/^ */)||[''])[0].length,text:raw.trim()}));
    function nextBodyIndent(index,parentIndent){for(let i=index;i<rows.length;i++){if(!rows[i].text)continue;if(rows[i].indent>parentIndent)return rows[i].indent;break;}return parentIndent+4;}
    function parseBlock(start,indent,stopAtElse=false){
        const items=[];let i=start;
        while(i<rows.length){const row=rows[i],rawText=row.text;if(!rawText){i++;continue;}if(row.indent<indent)break;if(row.indent>indent){const simple=flowSimpleFromLine(row.raw,'python',row.line);if(simple)items.push(simple);i++;continue;}
            if(stopAtElse&&/^(?:elif\b|else\s*:)/.test(rawText))break;
            const comment=flowCommentText(rawText,'python');if(comment){items.push({type:'simple',shape:'flow-comment',label:comment,line:row.line,kind:'comment'});i++;continue;}
            let m=rawText.match(/^if\s+(.+):\s*$/);if(m){const childIndent=nextBodyIndent(i+1,indent),thenRes=parseBlock(i+1,childIndent,true);i=thenRes.index;let elseItems=[];
                if(i<rows.length&&rows[i].indent===indent&&/^elif\s+(.+):\s*$/.test(rows[i].text)){const em=rows[i].text.match(/^elif\s+(.+):\s*$/),nestedIndent=nextBodyIndent(i+1,indent),nested=parseBlock(i+1,nestedIndent,true);i=nested.index;let nestedElse=[];if(i<rows.length&&rows[i].indent===indent&&/^else\s*:\s*$/.test(rows[i].text)){const ei=nextBodyIndent(i+1,indent),er=parseBlock(i+1,ei,false);nestedElse=er.items;i=er.index;}elseItems=[{type:'if',condition:em[1].trim(),thenItems:nested.items,elseItems:nestedElse,line:row.line}];}
                else if(i<rows.length&&rows[i].indent===indent&&/^else\s*:\s*$/.test(rows[i].text)){const ei=nextBodyIndent(i+1,indent),er=parseBlock(i+1,ei,false);elseItems=er.items;i=er.index;}
                items.push({type:'if',condition:m[1].trim(),thenItems:thenRes.items,elseItems,line:row.line});continue;}
            m=rawText.match(/^while\s+(.+):\s*$/);if(m){const bi=nextBodyIndent(i+1,indent),br=parseBlock(i+1,bi,false);items.push({type:'loop',loopKind:'While',condition:m[1].trim(),body:br.items,line:row.line});i=br.index;continue;}
            m=rawText.match(/^for\s+(.+):\s*$/);if(m){const bi=nextBodyIndent(i+1,indent),br=parseBlock(i+1,bi,false);items.push({type:'loop',loopKind:'For',condition:m[1].trim(),body:br.items,line:row.line});i=br.index;continue;}
            if(/^def\s+/.test(rawText)){const bi=nextBodyIndent(i+1,indent),br=parseBlock(i+1,bi,false);items.push(...br.items);i=br.index;continue;}
            const simple=flowSimpleFromLine(row.raw,'python',row.line);if(simple)items.push(simple);i++;
        }
        return{items,index:i};
    }
    const first=rows.find(row=>row.text);return parseBlock(0,first?first.indent:0,false).items;
}
function pseudoFlowAst(source,sourceType){
    const rows=String(source||'').replace(/\r/g,'').split('\n').map((raw,index)=>({raw,line:index+1,text:raw.trim()}));let index=0;
    function upperText(row){return flowStripInlineComment(row.text,sourceType).trim().toUpperCase();}
    function isStop(text,stops){return stops.some(re=>re.test(text));}
    function parseBlock(stops=[]){const items=[];while(index<rows.length){const row=rows[index],clean=flowStripInlineComment(row.text,sourceType).trim(),upper=clean.toUpperCase();if(!clean){index++;continue;}if(isStop(upper,stops))break;if(/^(BEGIN(?:\s+\w+)?|START)$/.test(upper)){index++;continue;}
            const comment=flowCommentText(row.text,sourceType);if(comment){items.push({type:'simple',shape:'flow-comment',label:comment,line:row.line,kind:'comment'});index++;continue;}
            let m=clean.match(/^IF\s+(.+?)(?:\s+THEN)?$/i);if(m){index++;const thenItems=parseBlock([/^ELSE(?:\s+IF\b.*)?$/, /^ELSEIF\b.*$/, /^ENDIF$/, /^END\s+IF$/]);let elseItems=[];if(index<rows.length){const stop=upperText(rows[index]);if(/^ELSEIF\b/.test(stop)||/^ELSE\s+IF\b/.test(stop)){const cond=rows[index].text.replace(/^ELSE\s*IF\s+/i,'').replace(/^ELSEIF\s+/i,'').replace(/\s+THEN\s*$/i,'').trim();index++;const branch=parseBlock([/^ELSE$/, /^ENDIF$/, /^END\s+IF$/]);let nestedElse=[];if(index<rows.length&&/^ELSE$/.test(upperText(rows[index]))){index++;nestedElse=parseBlock([/^ENDIF$/, /^END\s+IF$/]);}elseItems=[{type:'if',condition:cond,thenItems:branch,elseItems:nestedElse,line:row.line}];}else if(/^ELSE$/.test(stop)){index++;elseItems=parseBlock([/^ENDIF$/, /^END\s+IF$/]);}if(index<rows.length&&(/^(?:ENDIF|END\s+IF)$/.test(upperText(rows[index]))))index++;}
                items.push({type:'if',condition:m[1].trim(),thenItems,elseItems,line:row.line});continue;}
            m=clean.match(/^WHILE\s+(.+)$/i);if(m){index++;const body=parseBlock([/^ENDWHILE$/, /^END\s+WHILE$/, /^WEND$/]);if(index<rows.length)index++;items.push({type:'loop',loopKind:'While',condition:m[1].trim(),body,line:row.line});continue;}
            m=clean.match(/^FOR\s+(.+)$/i);if(m){index++;const body=parseBlock([/^ENDFOR$/, /^END\s+FOR$/, /^NEXT(?:\s+.*)?$/]);if(index<rows.length)index++;items.push({type:'loop',loopKind:'For',condition:m[1].trim(),body,line:row.line});continue;}
            if(/^REPEAT$|^DO$/.test(upper)){index++;const body=parseBlock([/^UNTIL\b.*$/, /^WHILE\b.*$/]);let condition='condition',until=true;if(index<rows.length){const end=flowStripInlineComment(rows[index].text,sourceType).trim();let um=end.match(/^UNTIL\s+(.+)$/i);if(um){condition=um[1].trim();until=true;}else if((um=end.match(/^WHILE\s+(.+)$/i))){condition=um[1].trim();until=false;}index++;}items.push({type:'do',condition,body,until,line:row.line});continue;}
            if(/^(END|STOP)$/.test(upper)){index++;continue;}
            const simple=flowSimpleFromLine(row.raw,sourceType,row.line);if(simple)items.push(simple);index++;
        }return items;}
    return parseBlock([]);
}
function splitJavaScriptFlowTokens(source){
    const tokens=[];let current='',quote='',escaped=false,paren=0,bracket=0,line=1,startLine=1;
    function push(){const text=current.trim();if(text)tokens.push({text,line:startLine});current='';startLine=line;}
    const src=String(source||'').replace(/\r/g,'');
    for(let i=0;i<src.length;i++){const c=src[i],next=src[i+1]||'';if(c==='\n')line++;
        if(quote){current+=c;if(escaped){escaped=false;continue;}if(c==='\\'){escaped=true;continue;}if(c===quote)quote='';continue;}
        if(c==='"'||c==="'"||c==='`'){quote=c;current+=c;continue;}
        if(c==='/'&&next==='/'){while(i<src.length&&src[i]!=='\n'){current+=src[i++];}i--;continue;}
        if(c==='(')paren++;if(c===')')paren=Math.max(0,paren-1);if(c==='[')bracket++;if(c===']')bracket=Math.max(0,bracket-1);
        if(paren===0&&bracket===0&&(c==='{'||c==='}')){push();tokens.push({text:c,line});startLine=line;continue;}
        if(paren===0&&bracket===0&&c===';'){current+=c;push();continue;}
        if(c==='\n'&&paren===0&&bracket===0){push();continue;}
        current+=c;
    }push();return tokens;
}
function javascriptFlowAst(source){
    const tokens=splitJavaScriptFlowTokens(source);let index=0;
    function skipOpen(){if(index<tokens.length&&tokens[index].text==='{')index++;}
    function parseBlock(stopOnBrace=true){const items=[];while(index<tokens.length){let token=tokens[index],text=token.text.trim();if(stopOnBrace&&text==='}')break;if(text==='{'){index++;continue;}if(text==='}'){index++;continue;}
            let m=text.match(/^if\s*\((.*)\)\s*$/i);if(m){index++;skipOpen();const thenItems=parseBlock(true);if(index<tokens.length&&tokens[index].text==='}')index++;let elseItems=[];if(index<tokens.length&&/^else\b/i.test(tokens[index].text)){let elseToken=tokens[index],elseText=elseToken.text.trim();index++;if(/^else\s+if\s*\(/i.test(elseText)){const cond=(elseText.match(/^else\s+if\s*\((.*)\)\s*$/i)||[])[1]||'condition';skipOpen();const branch=parseBlock(true);if(index<tokens.length&&tokens[index].text==='}')index++;elseItems=[{type:'if',condition:cond,thenItems:branch,elseItems:[],line:elseToken.line}];}else{skipOpen();elseItems=parseBlock(true);if(index<tokens.length&&tokens[index].text==='}')index++;}}items.push({type:'if',condition:m[1].trim(),thenItems,elseItems,line:token.line});continue;}
            m=text.match(/^while\s*\((.*)\)\s*$/i);if(m){index++;skipOpen();const body=parseBlock(true);if(index<tokens.length&&tokens[index].text==='}')index++;items.push({type:'loop',loopKind:'While',condition:m[1].trim(),body,line:token.line});continue;}
            m=text.match(/^for\s*\((.*)\)\s*$/i);if(m){index++;skipOpen();const body=parseBlock(true);if(index<tokens.length&&tokens[index].text==='}')index++;items.push({type:'loop',loopKind:'For',condition:m[1].trim(),body,line:token.line});continue;}
            if(/^do\s*$/i.test(text)){index++;skipOpen();const body=parseBlock(true);if(index<tokens.length&&tokens[index].text==='}')index++;let condition='condition';if(index<tokens.length){const wm=tokens[index].text.match(/^while\s*\((.*)\)\s*;?$/i);if(wm){condition=wm[1].trim();index++;}}items.push({type:'do',condition,body,until:false,line:token.line});continue;}
            if(/^function\s+/.test(text)){index++;skipOpen();const body=parseBlock(true);if(index<tokens.length&&tokens[index].text==='}')index++;items.push(...body);continue;}
            const simple=flowSimpleFromLine(text,'javascript',token.line);if(simple)items.push(simple);index++;
        }return items;}
    return parseBlock(false);
}
function buildFlowgorithmModel(ast,sourceType){
    const nodes=[],edges=[],warnings=[],counts=new Map();let edgeCounter=0;
    function uniqueKey(kind,label){const base=String(kind)+'|'+String(label||'').trim().toLowerCase(),n=(counts.get(base)||0)+1;counts.set(base,n);return base+'|'+n;}
    function addNode(shape,label,kind){const key=uniqueKey(kind||shape,label),id=stableId('flowcode',sourceType+'|'+key),node={id,key,label:String(label||kind||'Step'),shape,type:'flow',flowKind:kind||shape};nodes.push(node);return node;}
    function addEdge(from,to,label=''){if(!from||!to)return;edges.push({id:stableId('edge',sourceType+'|'+from+'>'+to+'|'+label+'|'+(++edgeCounter)),from,to,label:String(label||'')});}
    function connectIncoming(incoming,nodeId){(incoming||[]).forEach(item=>addEdge(item.id,nodeId,item.label||''));}
    function compileSequence(items,incoming){let tails=(incoming||[]).slice();for(const item of items||[]){if(!item)continue;
            if(item.type==='if'){const decision=addNode('flow-if',item.condition||'Condition','if');connectIncoming(tails,decision.id);const yes=compileSequence(item.thenItems||[],[{id:decision.id,label:'True'}]);const no=(item.elseItems&&item.elseItems.length)?compileSequence(item.elseItems,[{id:decision.id,label:'False'}]):[{id:decision.id,label:'False'}];tails=yes.concat(no);continue;}
            if(item.type==='loop'){const loop=addNode('flow-loop',(item.loopKind||'Loop')+': '+(item.condition||'condition'),String(item.loopKind||'loop').toLowerCase());connectIncoming(tails,loop.id);const bodyTails=compileSequence(item.body||[],[{id:loop.id,label:'True'}]);bodyTails.forEach(t=>{if(t.id!==loop.id)addEdge(t.id,loop.id,'');});tails=[{id:loop.id,label:'False'}];continue;}
            if(item.type==='do'){const entry=addNode('flow-loop','Do','do');connectIncoming(tails,entry.id);const bodyTails=compileSequence(item.body||[],[{id:entry.id,label:''}]);const decision=addNode('flow-if',(item.until?'Until: ':'While: ')+(item.condition||'condition'),'do-condition');connectIncoming(bodyTails.length?bodyTails:[{id:entry.id,label:''}],decision.id);if(item.until){addEdge(decision.id,entry.id,'False');tails=[{id:decision.id,label:'True'}];}else{addEdge(decision.id,entry.id,'True');tails=[{id:decision.id,label:'False'}];}continue;}
            const node=addNode(item.shape||'flow-assign',item.label||'Step',item.kind||'statement');connectIncoming(tails,node.id);tails=item.type==='terminal'?[]:[{id:node.id,label:''}];
        }return tails;}
    const start=addNode('terminator','Main','start'),end=addNode('terminator','End','end');const tails=compileSequence(ast||[],[{id:start.id,label:''}]);if(tails.length)connectIncoming(tails,end.id);else if(nodes.length===2)addEdge(start.id,end.id,'');
    return{nodes,edges,warnings,title:''};
}
function parseCodeFlowchart(source,sourceType){
    if(sourceType==='flowgorithm')return parseFlowgorithmShorthand(source);
    let ast=[];if(sourceType==='python')ast=pythonFlowAst(source);else if(sourceType==='javascript')ast=javascriptFlowAst(source);else ast=pseudoFlowAst(source,sourceType==='gatton'?'gatton':'qcaa');return buildFlowgorithmModel(ast,sourceType);
}

function flowgorithmGraphAst(source){
    const model=parseFlowgorithmShorthand(source),nodes=new Map(model.nodes.map(node=>[node.id,node])),outgoing=new Map(),incoming=new Map();
    model.nodes.forEach(node=>{outgoing.set(node.id,[]);incoming.set(node.id,[]);});
    model.edges.forEach(edge=>{if(outgoing.has(edge.from))outgoing.get(edge.from).push(edge);if(incoming.has(edge.to))incoming.get(edge.to).push(edge);});
    const start=model.nodes.find(node=>/^start\d*$/i.test(String(node.key)))||model.nodes.find(node=>node.flowKind==='terminal'&&(incoming.get(node.id)||[]).length===0)||model.nodes.find(node=>(incoming.get(node.id)||[]).length===0);
    function simple(node){
        const kind=String(node.flowKind||node.sourceKeyword||'statement').toLowerCase(),value=String(node.sourceValue||'').trim();
        if(kind==='terminal'||kind==='start'||kind==='end'||kind==='stop')return null;
        return{type:'simple',shape:node.shape,label:node.label||node.key,kind:kind==='function'?'call':kind,key:node.key,value,caption:node.sourceCaption||''};
    }
    function canReach(fromId,targetId,blocked=new Set()){
        if(fromId===targetId)return true;const queue=[fromId],seen=new Set(blocked);
        while(queue.length){const id=queue.shift();if(seen.has(id))continue;seen.add(id);for(const edge of outgoing.get(id)||[]){if(edge.to===targetId)return true;if(!seen.has(edge.to))queue.push(edge.to);}}
        return false;
    }
    function distanceMap(fromId,blocked=new Set()){
        const dist=new Map([[fromId,0]]),queue=[fromId];
        while(queue.length){const id=queue.shift(),d=dist.get(id);for(const edge of outgoing.get(id)||[]){if(blocked.has(edge.to)||dist.has(edge.to))continue;dist.set(edge.to,d+1);queue.push(edge.to);}}
        return dist;
    }
    function commonMerge(targets,avoidId){
        if(targets.length<2)return null;const maps=targets.map(id=>distanceMap(id,new Set([avoidId]))),candidates=[...maps[0].keys()].filter(id=>maps.every(map=>map.has(id)));
        candidates.sort((a,b)=>maps.reduce((n,m)=>n+m.get(a),0)-maps.reduce((n,m)=>n+m.get(b),0));
        return candidates[0]||null;
    }
    function walk(id,stopIds=new Set(),seen=new Set()){
        const items=[];let current=id,guard=0;
        while(current&&!stopIds.has(current)&&guard++<500){
            if(seen.has(current))break;seen.add(current);
            const node=nodes.get(current);if(!node)break;
            const kind=String(node.flowKind||node.sourceKeyword||'').toLowerCase(),outs=(outgoing.get(current)||[]).slice();
            if(kind==='stop'||kind==='end'||(kind==='terminal'&&/^end/i.test(String(node.key))))break;
            if(kind==='if'){
                const targets=outs.map(e=>e.to),merge=commonMerge(targets,current);
                // Branch semantics are positional: the first outgoing link is TRUE,
                // the second is FALSE. Captions on edges are descriptive only.
                let yes=outs[0]||null,no=outs[1]||null;
                if(!yes)yes=outs[0]||null;if(!no)no=outs.find(e=>!yes||e.id!==yes.id)||null;
                const branchStop=new Set(stopIds);if(merge)branchStop.add(merge);
                const thenItems=yes?walk(yes.to,branchStop,new Set(seen)).items:[],elseItems=no?walk(no.to,branchStop,new Set(seen)).items:[];
                items.push({type:'if',condition:node.sourceValue||node.label||'condition',thenItems,elseItems,line:node.sourceDeclarationLineIndex||0});
                current=merge;continue;
            }
            if(kind==='while'||kind==='for'){
                // WHILE / FOR: first outgoing connector is the TRUE/body branch,
                // second is FALSE/exit, matching IF ordering.
                const bodyEdge=outs[0]||null,exitEdge=outs[1]||null;
                const body=bodyEdge?walk(bodyEdge.to,new Set([...stopIds,current]),new Set(seen)).items:[];
                items.push({type:'loop',loopKind:kind==='for'?'For':'While',condition:node.sourceValue||node.label||'condition',body,line:node.sourceDeclarationLineIndex||0});
                current=exitEdge?exitEdge.to:null;continue;
            }
            if(kind==='do'){
                const bodyEdge=outs[0]||null,body=bodyEdge?walk(bodyEdge.to,new Set([...stopIds,current]),new Set(seen)).items:[];
                items.push({type:'do',condition:node.sourceValue||node.label||'condition',body,until:false,line:node.sourceDeclarationLineIndex||0});break;
            }
            const item=simple(node);if(item)items.push(item);
            current=outs.length?outs[0].to:null;
        }
        return{items,next:current};
    }
    if(!start)return[];
    const first=(outgoing.get(start.id)||[])[0];return first?walk(first.to,new Set(),new Set([start.id])).items:[];
}
function flowAstForSource(sourceType,source){
    if(sourceType==='flowgorithm')return flowgorithmGraphAst(source);
    if(sourceType==='python')return pythonFlowAst(source);
    if(sourceType==='javascript')return javascriptFlowAst(source);
    return pseudoFlowAst(source,sourceType==='gatton'?'gatton':'qcaa');
}
function cleanPseudoVerb(text){return String(text||'').replace(/^(INPUT|READ|GET|ACCEPT|OUTPUT|WRITE|PRINT|DISPLAY|PUT|DECLARE|INITIALISE|INITIALIZE|CONSTANT|VAR|CALCULATE|COMPUTE|SET|ASSIGN|CALL)\s+/i,'').trim();}
function inputTarget(item){
    if(item.key&&item.value)return String(item.value).split(/[,\s]/)[0]||item.key;
    let m=String(item.label||'').match(/^Input\s+([A-Za-z_]\w*)$/i);if(m)return m[1];
    m=cleanPseudoVerb(item.label).match(/^([A-Za-z_]\w*)/);return m?m[1]:'value';
}
function outputExpression(item){
    if(item.value)return item.value;
    let text=String(item.label||'').trim(),m=text.match(/^(?:print|console\.(?:log|info|warn)|alert)\s*\((.*)\)\s*;?$/i);if(m)return m[1];
    return cleanPseudoVerb(text)||'value';
}
function assignmentExpression(item){
    if(item.key&&item.kind==='declare')return item.key+(String(item.value||'').replace(/^=\s*/,'').trim()?' = '+String(item.value).replace(/^=\s*/,'').trim():' = null');
    if(item.key&&item.kind==='assign'){const a=flowAssignParts(item.value);return a.variable?a.variable+' = '+(a.expression||'null'):'value = null';}
    if(item.key&&item.value)return item.value||item.key+' = 0';
    let text=String(item.label||'').trim().replace(/;$/,'');return cleanPseudoVerb(text)||'value = 0';
}
function callExpression(item){let text=item.value||cleanPseudoVerb(item.label||'');text=String(text||'procedure()').trim().replace(/;$/,'');return text||'procedure()';}
function forSpecParts(condition){
    const text=String(condition||'').trim(),parts={iterator:'i',start:'0',end:'9',increment:'1'};
    text.split(';').forEach(part=>{const m=part.match(/^\s*(iterator|start|end|increment)\s*=\s*(.+)\s*$/i);if(m)parts[m[1].toLowerCase()]=m[2].trim();});return parts;
}
function astToPython(ast){
    const lines=[];
    function emit(items,indent=''){
        (items||[]).forEach(item=>{
            if(item.type==='if'){lines.push(indent+'if '+(item.condition||'condition')+':');if(item.thenItems&&item.thenItems.length)emit(item.thenItems,indent+'    ');else lines.push(indent+'    pass');if(item.elseItems&&item.elseItems.length){lines.push(indent+'else:');emit(item.elseItems,indent+'    ');}return;}
            if(item.type==='loop'){if(String(item.loopKind).toLowerCase()==='for'){const p=forSpecParts(item.condition),end=`(${p.end}) + (1 if (${p.increment}) > 0 else -1)`;lines.push(indent+`for ${p.iterator} in range(${p.start}, ${end}, ${p.increment}):`);}else lines.push(indent+'while '+(item.condition||'condition')+':');if(item.body&&item.body.length)emit(item.body,indent+'    ');else lines.push(indent+'    pass');return;}
            if(item.type==='do'){lines.push(indent+'while True:');if(item.body&&item.body.length)emit(item.body,indent+'    ');const condition=item.condition||'condition';lines.push(indent+'    if '+(item.until?condition:'not ('+condition+')')+':');lines.push(indent+'        break');return;}
            const kind=String(item.kind||'statement').toLowerCase();
            if(kind==='comment')lines.push(indent+'# '+String(item.label||''));else if(kind==='input')lines.push(indent+inputTarget(item)+' = input('+(item.caption?JSON.stringify(item.caption):'')+')');else if(kind==='output')lines.push(indent+'print('+outputExpression(item)+')');else if(kind==='declare'&&!String(item.value||'').trim())lines.push(indent+item.key+' = None');else if(kind==='declare'||kind==='assign'||kind==='statement')lines.push(indent+assignmentExpression(item));else if(kind==='call'||kind==='function')lines.push(indent+callExpression(item));else if(kind==='return')lines.push(indent+String(item.label||'return').replace(/^Return\b/i,'return'));else if(item.label)lines.push(indent+String(item.label));
        });
    }emit(ast);return lines.join('\n').replace(/\n{3,}/g,'\n\n').trim()+'\n';
}
function astToPseudo(ast,flavour){
    const gatton=flavour==='gatton',lines=[gatton?'START':'BEGIN'];
    function emit(items,indent='    '){
        (items||[]).forEach(item=>{
            if(item.type==='if'){lines.push(indent+'IF '+(item.condition||'condition')+(gatton?'':' THEN'));emit(item.thenItems||[],indent+'    ');if(item.elseItems&&item.elseItems.length){lines.push(indent+'ELSE');emit(item.elseItems,indent+'    ');}lines.push(indent+'ENDIF');return;}
            if(item.type==='loop'){const isFor=String(item.loopKind).toLowerCase()==='for';lines.push(indent+(isFor?'FOR ':'WHILE ')+(item.condition||'condition'));emit(item.body||[],indent+'    ');lines.push(indent+(isFor?'ENDFOR':'ENDWHILE'));return;}
            if(item.type==='do'){lines.push(indent+'REPEAT');emit(item.body||[],indent+'    ');lines.push(indent+'UNTIL '+(item.condition||'condition'));return;}
            const kind=String(item.kind||'statement').toLowerCase();
            if(kind==='comment')lines.push(indent+'// '+String(item.label||''));else if(kind==='input')lines.push(indent+(gatton?'GET ':'INPUT ')+inputTarget(item));else if(kind==='output')lines.push(indent+(gatton?'DISPLAY ':'OUTPUT ')+outputExpression(item));else if(kind==='declare')lines.push(indent+'DECLARE '+assignmentExpression(item));else if(kind==='assign'||kind==='statement')lines.push(indent+'SET '+assignmentExpression(item));else if(kind==='call'||kind==='function')lines.push(indent+'CALL '+callExpression(item));else if(kind==='return')lines.push(indent+String(item.label||'RETURN').replace(/^Return\b/i,'RETURN'));
        });
    }emit(ast);lines.push(gatton?'STOP':'END');return lines.join('\n')+'\n';
}
function astToJavaScript(ast){
    const lines=[];
    function emit(items,indent=''){
        (items||[]).forEach(item=>{
            if(item.type==='if'){lines.push(indent+'if ('+(item.condition||'condition')+') {');emit(item.thenItems||[],indent+'    ');if(item.elseItems&&item.elseItems.length){lines.push(indent+'} else {');emit(item.elseItems,indent+'    ');}lines.push(indent+'}');return;}
            if(item.type==='loop'){if(String(item.loopKind).toLowerCase()==='for'){const p=forSpecParts(item.condition);lines.push(indent+`for (let ${p.iterator} = ${p.start}; ${p.iterator} <= ${p.end}; ${p.iterator} += ${p.increment}) {`);}else lines.push(indent+'while ('+(item.condition||'condition')+') {');emit(item.body||[],indent+'    ');lines.push(indent+'}');return;}
            if(item.type==='do'){lines.push(indent+'do {');emit(item.body||[],indent+'    ');lines.push(indent+'} while ('+(item.condition||'condition')+');');return;}
            const kind=String(item.kind||'statement').toLowerCase();
            if(kind==='comment')lines.push(indent+'// '+String(item.label||''));else if(kind==='input')lines.push(indent+'let '+inputTarget(item)+' = prompt('+(item.caption?JSON.stringify(item.caption):'""')+');');else if(kind==='output')lines.push(indent+'console.log('+outputExpression(item)+');');else if(kind==='declare'){let expr=assignmentExpression(item);if(!/^(?:const|let|var)\b/.test(expr))expr='let '+expr;lines.push(indent+expr.replace(/;$/,'')+';');}else if(kind==='assign'||kind==='statement')lines.push(indent+assignmentExpression(item).replace(/;$/,'')+';');else if(kind==='call'||kind==='function')lines.push(indent+callExpression(item).replace(/;$/,'')+';');else if(kind==='return')lines.push(indent+String(item.label||'return').replace(/^Return\b/i,'return').replace(/;$/,'')+';');
        });
    }emit(ast);return lines.join('\n').trim()+'\n';
}
function astToFlowgorithm(ast,title='My Flow Diagram'){
    const model=buildFlowgorithmModel(ast,'conversion'),nodes=model.nodes,edges=model.edges,ids=new Map(),counts={};
    function keyFor(node){if(node.flowKind==='start')return'Start';if(node.flowKind==='end')return'End';const map={input:'Input',output:'Output',declare:'Declare',assign:'Assign',if:'If',call:'Function',while:'While',for:'For',do:'Do',statement:'Statement',comment:'Comment',return:'Return'};const base=map[String(node.flowKind||'statement').toLowerCase()]||'Step';counts[base]=(counts[base]||0)+1;return base+counts[base];}
    nodes.forEach(node=>ids.set(node.id,keyFor(node)));
    const declarations=[];
    nodes.forEach(node=>{
        const key=ids.get(node.id),kind=String(node.flowKind||'statement').toLowerCase(),label=String(node.label||'').trim();
        if(kind==='start'){declarations.push('terminal '+key+' : "Start"');return;}if(kind==='end'){declarations.push('stop '+key+' : "End"');return;}
        if(kind==='input')declarations.push('input '+key+' '+(inputTarget({label})||'value'));else if(kind==='output')declarations.push('output '+key+' '+outputExpression({label}));else if(kind==='declare')declarations.push('declare '+key+' = '+assignmentExpression({label}).replace(/^[A-Za-z_]\w*\s*=\s*/,''));else if(kind==='if')declarations.push('if '+key+' '+label);else if(kind==='while')declarations.push('while '+key+' '+label.replace(/^While:\s*/i,''));else if(kind==='for')declarations.push('for '+key+' '+label.replace(/^For:\s*/i,''));else if(kind==='do')declarations.push('do '+key+' '+label);else if(kind==='call'||kind==='statement')declarations.push('function '+key+' '+callExpression({label}));else declarations.push('assign '+key+' '+assignmentExpression({label}));
    });
    const connections=edges.map(edge=>ids.get(edge.from)+' -> '+ids.get(edge.to)+(edge.label?' : '+edge.label:''));
    return 'title: '+title+'\n\n'+declarations.join('\n')+'\n\n'+connections.join('\n')+'\n';
}
function convertFlowchartSource(source,fromType,toType){
    if(fromType===toType)return String(source||'');const ast=flowAstForSource(fromType,source);
    if(toType==='python')return astToPython(ast);if(toType==='qcaa')return astToPseudo(ast,'qcaa');if(toType==='gatton')return astToPseudo(ast,'gatton');if(toType==='javascript')return astToJavaScript(ast);if(toType==='flowgorithm')return astToFlowgorithm(ast,project&&project.title?project.title:'My Flow Diagram');return String(source||'');
}
function dfdToCode(target){
    const model=parseDfd(sourceEditor.value),title=project.title||'DFD';
    if(target==='python'){
        const entities=model.nodes.filter(n=>n.type==='entity'),processes=model.nodes.filter(n=>n.type==='process'),stores=model.nodes.filter(n=>n.type==='store');
        return '# '+title+'\n# Generated from Charter DFD shorthand.\n\nentities = '+JSON.stringify(Object.fromEntries(entities.map(n=>[n.key,n.label])),null,4)+'\n\nprocesses = '+JSON.stringify(Object.fromEntries(processes.map(n=>[n.key,n.label])),null,4)+'\n\ndata_stores = '+JSON.stringify(Object.fromEntries(stores.map(n=>[n.key,{name:n.label,type:n.storeKind||'D'}])),null,4)+'\n\ndata_flows = '+JSON.stringify(model.edges.map(e=>{const a=model.nodes.find(n=>n.id===e.from),b=model.nodes.find(n=>n.id===e.to);return{from:a?a.key:e.from,to:b?b.key:e.to,label:e.label||''};}),null,4)+'\n';
    }
    if(target==='javascript')return '// '+title+'\n// Generated from Charter DFD shorthand.\n\nconst dfd = '+JSON.stringify({nodes:model.nodes.map(n=>({name:n.key,caption:n.label,type:n.type,storeType:n.storeKind||undefined})),flows:model.edges.map(e=>{const a=model.nodes.find(n=>n.id===e.from),b=model.nodes.find(n=>n.id===e.to);return{from:a?a.key:e.from,to:b?b.key:e.to,label:e.label||''};})},null,4)+';\n';
    const start=target==='gatton'?'START':'BEGIN',end=target==='gatton'?'STOP':'END',lines=[start,'    // '+title,'    // Generated from Charter DFD shorthand.'];model.nodes.forEach(n=>lines.push('    // '+n.type.toUpperCase()+' '+n.key+': '+n.label));model.edges.forEach(e=>{const a=model.nodes.find(n=>n.id===e.from),b=model.nodes.find(n=>n.id===e.to);lines.push('    // FLOW '+(a?a.key:e.from)+' -> '+(b?b.key:e.to)+(e.label?' : '+e.label:''));});lines.push(end);return lines.join('\n')+'\n';
}
function codeForExport(target){if(project.diagramType==='flowchart')return convertFlowchartSource(sourceEditor.value,project.sourceType,target);if(project.diagramType==='dfd')return dfdToCode(target);return '';}
function exportCode(target){
    const labels={python:'Python',qcaa:'QCAA Pseudocode',gatton:'Gatton Pseudocode',javascript:'JavaScript'},extensions={python:'py',qcaa:'txt',gatton:'txt',javascript:'js'},code=codeForExport(target);if(!code){toast('Export to '+(labels[target]||target)+' is available for flowcharts and DFDs.','error');return;}downloadBlob(new Blob([code],{type:'text/plain;charset=utf-8'}),safeFilename(project.title)+'.'+extensions[target]);toast('Exported to '+labels[target]+'.','success');
}
function switchFlowchartSourceType(targetType){
    const fromType=project.sourceType;if(project.diagramType!=='flowchart'||fromType===targetType){project.sourceType=targetType;return;}const original=sourceEditor.value;
    try{pushUndoSnapshot();const converted=convertFlowchartSource(original,fromType,targetType);project.sourceType=targetType;sourceEditor.value=converted;project.source=converted;updateLineNumbers();project.layout.positions={};project.layout.positionsByKey={};project.layout.links={};clearSelection();renderFromSource({forceLayout:true,fit:true,markDirty:false});captureCurrentLayout(false);renderControlPalette();setDirty(true);persistLayoutSoon();toast('Converted '+sourceTypeLabel(fromType)+' to '+sourceTypeLabel(targetType)+'.','success');}
    catch(error){project.sourceType=fromType;el('sourceType').value=fromType;sourceEditor.value=original;project.source=original;updateLineNumbers();renderFromSource({fit:false,markDirty:false});toast('The source could not be converted: '+error.message,'error');}
}
function sourceTypeLabel(type){return({flowgorithm:'Flowgorithm',python:'Python',qcaa:'QCAA Pseudocode',gatton:'Gatton Pseudocode',javascript:'JavaScript'})[type]||type;}

function parseDfd(source){
    const nodes=new Map(),edges=[],warnings=[];let title='',sequence=0;
    function storeKindFor(key){const first=String(key||'').trim().charAt(0).toUpperCase();return['D','M','T'].includes(first)?first:'D';}
    function ensure(key,label,type){
        key=String(key).trim();
        if(!nodes.has(key)){
            const shape=type==='entity'?'entity':type==='store'?'store':'dfd-process';
            nodes.set(key,{id:stableId('dfd',key),key,label:label||key,shape,type,sourceOrder:sequence++,storeKind:type==='store'?storeKindFor(key):null,processNumber:null});
        }else{
            const node=nodes.get(key);if(label&&label!==key)node.label=label;if(type&&node.type==='process'&&type!=='process'){node.type=type;node.shape=type==='entity'?'entity':'store';node.storeKind=type==='store'?storeKindFor(key):null;}
        }
        return nodes.get(key);
    }
    analyseDfdSource(source).forEach(record=>{
        if(record.isText)return;const line=record.code;if(!line)return;
        if(/^title(?:\s*:|\s+)/i.test(line)){title=line.replace(/^title(?:\s*:|\s+)/i,'').trim();return;}
        let match=line.match(/^(store|datastore)\s+([A-Za-z_][\w.-]*)\s+(digital|manual|temporary|D|M|T)(?:\s+["'](.+?)["']|\s+(.+))?$/i);
        if(match){const mode=match[3].toLowerCase(),node=ensure(match[2],match[4]||match[5]||match[2],'store');node.storeKind=mode==='manual'||mode==='m'?'M':mode==='temporary'||mode==='t'?'T':'D';return;}
        match=line.match(/^(entity|external|process|store|datastore)\s+([A-Za-z_][\w.-]*)(?:\s+["'](.+?)["']|\s+(.+))?$/i);
        if(match){
            const rawType=match[1].toLowerCase(),type=rawType==='entity'||rawType==='external'?'entity':rawType==='store'||rawType==='datastore'?'store':'process';
            ensure(match[2],match[3]||match[4]||match[2],type);return;
        }
        const arrow=parseArrowLine(line);
        if(arrow){
            const left=parseNodeToken(arrow.from),right=parseNodeToken(arrow.to),from=ensure(left.key,left.label,'process'),to=ensure(right.key,right.label,'process');
            edges.push({id:stableId('flow',from.key+'>'+to.key+'>'+arrow.label),from:from.id,to:to.id,label:arrow.label});return;
        }
        warnings.push('Line '+(record.lineIndex+1)+' could not be read.');
    });
    const connected=new Set();edges.forEach(edge=>{connected.add(edge.from);connected.add(edge.to);});
    let processNumber=1;
    [...nodes.values()].sort((a,b)=>a.sourceOrder-b.sourceOrder).forEach(node=>{
        if(node.type==='process'&&connected.has(node.id))node.processNumber=processNumber++;
        else if(node.type==='process'&&!connected.has(node.id))warnings.push('Process “'+node.label+'” will be numbered after it is connected to another DFD object.');
    });
    return{nodes:[...nodes.values()],edges,warnings,title,level:1};
}

function splitTopLevel(text,delimiter=','){
    const result=[];let current='',depth=0,quote='';for(let i=0;i<text.length;i++){const c=text[i];if(quote){current+=c;if(c===quote&&text[i-1]!=='\\')quote='';continue;}if(c==='"'||c==="'"||c==='`'){quote=c;current+=c;continue;}if(c==='('||c==='['||c==='{')depth++;if(c===')'||c===']'||c==='}')depth=Math.max(0,depth-1);if(c===delimiter&&depth===0){result.push(current);current='';}else current+=c;}if(current.trim())result.push(current);return result;
}
function cleanSqlIdentifier(value){return String(value||'').trim().replace(/^[`"\[]|[`"\]]$/g,'').split('.').pop();}
function parseSql(source){
    const nodes=[],edges=[],warnings=[];let match;const tableMap=new Map();const expression=/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([`"\[]?[A-Za-z_][\w.]*[`"\]]?)\s*\(([\s\S]*?)\)\s*;/gi;
    while((match=expression.exec(source))){const table=cleanSqlIdentifier(match[1]),fields=[],tablePks=new Set(),pendingFks=[];splitTopLevel(match[2]).forEach(part=>{const item=part.trim();if(!item)return;let m;if((m=item.match(/^(?:CONSTRAINT\s+\S+\s+)?PRIMARY\s+KEY\s*\(([^)]+)\)/i))){splitTopLevel(m[1]).forEach(x=>tablePks.add(cleanSqlIdentifier(x)));return;}if((m=item.match(/^(?:CONSTRAINT\s+\S+\s+)?FOREIGN\s+KEY\s*\(([^)]+)\)\s+REFERENCES\s+([`"\[]?[A-Za-z_][\w.]*[`"\]]?)\s*\(([^)]+)\)/i))){const locals=splitTopLevel(m[1]),targets=splitTopLevel(m[3]);locals.forEach((local,i)=>pendingFks.push({field:cleanSqlIdentifier(local),target:cleanSqlIdentifier(m[2]),targetField:cleanSqlIdentifier(targets[i]||targets[0])}));return;}m=item.match(/^([`"\[]?[A-Za-z_][\w$]*[`"\]]?)\s+(.+)$/);if(!m)return;const name=cleanSqlIdentifier(m[1]),rest=m[2],constraintAt=rest.search(/\s+(?:PRIMARY|NOT|NULL|UNIQUE|REFERENCES|DEFAULT|CHECK|COLLATE|CONSTRAINT|GENERATED)\b/i),type=(constraintAt>=0?rest.slice(0,constraintAt):rest).trim()||'unknown';const ref=rest.match(/REFERENCES\s+([`"\[]?[A-Za-z_][\w.]*[`"\]]?)\s*\(([^)]+)\)/i);fields.push({name,type,pk:/\bPRIMARY\s+KEY\b/i.test(rest),nullable:!/\bNOT\s+NULL\b/i.test(rest),unique:/\bUNIQUE\b/i.test(rest),fk:!!ref});if(ref)pendingFks.push({field:name,target:cleanSqlIdentifier(ref[1]),targetField:cleanSqlIdentifier(ref[2])});});fields.forEach(field=>{if(tablePks.has(field.name))field.pk=true;if(pendingFks.some(fk=>fk.field===field.name))field.fk=true;});const node={id:stableId('table',table),key:table,label:table,shape:'table',type:'table',fields};nodes.push(node);tableMap.set(table,node);pendingFks.forEach(fk=>{const localField=fields.find(field=>field.name===fk.field),fromCard=localField&&localField.unique?'0..1':'0..*',toCard=localField&&localField.nullable?'0..1':'1';edges.push({id:stableId('relation',table+'.'+fk.field+'>'+fk.target+'.'+fk.targetField),from:node.id,to:stableId('table',fk.target),label:fk.field+' → '+fk.targetField,cardinality:fromCard+'--'+toCard});});}
    if(!nodes.length)warnings.push('No CREATE TABLE statements were found. End each statement with a semicolon.');
    return{nodes,edges,warnings,title:''};
}

function parsePrisma(source){
    const nodes=[],edges=[],warnings=[],modelMap=new Map();let match;const expression=/model\s+([A-Za-z_][\w]*)\s*\{([\s\S]*?)\}/g;
    while((match=expression.exec(source))){const name=match[1],fields=[];match[2].split(/\r?\n/).forEach(raw=>{const line=stripLineComment(raw).trim();if(!line||line.startsWith('@@'))return;const m=line.match(/^([A-Za-z_][\w]*)\s+([^\s]+)(.*)$/);if(!m)return;const fieldName=m[1],rawType=m[2],attrs=m[3]||'';fields.push({name:fieldName,type:rawType,pk:/\@id\b/.test(attrs),unique:/\@unique\b/.test(attrs),nullable:/\?$/.test(rawType),fk:/\@relation\b/.test(attrs),attrs});});const node={id:stableId('table',name),key:name,label:name,shape:'table',type:'table',fields};nodes.push(node);modelMap.set(name,node);}
    const scalar=new Set(['String','Int','BigInt','Float','Decimal','Boolean','DateTime','Json','Bytes','Unsupported']);
    nodes.forEach(node=>node.fields.forEach(field=>{const target=field.type.replace(/[?\[\]]/g,'');if(modelMap.has(target)&&!scalar.has(target)){const relation=field.attrs.match(/\@relation\s*\(\s*fields\s*:\s*\[([^\]]+)\]\s*,\s*references\s*:\s*\[([^\]]+)\]/);const label=relation?relation[1].trim()+' → '+relation[2].trim():field.name;const targetId=modelMap.get(target).id,pair=e=>(e.from===node.id&&e.to===targetId)||(e.from===targetId&&e.to===node.id),duplicate=edges.some(pair);if(relation){for(let i=edges.length-1;i>=0;i--){if(pair(edges[i])&&!edges[i].explicit)edges.splice(i,1);}edges.push({id:stableId('relation',node.key+'.'+field.name+'>'+target),from:node.id,to:targetId,label,cardinality:/\[\]$/.test(field.type)?'1--0..*':('0..*--'+(/\?$/.test(field.type)?'0..1':'1')),explicit:true});}else if(!duplicate){edges.push({id:stableId('relation',node.key+'.'+field.name+'>'+target),from:node.id,to:targetId,label,cardinality:/\[\]$/.test(field.type)?'1--0..*':('0..*--'+(/\?$/.test(field.type)?'0..1':'1')),explicit:false});}}}));
    if(!nodes.length)warnings.push('No Prisma model blocks were found.');
    return{nodes,edges,warnings,title:''};
}

function parseQuickDbd(source){
    const nodes=[],edges=[],warnings=[],tables=new Map();const lines=source.replace(/\r/g,'').split('\n');let index=0;
    while(index<lines.length){let line=stripLineComment(lines[index]).trim();if(!line){index++;continue;}let tableName='';if(/^Table\s+/i.test(line)){tableName=line.replace(/^Table\s+/i,'').replace(/\s*\{$/,'').trim();index++;}else if(index+1<lines.length&&/^\s*-+\s*$/.test(lines[index+1])){tableName=line;index+=2;}else{index++;continue;}const fields=[];while(index<lines.length){line=stripLineComment(lines[index]).trim();if(!line){index++;if(fields.length)break;continue;}if(line==='}'){index++;break;}if(index+1<lines.length&&/^\s*-+\s*$/.test(lines[index+1]))break;const m=line.match(/^([A-Za-z_][\w]*)\s+([^\s]+)(.*)$/);if(!m){index++;continue;}const name=m[1],type=m[2],attrs=m[3]||'';fields.push({name,type,pk:/\bPK\b/i.test(attrs),unique:/\bUNIQUE\b/i.test(attrs),nullable:/\bNULL\b/i.test(attrs)&&!/NOT\s+NULL/i.test(attrs),fk:/\bFK\b/i.test(attrs),attrs});const ref=attrs.match(/(?:FK\s*)?(?:>-|->|ref\s*:)\s*([A-Za-z_][\w]*)\.([A-Za-z_][\w]*)/i);if(ref){const unique=/\bUNIQUE\b/i.test(attrs),nullable=/\bNULL\b/i.test(attrs)&&!/NOT\s+NULL/i.test(attrs);edges.push({id:stableId('relation',tableName+'.'+name+'>'+ref[1]+'.'+ref[2]),from:stableId('table',tableName),to:stableId('table',ref[1]),label:name+' → '+ref[2],cardinality:(unique?'0..1':'0..*')+'--'+(nullable?'0..1':'1')});}index++;}const node={id:stableId('table',tableName),key:tableName,label:tableName,shape:'table',type:'table',fields};nodes.push(node);tables.set(tableName,node);}
    if(!nodes.length)warnings.push('No QuickDBD-style tables were found. Put a table name above a line containing - .');
    return{nodes,edges,warnings,title:''};
}

function parseMapping(source){
    const nodes=[],edges=[],warnings=[],blocks=[];let match;const expression=/(?:entity\s+)?([A-Za-z_][\w]*)\s*\{([\s\S]*?)\}/gi;
    while((match=expression.exec(source))){const name=match[1],fields=[];match[2].split(/\r?\n/).forEach(raw=>{const line=stripLineComment(raw).trim().replace(/,$/,'');if(!line)return;const m=line.match(/^([A-Za-z_][\w]*)\s*:\s*([^\s]+)(.*)$/);if(!m)return;const attrs=m[3]||'';fields.push({name:m[1],type:m[2],pk:/\bPK\b/i.test(attrs),unique:/\bUNIQUE\b/i.test(attrs),nullable:/\bNULL\b/i.test(attrs)&&!/NOT\s+NULL/i.test(attrs),fk:/\bFK\b/i.test(attrs)});});nodes.push({id:stableId('table',name),key:name,label:name,shape:'table',type:'table',fields});blocks.push({start:match.index,end:expression.lastIndex});}
    const remainder=source.split(/\r?\n/);remainder.forEach(raw=>{const line=stripLineComment(raw).trim();let m=line.match(/^([A-Za-z_][\w]*)\.([A-Za-z_][\w]*)\s+([^\s]+)\s+([A-Za-z_][\w]*)\.([A-Za-z_][\w]*)$/);if(m)edges.push({id:stableId('relation',line),from:stableId('table',m[1]),to:stableId('table',m[4]),label:m[2]+' '+m[3]+' '+m[5],cardinality:m[3]});else if((m=line.match(/^([A-Za-z_][\w]*)\s+([^\s]+)\s+([A-Za-z_][\w]*)$/)))edges.push({id:stableId('relation',line),from:stableId('table',m[1]),to:stableId('table',m[3]),label:m[2],cardinality:m[2]});});
    if(!nodes.length)warnings.push('No entity blocks were found.');
    return{nodes,edges,warnings,title:''};
}

function combineBidirectionalEdges(model){
    if(project.diagramType==='erd'||!model||!Array.isArray(model.edges))return model;
    const edges=model.edges,used=new Set(),combined=[];
    for(let i=0;i<edges.length;i++){
        if(used.has(i))continue;
        const edge=edges[i];let reverseIndex=-1;
        for(let j=i+1;j<edges.length;j++){
            if(used.has(j))continue;
            const candidate=edges[j];
            if(candidate.from===edge.to&&candidate.to===edge.from){reverseIndex=j;break;}
        }
        if(reverseIndex>=0){
            const reverse=edges[reverseIndex];used.add(reverseIndex);
            const pairKey=[edge.id,reverse.id].sort().join('<->');
            combined.push({
                id:stableId('biedge',pairKey),from:edge.from,to:edge.to,label:edge.label||'',label2:reverse.label||'',
                bidirectional:true,originalIds:[edge.id,reverse.id]
            });
        }else combined.push(edge);
    }
    model.edges=combined;return model;
}
function parseProject(){
    const source=sourceEditor.value;let model;
    if(project.diagramType==='flowchart')model=parseCodeFlowchart(source,project.sourceType);else if(project.diagramType==='dfd')model=parseDfd(source);else if(project.sourceType==='prisma')model=parsePrisma(source);else if(project.sourceType==='quickdbd')model=parseQuickDbd(source);else if(project.sourceType==='mapping')model=parseMapping(source);else model=parseSql(source);
    const nodeIds=new Set(model.nodes.map(n=>n.id));model.edges=model.edges.filter(edge=>nodeIds.has(edge.from)&&nodeIds.has(edge.to));return combineBidirectionalEdges(model);
}

function wrapText(text,maxChars=24){const words=String(text||'').split(/\s+/),lines=[];let current='';words.forEach(word=>{if(!current)current=word;else if((current+' '+word).length<=maxChars)current+=' '+word;else{lines.push(current);current=word;}});if(current)lines.push(current);return lines.slice(0,4);}
function measureNode(node){
    if(node.shape==='table'){const longest=Math.max(node.label.length,...node.fields.map(f=>(f.name+' '+f.type).length));return{width:Math.max(230,Math.min(360,longest*7.2+66)),height:42+Math.max(1,node.fields.length)*25};}
    if(node.shape==='dfd-process'){const lines=wrapText(node.label,22);return{width:Math.max(150,Math.min(245,Math.max(...lines.map(x=>x.length))*8+44)),height:Math.max(86,lines.length*19+58)};}
    if(node.shape==='entity'){const lines=wrapText(node.label,22);return{width:Math.max(150,Math.min(235,Math.max(...lines.map(x=>x.length))*8+40)),height:Math.max(62,lines.length*19+28)};}
    if(node.shape==='store'){const lines=wrapText(node.label,22);return{width:Math.max(175,Math.min(255,Math.max(...lines.map(x=>x.length))*8+78)),height:Math.max(58,lines.length*19+26)};}
    if(['flow-if','flow-loop','flow-for'].includes(node.shape)){const lines=wrapText(node.label,18);return{width:Math.max(150,Math.min(250,Math.max(...lines.map(x=>x.length))*8+48)),height:Math.max(node.shape==='flow-if'?104:82,lines.length*19+34)};}
    if(['flow-input','flow-output'].includes(node.shape)){const lines=wrapText(node.label,24);return{width:Math.max(150,Math.min(270,Math.max(...lines.map(x=>x.length))*8+54)),height:Math.max(64,lines.length*19+30)};}
    if(['flow-declare','flow-call','flow-comment','flow-assign'].includes(node.shape)){const lines=wrapText(node.label,26);return{width:Math.max(150,Math.min(285,Math.max(...lines.map(x=>x.length))*8+50)),height:Math.max(64,lines.length*19+30)};}
    if(node.shape==='flow-stop'){const lines=wrapText(node.label,12);const side=Math.max(92,Math.min(180,Math.max(...lines.map(x=>x.length))*12+24));return{width:side,height:side};}const lines=wrapText(node.label,node.shape==='decision'?18:24);const width=Math.max(node.shape==='terminator'?120:135,Math.min(250,Math.max(...lines.map(x=>x.length))*8+42));const height=Math.max(node.shape==='decision'?100:64,lines.length*19+30);return{width,height};
}

function fixedVerticalPositions(model){
    // Deterministic source-order layout. The main path is centred; conditional/loop
    // branch targets are displayed on opposing sides, and never manually draggable.
    const nodes=model.nodes||[],edges=model.edges||[],positions=new Map();
    if(!nodes.length)return positions;
    const byId=new Map(nodes.map(n=>[n.id,n]));
    const outgoing=new Map(nodes.map(n=>[n.id,[]]));
    const incoming=new Map(nodes.map(n=>[n.id,[]]));
    edges.forEach(e=>{if(outgoing.has(e.from)&&incoming.has(e.to)){outgoing.get(e.from).push(e);incoming.get(e.to).push(e);}});
    const order=new Map(nodes.map((n,i)=>[n.id,i]));
    const isBranch=n=>['if','while','for'].includes(String(n.flowKind||'').toLowerCase())||['flow-if','flow-loop','flow-for'].includes(n.shape);
    const roots=nodes.filter(n=>!incoming.get(n.id).length);
    const first=roots.find(n=>/^(start|terminal)$/i.test(n.key||''))||roots[0]||nodes[0];
    const queued=new Set(),rows=[];
    const walk=(node,column,depth)=>{
        if(!node||queued.has(node.id))return;
        queued.add(node.id);rows.push({node,column,depth});
        const next=outgoing.get(node.id)||[];
        if(isBranch(node)&&next.length>=2){
            // Connections are source ordered: first is True/body, second False/exit.
            // Place those two paths to the left and right of the control.
            walk(byId.get(next[0].to),column-1,depth+1);
            walk(byId.get(next[1].to),column+1,depth+1);
            next.slice(2).forEach(e=>walk(byId.get(e.to),column,depth+1));
        }else next.forEach(e=>walk(byId.get(e.to),column,depth+1));
    };
    walk(first,0,0);
    // Include disconnected controls in source order, continuing below the diagram.
    nodes.forEach(n=>{if(!queued.has(n.id))walk(n,0,Math.max(0,...rows.map(r=>r.depth))+1);});
    const occupied=new Map();
    rows.forEach(entry=>{
        let row=entry.depth;
        while(occupied.has(entry.column+':'+row))row++;
        occupied.set(entry.column+':'+row,true);
        positions.set(entry.node.id,{x:entry.column*330,y:row*140});
    });
    // Reconverging branches belong on the centre spine below both incoming paths.
    nodes.filter(n=>(incoming.get(n.id)||[]).length>1).forEach(n=>{
        const dest=positions.get(n.id);if(!dest)return;
        const parents=(incoming.get(n.id)||[]).map(e=>positions.get(e.from)).filter(Boolean);
        if(parents.length){dest.x=0;dest.y=Math.max(dest.y,...parents.map(pt=>pt.y+140));}
    });
    const ys=[...positions.values()].map(v=>v.y);const mid=(Math.min(...ys)+Math.max(...ys))/2;
    positions.forEach(v=>{v.y-=mid;});
    return positions;
}
function isFixedVertical(){return !!project&&project.diagramType==='flowchart'&&project.direction==='FV';}
function computeAutoPositions(model,force=false){
    if(isFixedVertical())return fixedVerticalPositions(model);
    const positions=new Map(),stored=project.layout.positions||{},storedByKey=project.layout.positionsByKey||{};
    model.nodes.forEach(node=>{
        if(force)return;
        let saved=stored[node.id];
        if(!saved){const keyed=storedByKey[nodeLayoutKey(node)];if(keyed)saved=keyed;}
        if(!saved&&node.sourcePosition&&Number.isFinite(Number(node.sourcePosition.x))&&Number.isFinite(Number(node.sourcePosition.y)))saved=node.sourcePosition;
        if(saved&&Number.isFinite(Number(saved.x))&&Number.isFinite(Number(saved.y)))positions.set(node.id,{x:Number(saved.x),y:Number(saved.y)});
    });
    const storedPositionCount=positions.size;
    const missing=model.nodes.filter(n=>!positions.has(n.id));if(!missing.length)return positions;
    if(project.direction==='GRID'){
        const columns=Math.max(1,Math.ceil(Math.sqrt(model.nodes.length))),cellW=330,cellH=250;model.nodes.forEach((node,i)=>{if(!positions.has(node.id)){positions.set(node.id,{x:(i%columns)*cellW,y:Math.floor(i/columns)*cellH});}});
    }else{
        const incoming=new Map(),outgoing=new Map(),level=new Map();model.nodes.forEach(n=>{incoming.set(n.id,0);outgoing.set(n.id,[]);level.set(n.id,0);});model.edges.forEach(e=>{if(incoming.has(e.to)){incoming.set(e.to,incoming.get(e.to)+1);outgoing.get(e.from).push(e.to);}});const queue=model.nodes.filter(n=>incoming.get(n.id)===0).map(n=>n.id);const visited=new Set();while(queue.length){const id=queue.shift();visited.add(id);outgoing.get(id).forEach(to=>{level.set(to,Math.max(level.get(to),level.get(id)+1));incoming.set(to,incoming.get(to)-1);if(incoming.get(to)===0)queue.push(to);});}model.nodes.filter(n=>!visited.has(n.id)).forEach((n,i)=>level.set(n.id,Math.max(level.get(n.id),i%4)));
        const groups=new Map();model.nodes.forEach(n=>{const l=level.get(n.id);if(!groups.has(l))groups.set(l,[]);groups.get(l).push(n);});[...groups.keys()].sort((a,b)=>a-b).forEach(l=>{const group=groups.get(l),spacing=project.direction==='TB'?260:145;group.forEach((node,i)=>{if(positions.has(node.id))return;const cross=(i-(group.length-1)/2)*spacing;positions.set(node.id,project.direction==='TB'?{x:cross,y:l*190}:{x:l*300,y:cross});});});
    }
    /* Only centre a completely automatic layout. If any stored positions were
       restored, never translate them: their coordinates are the user's saved layout. */
    if(positions.size&&storedPositionCount===0){const xs=[...positions.values()].map(p=>p.x),ys=[...positions.values()].map(p=>p.y),cx=(Math.min(...xs)+Math.max(...xs))/2,cy=(Math.min(...ys)+Math.max(...ys))/2;positions.forEach(p=>{p.x-=cx;p.y-=cy;});}
    return positions;
}

function renderFromSource(options={}){
    if(runState.active||runState.view)stopProgramRun();
    project.source=sourceEditor.value;project.title=el('diagramTitle').value.trim()||project.title;project.diagramType=el('diagramType').value;project.sourceType=el('sourceType').value;project.direction=el('direction').value;project.settings.lineType=el('lineType').value;if(isFixedVertical())stripFixedVerticalLayoutSource();updateSourceMeta();
    try{if(project.diagramType==='dfd')syncDfdSourceTextboxes(project.source);currentModel=applyHiddenDiagramItems(parseProject());currentPositions=computeAutoPositions(currentModel,!!options.forceLayout);nodeSizes=new Map(currentModel.nodes.map(n=>[n.id,measureNode(n)]));currentModel.nodes.forEach(n=>{if(currentPositions.has(n.id))project.layout.positions[n.id]={x:currentPositions.get(n.id).x,y:currentPositions.get(n.id).y};});renderDiagram();renderControlPalette();const warnings=currentModel.warnings||[],textCount=floatingTextboxes().length,totalObjects=currentModel.nodes.length+textCount;el('parseStatus').className='parse-status'+(warnings.length?' error':'');el('parseStatus').textContent=warnings.length?warnings.join(' '):`${totalObjects} objects and ${currentModel.edges.length} connections rendered.`;el('objectCount').textContent=`${totalObjects} objects · ${currentModel.edges.length} connections`;if(currentModel.title&&!el('diagramTitle').matches(':focus')){project.title=currentModel.title;el('diagramTitle').value=currentModel.title;}if(options.markDirty!==false)setDirty(true);if(options.fit)requestAnimationFrame(fitDiagram);}catch(error){el('parseStatus').className='parse-status error';el('parseStatus').textContent=error.message;setStatus('The source contains an error.');}
}

function applyGrid(){canvasShell.classList.toggle('no-grid',!project.settings.showGrid);}
function renderDiagram(updateInspector=true){
    document.body.classList.toggle('fixed-vertical-mode',isFixedVertical());
    edgesLayer.innerHTML='';labelsLayer.innerHTML='';nodesLayer.innerHTML='';viewport.setAttribute('transform',`translate(${camera.x} ${camera.y}) scale(${camera.scale})`);
    currentModel.edges.forEach(renderEdge);currentModel.nodes.forEach(renderNode);renderFloatingTextboxes();if(updateInspector)renderInspector();el('zoomReadout').textContent=Math.round(camera.scale*100)+'%';
}
function nodeAnchorInfo(fromId,toId){
    const a=currentPositions.get(fromId),b=currentPositions.get(toId),size=nodeSizes.get(fromId);if(!a||!b||!size)return{point:{x:a?a.x:0,y:a?a.y:0},normal:{x:1,y:0},side:'right'};
    const dx=b.x-a.x,dy=b.y-a.y,halfW=size.width/2,halfH=size.height/2;
    if(Math.abs(dx/Math.max(1,size.width))>=Math.abs(dy/Math.max(1,size.height))){
        const right=dx>=0,y=a.y+Math.max(-halfH+12,Math.min(halfH-12,dy*Math.min(1,halfW/Math.max(1,Math.abs(dx)))));
        return{point:{x:a.x+(right?halfW:-halfW),y},normal:{x:right?1:-1,y:0},side:right?'right':'left'};
    }
    const bottom=dy>=0,x=a.x+Math.max(-halfW+12,Math.min(halfW-12,dx*Math.min(1,halfH/Math.max(1,Math.abs(dy)))));
    return{point:{x,y:a.y+(bottom?halfH:-halfH)},normal:{x:0,y:bottom?1:-1},side:bottom?'bottom':'top'};
}
function pointAlong(point,normal,distance){return{x:point.x+normal.x*distance,y:point.y+normal.y*distance};}
function fixedNodeAnchor(node,side){
    const p=node?currentPositions.get(node.id):null,size=node?nodeSizes.get(node.id):null;if(!p||!size)return null;
    if(side==='top')return{point:{x:p.x,y:p.y-size.height/2},normal:{x:0,y:-1},side:'top'};
    if(side==='bottom')return{point:{x:p.x,y:p.y+size.height/2},normal:{x:0,y:1},side:'bottom'};
    if(side==='left')return{point:{x:p.x-size.width/2,y:p.y},normal:{x:-1,y:0},side:'left'};
    return{point:{x:p.x+size.width/2,y:p.y},normal:{x:1,y:0},side:'right'};
}
function flowchartEdgeAnchorInfo(edge){
    const fromNode=currentModel.nodes.find(n=>n.id===edge.from),toNode=currentModel.nodes.find(n=>n.id===edge.to),fromPos=currentPositions.get(edge.from),toPos=currentPositions.get(edge.to);
    let a=nodeAnchorInfo(edge.from,edge.to),b=nodeAnchorInfo(edge.to,edge.from);
    if(!fromNode||!toNode||!fromPos||!toPos)return{a,b};
    if(fromNode.flowKind==='if'){
        const outs=currentModel.edges.filter(e=>e.from===fromNode.id),targets=outs.map(e=>({e,p:currentPositions.get(e.to)})).filter(v=>v.p).sort((u,v)=>u.p.x-v.p.x);
        if(targets.length===2){a=fixedNodeAnchor(fromNode,targets[0].e.id===edge.id?'left':'right');}
        else if(toPos.y>=fromPos.y-8)a=fixedNodeAnchor(fromNode,'bottom');
        else a=fixedNodeAnchor(fromNode,toPos.x<fromPos.x?'left':'right');
    }else if(fromNode.flowKind==='while'||fromNode.flowKind==='for')a=fixedNodeAnchor(fromNode,'bottom');
    if(toNode.flowKind==='if')b=fixedNodeAnchor(toNode,'top');
    else if(toNode.flowKind==='while'||toNode.flowKind==='for'){
        b=fromPos.y<toPos.y-8?fixedNodeAnchor(toNode,'top'):fixedNodeAnchor(toNode,fromPos.x<toPos.x?'left':'right');
    }
    return{a:a||nodeAnchorInfo(edge.from,edge.to),b:b||nodeAnchorInfo(edge.to,edge.from)};
}
/* Attach each connection to a side-centred port. Multiple connections on a side
   share evenly distributed ports, ordered by the position of the opposite node. */
function distributedEdgeAnchors(edge){
    const flowMode=project.diagramType==='flowchart'&&project.sourceType==='flowgorithm';
    const base=e=>{
        const special=flowMode?flowchartEdgeAnchorInfo(e):null;
        const source=special?special.a:nodeAnchorInfo(e.from,e.to);
        const target=special?special.b:nodeAnchorInfo(e.to,e.from);
        return{a:fixedNodeAnchor(currentModel.nodes.find(n=>n.id===e.from),source.side)||source,
               b:fixedNodeAnchor(currentModel.nodes.find(n=>n.id===e.to),target.side)||target};
    };
    const own=base(edge);
    const distribute=(end,anchor)=>{
        const nodeId=end==='a'?edge.from:edge.to;
        const candidates=currentModel.edges.filter(other=>{
            if((end==='a'?other.from:other.to)!==nodeId)return false;
            return base(other)[end].side===anchor.side;
        });
        if(candidates.length<=1)return anchor;
        const size=nodeSizes.get(nodeId);
        if(!size)return anchor;
        const horizontal=anchor.side==='top'||anchor.side==='bottom';
        const span=horizontal?size.width:size.height;
        const available=Math.max(0,span-32);
        const step=Math.min(28,available/(candidates.length+1));
        const coordinate=other=>{
            const counterpart=currentPositions.get(end==='a'?other.to:other.from);
            return counterpart?(horizontal?counterpart.x:counterpart.y):0;
        };
        candidates.sort((x,y)=>coordinate(x)-coordinate(y)||String(x.id).localeCompare(String(y.id)));
        const rank=candidates.findIndex(other=>other.id===edge.id);
        const displacement=(rank-(candidates.length-1)/2)*step;
        return{...anchor,point:{x:anchor.point.x+(horizontal?displacement:0),y:anchor.point.y+(horizontal?0:displacement)}};
    };
    return{a:distribute('a',own.a),b:distribute('b',own.b)};
}
function edgeRoute(aInfo,bInfo,lineType,isErd){
    const a=aInfo.point,b=bInfo.point,stub=isErd?30:16,sa=pointAlong(a,aInfo.normal,stub),sb=pointAlong(b,bInfo.normal,stub);
    if(lineType==='direct'){
        if(isErd)return{d:`M ${a.x} ${a.y} L ${sa.x} ${sa.y} L ${sb.x} ${sb.y} L ${b.x} ${b.y}`,label:{x:(sa.x+sb.x)/2,y:(sa.y+sb.y)/2}};
        return{d:`M ${a.x} ${a.y} L ${b.x} ${b.y}`,label:{x:(a.x+b.x)/2,y:(a.y+b.y)/2}};
    }
    if(lineType==='curved'){
        const dist=Math.max(60,Math.hypot(b.x-a.x,b.y-a.y)*.28);
        if(isErd){const c1=pointAlong(sa,aInfo.normal,dist),c2=pointAlong(sb,bInfo.normal,dist);return{d:`M ${a.x} ${a.y} L ${sa.x} ${sa.y} C ${c1.x} ${c1.y}, ${c2.x} ${c2.y}, ${sb.x} ${sb.y} L ${b.x} ${b.y}`,label:{x:(sa.x+sb.x)/2,y:(sa.y+sb.y)/2}};}
        const c1=pointAlong(a,aInfo.normal,dist),c2=pointAlong(b,bInfo.normal,dist);return{d:`M ${a.x} ${a.y} C ${c1.x} ${c1.y}, ${c2.x} ${c2.y}, ${b.x} ${b.y}`,label:{x:(a.x+b.x)/2,y:(a.y+b.y)/2}};
    }
    let parts=[`M ${a.x} ${a.y}`,`L ${sa.x} ${sa.y}`];
    const aHorizontal=aInfo.normal.x!==0,bHorizontal=bInfo.normal.x!==0;
    if(aHorizontal&&bHorizontal){const midX=(sa.x+sb.x)/2;parts.push(`L ${midX} ${sa.y}`,`L ${midX} ${sb.y}`);}
    else if(!aHorizontal&&!bHorizontal){const midY=(sa.y+sb.y)/2;parts.push(`L ${sa.x} ${midY}`,`L ${sb.x} ${midY}`);}
    else if(aHorizontal){parts.push(`L ${sb.x} ${sa.y}`);}
    else{parts.push(`L ${sa.x} ${sb.y}`);}
    parts.push(`L ${sb.x} ${sb.y}`,`L ${b.x} ${b.y}`);
    return{d:parts.join(' '),label:{x:(sa.x+sb.x)/2,y:(sa.y+sb.y)/2}};
}
function splitCardinality(value){
    const text=String(value||'0..*--1').replace(/\s+/g,'');const match=text.match(/^(.*?)--(.*?)$/);return match?[match[1],match[2]]:['0..*','1'];
}
function canonicalCardinality(token){
    token=String(token||'').toLowerCase().replace(/∞/g,'*');
    if(['1','one','||','1..1'].includes(token))return'one';
    if(['0..1','0-1','o|','optional'].includes(token))return'zero-one';
    if(['1..*','1..n','1-*','|{','one-many'].includes(token))return'one-many';
    if(['0..*','0..n','0-*','o{','zero-many'].includes(token))return'zero-many';
    if(['*','n','many','{'].includes(token))return'many';
    return token.includes('0')&&token.includes('*')?'zero-many':token.includes('*')?'many':'one';
}
function drawRelationBar(group,anchor,normal,distance){const c=pointAlong(anchor,normal,distance),t={x:-normal.y,y:normal.x},half=7;group.appendChild(createSvg('line',{x1:c.x-t.x*half,y1:c.y-t.y*half,x2:c.x+t.x*half,y2:c.y+t.y*half,stroke:'#344e60','stroke-width':2,'vector-effect':'non-scaling-stroke'}));}
function drawRelationCircle(group,anchor,normal,distance){const c=pointAlong(anchor,normal,distance);group.appendChild(createSvg('circle',{cx:c.x,cy:c.y,r:4.5,fill:'#fff',stroke:'#344e60','stroke-width':2,'vector-effect':'non-scaling-stroke'}));}
function drawCrowFoot(group,anchor,normal,distance){const vertex=pointAlong(anchor,normal,distance),base=pointAlong(anchor,normal,distance-10),t={x:-normal.y,y:normal.x},spread=8;[-1,0,1].forEach(mult=>group.appendChild(createSvg('line',{x1:vertex.x,y1:vertex.y,x2:base.x+t.x*spread*mult,y2:base.y+t.y*spread*mult,stroke:'#344e60','stroke-width':2,'stroke-linecap':'round','vector-effect':'non-scaling-stroke'})));}
function drawCardinality(group,info,token){
    const kind=canonicalCardinality(token),a=info.point,n=info.normal;
    if(kind==='one'){drawRelationBar(group,a,n,7);drawRelationBar(group,a,n,16);}
    else if(kind==='zero-one'){drawRelationCircle(group,a,n,7);drawRelationBar(group,a,n,18);}
    else if(kind==='one-many'){drawRelationBar(group,a,n,7);drawCrowFoot(group,a,n,25);}
    else if(kind==='zero-many'){drawRelationCircle(group,a,n,7);drawCrowFoot(group,a,n,25);}
    else if(kind==='many'){drawCrowFoot(group,a,n,18);}
}
function defaultCaptionStyle(){return{fontSize:11,color:'#465662',bold:false,italic:false,underline:false};}
function normaliseCaptionStyle(value){
    const style=Object.assign(defaultCaptionStyle(),value&&typeof value==='object'?value:{});
    style.fontSize=Math.min(48,Math.max(8,Number(style.fontSize)||11));
    style.color=/^#[0-9a-f]{6}$/i.test(String(style.color||''))?String(style.color):'#465662';
    style.bold=!!style.bold;style.italic=!!style.italic;style.underline=!!style.underline;
    return style;
}
function getEdgeLayout(edge){
    project.layout.links=project.layout.links&&typeof project.layout.links==='object'?project.layout.links:{};
    let saved=project.layout.links[edge.id]&&typeof project.layout.links[edge.id]==='object'?project.layout.links[edge.id]:null;
    if(!saved&&edge.bidirectional&&Array.isArray(edge.originalIds)){
        const forward=project.layout.links[edge.originalIds[0]],reverse=project.layout.links[edge.originalIds[1]];
        if(forward||reverse)saved={caption1:forward&&Object.prototype.hasOwnProperty.call(forward,'caption1')?forward.caption1:edge.label,caption2:reverse&&Object.prototype.hasOwnProperty.call(reverse,'caption1')?reverse.caption1:edge.label2,offset1:forward&&forward.offset1?{x:Number(forward.offset1.x)||0,y:Number(forward.offset1.y)||-14}:{x:0,y:-14},offset2:reverse&&reverse.offset1?{x:Number(reverse.offset1.x)||0,y:Number(reverse.offset1.y)||14}:{x:0,y:14},style1:forward&&forward.style1?forward.style1:null,style2:reverse&&reverse.style1?reverse.style1:null};
    }
    saved=saved||{};
    const offset1=saved.offset1&&typeof saved.offset1==='object'?saved.offset1:{};
    const offset2=saved.offset2&&typeof saved.offset2==='object'?saved.offset2:{};
    const defaultY1=edge.bidirectional?-14:0,defaultY2=edge.bidirectional?14:22;
    return{
        caption1:Object.prototype.hasOwnProperty.call(saved,'caption1')?String(saved.caption1):String(edge.label||''),
        caption2:Object.prototype.hasOwnProperty.call(saved,'caption2')?String(saved.caption2):String(edge.label2||''),
        offset1:{x:Number(offset1.x)||0,y:Number.isFinite(Number(offset1.y))?Number(offset1.y):defaultY1},
        offset2:{x:Number(offset2.x)||0,y:Number.isFinite(Number(offset2.y))?Number(offset2.y):defaultY2},
        style1:normaliseCaptionStyle(saved.style1),style2:normaliseCaptionStyle(saved.style2)
    };
}
function writeFlowgorithmEdgeCaption(edge,caption){
    if(project.diagramType!=='flowchart'||project.sourceType!=='flowgorithm'||!Number.isInteger(edge.sourceLineIndex))return false;
    const lines=sourceEditor.value.replace(/\r/g,'').split('\n');
    const raw=lines[edge.sourceLineIndex];
    if(raw===undefined)return false;
    const match=raw.match(/^(\s*[^\n]*?(?:-->|->|=>)\s*[A-Za-z_][\w.-]*)(?:\s*:\s*(?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|.*?))?(\s*)$/);
    if(!match)return false;
    const value=String(caption||'').trim();
    const escaped=value.replace(/\\/g,'\\\\').replace(/"/g,'\\"');
    lines[edge.sourceLineIndex]=match[1]+(value?' : "'+escaped+'"':'')+match[2];
    if(lines[edge.sourceLineIndex]===raw)return false;
    sourceEditor.value=lines.join('\n');project.source=sourceEditor.value;updateLineNumbers();return true;
}
function saveEdgeLayout(edgeId,settings){project.layout.links[edgeId]={caption1:String(settings.caption1||''),caption2:String(settings.caption2||''),offset1:{x:Number(settings.offset1.x)||0,y:Number(settings.offset1.y)||0},offset2:{x:Number(settings.offset2.x)||0,y:Number(settings.offset2.y)||0},style1:normaliseCaptionStyle(settings.style1),style2:normaliseCaptionStyle(settings.style2)};}
function renderEdgeCaption(edge,caption,x,y,index,style){
    if(!project.settings.showEdgeLabels||!caption)return;
    style=normaliseCaptionStyle(style);const label=String(caption),fontSize=style.fontSize,width=Math.min(320,Math.max(42,label.length*fontSize*.58+18)),height=Math.max(20,fontSize+9);
    const group=createSvg('g',{class:'edge-label'+(isSelected('edge',edge.id)?' selected':''),'data-edge-id':edge.id,'data-caption-index':index});
    group.appendChild(createSvg('rect',{x:x-width/2,y:y-height/2,width,height,rx:4}));
    const attrs={x,y:y+fontSize*.34,'text-anchor':'middle','font-size':fontSize,fill:style.color,'font-weight':style.bold?'700':'400','font-style':style.italic?'italic':'normal'};if(style.underline)attrs['text-decoration']='underline';
    const text=createSvg('text',attrs);text.textContent=label.length>60?label.slice(0,58)+'…':label;group.appendChild(text);
    group.addEventListener('pointerdown',event=>event.stopPropagation());group.addEventListener('click',event=>{event.stopPropagation();selectItem('edge',edge.id,event.shiftKey);renderDiagram();});labelsLayer.appendChild(group);
}
function openFlowgorithmInsertFromPointer(edge,event){
    if(project.diagramType!=='flowchart'||project.sourceType!=='flowgorithm')return;
    event.preventDefault();event.stopPropagation();
    const p=screenToWorld(event.clientX,event.clientY);openFlowgorithmInsertDialog(edge,p.x,p.y);
}
function renderFlowgorithmInsertArrow(edge,route,from,to){
    if(project.diagramType!=='flowchart'||project.sourceType!=='flowgorithm')return;
    /* The insertion control is intentionally just a small red-outlined circle.
       Earlier builds drew an arrow/triangle inside the circle, which was visually
       confusing and looked like part of the actual flowchart connector. */
    const group=createSvg('g',{class:'flow-insert-arrow','data-edge-id':edge.id,transform:`translate(${route.label.x} ${route.label.y})`,'aria-label':'Double-click to insert a Flowgorithm statement'});
    group.appendChild(createSvg('circle',{class:'flow-insert-halo',cx:0,cy:0,r:7}));
    /* Do not rebuild the SVG on the first click: replacing this element would prevent
       the browser from delivering the second click as a dblclick event. */
    group.addEventListener('pointerdown',event=>event.stopPropagation());
    group.addEventListener('click',event=>event.stopPropagation());
    group.addEventListener('dblclick',event=>openFlowgorithmInsertFromPointer(edge,event));
    edgesLayer.appendChild(group);
}
function renderEdge(edge){
    const from=currentPositions.get(edge.from),to=currentPositions.get(edge.to);if(!from||!to)return;
    const anchors=distributedEdgeAnchors(edge),aInfo=anchors.a,bInfo=anchors.b,isErd=project.diagramType==='erd',route=edgeRoute(aInfo,bInfo,project.settings.lineType||'orthogonal',isErd),selected=isSelected('edge',edge.id);
    const attrs={d:route.d,class:'edge'+(selected?' selected':'')};if(!isErd){attrs['marker-end']='url(#arrowHead)';if(edge.bidirectional)attrs['marker-start']='url(#arrowHead)';}
    const path=createSvg('path',attrs);edgesLayer.appendChild(path);
    const hit=createSvg('path',{d:route.d,class:'edge-hit','data-edge-id':edge.id});hit.addEventListener('pointerdown',event=>event.stopPropagation());hit.addEventListener('mouseenter',()=>path.classList.add('hovered'));hit.addEventListener('mouseleave',()=>path.classList.remove('hovered'));hit.addEventListener('click',event=>{event.stopPropagation();clearTimeout(edgeClickTimer);const additive=event.shiftKey;edgeClickTimer=setTimeout(()=>{selectItem('edge',edge.id,additive);renderDiagram();edgeClickTimer=null;},220);});hit.addEventListener('dblclick',event=>{clearTimeout(edgeClickTimer);edgeClickTimer=null;openFlowgorithmInsertFromPointer(edge,event);});edgesLayer.appendChild(hit);
    if(isErd){const parts=splitCardinality(edge.cardinality);const marks=createSvg('g',{class:'relationship-cardinality'+(selected?' selected':'')});drawCardinality(marks,aInfo,parts[0]);drawCardinality(marks,bInfo,parts[1]);edgesLayer.appendChild(marks);}
    const layout=getEdgeLayout(edge);renderEdgeCaption(edge,layout.caption1,route.label.x+layout.offset1.x,route.label.y+layout.offset1.y,1,layout.style1);renderEdgeCaption(edge,layout.caption2,route.label.x+layout.offset2.x,route.label.y+layout.offset2.y,2,layout.style2);
}

function defaultNodeVisualStyle(node){
    if(node&&node.shape==='dfd-process')return{fill:'#d3d3d3'};
    if(node&&node.shape==='entity')return{fill:'#d0d0d0'};
    if(node&&node.shape==='store')return{fill:'#ffffff'};
    return{fill:'#ffffff'};
}
function getNodeVisualStyle(node){
    project.layout.nodeStyles=project.layout.nodeStyles&&typeof project.layout.nodeStyles==='object'?project.layout.nodeStyles:{};
    project.layout.nodeStylesByKey=project.layout.nodeStylesByKey&&typeof project.layout.nodeStylesByKey==='object'?project.layout.nodeStylesByKey:{};
    let saved=project.layout.nodeStyles[node.id];if(!saved)saved=project.layout.nodeStylesByKey[nodeLayoutKey(node)];
    const style=Object.assign(defaultNodeVisualStyle(node),saved&&typeof saved==='object'?saved:{});
    if(!/^#[0-9a-f]{6}$/i.test(String(style.fill||'')))style.fill=defaultNodeVisualStyle(node).fill;
    return style;
}
function saveNodeVisualStyle(node,style){
    const clean={fill:/^#[0-9a-f]{6}$/i.test(String(style.fill||''))?String(style.fill):defaultNodeVisualStyle(node).fill};
    project.layout.nodeStyles[node.id]=clean;project.layout.nodeStylesByKey[nodeLayoutKey(node)]=clone(clean);
}
function floatingTextboxes(){project.layout.textboxes=project.layout.textboxes&&typeof project.layout.textboxes==='object'?project.layout.textboxes:{};return Object.values(project.layout.textboxes).filter(t=>t&&typeof t==='object');}
function normaliseFloatingTextbox(box){
    box=Object.assign({id:'text-'+uuid(),text:'Text',x:0,y:0,fontSize:18,color:'#20303b',bold:false,italic:false,underline:false},box||{});
    box.text=String(box.text==null?'Text':box.text);box.x=Number(box.x)||0;box.y=Number(box.y)||0;box.fontSize=Math.min(72,Math.max(8,Number(box.fontSize)||18));box.color=/^#[0-9a-f]{6}$/i.test(String(box.color||''))?String(box.color):'#20303b';box.bold=!!box.bold;box.italic=!!box.italic;box.underline=!!box.underline;return box;
}
function renderFloatingTextboxes(){
    floatingTextboxes().forEach(raw=>{const box=normaliseFloatingTextbox(raw);project.layout.textboxes[box.id]=box;const lines=String(box.text||'Text').split(/\r?\n/),lineHeight=box.fontSize*1.25,maxLen=Math.max(1,...lines.map(line=>line.length)),width=Math.max(36,maxLen*box.fontSize*.62+14),height=Math.max(box.fontSize+10,lines.length*lineHeight+8);const group=createSvg('g',{class:'node floating-text'+(isSelected('textbox',box.id)?' selected':''),transform:`translate(${box.x} ${box.y})`,'data-text-id':box.id,'data-object-kind':'textbox'});group.appendChild(createSvg('rect',{class:'text-hit',x:-width/2,y:-height/2,width,height,rx:3}));const text=createSvg('text',{x:0,y:-((lines.length-1)*lineHeight)/2+box.fontSize*.34,'text-anchor':'middle','font-size':box.fontSize,fill:box.color,'font-weight':box.bold?'700':'400','font-style':box.italic?'italic':'normal'});if(box.underline)text.setAttribute('text-decoration','underline');lines.forEach((line,i)=>{const span=createSvg('tspan',{x:0,dy:i===0?0:lineHeight});span.textContent=line||' ';text.appendChild(span);});group.appendChild(text);group.appendChild(createSvg('rect',{class:'text-selection',x:-width/2,y:-height/2,width,height,rx:3}));group.addEventListener('pointerdown',startFloatingTextDrag);group.addEventListener('click',event=>event.stopPropagation());nodesLayer.appendChild(group);});
}
function addFloatingTextboxAt(clientX,clientY){const point=screenToWorld(clientX,clientY);return addFloatingTextboxWorld(point.x,point.y);}
function addFloatingTextboxWorld(x,y){pushUndoSnapshot();if(project.diagramType==='dfd')return addDfdSourceTextboxWorld(x,y);const id='text-'+uuid(),box=normaliseFloatingTextbox({id,text:'Text',x,y});project.layout.textboxes[id]=box;selectItem('textbox',id,false);setDirty(true);renderDiagram();persistLayoutSoon();return id;}
function addDfdSourceTextboxWorld(x,y){appendSourceBlock('## Text');syncDfdSourceTextboxes(sourceEditor.value);const records=dfdTextRecords(sourceEditor.value),record=records[records.length-1],id=record?record.id:'',box=id?project.layout.textboxes[id]:null;if(box){box.x=x;box.y=y;box.sourceBacked=true;box.sourceType='dfd';box.sourceOrdinal=record.ordinal;}if(id)selectItem('textbox',id,false);renderFromSource({fit:false,markDirty:false});setDirty(true);renderDiagram();persistLayoutSoon();return id;}
function startFloatingTextDrag(event){
    event.stopPropagation();const id=event.currentTarget.getAttribute('data-text-id'),box=project.layout.textboxes[id];if(!box)return;
    if(event.shiftKey)selectItem('textbox',id,true);else if(!isSelected('textbox',id))selectItem('textbox',id,false);
    const point=screenToWorld(event.clientX,event.clientY),members=[];
    selectedMovableItems().forEach(item=>{if(item.kind==='textbox'){const b=project.layout.textboxes[item.id];if(b)members.push({kind:'textbox',id:item.id,x:Number(b.x)||0,y:Number(b.y)||0});}else{const pos=currentPositions.get(item.id);if(pos)members.push({kind:'node',id:item.id,x:pos.x,y:pos.y});}});
    dragState={kind:'group',id,startX:point.x,startY:point.y,members,moved:false,undoSnapshot:makeUndoSnapshot()};event.currentTarget.setPointerCapture(event.pointerId);renderDiagram();
}

function addMultilineText(group,lines,x,y,lineHeight=18,attrs={}){const text=createSvg('text',Object.assign({x,y,'text-anchor':'middle'},attrs));lines.forEach((line,i)=>{const span=createSvg('tspan',{x,dy:i===0?0:lineHeight});span.textContent=line;text.appendChild(span);});group.appendChild(text);return text;}
function addCenteredMultilineText(group,lines,x,lineHeight=18,attrs={}){const safeLines=(lines&&lines.length?lines:[' ']);const text=createSvg('text',Object.assign({x,y:0,'text-anchor':'middle','dominant-baseline':'middle'},attrs));safeLines.forEach((line,i)=>{const span=createSvg('tspan',{x,y:(i-(safeLines.length-1)/2)*lineHeight,'dominant-baseline':'middle'});span.textContent=line||' ';text.appendChild(span);});group.appendChild(text);return text;}
function beginInlineFlowgorithmEdit(node,group,size){
    if(!node||!group||!size||project.diagramType!=='flowchart'||project.sourceType!=='flowgorithm')return focusFlowgorithmInspectorEditor(node);
    selectItem('node',node.id,false);renderInspector();
    const explicit=node.hasExplicitCaption?String(node.sourceCaption||''):'';
    const fo=createSvg('foreignObject',{x:-size.width/2+8,y:-size.height/2+8,width:Math.max(40,size.width-16),height:Math.max(34,size.height-16)});
    const input=document.createElement('input');input.setAttribute('xmlns','http://www.w3.org/1999/xhtml');input.type='text';input.value=explicit||String(node.key||node.label||'');input.style.cssText='width:100%;height:100%;border:2px solid #2475a8;border-radius:4px;background:rgba(255,255,255,.96);font:500 21px Inter,Segoe UI,Arial,sans-serif;text-align:center;padding:4px 8px;outline:none;';
    fo.appendChild(input);group.appendChild(fo);
    let finished=false;const finish=commit=>{if(finished)return;finished=true;const value=input.value.trim();if(commit&&value){pushUndoSnapshot();if(explicit)updateFlowgorithmNodeFields(node,node.key,value);else updateFlowgorithmNodeFields(node,value,'');}else renderDiagram();};
    input.addEventListener('pointerdown',e=>e.stopPropagation());input.addEventListener('click',e=>e.stopPropagation());input.addEventListener('dblclick',e=>e.stopPropagation());input.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();finish(true);}else if(e.key==='Escape'){e.preventDefault();finish(false);}});input.addEventListener('blur',()=>finish(true));setTimeout(()=>{input.focus();input.select();},0);
}
function renderNode(node){
    const p=currentPositions.get(node.id),size=nodeSizes.get(node.id);if(!p||!size)return;const group=createSvg('g',{class:'node'+(isSelected('node',node.id)?' selected':''),transform:`translate(${p.x} ${p.y})`,'data-id':node.id});
    const x=-size.width/2,y=-size.height/2,w=size.width,h=size.height,nodeVisual=getNodeVisualStyle(node);let body,customLabel=false;
    if(node.shape==='decision')body=createSvg('polygon',{class:'body',points:`0,${y} ${w/2},0 0,${h/2} ${-w/2},0`,fill:'#fff8e8',stroke:'#000','stroke-width':3});
    else if(node.shape==='io')body=createSvg('polygon',{class:'body',points:`${x+18},${y} ${w/2},${y} ${w/2-18},${h/2} ${x},${h/2}`,fill:'#eef7ff',stroke:'#000','stroke-width':3});
    else if(node.shape==='terminator')body=createSvg('rect',{class:'body',x,y,width:w,height:h,rx:h/2,fill:'#edf9f1',stroke:'#2f7d4d','stroke-width':3});
    else if(node.shape==='flow-stop')body=createSvg('polygon',{class:'body',points:`${x+w*.29},${y} ${x+w*.71},${y} ${x+w},${y+h*.29} ${x+w},${y+h*.71} ${x+w*.71},${y+h} ${x+w*.29},${y+h} ${x},${y+h*.71} ${x},${y+h*.29}`,fill:'#ffd7d3',stroke:'#8b2b25','stroke-width':3});
    else if(node.shape==='rounded')body=createSvg('rect',{class:'body',x,y,width:w,height:h,rx:10,fill:'#f6f2ff',stroke:'#000','stroke-width':3});
    else if(node.shape==='flow-if')body=createSvg('polygon',{class:'body',points:`0,${y} ${w/2},0 0,${h/2} ${-w/2},0`,fill:'#ffd0d0',stroke:'#8f5f5f','stroke-width':3});
    else if(node.shape==='flow-loop')body=createSvg('polygon',{class:'body',points:`${x+24},${y} ${x+w-24},${y} ${x+w},0 ${x+w-24},${h/2} ${x+24},${h/2} ${x},0`,fill:'#ffe0a0',stroke:'#8b6b36','stroke-width':3});
    else if(node.shape==='flow-for'){body=createSvg('polygon',{class:'body',points:`${x+24},${y} ${x+w-24},${y} ${x+w},0 ${x+w-24},${h/2} ${x+24},${h/2} ${x},0`,fill:'#ffe0a0',stroke:'#8b6b36','stroke-width':3});group.appendChild(body);body=null;const forMark=createSvg('text',{x:0,y:y+24,'text-anchor':'middle','font-size':16,'font-weight':'800',fill:'#6f4c16'});forMark.textContent='i';group.appendChild(forMark);}
    else if(node.shape==='flow-input')body=createSvg('polygon',{class:'body',points:`${x+22},${y} ${w/2},${y} ${w/2-22},${h/2} ${x},${h/2}`,fill:'#b0e0ff',stroke:'#466f8a','stroke-width':3});
    else if(node.shape==='flow-output')body=createSvg('polygon',{class:'body',points:`${x},${y} ${w/2-22},${y} ${w/2},${h/2} ${x+22},${h/2}`,fill:'#d0ffd0',stroke:'#5f8f5f','stroke-width':3});
    else if(node.shape==='flow-declare'){body=createSvg('rect',{class:'body',x,y,width:w,height:h,rx:0,fill:'#ffffd0',stroke:'#8d8d5f','stroke-width':3});group.appendChild(body);body=null;group.appendChild(createSvg('line',{x1:x+18,y1:y,x2:x+18,y2:y+h,stroke:'#8d8d5f','stroke-width':3}));}
    else if(node.shape==='flow-call'){body=createSvg('rect',{class:'body',x,y,width:w,height:h,rx:0,fill:'#f0e0ff',stroke:'#7d608d','stroke-width':3});group.appendChild(body);body=null;group.appendChild(createSvg('line',{x1:x+14,y1:y,x2:x+14,y2:y+h,stroke:'#9070a0','stroke-width':3}));group.appendChild(createSvg('line',{x1:x+w-14,y1:y,x2:x+w-14,y2:y+h,stroke:'#9070a0','stroke-width':3}));}
    else if(node.shape==='flow-comment')body=createSvg('rect',{class:'body',x,y,width:w,height:h,rx:4,fill:'#fffde8',stroke:'#8c8458','stroke-width':1.7,'stroke-dasharray':'6 4'});
    else if(node.shape==='flow-assign')body=createSvg('rect',{class:'body',x,y,width:w,height:h,rx:0,fill:'#ffffd0',stroke:'#8d8d5f','stroke-width':3});
    else if(node.shape==='dfd-process'){
        body=createSvg('rect',{class:'body',x,y,width:w,height:h,rx:14,fill:nodeVisual.fill,stroke:'#171717','stroke-width':2.4});group.appendChild(body);body=null;customLabel=true;
        const headerHeight=28;group.appendChild(createSvg('line',{x1:x,y1:y+headerHeight,x2:x+w,y2:y+headerHeight,stroke:'#171717','stroke-width':2.2}));
        const number=createSvg('text',{x:0,y:y+19,'text-anchor':'middle','font-size':15,'font-weight':'700',fill:'#111'});number.textContent=node.processNumber==null?'':String(node.processNumber);group.appendChild(number);
        const lines=wrapText(node.label,22),startY=y+headerHeight+(h-headerHeight)/2-((lines.length-1)*9)+5;addMultilineText(group,lines,0,startY,18,{'font-size':14,fill:'#111','font-weight':'500'});
    }
    else if(node.shape==='entity'){
        customLabel=true;group.appendChild(createSvg('rect',{x:x-8,y:y-8,width:w,height:h,fill:nodeVisual.fill,stroke:'#171717','stroke-width':2.2}));
        body=createSvg('rect',{class:'body',x,y,width:w,height:h,fill:nodeVisual.fill,stroke:'#171717','stroke-width':2.2});group.appendChild(body);body=null;
        const lines=wrapText(node.label,22);addMultilineText(group,lines,0,-((lines.length-1)*9)+4,18,{'font-size':14,fill:'#111','font-weight':'500'});
    }
    else if(node.shape==='store'){
        customLabel=true;const divider=x+38;body=createSvg('rect',{class:'body',x,y,width:w,height:h,fill:nodeVisual.fill,stroke:'#171717','stroke-width':2});group.appendChild(body);body=null;
        group.appendChild(createSvg('line',{x1:divider,y1:y,x2:divider,y2:y+h,stroke:'#171717','stroke-width':2}));
        const kind=createSvg('text',{x:x+19,y:5,'text-anchor':'middle','font-size':17,'font-weight':'700',fill:'#111'});kind.textContent=node.storeKind||'D';group.appendChild(kind);
        const lines=wrapText(node.label,22),labelX=(divider+(x+w))/2;addMultilineText(group,lines,labelX,-((lines.length-1)*9)+4,18,{'font-size':14,fill:'#111','font-weight':'500'});
    }
    else if(node.shape==='table'){
        body=createSvg('rect',{class:'body',x,y,width:w,height:h,rx:5,fill:'#fff',stroke:'#496b83','stroke-width':1.7});group.appendChild(body);group.appendChild(createSvg('rect',{x,y,width:w,height:38,rx:5,fill:'#365d78'}));group.appendChild(createSvg('rect',{x,y:y+33,width:w,height:5,fill:'#365d78'}));const title=createSvg('text',{x:0,y:y+24,'text-anchor':'middle',fill:'#fff','font-size':15,'font-weight':'700'});title.textContent=node.label;group.appendChild(title);const fields=node.fields.length?node.fields:[{name:'No fields detected',type:'',pk:false,fk:false}];fields.forEach((field,i)=>{const rowY=y+38+i*25;if(i>0)group.appendChild(createSvg('line',{x1:x,y1:rowY,x2:x+w,y2:rowY,stroke:'#dbe1e6'}));const flags=[field.pk?'PK':'',field.fk?'FK':'',field.unique?'UQ':''].filter(Boolean).join(' ');const left=createSvg('text',{x:x+9,y:rowY+17,'font-size':12,fill:'#263640','font-weight':field.pk?'700':'400'});left.textContent=(flags?flags+'  ':'')+field.name;group.appendChild(left);const right=createSvg('text',{x:x+w-9,y:rowY+17,'font-size':11,fill:'#6a7781','text-anchor':'end'});right.textContent=field.type||'';group.appendChild(right);});body=null;customLabel=true;
    }
    else body=createSvg('rect',{class:'body',x,y,width:w,height:h,rx:4,fill:'#fff',stroke:'#000','stroke-width':3});
    if(body)group.appendChild(body);
    if(!customLabel){const maxChars=(node.shape==='decision'||node.shape==='flow-if'||node.shape==='flow-loop'||node.shape==='flow-for')?18:24,lines=wrapText(node.label,maxChars),fontSize=21,lineHeight=27,labelX=node.shape==='flow-declare'?9:0;addCenteredMultilineText(group,lines,labelX,lineHeight,{'font-size':fontSize,fill:'#20303b','font-weight':node.shape==='terminator'?'700':'500'});}
    group.appendChild(createSvg('rect',{class:'selection',x:x-12,y:y-12,width:w+24,height:h+24,rx:7}));
    group.addEventListener('pointerdown',startNodeDrag);group.addEventListener('click',event=>event.stopPropagation());nodesLayer.appendChild(group);
}

function flowgorithmDefaultCaption(node){
    if(!node)return'';
    if(node.flowKind==='terminal'||node.flowKind==='start'||node.flowKind==='end'||node.flowKind==='stop')return node.key;
    if(node.flowKind==='declare'&&node.sourceValue)return node.key+' = '+node.sourceValue;
    if(node.flowKind==='assign'){const a=flowAssignParts(node.sourceValue);return a.variable?a.variable+' = '+(a.expression||'value'):node.key;}
    return node.sourceValue||node.key||node.label||'';
}
function updateFlowgorithmNodeFields(node,newName,newCaption,newValue){
    if(!node||project.diagramType!=='flowchart'||project.sourceType!=='flowgorithm')return false;
    newName=String(newName==null?node.key:newName).trim().replace(/\s+/g,'_');
    if(!/^[A-Za-z_][\w.-]*$/.test(newName)){toast('Name must begin with a letter or underscore and contain no spaces.','error');return false;}
    const oldName=String(node.key),caption=String(newCaption==null?'':newCaption).trim(),lines=sourceEditor.value.replace(/\r/g,'').split('\n');
    const idx=Number(node.sourceDeclarationLineIndex);if(!Number.isInteger(idx)||idx<0||idx>=lines.length)return false;
    let keyword=String(node.sourceKeyword||node.flowKind||'assign').toLowerCase();if(keyword==='start'||keyword==='end'||keyword==='terminal')keyword='terminal';if(keyword==='call')keyword='function';
    const value=String(newValue==null?node.sourceValue:newValue).trim(),quoted=caption?' : '+JSON.stringify(caption):'';
    lines[idx]=keyword+' '+newName+(value?' '+value:'')+quoted;
    if(newName!==oldName){
        for(let i=0;i<lines.length;i++){
            if(i===idx)continue;
            const arrow=parseArrowLine(stripLineComment(lines[i]).trim());if(!arrow)continue;
            const from=arrow.from===oldName?newName:arrow.from,to=arrow.to===oldName?newName:arrow.to;
            if(from!==arrow.from||to!==arrow.to)lines[i]=from+' -> '+to+(arrow.label?' : '+arrow.label:'');
            const pos=lines[i].match(/^\s*#\s*@position\s+(\S+)\s+(.+)$/i);if(pos&&pos[1]===oldName)lines[i]='# @position '+newName+' '+pos[2];
        }
    }
    sourceEditor.value=lines.join('\n');project.source=sourceEditor.value;updateLineNumbers();renderFromSource({fit:false,markDirty:false});
    const id=stableId('flowgorithm',newName);selectItem('node',id,false);setDirty(true);persistLayoutSoon();return true;
}
function updateFlowgorithmNodeType(node,newType){
    if(!node||project.diagramType!=='flowchart'||project.sourceType!=='flowgorithm')return false;
    const allowed=new Set(['terminal','if','stop','input','output','declare','assign','function','while','for']);newType=String(newType||'').toLowerCase();if(!allowed.has(newType))return false;
    const lines=sourceEditor.value.replace(/\r/g,'').split('\n'),idx=Number(node.sourceDeclarationLineIndex);if(!Number.isInteger(idx)||idx<0||idx>=lines.length)return false;
    const caption=node.hasExplicitCaption?String(node.sourceCaption||''):'',quoted=caption?' : '+JSON.stringify(caption):'',defaults={input:'value',output:'value',declare:'',assign:'value = 0',if:'condition',function:'procedure()',while:'condition',for:'iterator=i; start=0; end=9; increment=1'},value=(newType==='terminal'||newType==='stop')?'':(String(node.sourceValue||'').trim()||defaults[newType]||'');
    lines[idx]=newType+' '+node.key+(value?' '+value:'')+quoted;sourceEditor.value=lines.join('\n');project.source=sourceEditor.value;updateLineNumbers();renderFromSource({fit:false,markDirty:false});selectItem('node',stableId('flowgorithm',node.key),false);setDirty(true);persistLayoutSoon();return true;
}
function focusFlowgorithmInspectorEditor(node){
    if(project.diagramType!=='flowchart'||project.sourceType!=='flowgorithm'||!node)return;
    selectItem('node',node.id,false);renderDiagram();
    requestAnimationFrame(()=>{const target=node.hasExplicitCaption&&String(node.sourceCaption||'').trim()?el('nodeCaption'):el('nodeName');if(target){target.focus();target.select();}});
}
function beginFlowchartInlineEdit(node,group,size){
    if(project.diagramType!=='flowchart'||project.sourceType!=='flowgorithm'||!node)return;
    const existing=svg.querySelector('.inline-node-editor');if(existing)existing.remove();
    const fo=createSvg('foreignObject',{class:'inline-node-editor',x:-size.width/2+8,y:-size.height/2+8,width:size.width-16,height:size.height-16});
    const input=document.createElementNS('http://www.w3.org/1999/xhtml','input');input.setAttribute('type','text');input.value=node.hasExplicitCaption?node.sourceCaption:node.label;input.style.cssText='width:100%;height:100%;border:0;outline:2px solid #e11;background:rgba(255,255,255,.96);text-align:center;font:600 14px Inter,Segoe UI,Arial,sans-serif;padding:4px;';fo.appendChild(input);group.appendChild(fo);
    const finish=()=>{if(!fo.isConnected)return;const value=input.value.trim();pushUndoSnapshot();fo.remove();updateFlowgorithmNodeFields(node,node.key,value);};
    input.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();finish();}else if(e.key==='Escape'){e.preventDefault();fo.remove();}});input.addEventListener('blur',finish);setTimeout(()=>{input.focus();input.select();},0);
}
function screenToWorld(clientX,clientY){const rect=svg.getBoundingClientRect();return{x:(clientX-rect.left-camera.x)/camera.scale,y:(clientY-rect.top-camera.y)/camera.scale};}
function startNodeDrag(event){
    event.stopPropagation();
    const id=event.currentTarget.getAttribute('data-id'),point=screenToWorld(event.clientX,event.clientY),p=currentPositions.get(id);if(!p)return;
    const stamp=(window.performance&&performance.now)?performance.now():Date.now(),isDouble=lastNodePointer.id===id&&(stamp-lastNodePointer.time)<=420;
    lastNodePointer={id,time:stamp};
    if(event.shiftKey)selectItem('node',id,true);else if(!isSelected('node',id))selectItem('node',id,false);
    if(isDouble&&!event.shiftKey&&project.diagramType==='flowchart'&&project.sourceType==='flowgorithm'){
        dragState=null;
        const node=currentModel.nodes.find(item=>item.id===id),size=nodeSizes.get(id);
        renderDiagram();
        requestAnimationFrame(()=>{const group=[...nodesLayer.querySelectorAll('.node[data-id]')].find(item=>item.getAttribute('data-id')===id);if(node&&group&&size)beginInlineFlowgorithmEdit(node,group,size);else if(node)focusFlowgorithmInspectorEditor(node);});
        return;
    }
    if(isFixedVertical()){dragState=null;renderDiagram();return;}
    const members=[];selectedMovableItems().forEach(item=>{if(item.kind==='node'){const pos=currentPositions.get(item.id);if(pos)members.push({kind:'node',id:item.id,x:pos.x,y:pos.y});}else{const b=project.layout.textboxes[item.id];if(b)members.push({kind:'textbox',id:item.id,x:Number(b.x)||0,y:Number(b.y)||0});}});
    dragState={kind:'group',id,startX:point.x,startY:point.y,members,moved:false,undoSnapshot:makeUndoSnapshot()};event.currentTarget.setPointerCapture(event.pointerId);renderDiagram();
}
svg.addEventListener('pointerdown',event=>{if(event.target.closest('.node')||event.target.closest('.floating-text')||event.target.closest('.edge-hit')||event.target.closest('.edge-label'))return;panState={x:event.clientX,y:event.clientY,cameraX:camera.x,cameraY:camera.y};svg.classList.add('panning');svg.setPointerCapture(event.pointerId);clearSelection();renderDiagram();});
svg.addEventListener('pointermove',event=>{if(dragState){const point=screenToWorld(event.clientX,event.clientY),dx=point.x-dragState.startX,dy=point.y-dragState.startY;if(!dragState.moved)pushUndoSnapshot(dragState.undoSnapshot);(dragState.members||[]).forEach(member=>{const p={x:member.x+dx,y:member.y+dy};if(member.kind==='textbox'){const box=project.layout.textboxes[member.id];if(box){box.x=p.x;box.y=p.y;}}else{currentPositions.set(member.id,p);project.layout.positions[member.id]={x:p.x,y:p.y};}});dragState.moved=true;setDirty(true);renderDiagram();return;}if(panState){camera.x=panState.cameraX+event.clientX-panState.x;camera.y=panState.cameraY+event.clientY-panState.y;renderDiagram();}});
svg.addEventListener('pointerup',event=>{if(dragState){const moved=dragState.moved;dragState=null;if(moved){captureCurrentLayout(false);syncFlowgorithmVisualPositionsToSource();persistLayoutSoon();}return;}if(panState){panState=null;svg.classList.remove('panning');}});
svg.addEventListener('pointercancel',()=>{dragState=null;panState=null;svg.classList.remove('panning');});
svg.addEventListener('dblclick',event=>{if(event.target.closest('.node')||event.target.closest('.floating-text')||event.target.closest('.edge-hit')||event.target.closest('.edge-label'))return;event.preventDefault();event.stopPropagation();addFloatingTextboxAt(event.clientX,event.clientY);});
canvasShell.addEventListener('dragover',event=>{if(event.dataTransfer&&Array.from(event.dataTransfer.types||[]).includes('application/x-charter-control')){event.preventDefault();event.dataTransfer.dropEffect='copy';}});
canvasShell.addEventListener('drop',event=>{const type=event.dataTransfer?event.dataTransfer.getData('application/x-charter-control'):'';if(!type)return;event.preventDefault();const p=screenToWorld(event.clientX,event.clientY);addControlAtWorld(type,p.x,p.y);});
svg.addEventListener('wheel',event=>{event.preventDefault();const rect=svg.getBoundingClientRect(),mx=event.clientX-rect.left,my=event.clientY-rect.top,old=camera.scale,next=Math.min(3,Math.max(.2,old*(event.deltaY<0?1.12:.89)));camera.x=mx-(mx-camera.x)*(next/old);camera.y=my-(my-camera.y)*(next/old);camera.scale=next;renderDiagram();},{passive:false});

function stripFixedVerticalLayoutSource(){
    if(!isFixedVertical()||!project||project.sourceType!=='flowgorithm')return false;
    const lines=sourceEditor.value.replace(/\r/g,'').split('\n');
    const filtered=lines.filter(line=>!/^\s*#\s*@(?:visual-layout|position)\b/i.test(line));
    while(filtered.length&&filtered[filtered.length-1].trim()==='')filtered.pop();
    const next=filtered.join('\n')+'\n';
    if(next===sourceEditor.value)return false;
    sourceEditor.value=next;project.source=next;updateLineNumbers();return true;
}
function syncFlowgorithmVisualPositionsToSource(){
    if(isFixedVertical())return stripFixedVerticalLayoutSource();
    if(!project||project.diagramType!=='flowchart'||project.sourceType!=='flowgorithm'||!currentModel||isFixedVertical())return false;
    const original=sourceEditor.value.replace(/\r/g,'').split('\n');
    let layoutAt=original.findIndex(line=>/^\s*#\s*@visual-layout\b/i.test(line));
    const positionLines=[];
    (currentModel.nodes||[]).forEach(node=>{
        const point=currentPositions.get(node.id);if(!point||!Number.isFinite(Number(point.x))||!Number.isFinite(Number(point.y)))return;
        positionLines.push(`# @position ${node.key} ${Math.round(Number(point.x)*100)/100} ${Math.round(Number(point.y)*100)/100}`);
    });
    let nextLines;
    if(layoutAt>=0){
        /* Never move the layout marker or alter the whitespace above it. Only replace
           @position records below the existing marker. */
        const prefix=original.slice(0,layoutAt+1),suffix=original.slice(layoutAt+1).filter(line=>!/^\s*#\s*@position\b/i.test(line));
        while(suffix.length&&suffix[suffix.length-1]==='')suffix.pop();
        nextLines=[...prefix,...positionLines,...suffix];
    }else{
        const base=[...original];while(base.length&&base[base.length-1]==='')base.pop();
        nextLines=[...base,'','','','','','','# @visual-layout positions for the designer:',...positionLines];
    }
    const next=nextLines.join('\n')+'\n';
    if(next===sourceEditor.value)return false;
    sourceEditor.value=next;project.source=next;updateLineNumbers();return true;
}

function diagramBounds(){let minX=Infinity,minY=Infinity,maxX=-Infinity,maxY=-Infinity;currentModel.nodes.forEach(n=>{const p=currentPositions.get(n.id),s=nodeSizes.get(n.id);if(!p||!s)return;minX=Math.min(minX,p.x-s.width/2);maxX=Math.max(maxX,p.x+s.width/2);minY=Math.min(minY,p.y-s.height/2);maxY=Math.max(maxY,p.y+s.height/2);});floatingTextboxes().forEach(raw=>{const b=normaliseFloatingTextbox(raw),lines=String(b.text||'Text').split(/\r?\n/),w=Math.max(36,Math.max(1,...lines.map(line=>line.length))*b.fontSize*.62+14),h=Math.max(b.fontSize+10,lines.length*b.fontSize*1.25+8);minX=Math.min(minX,b.x-w/2);maxX=Math.max(maxX,b.x+w/2);minY=Math.min(minY,b.y-h/2);maxY=Math.max(maxY,b.y+h/2);});if(!Number.isFinite(minX))return{minX:-100,minY:-100,maxX:100,maxY:100,width:200,height:200};return{minX,minY,maxX,maxY,width:maxX-minX,height:maxY-minY};}
function fitDiagram(){const rect=svg.getBoundingClientRect(),b=diagramBounds(),pad=70;camera.scale=Math.min(1.35,Math.max(.2,Math.min((rect.width-pad*2)/Math.max(1,b.width),(rect.height-pad*2)/Math.max(1,b.height))));camera.x=rect.width/2-((b.minX+b.maxX)/2)*camera.scale;camera.y=rect.height/2-((b.minY+b.maxY)/2)*camera.scale;renderDiagram();}
function zoom(factor){const rect=svg.getBoundingClientRect(),mx=rect.width/2,my=rect.height/2,old=camera.scale,next=Math.min(3,Math.max(.2,old*factor));camera.x=mx-(mx-camera.x)*(next/old);camera.y=my-(my-camera.y)*(next/old);camera.scale=next;renderDiagram();}
function autoLayout(){pushUndoSnapshot();project.layout.positions={};project.layout.positionsByKey={};renderFromSource({forceLayout:true,fit:true});captureCurrentLayout(false);syncFlowgorithmVisualPositionsToSource();setDirty(true);persistLayoutSoon();}

function deleteFlowgorithmSelectionFromSource(){
    if(!project||project.diagramType!=='flowchart'||project.sourceType!=='flowgorithm'||!selectedItems.size)return false;
    const selectedNodeKeys=new Set(),selectedRawEdgeIds=new Set(),selectedTextboxIds=[];
    selectedItems.forEach(selection=>{
        const cut=selection.indexOf(':'),kind=selection.slice(0,cut),id=selection.slice(cut+1);
        if(kind==='node'){
            const node=currentModel.nodes.find(item=>item.id===id);if(node)selectedNodeKeys.add(String(node.key));
        }else if(kind==='edge'){
            const edge=currentModel.edges.find(item=>item.id===id);if(edge){if(Array.isArray(edge.originalIds))edge.originalIds.forEach(rawId=>selectedRawEdgeIds.add(rawId));else selectedRawEdgeIds.add(edge.id);}
        }else if(kind==='textbox')selectedTextboxIds.push(id);
    });
    const parsed=parseFlowgorithmShorthand(sourceEditor.value),removeLines=new Set();
    parsed.edges.forEach(edge=>{if(selectedRawEdgeIds.has(edge.id)||selectedNodeKeys.has(String(edge.fromKey))||selectedNodeKeys.has(String(edge.toKey)))removeLines.add(Number(edge.sourceLineIndex));});
    const lines=sourceEditor.value.replace(/\r/g,'').split('\n');
    const bypasses=[];
    selectedNodeKeys.forEach(key=>{
        const incoming=parsed.edges.filter(edge=>String(edge.toKey)===key&&!selectedNodeKeys.has(String(edge.fromKey))&&!selectedRawEdgeIds.has(edge.id));
        const outgoing=parsed.edges.filter(edge=>String(edge.fromKey)===key&&!selectedNodeKeys.has(String(edge.toKey))&&!selectedRawEdgeIds.has(edge.id));
        // Reconnect only linear chains; avoid guessing which IF / loop branch to preserve.
        if(incoming.length===1&&outgoing.length===1){
            const from=String(incoming[0].fromKey),to=String(outgoing[0].toKey);
            if(from!==to&&!parsed.edges.some(edge=>String(edge.fromKey)===from&&String(edge.toKey)===to))bypasses.push(`${from} -> ${to}`);
        }
    });
    lines.forEach((raw,index)=>{
        if(removeLines.has(index))return;
        const position=String(raw||'').match(/^\s*#\s*@position\s+([^\s]+)/i);if(position&&selectedNodeKeys.has(position[1])){removeLines.add(index);return;}
        const code=stripLineComment(raw).trim();if(!code||/^title(?:\s*:|\s+)/i.test(code)||parseArrowLine(code))return;
        const declaration=parseFlowgorithmDeclaration(code);if(declaration&&selectedNodeKeys.has(String(declaration.key))){removeLines.add(index);return;}
    });
    let changed=removeLines.size>0;
    selectedTextboxIds.forEach(id=>{if(project.layout.textboxes&&project.layout.textboxes[id]){delete project.layout.textboxes[id];changed=true;}});
    if(!changed)return false;
    const kept=lines.filter((line,index)=>!removeLines.has(index));
    if(bypasses.length){
        const layoutAt=flowgorithmLayoutStartIndex(kept);
        const blankCount=countBlankLinesBefore(kept,layoutAt);
        kept.splice(layoutAt-blankCount,0,...bypasses);
    }
    const next=kept.join('\n');
    sourceEditor.value=next.endsWith('\n')?next:next+'\n';project.source=sourceEditor.value;updateLineNumbers();
    selectedNodeKeys.forEach(key=>{const node=currentModel.nodes.find(item=>String(item.key)===key);if(node){delete project.layout.positions[node.id];delete project.layout.positionsByKey[nodeLayoutKey(node)];delete project.layout.nodeStyles[node.id];delete project.layout.nodeStylesByKey[nodeLayoutKey(node)];}});
    clearSelection();renderFromSource({fit:false,markDirty:false});captureCurrentLayout(false);syncFlowgorithmVisualPositionsToSource();setDirty(true);persistLayoutSoon();return true;
}

function deleteSelectedDiagramItem(){
    if(!project)return false;
    if(!selectedItems.size){if(selectedTextId)selectedItems.add(selectionKey('textbox',selectedTextId));else if(selectedEdgeId)selectedItems.add(selectionKey('edge',selectedEdgeId));else if(selectedId)selectedItems.add(selectionKey('node',selectedId));}
    if(!selectedItems.size)return false;
    pushUndoSnapshot();
    if(project.diagramType==='flowchart'&&project.sourceType==='flowgorithm')return deleteFlowgorithmSelectionFromSource();
    let changed=false;const sourceTextOrdinals=[];
    [...selectedItems].forEach(key=>{
        const cut=key.indexOf(':'),kind=key.slice(0,cut),id=key.slice(cut+1);
        if(kind==='textbox'){
            const box=project.layout.textboxes&&project.layout.textboxes[id];
            if(box&&project.diagramType==='dfd'&&box.sourceBacked&&box.sourceType==='dfd')sourceTextOrdinals.push(Number(box.sourceOrdinal));
            else if(box){delete project.layout.textboxes[id];changed=true;}
        }else if(kind==='edge'){
            const edge=currentModel.edges.find(item=>item.id===id);if(edge){project.layout.hiddenEdges=project.layout.hiddenEdges||{};project.layout.hiddenEdges[edge.id]=true;project.layout.hiddenEdges[edgeLayoutKey(edge)]=true;changed=true;}
        }else if(kind==='node'){
            const node=currentModel.nodes.find(item=>item.id===id);if(node){project.layout.hiddenNodes=project.layout.hiddenNodes||{};project.layout.hiddenNodes[node.id]=true;project.layout.hiddenNodes[nodeLayoutKey(node)]=true;delete project.layout.positions[node.id];delete project.layout.nodeStyles[node.id];changed=true;}
        }
    });
    if(sourceTextOrdinals.length&&removeDfdTextSourceOrdinals(sourceTextOrdinals))changed=true;
    clearSelection();
    if(changed){setDirty(true);renderFromSource({fit:false,markDirty:false});persistLayoutSoon();}
    return changed;
}

function selectedNodeSequence(){
    const nodes=[];
    selectedItems.forEach(key=>{
        const cut=key.indexOf(':');if(cut<0||key.slice(0,cut)!=='node')return;
        const node=currentModel.nodes.find(item=>item.id===key.slice(cut+1));if(node)nodes.push(node);
    });
    return nodes;
}
function appendConnectionsToSource(newLines){
    if(!newLines.length)return false;
    const lines=sourceEditor.value.replace(/\r/g,'').split('\n'),layoutAt=flowgorithmLayoutStartIndex(lines);
    let insertAt=layoutAt;while(insertAt>0&&lines[insertAt-1].trim()==='')insertAt--;
    /* Insert before the existing whitespace/layout block so its location and spacing stay intact. */
    const insertion=[];if(insertAt>0&&lines[insertAt-1].trim()!=='')insertion.push('');insertion.push(...newLines);
    lines.splice(insertAt,0,...insertion);
    sourceEditor.value=lines.join('\n');if(!sourceEditor.value.endsWith('\n'))sourceEditor.value+='\n';project.source=sourceEditor.value;updateLineNumbers();return true;
}
function selectedConnectionsState(){
    const nodes=selectedNodeSequence();if(nodes.length<2)return{nodes,connected:false,pairs:[]};
    const pairs=[];for(let i=0;i<nodes.length-1;i++)pairs.push([nodes[i],nodes[i+1]]);
    const connected=pairs.every(([a,b])=>currentModel.edges.some(e=>(e.from===a.id&&e.to===b.id)||(e.from===b.id&&e.to===a.id)));
    return{nodes,connected,pairs};
}
function disconnectSelectedObjects(){
    const state=selectedConnectionsState();if(state.nodes.length<2)return false;
    const pairKeys=new Set();state.pairs.forEach(([a,b])=>{pairKeys.add(a.key+'>'+b.key);pairKeys.add(b.key+'>'+a.key);});
    const lines=sourceEditor.value.replace(/\r/g,'').split('\n');let removed=0;
    const kept=lines.filter(line=>{const arrow=parseArrowLine(stripLineComment(line).trim());if(!arrow)return true;const key=arrow.from+'>'+arrow.to;if(pairKeys.has(key)){removed++;return false;}return true;});
    if(!removed){toast('The selected objects are not connected.','error');return false;}
    pushUndoSnapshot();sourceEditor.value=kept.join('\n');project.source=sourceEditor.value;updateLineNumbers();renderFromSource({fit:false,markDirty:false});setDirty(true);persistLayoutSoon();toast(removed===1?'Selected objects disconnected.':`${removed} connections removed.`,'success');return true;
}
function connectSelectedObjects(){
    const nodes=selectedNodeSequence();
    if(nodes.length<2){toast('Select at least two diagram objects to connect.','error');return false;}
    if(project.diagramType==='flowchart'&&project.sourceType==='flowgorithm'){
        pushUndoSnapshot();
        const parsed=parseFlowgorithmShorthand(sourceEditor.value),existing=new Set(parsed.edges.map(edge=>String(edge.fromKey||'')+'>'+String(edge.toKey||''))),lines=[];
        for(let i=0;i<nodes.length-1;i++){
            const from=nodes[i],to=nodes[i+1],key=String(from.key)+'>'+String(to.key);if(from.id===to.id||existing.has(key))continue;
            lines.push(`${from.key} -> ${to.key}`);existing.add(key);
        }
        if(!appendConnectionsToSource(lines)){toast('The selected objects are already connected.');return false;}
        renderFromSource({fit:false,markDirty:false});setDirty(true);persistLayoutSoon();toast(lines.length===1?'Selected objects connected.':`${lines.length} connections added.`,'success');return true;
    }
    if(project.diagramType==='dfd'&&project.sourceType==='dfd'){
        pushUndoSnapshot();
        const parsed=parseDfd(sourceEditor.value),existing=new Set(parsed.edges.map(edge=>String(edge.fromKey||edge.from)+'>'+String(edge.toKey||edge.to))),lines=[];
        for(let i=0;i<nodes.length-1;i++){
            const from=nodes[i],to=nodes[i+1],key=String(from.key)+'>'+String(to.key);if(from.id===to.id||existing.has(key))continue;
            lines.push(`${from.key} -> ${to.key}`);existing.add(key);
        }
        if(!appendConnectionsToSource(lines)){toast('The selected objects are already connected.');return false;}
        renderFromSource({fit:false,markDirty:false});setDirty(true);persistLayoutSoon();toast(lines.length===1?'Selected objects connected.':`${lines.length} connections added.`,'success');return true;
    }
    toast('Connect selected is available for Flowgorithm flowcharts and DFD shorthand diagrams.','error');return false;
}


// Flowchart executor: runs locally without executing arbitrary JavaScript source.
const runState={active:false,paused:false,token:0,variables:Object.create(null),output:[],current:null,errorNode:null,inputResolve:null,view:false,stepDelay:850};
function runValue(text){
    const src=String(text==null?'':text).trim();if(!src)return '';
    // Strings, numbers, booleans, variables and conventional expressions.
    const tokens=src.match(/(?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|\d+(?:\.\d+)?|[A-Za-z_]\w*|===|!==|==|!=|<=|>=|&&|\|\||[()+\-*\/%<>!])/g)||[];
    if(tokens.join('').replace(/\s/g,'')!==src.replace(/\s/g,''))throw Error('Unsupported expression: '+src);
    let index=0;
    const atom=()=>{const t=tokens[index++];if(t==='('){const v=or();if(tokens[index++]!==')')throw Error('Unclosed parentheses');return v;}if(t==='!')return !atom();if(t==='-')return -Number(atom());if(t==='+')return Number(atom());if(t===undefined)throw Error('Missing value');if(/^['"]/.test(t)){if(t[0]==='"')return JSON.parse(t);return t.slice(1,-1).replace(/\\'/g,"'").replace(/\\\\/g,'\\');}if(/^\d/.test(t))return Number(t);if(t==='true'||t==='True')return true;if(t==='false'||t==='False')return false;if(t==='None'||t==='null')return null;if(Object.prototype.hasOwnProperty.call(runState.variables,t))return runState.variables[t];throw Error('Variable "'+t+'" has no value');};
    const calc=(next,ops)=>{let v=next();while(ops.includes(tokens[index])){const op=tokens[index++],b=next();if(op==='+')v=v+b;else if(op==='-')v=v-b;else if(op==='*')v=v*b;else if(op==='/')v=v/b;else if(op==='%')v=v%b;else if(op==='=='||op==='===')v=v===b;else if(op==='!='||op==='!==')v=v!==b;else if(op==='<')v=v<b;else if(op==='>')v=v>b;else if(op==='<=')v=v<=b;else if(op==='>=')v=v>=b;else if(op==='&&')v=Boolean(v&&b);else if(op==='||')v=Boolean(v||b);}return v;};
    const mul=()=>calc(atom,['*','/','%']);const add=()=>calc(mul,['+','-']);const cmp=()=>calc(add,['<','>','<=','>=']);const eq=()=>calc(cmp,['==','===','!=','!==']);const and=()=>calc(eq,['&&']);const or=()=>calc(and,['||']);
    const value=or();if(index!==tokens.length)throw Error('Unsupported expression: '+src);return value;
}
function runDisplay(v){return typeof v==='string'?v:JSON.stringify(v)??String(v);}
function renderRunInspector(){
    const rows=Object.entries(runState.variables).map(([k,v])=>`<tr><td>${escapeHtml(k)}</td><td>${escapeHtml(runDisplay(v))}</td></tr>`).join('');
    inspector.innerHTML=`<div class="info-card"><h3>Program output</h3><div class="info-body"><div id="runOutput" class="run-output">${escapeHtml(runState.output.join('\n'))}</div></div></div><div class="info-card"><h3>Variables and values</h3><div class="info-body run-variables">${rows?'<table>'+rows+'</table>':'<span class="syntax-help">No variables assigned yet.</span>'}</div></div><div class="run-runtime"><strong>${escapeHtml(runState.paused?'Paused: '+(runState.current?.key||''):runState.current?'Running: '+runState.current.key:(runState.active?'Program running':'Completed'))}</strong><div class="mini-actions" style="margin-top:8px"><button id="stopRunButton" class="mini-btn" type="button">■ Stop</button><button id="pauseRunButton" class="mini-btn" type="button" ${!runState.active?'disabled':''}>${runState.paused?'▶ Resume':'Ⅱ Pause'}</button><button id="clearRunButton" class="mini-btn" type="button">Clear</button></div></div>`;
    const out=el('runOutput');if(out)out.scrollTop=out.scrollHeight;el('stopRunButton').onclick=stopProgramRun;el('pauseRunButton').onclick=togglePauseProgramRun;el('clearRunButton').onclick=clearProgramRun;
}
function togglePauseProgramRun(){
    if(!runState.active)return;
    runState.paused=!runState.paused;
    renderRunInspector();
}
function waitWhileRunPaused(token){
    return new Promise(resolve=>{
        const check=()=>{if(!runState.active||runState.token!==token||!runState.paused){resolve();return;}setTimeout(check,75);};
        check();
    });
}
function clearProgramRun(){
    stopProgramRun();
    runState.variables=Object.create(null);runState.output=[];runState.errorNode=null;
    renderDiagram();
}
function applyRunNodeGlow(node,error=false){
    const element=nodesLayer.querySelector(`[data-node-id="${CSS.escape(node.id)}"]`);
    if(element)element.classList.add(error?'run-error':'run-active');
    nodesLayer.querySelectorAll('g').forEach(group=>{if(group.getAttribute('data-id')===node.id)group.classList.add(error?'run-error':'run-active');});
}
function stopProgramRun(){
    runState.active=false;runState.paused=false;runState.view=false;runState.token++;runState.current=null;runState.errorNode=null;
    if(runState.inputResolve){const resolve=runState.inputResolve;runState.inputResolve=null;resolve(null);closeModal();}
    const btn=el('runProgramBtn');if(btn){btn.classList.remove('running');btn.innerHTML='▶ <span class="tool-label">Run</span>';}
    renderDiagram();
}
function runCenterNode(node){
    const pos=currentPositions.get(node.id);if(!pos)return;
    const rect=svg.getBoundingClientRect();camera.x=rect.width/2-pos.x*camera.scale;camera.y=rect.height/2-pos.y*camera.scale;
    renderDiagram();
    applyRunNodeGlow(node);
}
function runDelay(ms){return new Promise(resolve=>setTimeout(resolve,ms));}
function runAsk(question){
    return new Promise(resolve=>{
        const root=showModal('Program input',`<div class="field"><label for="runInputField">${escapeHtml(question||'Enter a value')}</label><input id="runInputField" autocomplete="off" style="width:100%"></div>`,`<button data-run-cancel>Cancel run</button><button class="primary" data-run-submit>Continue</button>`,true);
        runState.inputResolve=resolve;
        const field=el('runInputField');
        const finish=(value)=>{if(runState.inputResolve!==resolve)return;runState.inputResolve=null;closeModal();resolve(value);};
        root.querySelector('[data-run-cancel]').onclick=()=>finish(null);
        root.querySelector('[data-run-submit]').onclick=()=>finish(field.value);
        field.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();finish(field.value);}});
        field.focus();
    });
}
function runExecuteStatement(node){
    const type=String(node.flowKind||'').toLowerCase(),value=String(node.sourceValue||'').trim();
    if(type==='declare'){runState.variables[node.key]=value?runValue(value.replace(/^=\s*/,'')):undefined;return;}
    if(type==='assign'){
        const m=value.match(/^([A-Za-z_]\w*)\s*(=|\+=|-=|\*=|\/=)\s*(.+)$/)||value.match(/^([A-Za-z_]\w*)\s+(.+)$/)?.map((v,i)=>i===2?'=':i===3?v:v);
        if(!m)throw Error('Invalid assignment: '+value);
        const v=runValue(m[3]),previous=runState.variables[m[1]];
        runState.variables[m[1]]=m[2]==='='?v:m[2]==='+='?previous+v:m[2]==='-='?previous-v:m[2]==='*='?previous*v:previous/v;
        return;
    }
    if(type==='output'){const result=value?runValue(value):(node.hasExplicitCaption?node.sourceCaption:node.key);runState.output.push(runDisplay(result));return;}
    if(type==='call'||type==='function')throw Error('Function calls cannot be executed in the visual runner.');
}
async function toggleProgramRun(){
    if(runState.active){stopProgramRun();return;}
    // Consume a queued source edit before starting so its delayed render cannot cancel execution.
    if(renderTimer){clearTimeout(renderTimer);renderTimer=null;}
    if(project.diagramType!=='flowchart'||project.sourceType!=='flowgorithm'){toast('Run currently supports Flowgorithm shorthand flowcharts.','error');return;}
    renderFromSource({fit:false,markDirty:false});
    const nodes=currentModel.nodes,edges=currentModel.edges;
    const incoming=new Map(nodes.map(n=>[n.id,0]));for(const e of edges)incoming.set(e.to,(incoming.get(e.to)||0)+1);
    let node=nodes.find(n=>/^start\d*$/i.test(n.key))||nodes.find(n=>n.flowKind==='terminal'&&!incoming.get(n.id))||nodes.find(n=>!incoming.get(n.id));
    if(!node){toast('No starting control found.','error');return;}
    runState.active=true;runState.paused=false;runState.view=true;runState.token++;runState.variables=Object.create(null);runState.output=[];runState.current=null;runState.errorNode=null;
    const token=runState.token,button=el('runProgramBtn');button.classList.add('running');button.innerHTML='■ <span class="tool-label">Stop</span>';
    let steps=0;const forCounters=new Map();
    try{
        while(runState.active&&runState.token===token&&node){
            if(++steps>1000)throw Error('Stopped after 1,000 steps. Check the diagram for an infinite loop.');
            runState.current=node;runState.errorNode=null;runCenterNode(node);renderRunInspector();await waitWhileRunPaused(token);if(!runState.active||runState.token!==token)break;await runDelay(runState.stepDelay);await waitWhileRunPaused(token);
            if(!runState.active||runState.token!==token)break;
            const kind=String(node.flowKind||'').toLowerCase(),outs=edges.filter(e=>e.from===node.id);
            if(kind==='stop'||/^end\d*$/i.test(node.key))break;
            if(kind==='input'){
                const target=String(node.sourceValue||'value').trim().split(/\s+/)[0];
                const supplied=await runAsk(node.sourceCaption||'Enter '+target);
                if(supplied===null){stopProgramRun();return;}
                runState.variables[target]=supplied;
            }else if(kind==='if'||kind==='while'||kind==='for'){
                let passed;
                if(kind==='for'){
                    const spec=forSpecParts(node.sourceValue),iterator=spec.iterator||'i',start=Number(runValue(spec.start)),end=Number(runValue(spec.end)),increment=Number(runValue(spec.increment));
                    if(!Number.isFinite(increment)||increment===0)throw Error('FOR increment must be nonzero.');
                    if(!forCounters.has(node.id))forCounters.set(node.id,start);
                    const n=forCounters.get(node.id);runState.variables[iterator]=n;passed=increment>0?n<=end:n>=end;
                    if(passed)forCounters.set(node.id,n+increment);else forCounters.delete(node.id);
                }else passed=Boolean(runValue(node.sourceValue));
                node=nodes.find(n=>n.id===(outs[passed?0:1]||{}).to)||null;
                renderRunInspector();continue;
            }else runExecuteStatement(node);
            renderRunInspector();node=nodes.find(n=>n.id===(outs[0]||{}).to)||null;
        }
        if(runState.token===token&&runState.active){runState.active=false;runState.paused=false;runState.current=null;button.classList.remove('running');button.innerHTML='▶ <span class="tool-label">Run</span>';renderDiagram();}
    }catch(error){if(runState.token===token){runState.output.push('ERROR: '+error.message);runState.errorNode=runState.current;renderRunInspector();toast(error.message,'error');runState.active=false;runState.paused=false;button.classList.remove('running');button.innerHTML='▶ <span class="tool-label">Run</span>';renderDiagram();if(runState.errorNode)applyRunNodeGlow(runState.errorNode,true);}}
}

function renderInspector(){
    if(runState.active||runState.view){renderRunInspector();return;}
    if(selectedItems.size>1){
        const counts={node:0,edge:0,textbox:0};selectedItems.forEach(key=>{const kind=key.slice(0,key.indexOf(':'));if(Object.prototype.hasOwnProperty.call(counts,kind))counts[kind]++;});const connectionState=selectedConnectionsState(),connectionAction=connectionState.connected?'disconnect-selected':'connect-selected',connectionLabel=connectionState.connected?'Disconnect selected':'Connect selected';
        inspector.innerHTML=`<div class="info-card"><h3>Multiple selection</h3><div class="info-body"><p class="syntax-help"><strong>${selectedItems.size} items selected</strong><br>${counts.node} objects · ${counts.edge} connections · ${counts.textbox} textboxes</p><div class="mini-actions"><button class="mini-btn" data-inspector-action="${connectionAction}" ${counts.node<2?'disabled title="Select at least two objects"':''}>${connectionLabel}</button><button class="mini-btn" data-inspector-action="delete-selected">Delete selected</button><button class="mini-btn" data-inspector-action="clear-selection">Clear selection</button></div></div></div><div class="info-card"><h3>Selection</h3><div class="info-body syntax-help">Hold <strong>Shift</strong> while clicking objects, textboxes or connections to add or remove them from the selection. <strong>${connectionLabel}</strong> updates the connections between the selected objects in selection order and updates Diagram Source. Drag any selected object to move all selected movable objects together.</div></div>`;
        refreshRecentColourBlocks();
    inspector.querySelectorAll('[data-inspector-action]').forEach(button=>button.onclick=()=>{const action=button.getAttribute('data-inspector-action');if(action==='connect-selected')connectSelectedObjects();if(action==='disconnect-selected')disconnectSelectedObjects();if(action==='delete-selected')deleteSelectedDiagramItem();if(action==='clear-selection'){clearSelection();renderDiagram();}});return;
    }
    const textBox=selectedTextId&&project.layout.textboxes?project.layout.textboxes[selectedTextId]:null;
    if(textBox){
        const box=normaliseFloatingTextbox(textBox);project.layout.textboxes[box.id]=box;
        inspector.innerHTML=`<div class="info-card"><h3>Floating textbox</h3><div class="info-body"><div class="field"><label>Text</label><textarea id="floatingText" rows="3">${escapeHtml(box.text)}</textarea></div><div class="field"><label>X</label><input id="floatingX" type="number" value="${Math.round(box.x)}"></div><div class="field"><label>Y</label><input id="floatingY" type="number" value="${Math.round(box.y)}"></div></div></div><div class="info-card"><h3>Text formatting</h3><div class="info-body"><div class="field"><label>Font size</label><input id="floatingSize" type="number" min="8" max="72" value="${box.fontSize}"></div><div class="field"><label>Colour</label><input id="floatingColor" type="color" value="${escapeAttr(box.color)}"></div>${recentColoursBlockHtml()}<div class="format-checks"><label><input id="floatingBold" type="checkbox" ${box.bold?'checked':''}> Bold</label><label><input id="floatingItalic" type="checkbox" ${box.italic?'checked':''}> Italic</label><label><input id="floatingUnderline" type="checkbox" ${box.underline?'checked':''}> Underline</label></div><div class="mini-actions" style="margin-top:9px"><button class="mini-btn" data-inspector-action="delete-selected">Delete object</button></div></div></div>`;
        let snap=null,used=false;const apply=()=>{if(!used){pushUndoSnapshot(snap||makeUndoSnapshot());used=true;}const target=project.layout.textboxes[selectedTextId];if(!target)return;target.text=el('floatingText').value;target.x=Number(el('floatingX').value)||0;target.y=Number(el('floatingY').value)||0;target.fontSize=Math.min(72,Math.max(8,Number(el('floatingSize').value)||18));target.color=el('floatingColor').value;target.bold=el('floatingBold').checked;target.italic=el('floatingItalic').checked;target.underline=el('floatingUnderline').checked;if(project.diagramType==='dfd'&&target.sourceBacked&&target.sourceType==='dfd')replaceDfdTextSourceLine(target.sourceOrdinal,target.text);setDirty(true);renderDiagram(false);};
        ['floatingText','floatingX','floatingY','floatingSize','floatingColor','floatingBold','floatingItalic','floatingUnderline'].forEach(id=>{const input=el(id);if(input){input.onfocus=()=>{snap=makeUndoSnapshot();used=false;};input.oninput=apply;input.onchange=()=>{apply();if(id==='floatingColor')rememberRecentColour(input.value);persistLayoutSoon();snap=null;used=false;};}});
    }else{
        const edge=currentModel.edges.find(e=>e.id===selectedEdgeId);
        if(edge){
            const from=currentModel.nodes.find(n=>n.id===edge.from),to=currentModel.nodes.find(n=>n.id===edge.to),layout=getEdgeLayout(edge);
            const captionBlock=(index,caption,offset,style)=>`<div class="info-card"><h3>Caption ${index}</h3><div class="info-body"><div class="field"><label>Caption</label><input id="edgeCaption${index}" value="${escapeAttr(caption)}" ${index===2?'placeholder="Optional second caption"':''}></div><div class="field"><label>X offset</label><input id="edgeCaption${index}X" type="number" value="${Math.round(offset.x)}"></div><div class="field"><label>Y offset</label><input id="edgeCaption${index}Y" type="number" value="${Math.round(offset.y)}"></div><div class="field"><label>Font size</label><input id="edgeCaption${index}Size" type="number" min="8" max="48" value="${style.fontSize}"></div><div class="field"><label>Colour</label><input id="edgeCaption${index}Color" type="color" value="${escapeAttr(style.color)}"></div>${recentColoursBlockHtml()}<div class="format-checks"><label><input id="edgeCaption${index}Bold" type="checkbox" ${style.bold?'checked':''}> Bold</label><label><input id="edgeCaption${index}Italic" type="checkbox" ${style.italic?'checked':''}> Italic</label><label><input id="edgeCaption${index}Underline" type="checkbox" ${style.underline?'checked':''}> Underline</label></div>${index===2?'<div class="mini-actions" style="margin-top:9px"><button class="mini-btn" data-inspector-action="reset-link">Reset captions</button></div>':''}</div></div>`;
            inspector.innerHTML=`<div class="info-card"><h3>Selected connection</h3><div class="info-body"><div class="field"><label>From</label><input value="${escapeAttr(from?from.label:edge.from)}" readonly></div><div class="field"><label>To</label><input value="${escapeAttr(to?to.label:edge.to)}" readonly></div></div></div>${captionBlock(1,layout.caption1,layout.offset1,layout.style1)}${captionBlock(2,layout.caption2,layout.offset2,layout.style2)}<div class="info-card"><h3>Connection settings</h3><div class="info-body syntax-help">Caption text, offsets and formatting are stored with the diagram layout and included in browser autosave and shared diagrams.<div class="mini-actions" style="margin-top:9px"><button class="mini-btn" data-inspector-action="delete-selected">Delete connection</button></div></div></div>`;
            let edgeUndoSnapshot=null,edgeUndoUsed=false;
            const styleFor=i=>({fontSize:Number(el(`edgeCaption${i}Size`).value)||11,color:el(`edgeCaption${i}Color`).value,bold:el(`edgeCaption${i}Bold`).checked,italic:el(`edgeCaption${i}Italic`).checked,underline:el(`edgeCaption${i}Underline`).checked});
            const apply=()=>{if(!edgeUndoUsed){pushUndoSnapshot(edgeUndoSnapshot||makeUndoSnapshot());edgeUndoUsed=true;}const settings={caption1:el('edgeCaption1').value,caption2:el('edgeCaption2').value,offset1:{x:Number(el('edgeCaption1X').value)||0,y:Number(el('edgeCaption1Y').value)||0},offset2:{x:Number(el('edgeCaption2X').value)||0,y:Number(el('edgeCaption2Y').value)||0},style1:styleFor(1),style2:styleFor(2)};saveEdgeLayout(edge.id,settings);setDirty(true);renderDiagram(false);};
            ['edgeCaption1','edgeCaption2','edgeCaption1X','edgeCaption1Y','edgeCaption2X','edgeCaption2Y','edgeCaption1Size','edgeCaption2Size','edgeCaption1Color','edgeCaption2Color','edgeCaption1Bold','edgeCaption2Bold','edgeCaption1Italic','edgeCaption2Italic','edgeCaption1Underline','edgeCaption2Underline'].forEach(id=>{const input=el(id);if(input){input.onfocus=()=>{edgeUndoSnapshot=makeUndoSnapshot();edgeUndoUsed=false;};input.oninput=apply;input.onchange=()=>{apply();if(id==='edgeCaption1'&&writeFlowgorithmEdgeCaption(edge,input.value)){const lineIndex=edge.sourceLineIndex;renderFromSource({fit:false,markDirty:false});const updated=currentModel.edges.find(e=>e.sourceLineIndex===lineIndex&&e.fromKey===edge.fromKey&&e.toKey===edge.toKey);if(updated){if(project.layout.links[edge.id]){project.layout.links[updated.id]=project.layout.links[edge.id];delete project.layout.links[edge.id];}selectedItems.delete('edge:'+edge.id);selectedItems.add('edge:'+updated.id);renderDiagram();}}if(id.endsWith('Color'))rememberRecentColour(input.value);persistLayoutSoon();edgeUndoSnapshot=null;edgeUndoUsed=false;};}});
        }else{
            const node=currentModel.nodes.find(n=>n.id===selectedId);
            if(!node){inspector.innerHTML=`<div class="info-card"><h3>Diagram</h3><div class="info-body syntax-help"><strong>${escapeHtml(project.diagramType.toUpperCase())}</strong><br>${escapeHtml(sourceDescription())}</div></div><div class="info-card"><h3>Canvas</h3><div class="info-body"><div class="mini-actions"><button class="mini-btn" data-inspector-action="fit">Fit</button><button class="mini-btn" data-inspector-action="layout">Auto-layout</button></div></div></div><div class="info-card"><h3>Connections</h3><div class="info-body syntax-help">Click any connector line or its caption to edit up to two captions, offsets and font formatting. Hold Shift while clicking to select multiple items. Double-click empty canvas space to add a floating textbox.</div></div>`;}
            else{
                const p=currentPositions.get(node.id)||{x:0,y:0},extra=node.shape==='table'?`${node.fields.length} fields`:`${node.shape}`,visual=getNodeVisualStyle(node),colourCard=project.diagramType==='dfd'?`<div class="info-card"><h3>DFD object appearance</h3><div class="info-body"><div class="field"><label>Fill colour</label><input id="nodeFillColor" type="color" value="${escapeAttr(visual.fill)}"></div>${recentColoursBlockHtml()}<div class="mini-actions" style="margin-top:8px"><button class="mini-btn" data-inspector-action="reset-node-colour">Reset colour</button></div></div></div>`:'';
                const isFg=project.diagramType==='flowchart'&&project.sourceType==='flowgorithm',nameValue=isFg?node.key:node.label,valueField=isFg?(node.flowKind==='assign'?`<div class="field"><label>Variable</label><input id="assignVariable" value="${escapeAttr(flowAssignParts(node.sourceValue).variable)}"></div><div class="field"><label>Expression</label><input id="assignExpression" value="${escapeAttr(flowAssignParts(node.sourceValue).expression)}"></div>`:`<div class="field"><label>${node.flowKind==='declare'?'Value':(['if','while','for'].includes(node.flowKind)?'Condition / parameters':'Value / expression')}</label><input id="nodeValue" value="${escapeAttr(node.sourceValue||'')}" placeholder="${node.flowKind==='declare'?'Optional initial value':'Optional'}"></div>`):'',captionField=isFg?`<div class="field"><label>Caption</label><input id="nodeCaption" value="${node.hasExplicitCaption?escapeAttr(node.sourceCaption):''}" placeholder="${escapeAttr(flowgorithmDefaultCaption(node))}" class="${node.hasExplicitCaption?'':'default-caption'}"></div>`:'',fgType=(node.flowKind==='call'?'function':(node.flowKind==='start'||node.flowKind==='end'?'terminal':node.flowKind)),typeField=isFg?`<div class="field"><label>Type</label><select id="nodeType">${['terminal','if','stop','input','output','declare','assign','function','while','for'].map(type=>`<option value="${type}" ${type===fgType?'selected':''}>${type}</option>`).join('')}</select></div>`:`<div class="field"><label>Type</label><input value="${escapeAttr(extra)}" readonly></div>`;inspector.innerHTML=`<div class="info-card"><h3>Selected object</h3><div class="info-body"><div class="field"><label>Name</label><input id="nodeName" value="${escapeAttr(nameValue)}" ${(project.diagramType==='dfd'||isFg)?'':'readonly'}></div>${valueField}${captionField}${typeField}<div class="field"><label>X</label><input id="nodeX" type="number" value="${Math.round(p.x)}"></div><div class="field"><label>Y</label><input id="nodeY" type="number" value="${Math.round(p.y)}"></div><div class="mini-actions"><button class="mini-btn" data-inspector-action="reset-node">Reset position</button><button class="mini-btn" data-inspector-action="delete-selected">Delete object</button></div></div></div>${colourCard}<div class="info-card"><h3>Source-controlled</h3><div class="info-body syntax-help">${project.diagramType==='dfd'?'Change the object name here or edit the DFD shorthand. Position and colour are stored with the diagram layout.':isFg?'Name is the source identifier. Caption is the text shown inside the control; leave Caption blank to use the grey default shown in the field.':'Names, shapes, fields and connections are controlled by the text in the left panel.'}</div></div>`;
            }
        }
    }
    inspector.querySelectorAll('[data-inspector-action]').forEach(button=>button.onclick=()=>{const action=button.getAttribute('data-inspector-action');if(action==='fit')fitDiagram();if(action==='layout')autoLayout();if(action==='delete-selected')deleteSelectedDiagramItem();if(action==='clear-selection'){clearSelection();renderDiagram();}if(action==='reset-node'&&selectedId){pushUndoSnapshot();const node=currentModel.nodes.find(n=>n.id===selectedId);delete project.layout.positions[selectedId];if(node)delete project.layout.positionsByKey[nodeLayoutKey(node)];renderFromSource({fit:false});setDirty(true);persistLayoutSoon();}if(action==='reset-node-colour'&&selectedId){pushUndoSnapshot();const node=currentModel.nodes.find(n=>n.id===selectedId);delete project.layout.nodeStyles[selectedId];if(node)delete project.layout.nodeStylesByKey[nodeLayoutKey(node)];setDirty(true);renderDiagram();persistLayoutSoon();}if(action==='reset-link'&&selectedEdgeId){pushUndoSnapshot();delete project.layout.links[selectedEdgeId];setDirty(true);renderDiagram();persistLayoutSoon();}});
    const nodeName=el('nodeName'),nodeCaption=el('nodeCaption'),nodeValue=el('nodeValue'),nodeType=el('nodeType');
    const assignVariable=el('assignVariable'),assignExpression=el('assignExpression');
    const fieldOrder=['nodeName',...(assignVariable?['assignVariable','assignExpression']:(nodeValue?['nodeValue']:[])),...(nodeCaption?['nodeCaption']:[]),...(nodeType?['nodeType']:[])];
    const fieldValue=()=>assignVariable?(assignVariable.value.trim()+(assignExpression&&assignExpression.value.trim()?' = '+assignExpression.value.trim():'')):(nodeValue?nodeValue.value:null);
    function saveInspectorFlowField(fieldId,advance){
        const node=currentModel.nodes.find(n=>n.id===selectedId);if(!node)return;
        const destination=advance?fieldOrder[fieldOrder.indexOf(fieldId)+1]:null;
        const nextName=nodeName?nodeName.value:node.key,nextCaption=nodeCaption?nodeCaption.value:'',nextValue=fieldValue();
        if(fieldId==='nodeType'){
            if(nodeType.value!==(node.flowKind==='call'?'function':node.flowKind)){pushUndoSnapshot();updateFlowgorithmNodeType(node,nodeType.value);}
        }else if(nextName!==node.key||nextCaption!==(node.hasExplicitCaption?node.sourceCaption:'')||String(nextValue??'')!==String(node.sourceValue||'')){
            pushUndoSnapshot();updateFlowgorithmNodeFields(node,nextName,nextCaption,nextValue);
        }
        if(destination)requestAnimationFrame(()=>{const target=el(destination);if(target){target.focus();if(typeof target.select==='function')target.select();}});
    }
    if(project.diagramType==='flowchart'&&project.sourceType==='flowgorithm'){
        [nodeName,assignVariable,assignExpression,nodeValue,nodeCaption,nodeType].filter(Boolean).forEach(input=>{
            let completed=false;
            input.addEventListener('focus',()=>{completed=false;});
            input.addEventListener('keydown',event=>{
                if(event.key==='Enter'||event.key==='Tab'){
                    const idx=fieldOrder.indexOf(input.id);
                    // Commit before the inspector rerenders, then focus the next field.
                    event.preventDefault();completed=true;
                    saveInspectorFlowField(input.id,true);
                    if(idx===fieldOrder.length-1)requestAnimationFrame(()=>el('nodeName')?.focus());
                }
            });
            input.addEventListener('blur',()=>{if(!completed){completed=true;saveInspectorFlowField(input.id,false);}});
        });
    }else if(nodeName&&selectedId){nodeName.addEventListener('change',()=>{const node=currentModel.nodes.find(n=>n.id===selectedId);if(node&&project.diagramType==='dfd')updateDfdObjectLabel(node,nodeName.value);});}
    const x=el('nodeX'),y=el('nodeY');if(x&&y){let nodeUndoSnapshot=null,nodeUndoUsed=false;const apply=()=>{if(!nodeUndoUsed){pushUndoSnapshot(nodeUndoSnapshot||makeUndoSnapshot());nodeUndoUsed=true;}const p={x:Number(x.value)||0,y:Number(y.value)||0};currentPositions.set(selectedId,p);project.layout.positions[selectedId]=p;setDirty(true);renderDiagram(false);};[x,y].forEach(input=>{input.onfocus=()=>{nodeUndoSnapshot=makeUndoSnapshot();nodeUndoUsed=false;};input.oninput=apply;input.onchange=()=>{apply();syncFlowgorithmVisualPositionsToSource();persistLayoutSoon();nodeUndoSnapshot=null;nodeUndoUsed=false;};});}
    const fill=el('nodeFillColor');if(fill&&selectedId){let colourSnapshot=null,colourUsed=false;const applyColour=()=>{const node=currentModel.nodes.find(n=>n.id===selectedId);if(!node)return;if(!colourUsed){pushUndoSnapshot(colourSnapshot||makeUndoSnapshot());colourUsed=true;}saveNodeVisualStyle(node,{fill:fill.value});setDirty(true);renderDiagram(false);};fill.onfocus=()=>{colourSnapshot=makeUndoSnapshot();colourUsed=false;};fill.oninput=applyColour;fill.onchange=()=>{applyColour();rememberRecentColour(fill.value);persistLayoutSoon();colourSnapshot=null;colourUsed=false;};inspector.querySelectorAll('[data-recent-colour]').forEach(button=>button.onclick=()=>{fill.value=button.getAttribute('data-recent-colour');fill.dispatchEvent(new Event('input',{bubbles:true}));fill.dispatchEvent(new Event('change',{bubbles:true}));});}
}

function closeMenus(){document.querySelectorAll('.menu.open').forEach(m=>m.classList.remove('open'));}
document.querySelectorAll('.menu>button').forEach(button=>button.onclick=event=>{event.stopPropagation();const menu=button.parentElement,was=menu.classList.contains('open');closeMenus();if(!was)menu.classList.add('open');});document.addEventListener('click',closeMenus);
function closeModal(){el('modalRoot').innerHTML='';if(runState.inputResolve){const resolve=runState.inputResolve;runState.inputResolve=null;resolve(null);}}
function showModal(title,body,footer='',small=false){const root=el('modalRoot');root.innerHTML=`<div class="modal-backdrop"><section class="modal${small?' small':''}"><header class="modal-head"><h2>${escapeHtml(title)}</h2><button class="modal-close" type="button">×</button></header><div class="modal-body">${body}</div><footer class="modal-foot">${footer}</footer></section></div>`;root.querySelector('.modal-close').onclick=closeModal;root.querySelector('.modal-backdrop').addEventListener('mousedown',event=>{if(event.target.classList.contains('modal-backdrop'))closeModal();});return root;}
function confirmDiscard(callback){if(!dirty||confirm('Discard unsaved changes?'))callback();}
function openTemplates(){confirmDiscard(()=>window.location.href=window.location.pathname);}

const CONTROL_DEFINITIONS={
    flowchart:[
        {type:'terminal',label:'Terminal',icon:'terminator',group:'Control',hint:'Terminal'},
        {type:'stop',label:'Stop',icon:'stop-sign',group:'Control',hint:'Stop'},
        {type:'input',label:'Input',icon:'fg-input',group:'Input / Output',hint:'Read a value'},
        {type:'output',label:'Output',icon:'fg-output',group:'Input / Output',hint:'Display a value'},
        {type:'declare',label:'Declare',icon:'fg-declare',group:'Variables',hint:'Create a variable'},
        {type:'assign',label:'Assign',icon:'fg-assign',group:'Variables',hint:'Set or calculate a value'},
        {type:'if',label:'If',icon:'fg-if',group:'Control',hint:'Selection / decision'},
        {type:'call',label:'Function',icon:'fg-call',group:'Control',hint:'Run a function'},
        {type:'while',label:'While',icon:'fg-loop',group:'Control',hint:'Pre-test loop'},
        {type:'for',label:'For',icon:'fg-for',group:'Control',hint:'Counted loop: iterator, start, end and increment'},
    ],
    dfd:[
        {type:'dfd-process',label:'Process',icon:'dfd-process',hint:'Transforms data'},
        {type:'entity',label:'External entity',icon:'entity',hint:'Source or destination'},
        {type:'store-digital',label:'Digital store',icon:'store',kind:'D',hint:'Permanent computer file'},
        {type:'store-manual',label:'Manual store',icon:'store',kind:'M',hint:'Manual file'},
        {type:'store-temporary',label:'Temporary store',icon:'store',kind:'T',hint:'Deleted after processing'},
        {type:'textbox',label:'Text box',icon:'textbox',hint:'Floating annotation'}
    ],
    erd:[
        {type:'table',label:'Entity / table',icon:'table',hint:'Database entity'},
        {type:'textbox',label:'Text box',icon:'textbox',hint:'Floating annotation'}
    ]
};
function controlsForCurrentDiagram(){return CONTROL_DEFINITIONS[project&&project.diagramType]||CONTROL_DEFINITIONS.flowchart;}
function controlIconHtml(control,showLabelInside=false){
    const cls=escapeAttr(control.icon),extra=control.kind?` data-kind="${escapeAttr(control.kind)}"`:'';
    if(control.icon==='fg-if')return `<svg class="control-icon ${cls}" viewBox="0 0 78 46" aria-hidden="true"><polygon points="39,2 76,23 39,44 2,23" fill="#ffd0d0" stroke="#a07070" stroke-width="3" stroke-linejoin="round"></polygon><text x="39" y="23" dominant-baseline="middle">?</text></svg>`;
    if(control.icon==='fg-loop')return `<svg class="control-icon ${cls}" viewBox="0 0 78 40" aria-hidden="true"><polygon points="12,2 66,2 76,20 66,38 12,38 2,20" fill="#ffe0a0" stroke="#a08040" stroke-width="3" stroke-linejoin="round"></polygon><text x="39" y="20" dominant-baseline="middle">↻</text></svg>`;
    if(control.icon==='fg-for')return `<svg class="control-icon ${cls}" viewBox="0 0 78 40" aria-hidden="true"><polygon points="12,2 66,2 76,20 66,38 12,38 2,20" fill="#ffe0a0" stroke="#a08040" stroke-width="3" stroke-linejoin="round"></polygon><text x="39" y="20" dominant-baseline="middle">i</text></svg>`;
    if(control.icon==='stop-sign')return `<svg class="control-icon ${cls}" viewBox="0 0 46 46" aria-hidden="true"><polygon points="14,2 32,2 44,14 44,32 32,44 14,44 2,32 2,14" fill="#ffd7d3" stroke="#8b2b25" stroke-width="3" stroke-linejoin="round"></polygon></svg>`;
    const inner=control.icon==='textbox'?'T':control.icon==='fg-call'?'<span>ƒ</span>':control.icon==='fg-comment'?'<span>//</span>':'';return `<span class="control-icon ${cls}"${extra}>${inner}</span>`;
}
function renderControlPalette(){
    const palette=el('controlPalette');if(!palette||!project)return;
    const controls=controlsForCurrentDiagram();
    if(project.diagramType==='flowchart'&&project.sourceType==='flowgorithm'){
        const groups=['Input / Output','Variables','Control'];
        palette.className='control-palette flowgorithm-palette';
        palette.innerHTML=`<div class="flowgorithm-groups">${groups.map(group=>`<section class="flow-control-group"><div class="flow-control-group-title">${escapeHtml(group)}</div>${controls.filter(c=>c.group===group).map(control=>`<button type="button" class="control-item" draggable="true" data-control-type="${escapeAttr(control.type)}" title="${control.type==='terminal'||control.type==='stop'?'Click or drag to add a terminal':'Click to add before End or drag onto the canvas'}">${controlIconHtml(control)}<span class="control-label"><strong>${escapeHtml(control.label)}</strong></span></button>`).join('')}</section>`).join('')}</div>`;
    }else{
        palette.className='control-palette';
        palette.innerHTML=controls.map(control=>`<button type="button" class="control-item" draggable="true" data-control-type="${escapeAttr(control.type)}" title="Click to add at the centre or drag onto the canvas">${controlIconHtml(control)}<span class="control-label"><strong>${escapeHtml(control.label)}</strong><small>${escapeHtml(control.hint)}</small></span></button>`).join('');
    }
    const hint=el('canvasHint');if(hint)hint.textContent=project.diagramType==='flowchart'&&project.sourceType==='flowgorithm'?'Double-click a connector line to insert a Flowgorithm control · Terminal and Stop are available in Controls · moving controls updates Diagram Source positions · Shift-click to multi-select':'Edit the notation on the left · drag nodes to adjust positions · double-click empty canvas to add text · drag the background to pan';
    palette.querySelectorAll('[data-control-type]').forEach(button=>{
        button.addEventListener('click',()=>addControlAtCanvasCentre(button.getAttribute('data-control-type')));
        button.addEventListener('dragstart',event=>{event.dataTransfer.setData('application/x-charter-control',button.getAttribute('data-control-type'));event.dataTransfer.effectAllowed='copy';});
    });
}
function nextObjectKey(prefix){const existing=new Set((currentModel.nodes||[]).map(n=>String(n.key||n.label||'').toLowerCase()));let i=1,key;do{key=prefix+i++;}while(existing.has(key.toLowerCase())||sourceEditor.value.toLowerCase().includes(key.toLowerCase()));return key;}
function nextStoreKey(letter){let i=1,key;const source=sourceEditor.value.toLowerCase();do{key=letter+i++;}while(source.includes(key.toLowerCase()));return key;}
function appendSourceBlock(block){const base=sourceEditor.value.replace(/\s+$/,'');sourceEditor.value=base+(base?'\n\n':'')+block+'\n';project.source=sourceEditor.value;updateLineNumbers();}
function flowgorithmPrefixForType(type){return{start:'Terminal',terminal:'Terminal',end:'Stop',stop:'Stop',input:'Input',output:'Output',declare:'Declare',assign:'Assign',if:'If',call:'Function',while:'While',for:'For',do:'Do'}[type]||'Assign';}
function nextFlowgorithmKey(type){const prefix=type==='declare'?'NewVariable':flowgorithmPrefixForType(type),existing=new Set((currentModel.nodes||[]).map(n=>String(n.key||'').toLowerCase()));if(type==='start'||type==='terminal'||type==='end'||type==='stop'){if(!existing.has(prefix.toLowerCase())&&!new RegExp('\\b'+prefix+'\\b','i').test(sourceEditor.value))return prefix;let i=2,key;do{key=prefix+i++;}while(existing.has(key.toLowerCase())||new RegExp('\\b'+key+'\\b','i').test(sourceEditor.value));return key;}let i=1,key;do{key=prefix+i++;}while(existing.has(key.toLowerCase())||new RegExp('\\b'+key.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'\\b','i').test(sourceEditor.value));return key;}
function flowgorithmLayoutStartIndex(lines){
    const index=lines.findIndex(line=>/^\s*#\s*@visual-layout\b/i.test(String(line||'')));
    return index<0?lines.length:index;
}
function countBlankLinesBefore(lines,index){let count=0;for(let i=index-1;i>=0&&String(lines[i]||'').trim()==='';i--)count++;return count;}
function insertFlowgorithmDeclarationIntoLines(lines,declaration){
    const layoutAt=flowgorithmLayoutStartIndex(lines);
    let firstArrow=-1;
    for(let i=0;i<layoutAt;i++){const code=stripLineComment(lines[i]).trim();if(code&&parseArrowLine(code)){firstArrow=i;break;}}
    /* Keep the visual-layout marker and the exact whitespace immediately above it untouched.
       Declarations remain with declarations, immediately before the first connection. */
    const insertAt=firstArrow>=0?firstArrow:Math.max(0,layoutAt-countBlankLinesBefore(lines,layoutAt));
    const addGap=insertAt<lines.length&&lines[insertAt].trim()!=='';
    lines.splice(insertAt,0,declaration,...(addGap?['']:[]));
}
function setFlowgorithmSourceLines(lines){
    sourceEditor.value=lines.join('\n');
    if(!sourceEditor.value.endsWith('\n'))sourceEditor.value+='\n';
    project.source=sourceEditor.value;updateLineNumbers();stripFixedVerticalLayoutSource();
}
function appendFlowgorithmDeclaration(declaration){
    const lines=sourceEditor.value.replace(/\r/g,'').split('\n');insertFlowgorithmDeclarationIntoLines(lines,declaration);setFlowgorithmSourceLines(lines);
}
function preferredFlowgorithmEdge(){
    if(selectedEdgeId){const selected=currentModel.edges.find(edge=>edge.id===selectedEdgeId);if(selected)return selected;}
    const end=currentModel.nodes.find(node=>String(node.flowKind||'').toLowerCase()==='end'||String(node.key||'').toLowerCase()==='end');
    if(end){const edge=currentModel.edges.find(item=>item.to===end.id);if(edge)return edge;}
    return currentModel.edges[0]||null;
}
function mostRecentFlowVariable(startNode){
    // Walk backwards through the actual connection graph; do not use source-line order.
    const seen=new Set(),queue=startNode?[startNode]:[];
    while(queue.length){
        const node=queue.shift();if(!node||seen.has(node.id))continue;seen.add(node.id);
        if(node.flowKind==='declare')return node.key;
        if(node.flowKind==='assign'){
            const variable=flowAssignParts(node.sourceValue).variable;if(variable)return variable;
        }
        if(node.flowKind==='input'){
            const variable=String(node.sourceValue||'').trim().split(/\s+/)[0];if(variable)return variable;
        }
        currentModel.edges.filter(edge=>edge.to===node.id).forEach(edge=>{const previous=currentModel.nodes.find(n=>n.id===edge.from);if(previous&&!seen.has(previous.id))queue.push(previous);});
    }
    return '';
}
function flowVariableControlName(type,variable){
    const prefix=type==='input'?'Get':'Show',stem=prefix+variable.charAt(0).toUpperCase()+variable.slice(1);
    const existing=new Set(currentModel.nodes.map(n=>String(n.key||'').toLowerCase()));
    let candidate=stem,index=2;while(existing.has(candidate.toLowerCase()))candidate=stem+index++;
    return candidate;
}
function insertFlowgorithmObjectOnEdge(edge,type,x,y){
    if(!edge||project.sourceType!=='flowgorithm')return null;
    const lineIndex=Number(edge.sourceLineIndex);if(!Number.isInteger(lineIndex)||lineIndex<0)return null;
    const lines=sourceEditor.value.replace(/\r/g,'').split('\n');if(lineIndex>=lines.length)return null;
    const fromKey=String(edge.fromKey||'Start'),toKey=String(edge.toKey||'End');
    const fromNode=currentModel.nodes.find(n=>n.key===fromKey);
    let key=nextFlowgorithmKey(type);
    if(type==='assign'&&fromNode&&fromNode.flowKind==='declare'){
        const stem='Set'+fromNode.key.charAt(0).toUpperCase()+fromNode.key.slice(1);
        const existing=new Set(currentModel.nodes.map(n=>String(n.key).toLowerCase()));
        key=stem;let suffix=2;while(existing.has(key.toLowerCase()))key=stem+suffix++;
    }
    const variable=(type==='input'||type==='output')?mostRecentFlowVariable(fromNode):'';
    if(variable)key=flowVariableControlName(type,variable);
    const declaration=type==='assign'&&fromNode&&fromNode.flowKind==='declare'?`assign ${key} ${fromNode.key} = 0`:(variable?`${type} ${key} ${variable}`:flowgorithmDeclarationForType(type,key));
    lines.splice(lineIndex,1,`${fromKey} -> ${key}`,`${key} -> ${toKey}`);
    insertFlowgorithmDeclarationIntoLines(lines,declaration);
    setFlowgorithmSourceLines(lines);renderFromSource({fit:false,markDirty:false});
    const id=stableId('flowgorithm',key),node=currentModel.nodes.find(n=>n.id===id);if(node){const p={x:Number(x)||0,y:Number(y)||0};currentPositions.set(id,p);project.layout.positions[id]=p;project.layout.positionsByKey[nodeLayoutKey(node)]={id,key:node.key,type:node.type,x:p.x,y:p.y};selectItem('node',id,false);}
    if(isFixedVertical()){
        currentPositions=fixedVerticalPositions(currentModel);
        currentModel.nodes.forEach(item=>{const point=currentPositions.get(item.id);if(point){project.layout.positions[item.id]={x:point.x,y:point.y};project.layout.positionsByKey[nodeLayoutKey(item)]={id:item.id,key:item.key,type:item.type,x:point.x,y:point.y};}});
    }
    syncFlowgorithmVisualPositionsToSource();setDirty(true);renderDiagram();persistLayoutSoon();return id;
}
function openFlowgorithmInsertDialog(edge,x,y){
    if(project.diagramType!=='flowchart'||project.sourceType!=='flowgorithm')return;
    const controls=CONTROL_DEFINITIONS.flowchart;
    const body=`<div class="new-object-grid control-insert-grid">${controls.map(control=>`<button type="button" class="new-object-choice control-insert-choice" data-flow-insert="${escapeAttr(control.type)}">${controlIconHtml(control)}<span class="control-label"><strong>${escapeHtml(control.label)}</strong></span></button>`).join('')}</div>`;
    const root=showModal('Insert control',body,'<button data-cancel>Cancel</button>',false);root.querySelector('[data-cancel]').onclick=closeModal;root.querySelectorAll('[data-flow-insert]').forEach(button=>button.onclick=()=>{const type=button.getAttribute('data-flow-insert');pushUndoSnapshot();closeModal();insertFlowgorithmObjectOnEdge(edge,type,x,y);});
}
function addSourceControlledObject(type,x,y){
    let key='',block='',id='';
    if(project.diagramType==='flowchart'){
        if(project.sourceType==='flowgorithm'){if(type==='start'||type==='terminal'||type==='end'||type==='stop'){const key=nextFlowgorithmKey(type),declaration=flowgorithmDeclarationForType(type,key),id=stableId('flowgorithm',key);appendFlowgorithmDeclaration(declaration);renderFromSource({fit:false,markDirty:false});const node=currentModel.nodes.find(n=>n.id===id);if(node){const p={x:Number(x)||0,y:Number(y)||0};currentPositions.set(id,p);project.layout.positions[id]=p;project.layout.positionsByKey[nodeLayoutKey(node)]={id,key:node.key,type:node.type,x:p.x,y:p.y};selectItem('node',id,false);}syncFlowgorithmVisualPositionsToSource();setDirty(true);renderDiagram();persistLayoutSoon();return id;}const edge=preferredFlowgorithmEdge();if(edge)return insertFlowgorithmObjectOnEdge(edge,type,x,y);toast('Create a connector first, then add the statement on that line.','error');return null;}
        const beforeIds=new Set((currentModel.nodes||[]).map(node=>node.id)),language=project.sourceType;
        const stem=String(Date.now()).slice(-5),valueName='value_'+stem,conditionName='condition_'+stem,indexName='i_'+stem,functionName='procedure_'+stem;
        if(language==='python'){
            if(type==='declare')block=`${valueName}: float = 0`;
            else if(type==='input')block=`${valueName} = input("Enter value: ")`;
            else if(type==='output')block=`print(${valueName})`;
            else if(type==='if')block=`if ${conditionName}:\n    pass`;
            else if(type==='while')block=`while ${conditionName}:\n    pass`;
            else if(type==='for')block=`for ${indexName} in range(10):\n    pass`;
            else if(type==='do')block=`while True:\n    pass`;
            else if(type==='call')block=`${functionName}()`;
            else if(type==='comment')block=`# Comment ${stem}`;
            else block=`${valueName} = 0`;
        }else if(language==='javascript'){
            if(type==='declare')block=`let ${valueName} = 0;`;
            else if(type==='input')block=`const ${valueName} = prompt("Enter value");`;
            else if(type==='output')block=`console.log(${valueName});`;
            else if(type==='if')block=`if (${conditionName}) {\n    // add statements\n}`;
            else if(type==='while')block=`while (${conditionName}) {\n    // add statements\n}`;
            else if(type==='for')block=`for (let ${indexName} = 0; ${indexName} < 10; ${indexName}++) {\n    // add statements\n}`;
            else if(type==='do')block=`do {\n    // add statements\n} while (${conditionName});`;
            else if(type==='call')block=`${functionName}();`;
            else if(type==='comment')block=`// Comment ${stem}`;
            else block=`${valueName} = 0;`;
        }else if(language==='gatton'){
            if(type==='declare')block=`DECLARE ${valueName}`;
            else if(type==='input')block=`GET ${valueName}`;
            else if(type==='output')block=`DISPLAY ${valueName}`;
            else if(type==='if')block=`IF ${conditionName} THEN\nENDIF`;
            else if(type==='while')block=`WHILE ${conditionName}\nENDWHILE`;
            else if(type==='for')block=`FOR ${indexName} = 1 TO 10\nENDFOR`;
            else if(type==='do')block=`REPEAT\nUNTIL ${conditionName}`;
            else if(type==='call')block=`CALL ${functionName}`;
            else if(type==='comment')block=`# Comment ${stem}`;
            else block=`SET ${valueName} = 0`;
        }else{
            if(type==='declare')block=`DECLARE ${valueName}`;
            else if(type==='input')block=`INPUT ${valueName}`;
            else if(type==='output')block=`OUTPUT ${valueName}`;
            else if(type==='if')block=`IF ${conditionName} THEN\nENDIF`;
            else if(type==='while')block=`WHILE ${conditionName}\nENDWHILE`;
            else if(type==='for')block=`FOR ${indexName} = 1 TO 10\nENDFOR`;
            else if(type==='do')block=`REPEAT\nUNTIL ${conditionName}`;
            else if(type==='call')block=`CALL ${functionName}`;
            else if(type==='comment')block=`# Comment ${stem}`;
            else block=`CALCULATE ${valueName} = 0`;
        }
        function insertFlowBlock(text){
            const src=sourceEditor.value.replace(/\s+$/,'');
            if(language==='qcaa'&&/^END\s*$/im.test(src)){const lines=src.split(/\r?\n/),at=[...lines].map(line=>line.trim().toUpperCase()).lastIndexOf('END');lines.splice(at<0?lines.length:at,0,...text.split('\n'));sourceEditor.value=lines.join('\n')+'\n';}
            else if(language==='gatton'&&/^STOP\s*$/im.test(src)){const lines=src.split(/\r?\n/),at=[...lines].map(line=>line.trim().toUpperCase()).lastIndexOf('STOP');lines.splice(at<0?lines.length:at,0,...text.split('\n'));sourceEditor.value=lines.join('\n')+'\n';}
            else sourceEditor.value=src+(src?'\n':'')+text+'\n';
            project.source=sourceEditor.value;updateLineNumbers();
        }
        insertFlowBlock(block);renderFromSource({fit:false,markDirty:false});
        const added=(currentModel.nodes||[]).filter(node=>!beforeIds.has(node.id)&&!['start','end'].includes(node.flowKind));const created=added[added.length-1]||null;
        if(created){id=created.id;const p={x,y};currentPositions.set(id,p);project.layout.positions[id]=p;project.layout.positionsByKey[nodeLayoutKey(created)]={id,key:created.key,type:created.type,x,y};selectItem('node',id,false);}
        setDirty(true);renderDiagram();persistLayoutSoon();return id;
    }else if(project.diagramType==='dfd'){
        if(type==='entity'){key=nextObjectKey('Entity');block=`entity ${key} "External entity"`;}
        else if(type.indexOf('store-')===0){const letter=type==='store-manual'?'M':type==='store-temporary'?'T':'D',mode=letter==='M'?'manual':letter==='T'?'temporary':'digital';key=nextStoreKey(letter);block=`store ${key} ${mode} "Data store"`;}
        else{key=nextObjectKey('Process');block=`process ${key} "New process"`;}
        id=stableId('dfd',key);
    }else{
        key=nextObjectKey(project.sourceType==='prisma'?'Entity':'entity_');
        if(project.sourceType==='prisma'){key=key.charAt(0).toUpperCase()+key.slice(1);block=`model ${key} {\n  id Int @id @default(autoincrement())\n}`;}
        else if(project.sourceType==='quickdbd')block=`${key}\n-\nid int PK`;
        else if(project.sourceType==='mapping')block=`entity ${key} {\n  id: integer PK\n}`;
        else block=`CREATE TABLE ${key} (\n    id INTEGER PRIMARY KEY\n);`;
        id=stableId('table',key);
    }
    appendSourceBlock(block);
    project.layout.positions[id]={x,y};
    renderFromSource({fit:false,markDirty:false});
    selectItem('node',id,false);setDirty(true);renderDiagram();persistLayoutSoon();return id;
}
function closestFixedVerticalEdge(y){
    if(!isFixedVertical()||!currentModel||!currentModel.edges.length)return null;
    // Use the connector's vertical position, not source order or the default End edge.
    // The first/last connection is selected for drops outside the diagram.
    const candidates=currentModel.edges.map(edge=>{
        const from=currentPositions.get(edge.from),to=currentPositions.get(edge.to);
        if(!from||!to)return null;
        const upper=Math.min(from.y,to.y),lower=Math.max(from.y,to.y);
        const distance=y<upper?upper-y:y>lower?y-lower:0;
        return {edge,upper,lower,mid:(from.y+to.y)/2,distance};
    }).filter(Boolean);
    if(!candidates.length)return null;
    const minY=Math.min(...candidates.map(e=>e.upper));
    const maxY=Math.max(...candidates.map(e=>e.lower));
    if(y<minY)return candidates.slice().sort((a,b)=>a.upper-b.upper||a.mid-b.mid)[0].edge;
    if(y>maxY)return candidates.slice().sort((a,b)=>b.lower-a.lower||b.mid-a.mid)[0].edge;
    candidates.sort((a,b)=>a.distance-b.distance||Math.abs(a.mid-y)-Math.abs(b.mid-y)||a.upper-b.upper);
    return candidates[0].edge;
}
function bottomFixedVerticalEdge(){
    if(!isFixedVertical()||!currentModel?.edges?.length)return null;
    const ranked=currentModel.edges.map((edge,index)=>{
        const from=currentPositions.get(edge.from),to=currentPositions.get(edge.to);
        const fromNode=currentModel.nodes.find(n=>n.id===edge.from);
        const toNode=currentModel.nodes.find(n=>n.id===edge.to);
        if(!from||!to)return null;
        // Keep final Stop/End beneath the newly inserted control, by splitting
        // its incoming connector when that connector is at the bottom.
        const terminal=toNode&&(/^(end|stop)\d*$/i.test(String(toNode.key||''))||toNode.flowKind==='stop');
        const branch=fromNode&&['if','for','while'].includes(String(fromNode.flowKind||'').toLowerCase());
        return {edge,index,y:Math.max(from.y,to.y),terminal:!!terminal,branch:!!branch};
    }).filter(Boolean);
    ranked.sort((a,b)=>Number(b.terminal)-Number(a.terminal)||b.y-a.y||Number(a.branch)-Number(b.branch)||b.index-a.index);
    return ranked[0]?.edge||null;
}
function addControlAtWorld(type,x,y){
    if(!project)return;
    pushUndoSnapshot();
    if(type==='textbox'){addFloatingTextboxWorldWithoutHistory(x,y);return;}
    if(isFixedVertical()&&project.sourceType==='flowgorithm'){
        // In Fixed Vertical, palette drops append to the end of the vertical chain,
        // independent of the pointer's y coordinate. The automatic layout then
        // redistributes all controls with uniform row spacing.
        const edge=bottomFixedVerticalEdge();
        if(edge){insertFlowgorithmObjectOnEdge(edge,type,x,y);return;}
    }
    addSourceControlledObject(type,x,y);
}
function addFloatingTextboxWorldWithoutHistory(x,y){if(project.diagramType==='dfd')return addDfdSourceTextboxWorld(x,y);const id='text-'+uuid(),box=normaliseFloatingTextbox({id,text:'Text',x,y});project.layout.textboxes[id]=box;selectItem('textbox',id,false);setDirty(true);renderDiagram();persistLayoutSoon();return id;}
function canvasCentreWorld(){const rect=svg.getBoundingClientRect();return screenToWorld(rect.left+rect.width/2,rect.top+rect.height/2);}
function addControlAtCanvasCentre(type){const p=canvasCentreWorld();addControlAtWorld(type,p.x,p.y);}
function openNewObjectDialog(){
    const controls=controlsForCurrentDiagram();
    const body=`<p class="share-note">Choose an object to add to this ${escapeHtml(project.diagramType.toUpperCase())}. It will be added near the centre of the current canvas view and can then be dragged into position.</p><div class="new-object-grid">${controls.map(control=>`<button type="button" class="new-object-choice" data-new-control="${escapeAttr(control.type)}">${controlIconHtml(control)}<span><strong>${escapeHtml(control.label)}</strong><small>${escapeHtml(control.hint)}</small></span></button>`).join('')}</div>`;
    const root=showModal('New Object',body,'<button data-cancel>Cancel</button>',false);root.querySelector('[data-cancel]').onclick=closeModal;root.querySelectorAll('[data-new-control]').forEach(button=>button.onclick=()=>{const type=button.getAttribute('data-new-control');closeModal();addControlAtCanvasCentre(type);});
}
function newDiagram(type,sourceType){if(dirty&&!confirm('Discard unsaved changes?')){el('diagramType').value=project.diagramType;refreshSourceTypeOptions();el('sourceType').value=project.sourceType;el('direction').value=project.direction;el('lineType').value=project.settings.lineType;return;}if(type==='dfd'){const next=clone(DEFAULT_DFD_PROJECT);next.id=uuid();next.dates={created:now(),modified:now()};next.layout=next.layout||{};next.layout.textboxes={};next.layout.hiddenNodes=next.layout.hiddenNodes||{};next.layout.hiddenEdges=next.layout.hiddenEdges||{};setProject(next);setDirty(true);return;}const key=type==='erd'?'erd-'+sourceType:(type==='flowchart'?'flowchart-'+sourceType:type),definition=DEFAULT_PROJECTS[key]||DEFAULT_PROJECTS['flowchart-flowgorithm']||DEFAULT_PROJECTS.flowchart;setProject({title:definition.title,diagramType:definition.type,sourceType:definition.sourceType,source:definition.source,direction:'TB',layout:{positions:{},positionsByKey:{},links:{},nodeStyles:{},nodeStylesByKey:{},textboxes:{},hiddenNodes:{},hiddenEdges:{}},settings:{showGrid:true,showEdgeLabels:true,fontSize:14,lineType:'orthogonal'},dates:{created:now(),modified:now()},id:uuid()});setDirty(true);}

function syntaxHelpHtml(){
    const examples={
        flowgorithm:`title My Flow Diagram

# you can double-click on the
# line between the Start and End
# in order to add a new item
terminal Start
terminal End

Start -> End






# @visual-layout positions for the designer:`,
        python:`radius = float(input("Radius: "))\narea = 3.14159 * radius * radius\nif area >= 100:\n    print("Large circle")\nelse:\n    print("Small circle")`,
        qcaa:`BEGIN\n    INPUT radius\n    CALCULATE area = 3.14159 * radius * radius\n    IF area >= 100 THEN\n        OUTPUT "Large circle"\n    ELSE\n        OUTPUT "Small circle"\n    ENDIF\nEND`,
        gatton:`START\n    GET radius\n    SET area = 3.14159 * radius * radius\n    IF area >= 100 THEN\n        DISPLAY "Large circle"\n    ELSE\n        DISPLAY "Small circle"\n    ENDIF\nSTOP`,
        javascript:`const radius = Number(prompt("Radius: "));\nconst area = 3.14159 * radius * radius;\nif (area >= 100) {\n    console.log("Large circle");\n} else {\n    console.log("Small circle");\n}`,
        dfd:`# Single-line comment\n// Another comment\n-- Another comment\n/* Block comments may span lines */\n\nentity Customer "Customer"\nprocess PlaceOrder "Place order"\nstore D1 digital "Orders"\nstore M1 manual "Paper forms"\nstore T1 temporary "Working data"\n## Level 1 overview\nCustomer -> PlaceOrder : order\nPlaceOrder -> D1 : new order`,
        sql:`CREATE TABLE orders (\n  order_id INT PRIMARY KEY,\n  customer_id INT REFERENCES customers(customer_id)\n);`,
        prisma:`model Post {\n  id Int @id\n  authorId Int\n  author User @relation(fields: [authorId], references: [id])\n}`,
        quickdbd:`Orders\n-\norder_id int PK\ncustomer_id int FK >- Customers.customer_id`,
        mapping:`entity Order {\n  order_id: integer PK\n  customer_id: integer FK\n}\nCustomer.customer_id 1--* Order.customer_id`
    };
    const flowNote=project.diagramType==='flowchart'?'<p class="share-note"><strong>Flowgorithm-style rendering:</strong> In Flowgorithm mode, controls are declared separately from connections, similar to DFD shorthand. For example <code>declare MyVar1 : 0</code> defines a control and <code>Start -&gt; MyVar1</code> connects it. You can double-click any connector to insert a statement. Code modes continue to generate Flowgorithm-style symbols from program structure.</p>':'';
    return`<p class="share-note">${escapeHtml(sourceDescription())}</p>${flowNote}<div class="syntax-help"><code>${escapeHtml(examples[project.sourceType]||examples.qcaa)}</code></div><p class="share-note">Changes render automatically after a short pause. Press <strong>Ctrl+Enter</strong> to render immediately.</p>`;
}
function showSyntaxHelp(){showModal('Notation help',syntaxHelpHtml(),'<button class="primary" data-done>Done</button>',false);el('modalRoot').querySelector('[data-done]').onclick=closeModal;}
function showAbout(){showModal('About Charter','<p class="share-note"><strong>Charter 0.0.2</strong></p><p class="share-note">Charter is a single-page PHP, HTML and JavaScript diagram editor for flowcharts, data-flow diagrams and entity-relationship diagrams. The diagram is generated locally in the browser from the source text.</p><p class="share-note">Shared diagrams are stored in the MySQL or MariaDB database configured in <code>charter-config.php</code>. Anyone with an unguessable editable share link can modify that shared copy. An always-new-session link creates an independent copy for every visitor.</p>','<button class="primary" data-done>Done</button>',false);el('modalRoot').querySelector('[data-done]').onclick=closeModal;}

async function sendServerRequest(action,payload=null,query={}){const address=new URL(window.location.href);address.search='';address.hash='';address.searchParams.set('charter_action',action);Object.keys(query).forEach(k=>address.searchParams.set(k,query[k]));const options={cache:'no-store'};if(payload!==null){options.method='POST';options.headers={'Content-Type':'application/json'};options.body=JSON.stringify(payload);}const response=await fetch(address.pathname+address.search,options),raw=await response.text();let data;try{data=JSON.parse(raw);}catch(error){throw new Error('The server returned an invalid response. '+raw.slice(0,180));}if(!data.ok)throw new Error(data.error||'The server request failed.');return data;}
async function loadSharedProject(shareId){el('loadingCover').classList.remove('hidden');try{const data=await sendServerRequest('load',null,{share:shareId});setProject(data.project,{serverShareId:data.shareId,serverShareUrl:data.shareUrl,serverNewSessionUrl:data.newSessionUrl});toast('Shared diagram loaded.','success');}finally{el('loadingCover').classList.add('hidden');}}
async function createNewSession(shareId){el('loadingCover').classList.remove('hidden');try{const data=await sendServerRequest('clone',{shareId});setProject(data.project,{serverShareId:data.shareId,serverShareUrl:data.shareUrl,serverNewSessionUrl:data.newSessionUrl});toast('A new independent session was created.','success');}finally{el('loadingCover').classList.add('hidden');}}
async function saveProjectToServer(forceNew=false){
    clearTimeout(sharedAutosaveTimer);
    const snapshot=prepareProjectForPersistence();
    saveAutosave();
    const revision=changeRevision;
    const exactLayout=clone(snapshot.layout);
    const data=await queueProjectSave({project:snapshot,shareId:forceNew?null:serverShareId,forceNew});
    const returnedProject=normaliseProject(data.project);
    /* Exact X/Y coordinates captured from the live canvas are authoritative. */
    returnedProject.layout=exactLayout;
    project=returnedProject;
    serverShareId=data.shareId;
    serverShareUrl=data.shareUrl;
    serverNewSessionUrl=data.newSessionUrl;
    updateShareAddress(serverShareId);
    saveAutosave();
    if(revision===changeRevision)setDirty(false);else scheduleSharedAutosave(400);
    return Object.assign({},data,{project:clone(project)});
}
async function copyText(value,button){try{await navigator.clipboard.writeText(value);const old=button.textContent;button.textContent='Copied';setTimeout(()=>button.textContent=old,1300);}catch(error){const input=button.parentElement.querySelector('input');input.focus();input.select();document.execCommand('copy');}}
function openShareDialog(){prepareProjectForPersistence();saveAutosave();const root=showModal('Share diagram',`<p class="share-note">The standard link opens and edits this shared diagram. Select <strong>Always open a new session</strong> to create a reusable link that gives every visitor a fresh independent copy.</p><div class="share-option"><input id="alwaysNew" type="checkbox"><label for="alwaysNew">Always open a new session<small>Changes made by one visitor will not affect the original diagram or anyone else’s session.</small></label></div><label id="shareLabel" for="shareInput">Editable share link</label><div class="share-link-row"><input id="shareInput" readonly><button id="copyShare" disabled>Copy link</button></div><div id="shareStatus" class="share-status">Saving the diagram and creating its link…</div>`,`<button class="primary" data-done>Done</button>`,true);const checkbox=el('alwaysNew'),input=el('shareInput'),copy=el('copyShare'),status=el('shareStatus'),label=el('shareLabel');let standard=serverShareUrl||'',newLink=serverNewSessionUrl||'',busy=false;function display(url,message,isNew){input.value=url||'';copy.disabled=!url;label.textContent=isNew?'Always-new-session link':'Editable share link';status.className='share-status';status.textContent=message;if(url){input.focus();input.select();}}async function build(useNew){if(busy)return;/* Always write a fresh project snapshot so every current X/Y coordinate is persisted. */busy=true;checkbox.disabled=true;copy.disabled=true;status.textContent='Saving the current diagram…';try{const data=await saveProjectToServer(false);standard=data.shareUrl||standard;newLink=data.newSessionUrl||newLink;display(useNew?newLink:standard,useNew?'Reusable new-session link ready.':'Editable share link ready.',useNew);}catch(error){status.className='share-status error';status.textContent=error.message;}finally{busy=false;checkbox.disabled=false;}}checkbox.onchange=()=>build(checkbox.checked);copy.onclick=()=>copyText(input.value,copy);input.onclick=()=>input.select();root.querySelector('[data-done]').onclick=closeModal;build(false);}
function openSaveTemplateDialog(){const root=showModal('Save as template',`<p class="share-note">Publish an anonymous reusable template on the Charter landing page. Enter the administrator password from <code>charter-config.php</code>.</p><div class="field" style="grid-template-columns:110px minmax(0,1fr)"><label for="templateTitle">Template name</label><input id="templateTitle" value="${escapeAttr(project.title)}"></div><div class="field" style="grid-template-columns:110px minmax(0,1fr)"><label for="templateDescription">Description</label><textarea id="templateDescription" placeholder="Explain what this template is for."></textarea></div><div class="field" style="grid-template-columns:110px minmax(0,1fr)"><label for="templatePassword">Password</label><input id="templatePassword" type="password" autocomplete="current-password"></div><div id="templateStatus" class="share-status"></div><div id="templateLinkRow" class="share-link-row hidden"><input id="templateLink" readonly><button id="copyTemplate">Copy link</button></div>`,`<button data-cancel>Cancel</button><button class="primary" data-save>Save template</button>`,true);const save=root.querySelector('[data-save]'),status=el('templateStatus');root.querySelector('[data-cancel]').onclick=closeModal;el('copyTemplate').onclick=()=>copyText(el('templateLink').value,el('copyTemplate'));save.onclick=async()=>{prepareProjectForPersistence();if(!el('templatePassword').value){status.className='share-status error';status.textContent='Enter the administrator password.';return;}save.disabled=true;status.className='share-status';status.textContent='Saving template…';try{const data=await sendServerRequest('save_template',{title:el('templateTitle').value.trim(),description:el('templateDescription').value.trim(),password:el('templatePassword').value,project:Object.assign({},project,{source:sourceEditor.value})});status.textContent='Template saved and listed on the landing page.';el('templateLink').value=data.templateUrl||'';el('templateLinkRow').classList.toggle('hidden',!data.templateUrl);toast('Template saved.','success');}catch(error){status.className='share-status error';status.textContent=error.message;}finally{save.disabled=false;el('templatePassword').value='';}};}

function saveBrowser(){prepareProjectForPersistence();const key=`charter.project.${project.id}`;localStorage.setItem(key,JSON.stringify(project));let list=[];try{list=JSON.parse(localStorage.getItem('charter.projectList')||'[]');}catch(error){}list=list.filter(x=>x.id!==project.id);list.unshift({id:project.id,title:project.title,modified:now(),type:project.diagramType});localStorage.setItem('charter.projectList',JSON.stringify(list.slice(0,50)));localProjectKey=key;setDirty(false);toast('Diagram saved in this browser.','success');}
function browserProjects(){let list=[];try{list=JSON.parse(localStorage.getItem('charter.projectList')||'[]');}catch(error){}const body=list.length?`<div>${list.map(x=>`<div style="display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:7px;align-items:center;padding:8px;border:1px solid #d3dae0;border-radius:5px;margin-bottom:7px"><div><strong>${escapeHtml(x.title)}</strong><br><small>${escapeHtml(x.type||'diagram')} · ${new Date(x.modified).toLocaleString()}</small></div><button data-open="${escapeAttr(x.id)}">Open</button><button data-delete="${escapeAttr(x.id)}">Delete</button></div>`).join('')}</div>`:'<p class="share-note">No named browser projects have been saved.</p>';const root=showModal('Browser projects',body,'<button data-done>Done</button>');root.querySelector('[data-done]').onclick=closeModal;root.querySelectorAll('[data-open]').forEach(b=>b.onclick=()=>{const raw=localStorage.getItem(`charter.project.${b.getAttribute('data-open')}`);if(raw){setProject(JSON.parse(raw),{localProjectKey:`charter.project.${b.getAttribute('data-open')}`});closeModal();}});root.querySelectorAll('[data-delete]').forEach(b=>b.onclick=()=>{const id=b.getAttribute('data-delete');localStorage.removeItem(`charter.project.${id}`);localStorage.setItem('charter.projectList',JSON.stringify(list.filter(x=>x.id!==id)));closeModal();browserProjects();});}

function downloadJson(){prepareProjectForPersistence();downloadBlob(new Blob([JSON.stringify(project,null,2)],{type:'application/json'}),safeFilename(project.title)+'.charter.json');setDirty(false);}
function openJson(){el('jsonInput').click();}
el('jsonInput').addEventListener('change',()=>{const file=el('jsonInput').files[0];if(!file)return;const reader=new FileReader();reader.onload=()=>{try{setProject(JSON.parse(reader.result));toast(`Opened ${file.name}.`,'success');}catch(error){toast(error.message,'error');}el('jsonInput').value='';};reader.onerror=()=>toast('The JSON file could not be read.','error');reader.readAsText(file);});
function buildExportSvg(){
    const b=diagramBounds(),pad=42,width=Math.ceil(b.width+pad*2),height=Math.ceil(b.height+pad*2),out=createSvg('svg',{xmlns:NS,width,height,viewBox:`${b.minX-pad} ${b.minY-pad} ${width} ${height}`}),defs=svg.querySelector('defs').cloneNode(true);
    const markerPath=defs.querySelector('#arrowHead path');if(markerPath)markerPath.setAttribute('fill','#5d7486');
    const style=createSvg('style');style.textContent='text{font-family:Inter,Segoe UI,Arial,sans-serif}.edge{fill:none;stroke:#000;stroke-width:3}.edge-label rect{fill:#fff;fill-opacity:.96;stroke:#d2d9df;stroke-width:1}.node .body{filter:drop-shadow(0 1px 1px rgba(0,0,0,.17))}';defs.appendChild(style);out.appendChild(defs);
    out.appendChild(createSvg('rect',{x:b.minX-pad,y:b.minY-pad,width,height,fill:'#fff'}));const content=createSvg('g');content.appendChild(edgesLayer.cloneNode(true));content.appendChild(labelsLayer.cloneNode(true));content.appendChild(nodesLayer.cloneNode(true));
    content.querySelectorAll('.selection,.text-selection,.edge-hit,.text-hit,.flow-insert-arrow').forEach(node=>node.remove());content.querySelectorAll('.node,.floating-text,.edge-label,.edge').forEach(node=>node.classList.remove('selected'));
    content.querySelectorAll('.edge').forEach(path=>{path.setAttribute('fill','none');path.setAttribute('stroke','#000');path.setAttribute('stroke-width','3');});
    content.querySelectorAll('.edge-label rect').forEach(rect=>{rect.setAttribute('fill','#ffffff');rect.setAttribute('fill-opacity','.96');rect.setAttribute('stroke','#d2d9df');rect.setAttribute('stroke-width','1');});
    out.appendChild(content);return{svg:out,width,height};
}

function exportSvg(){const built=buildExportSvg(),text=new XMLSerializer().serializeToString(built.svg);downloadBlob(new Blob([text],{type:'image/svg+xml'}),safeFilename(project.title)+'.svg');}
function exportPng(){const built=buildExportSvg(),text=new XMLSerializer().serializeToString(built.svg),blob=new Blob([text],{type:'image/svg+xml'}),url=URL.createObjectURL(blob),image=new Image();image.onload=()=>{const scale=Math.min(2,Math.max(1,2400/Math.max(built.width,built.height))),canvas=document.createElement('canvas');canvas.width=Math.ceil(built.width*scale);canvas.height=Math.ceil(built.height*scale);const ctx=canvas.getContext('2d');ctx.scale(scale,scale);ctx.drawImage(image,0,0);URL.revokeObjectURL(url);canvas.toBlob(png=>downloadBlob(png,safeFilename(project.title)+'.png'),'image/png');};image.onerror=()=>toast('The PNG could not be created.','error');image.src=url;}

function sqlQuoteIdentifier(name,engine){const text=String(name||'');if(engine==='mysql'||engine==='mariadb')return'`'+text.replace(/`/g,'``')+'`';if(engine==='sqlserver')return'['+text.replace(/]/g,']]')+']';return'"'+text.replace(/"/g,'""')+'"';}
function sqlTypeFor(rawType,engine){
    let type=String(rawType||'TEXT').trim().replace(/\?$/,'').replace(/\[\]$/,'');const upper=type.toUpperCase();
    const prisma={STRING:'String',INT:'Int',BIGINT:'BigInt',FLOAT:'Float',DECIMAL:'Decimal',BOOLEAN:'Boolean',DATETIME:'DateTime',JSON:'Json',BYTES:'Bytes'};
    const base=prisma[upper]||type;
    const kind=String(base).toLowerCase();
    if(/^varchar|^char|^nvarchar|^decimal|^numeric|^number\s*\(|^timestamp|^datetime|^date\b|^time\b|^blob|^clob|^text\b|^int\b|^integer\b|^bigint\b|^smallint\b|^real\b|^double\b|^float\b|^boolean\b|^bool\b/i.test(type)){
        if(engine==='sqlite'){if(/int/i.test(type))return'INTEGER';if(/real|float|double|decimal|numeric/i.test(type))return'REAL';if(/blob/i.test(type))return'BLOB';return'TEXT';}
        return type;
    }
    if(kind==='string')return engine==='mysql'||engine==='mariadb'?'VARCHAR(255)':engine==='sqlserver'?'NVARCHAR(255)':engine==='oracle'?'VARCHAR2(255)':'TEXT';
    if(kind==='int')return engine==='sqlserver'?'INT':engine==='oracle'?'NUMBER(10)':'INTEGER';
    if(kind==='bigint')return engine==='oracle'?'NUMBER(19)':'BIGINT';
    if(kind==='float')return engine==='oracle'?'BINARY_DOUBLE':engine==='mysql'||engine==='mariadb'?'DOUBLE':'REAL';
    if(kind==='decimal')return engine==='oracle'?'NUMBER(18,2)':'DECIMAL(18,2)';
    if(kind==='boolean')return engine==='mysql'||engine==='mariadb'?'TINYINT(1)':engine==='sqlite'?'INTEGER':engine==='sqlserver'?'BIT':engine==='oracle'?'NUMBER(1)':'BOOLEAN';
    if(kind==='datetime')return engine==='mysql'||engine==='mariadb'||engine==='sqlserver'?'DATETIME':engine==='sqlite'?'TEXT':'TIMESTAMP';
    if(kind==='json')return engine==='mysql'||engine==='mariadb'||engine==='postgresql'?'JSON':engine==='sqlserver'?'NVARCHAR(MAX)':engine==='oracle'?'CLOB':'TEXT';
    if(kind==='bytes')return engine==='postgresql'?'BYTEA':engine==='sqlserver'?'VARBINARY(MAX)':'BLOB';
    return engine==='sqlserver'?'NVARCHAR(MAX)':engine==='oracle'?'CLOB':'TEXT';
}
function erdForeignKeys(){
    const nodeById=new Map(currentModel.nodes.map(n=>[n.id,n])),keys=[];
    currentModel.edges.forEach(edge=>{
        const from=nodeById.get(edge.from),to=nodeById.get(edge.to);if(!from||!to||from.shape!=='table'||to.shape!=='table')return;
        let local='',target='';const arrow=String(edge.label||'').match(/^\s*([A-Za-z_][\w$]*)\s*(?:→|->)\s*([A-Za-z_][\w$]*)\s*$/);
        if(arrow){local=arrow[1];target=arrow[2];}
        else{const mapping=String(edge.label||'').match(/^\s*([A-Za-z_][\w$]*)\s+[^\s]+\s+([A-Za-z_][\w$]*)\s*$/);if(mapping){const fromField=from.fields&&from.fields.find(f=>f.name===mapping[1]),toField=to.fields&&to.fields.find(f=>f.name===mapping[2]);if(toField&&toField.fk){keys.push({table:to.label,field:mapping[2],targetTable:from.label,targetField:mapping[1]});return;}if(fromField&&fromField.fk){keys.push({table:from.label,field:mapping[1],targetTable:to.label,targetField:mapping[2]});return;}local=mapping[1];target=mapping[2];}}
        if(!local||!target)return;
        keys.push({table:from.label,field:local,targetTable:to.label,targetField:target});
    });
    return keys;
}
function buildSqlExport(options){
    const engine=options.engine,tables=currentModel.nodes.filter(n=>n.shape==='table'),fks=erdForeignKeys();
    if(!tables.length)return'-- SQL export is available for ERD diagrams.\n';
    const q=name=>sqlQuoteIdentifier(name,engine),lines=[];
    lines.push('-- Generated by Charter');
    lines.push('-- Engine: '+engine);lines.push('');
    tables.forEach(table=>{
        const tableFks=fks.filter(fk=>fk.table===table.label),pkFields=table.fields.filter(f=>f.pk),parts=[];
        table.fields.forEach(field=>{
            let part='    '+q(field.name)+' '+sqlTypeFor(field.type,engine);
            if(pkFields.length===1&&field.pk)part+=' PRIMARY KEY';
            if(field.nullable===false&&!field.pk)part+=' NOT NULL';
            if(field.unique&&!field.pk)part+=' UNIQUE';
            parts.push(part);
        });
        if(pkFields.length>1)parts.push('    PRIMARY KEY ('+pkFields.map(f=>q(f.name)).join(', ')+')');
        tableFks.forEach((fk,index)=>{
            let clause='    CONSTRAINT '+q('fk_'+table.label+'_'+fk.field)+' FOREIGN KEY ('+q(fk.field)+') REFERENCES '+q(fk.targetTable)+' ('+q(fk.targetField)+')';
            if(options.includeOnUpdate){if(engine==='oracle')clause+=' /* Oracle has no ON UPDATE referential action */';else clause+=' ON UPDATE '+options.onUpdateMode;}
            parts.push(clause);
        });
        lines.push('CREATE TABLE '+q(table.label)+' (');lines.push(parts.join(',\n'));lines.push(');');lines.push('');
    });
    if(options.includeIndexes){
        const seen=new Set();
        fks.forEach(fk=>{const key=fk.table+'|'+fk.field;if(seen.has(key))return;seen.add(key);lines.push('CREATE INDEX '+q('idx_'+fk.table+'_'+fk.field)+' ON '+q(fk.table)+' ('+q(fk.field)+');');});
        if(seen.size)lines.push('');
    }
    return lines.join('\n').trimEnd()+'\n';
}
function codeFileExtension(language){return{python:'py',php:'php',javascript:'js',csharp:'cs',java:'java'}[language]||'txt';}
function buildCodeScaffold(sql,engine,language){
    const escapedJson=JSON.stringify(sql);
    if(language==='python'){
        if(engine==='sqlite')return`import sqlite3\n\nsql = ${escapedJson}\n\nwith sqlite3.connect("charter.db") as connection:\n    connection.executescript(sql)\n`;
        const modules={mysql:'mysql.connector',mariadb:'mariadb',postgresql:'psycopg',sqlserver:'pyodbc',oracle:'oracledb',ansi:'sqlite3'};const module=modules[engine]||'sqlite3';
        return`# Install/configure the appropriate database driver before running.\nimport ${module}\n\nsql = ${escapedJson}\n\n# TODO: create a connection for ${engine} and execute each SQL statement.\n`;
    }
    if(language==='php'){
        const phpOpen='<'+'?php';
        if(engine==='mysql'||engine==='mariadb')return phpOpen+`\n$sql = ${escapedJson};\n$mysqli = new mysqli('localhost', 'user', 'password', 'database');\nif ($mysqli->connect_errno) { die($mysqli->connect_error); }\nif (!$mysqli->multi_query($sql)) { die($mysqli->error); }\n$mysqli->close();\n`;
        if(engine==='sqlite')return phpOpen+`\n$sql = ${escapedJson};\n$db = new SQLite3('charter.db');\nif (!$db->exec($sql)) { die($db->lastErrorMsg()); }\n$db->close();\n`;
        return phpOpen+`\n$sql = ${escapedJson};\n// TODO: connect using the PHP extension for ${engine} and execute the SQL.\n`;
    }
    if(language==='javascript'){
        const pkg=engine==='sqlite'?'better-sqlite3':engine==='mysql'||engine==='mariadb'?'mysql2/promise':engine==='postgresql'?'pg':engine==='sqlserver'?'mssql':engine==='oracle'?'oracledb':'database-driver';
        return`// npm install ${pkg}\nconst sql = ${escapedJson};\n\n// TODO: create a ${engine} connection and execute the SQL.\nconsole.log(sql);\n`;
    }
    if(language==='csharp')return`using System;\n\nclass CharterSchema\n{\n    static void Main()\n    {\n        string sql = @${JSON.stringify(sql.replace(/"/g,'""'))};\n        // TODO: open a ${engine} ADO.NET connection and execute sql.\n        Console.WriteLine(sql);\n    }\n}\n`;
    if(language==='java')return`public class CharterSchema {\n    public static void main(String[] args) throws Exception {\n        String sql = ${JSON.stringify(sql)};\n        // TODO: open a JDBC connection for ${engine} and execute sql.\n        System.out.println(sql);\n    }\n}\n`;
    return sql;
}
function openExportSqlDialog(){
    if(renderTimer){clearTimeout(renderTimer);renderTimer=null;renderFromSource({fit:false,markDirty:false});}
    if(project.diagramType!=='erd'){toast('Export SQL is available for ERD diagrams.','error');return;}
    const body=`<div class="field" style="grid-template-columns:150px minmax(0,1fr)"><label for="sqlEngine">SQL engine</label><select id="sqlEngine"><option value="sqlite">SQLite</option><option value="mysql">MySQL</option><option value="mariadb">MariaDB</option><option value="postgresql">PostgreSQL</option><option value="sqlserver">Microsoft SQL Server</option><option value="oracle">Oracle</option><option value="ansi">ANSI / generic SQL</option></select></div><div class="share-option"><input id="sqlIndexes" type="checkbox" checked><label for="sqlIndexes">Include indexes<small>Create indexes for detected foreign-key fields.</small></label></div><div class="share-option"><input id="sqlOnUpdate" type="checkbox"><label for="sqlOnUpdate">Include ON UPDATE mode<small>Add the selected referential action to generated foreign keys where supported.</small></label></div><div class="field" style="grid-template-columns:150px minmax(0,1fr)"><label for="sqlOnUpdateMode">ON UPDATE</label><select id="sqlOnUpdateMode"><option>CASCADE</option><option>RESTRICT</option><option>SET NULL</option><option>SET DEFAULT</option><option>NO ACTION</option></select></div><div class="share-option"><input id="sqlScaffold" type="checkbox"><label for="sqlScaffold">Include code scaffolding<small>Preview starter code that contains or executes the generated SQL.</small></label></div><div class="field" style="grid-template-columns:150px minmax(0,1fr)"><label for="sqlLanguage">Coding language</label><select id="sqlLanguage"><option value="python">Python</option><option value="php">PHP</option><option value="javascript">JavaScript / Node.js</option><option value="csharp">C#</option><option value="java">Java</option></select></div><label for="sqlPreview" style="display:block;margin:12px 0 6px;font-weight:700">Preview</label><textarea id="sqlPreview" style="min-height:290px;font-family:Consolas,'Courier New',monospace;white-space:pre" spellcheck="false" readonly></textarea>`;
    const root=showModal('Export SQL',body,`<button data-copy>Copy to clipboard</button><button data-save-sql>Save SQL file</button><button data-save-code>Save code file</button><button class="primary" data-done>Done</button>`,false);
    const controls=['sqlEngine','sqlIndexes','sqlOnUpdate','sqlOnUpdateMode','sqlScaffold','sqlLanguage'].map(el),preview=el('sqlPreview'),saveCode=root.querySelector('[data-save-code]');let currentSql='',currentPreview='';
    function refresh(){const options={engine:el('sqlEngine').value,includeIndexes:el('sqlIndexes').checked,includeOnUpdate:el('sqlOnUpdate').checked,onUpdateMode:el('sqlOnUpdateMode').value};currentSql=buildSqlExport(options);const lang=el('sqlLanguage').value,scaffold=el('sqlScaffold').checked;currentPreview=scaffold?buildCodeScaffold(currentSql,options.engine,lang):currentSql;preview.value=currentPreview;el('sqlOnUpdateMode').disabled=!options.includeOnUpdate;el('sqlLanguage').disabled=!scaffold;saveCode.disabled=!scaffold;saveCode.textContent='Save '+({python:'Python',php:'PHP',javascript:'JavaScript',csharp:'C#',java:'Java'}[lang]||'code')+' file';}
    controls.forEach(control=>{control.onchange=refresh;control.oninput=refresh;});
    root.querySelector('[data-copy]').onclick=()=>copyText(currentPreview,root.querySelector('[data-copy]'));
    root.querySelector('[data-save-sql]').onclick=()=>downloadBlob(new Blob([currentSql],{type:'application/sql;charset=utf-8'}),safeFilename(project.title)+'.sql');
    saveCode.onclick=()=>{const lang=el('sqlLanguage').value;downloadBlob(new Blob([currentPreview],{type:'text/plain;charset=utf-8'}),safeFilename(project.title)+'.'+codeFileExtension(lang));};
    root.querySelector('[data-done]').onclick=closeModal;refresh();
}

function handleAction(action){closeMenus();if(action==='new-object')openNewObjectDialog();else if(action==='templates')openTemplates();else if(action==='new-flow')newDiagram('flowchart','flowgorithm');else if(action==='new-dfd')newDiagram('dfd','dfd');else if(action==='new-erd')newDiagram('erd','sql');else if(action==='open-json')openJson();else if(action==='save-share')openShareDialog();else if(action==='save-template')openSaveTemplateDialog();else if(action==='save-browser')saveBrowser();else if(action==='browser-projects')browserProjects();else if(action==='download-json')downloadJson();else if(action==='export-svg')exportSvg();else if(action==='export-png')exportPng();else if(action==='export-sql')openExportSqlDialog();else if(action==='export-python')exportCode('python');else if(action==='export-qcaa')exportCode('qcaa');else if(action==='export-gatton')exportCode('gatton');else if(action==='export-javascript')exportCode('javascript');else if(action==='print')window.print();else if(action==='render')renderFromSource({fit:false});else if(action==='run')toggleProgramRun();else if(action==='auto-layout')autoLayout();else if(action==='fit')fitDiagram();else if(action==='toggle-grid'){pushUndoSnapshot();project.settings.showGrid=!project.settings.showGrid;applyGrid();setDirty(true);}else if(action==='toggle-labels'){pushUndoSnapshot();project.settings.showEdgeLabels=!project.settings.showEdgeLabels;renderDiagram();setDirty(true);}else if(action==='zoom-in')zoom(1.2);else if(action==='zoom-out')zoom(.83);else if(action==='toggle-source')setSourcePanelVisible(!isSourcePanelVisible());else if(action==='syntax-help')showSyntaxHelp();else if(action==='about')showAbout();}
document.querySelectorAll('[data-action]').forEach(button=>button.addEventListener('click',event=>{event.stopPropagation();handleAction(button.getAttribute('data-action'));}));

function isSourcePanelVisible(){return !document.body.classList.contains('source-panel-hidden');}
function setSourcePanelVisible(visible){
    document.body.classList.toggle('source-panel-hidden',!visible);
    el('sourcePanel').classList.toggle('mobile-open',visible);
    const toggle=document.querySelector('[data-action="toggle-source"]');
    if(toggle){toggle.setAttribute('aria-pressed',String(visible));toggle.title=visible?'Hide source panel':'Show source panel';}
    requestAnimationFrame(()=>renderDiagram(false));
}
function setSourcePanelWidth(width,remember=true){const value=Math.max(260,Math.min(720,Math.round(Number(width)||390)));document.documentElement.style.setProperty('--source-width',value+'px');if(remember){try{localStorage.setItem('charter.sourcePanelWidth',String(value));}catch(error){}}requestAnimationFrame(()=>renderDiagram(false));}
function initialiseSourcePanelResize(){
    const handle=el('sourceResizeHandle');if(!handle)return;let saved=390;try{saved=Number(localStorage.getItem('charter.sourcePanelWidth'))||390;}catch(error){}setSourcePanelWidth(saved,false);
    handle.addEventListener('pointerdown',event=>{if(window.innerWidth<=820)return;event.preventDefault();event.stopPropagation();const panel=el('sourcePanel');sourceResizeState={startX:event.clientX,startWidth:panel.getBoundingClientRect().width,pointerId:event.pointerId};handle.classList.add('active');handle.setPointerCapture(event.pointerId);});
    handle.addEventListener('pointermove',event=>{if(!sourceResizeState||event.pointerId!==sourceResizeState.pointerId)return;setSourcePanelWidth(sourceResizeState.startWidth+(event.clientX-sourceResizeState.startX),false);});
    const finish=event=>{if(!sourceResizeState)return;const width=el('sourcePanel').getBoundingClientRect().width;sourceResizeState=null;handle.classList.remove('active');setSourcePanelWidth(width,true);};handle.addEventListener('pointerup',finish);handle.addEventListener('pointercancel',finish);handle.addEventListener('dblclick',()=>setSourcePanelWidth(390,true));
}

function setControlsPanelWidth(width,remember=true){const value=Math.max(140,Math.min(360,Math.round(Number(width)||190)));document.documentElement.style.setProperty('--controls-width',value+'px');if(remember){try{localStorage.setItem('charter.controlsPanelWidth',String(value));}catch(error){}}requestAnimationFrame(()=>renderDiagram(false));}
function setInspectorPanelWidth(width,remember=true){const value=Math.max(220,Math.min(520,Math.round(Number(width)||270)));document.documentElement.style.setProperty('--inspector-width',value+'px');if(remember){try{localStorage.setItem('charter.inspectorPanelWidth',String(value));}catch(error){}}requestAnimationFrame(()=>renderDiagram(false));}
function initialiseSidePanelResize(){
    const controlsHandle=el('controlsResizeHandle'),inspectorHandle=el('inspectorResizeHandle');let controlsSaved=190,inspectorSaved=270;try{controlsSaved=Number(localStorage.getItem('charter.controlsPanelWidth'))||190;inspectorSaved=Number(localStorage.getItem('charter.inspectorPanelWidth'))||270;}catch(error){}setControlsPanelWidth(controlsSaved,false);setInspectorPanelWidth(inspectorSaved,false);
    if(controlsHandle){controlsHandle.addEventListener('pointerdown',event=>{if(window.innerWidth<=820)return;event.preventDefault();event.stopPropagation();controlsResizeState={startX:event.clientX,startWidth:el('controlsPanel').getBoundingClientRect().width,pointerId:event.pointerId};controlsHandle.classList.add('active');controlsHandle.setPointerCapture(event.pointerId);});controlsHandle.addEventListener('pointermove',event=>{if(!controlsResizeState||event.pointerId!==controlsResizeState.pointerId)return;setControlsPanelWidth(controlsResizeState.startWidth+(event.clientX-controlsResizeState.startX),false);});const finish=()=>{if(!controlsResizeState)return;const width=el('controlsPanel').getBoundingClientRect().width;controlsResizeState=null;controlsHandle.classList.remove('active');setControlsPanelWidth(width,true);};controlsHandle.addEventListener('pointerup',finish);controlsHandle.addEventListener('pointercancel',finish);controlsHandle.addEventListener('dblclick',()=>setControlsPanelWidth(190,true));}
    if(inspectorHandle){inspectorHandle.addEventListener('pointerdown',event=>{if(window.innerWidth<=1180)return;event.preventDefault();event.stopPropagation();inspectorResizeState={startX:event.clientX,startWidth:el('inspectorPanel').getBoundingClientRect().width,pointerId:event.pointerId};inspectorHandle.classList.add('active');inspectorHandle.setPointerCapture(event.pointerId);});inspectorHandle.addEventListener('pointermove',event=>{if(!inspectorResizeState||event.pointerId!==inspectorResizeState.pointerId)return;setInspectorPanelWidth(inspectorResizeState.startWidth-(event.clientX-inspectorResizeState.startX),false);});const finish=()=>{if(!inspectorResizeState)return;const width=el('inspectorPanel').getBoundingClientRect().width;inspectorResizeState=null;inspectorHandle.classList.remove('active');setInspectorPanelWidth(width,true);};inspectorHandle.addEventListener('pointerup',finish);inspectorHandle.addEventListener('pointercancel',finish);inspectorHandle.addEventListener('dblclick',()=>setInspectorPanelWidth(270,true));}
}

sourceEditor.addEventListener('beforeinput',()=>beginGroupedUndo('source'));sourceEditor.addEventListener('input',()=>{updateLineNumbers();clearTimeout(renderTimer);renderTimer=setTimeout(()=>renderFromSource({fit:false}),450);});sourceEditor.addEventListener('scroll',()=>lineNumbers.scrollTop=sourceEditor.scrollTop);sourceEditor.addEventListener('keydown',event=>{if(event.key==='Tab'){event.preventDefault();beginGroupedUndo('source');const start=sourceEditor.selectionStart,end=sourceEditor.selectionEnd;sourceEditor.setRangeText('  ',start,end,'end');sourceEditor.dispatchEvent(new Event('input'));}if(event.key==='Enter'&&(event.ctrlKey||event.metaKey)){event.preventDefault();renderFromSource({fit:false});}});
el('diagramTitle').addEventListener('beforeinput',()=>beginGroupedUndo('title'));el('diagramTitle').addEventListener('input',()=>{project.title=el('diagramTitle').value;setDirty(true);});
el('diagramType').addEventListener('change',()=>{const type=el('diagramType').value;refreshSourceTypeOptions();const sourceType=el('sourceType').value;newDiagram(type,sourceType);});
el('sourceType').addEventListener('change',()=>{const type=el('diagramType').value,sourceType=el('sourceType').value;if(type==='flowchart')switchFlowchartSourceType(sourceType);else newDiagram(type,sourceType);});
el('direction').addEventListener('change',()=>{pushUndoSnapshot();project.direction=el('direction').value;if(isFixedVertical())stripFixedVerticalLayoutSource();project.layout.positions={};project.layout.positionsByKey={};renderFromSource({forceLayout:true,fit:true});captureCurrentLayout(false);setDirty(true);persistLayoutSoon();});
el('lineType').addEventListener('change',()=>{pushUndoSnapshot();project.settings.lineType=el('lineType').value;renderDiagram();setDirty(true);});
window.addEventListener('resize',()=>renderDiagram());window.addEventListener('beforeunload',event=>{if(dirty){saveAutosave();event.preventDefault();event.returnValue='';}});
async function saveWithShortcut(){
    setSaveState('Saving diagram and layout…');
    try{
        await saveProjectToServer(false);
        toast('Diagram and all object coordinates saved.','success');
        openShareDialog();
    }catch(error){
        setSaveState('Save failed');
        toast(error.message,'error');
    }
}
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&runState.active&&!el('modalRoot').querySelector('.modal-backdrop')){stopProgramRun();return;}if(event.key==='Escape'&&el('modalRoot').querySelector('.modal-backdrop')){event.preventDefault();event.stopPropagation();closeModal();return;}if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='z'&&!event.shiftKey){event.preventDefault();undoLastChange();return;}if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='s'){event.preventDefault();saveWithShortcut();}if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='o'){event.preventDefault();openJson();}if((event.ctrlKey||event.metaKey)&&event.key==='Enter'){event.preventDefault();renderFromSource({fit:false});}if((event.key==='Delete'||event.key==='Backspace')&&!event.target.matches('input,textarea,select,[contenteditable=true]')&&selectedItems.size){event.preventDefault();deleteSelectedDiagramItem();return;}if(event.key==='Escape'){if(el('modalRoot').querySelector('.modal-backdrop')){event.preventDefault();event.stopPropagation();closeModal();}else closeMenus();}});

async function initialise(){initialiseSourcePanelResize();initialiseSidePanelResize();setProject(INITIAL_PROJECT);setSourcePanelVisible(REQUESTED_SOURCE_VISIBILITY==='show'||(REQUESTED_SOURCE_VISIBILITY!=='hide'&&window.innerWidth>820));if(INITIAL_NEW_SESSION_ID){try{await createNewSession(INITIAL_NEW_SESSION_ID);}catch(error){toast(error.message,'error');}}else if(INITIAL_SHARE_ID){try{await loadSharedProject(INITIAL_SHARE_ID);}catch(error){toast(error.message,'error');}}}
initialise();
})();
</script>
</body>
</html>
