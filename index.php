<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit('Invalid request');
}

require 'vendor/autoload.php'; // Ensure PHPMailer is installed via Composer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;



function getPostVariables() {
    $email = filter_input(INPUT_POST, 'mailto', FILTER_VALIDATE_EMAIL);
    $subject = $_POST['subject'];
    $message = $_POST['message'];
    $message = preg_replace('/\\\\n/', "\n", $message);

    return [$email, $subject, $message, $_FILES];
}

function getAuthorizationToken() {
    // Try to get token from Authorization header first
    $headers = apache_request_headers();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : null;
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        return $matches[1];
    }

    // Fallback: Check for token in POST data
    if (isset($_POST['token'])) {
        return $_POST['token'];
    }

    // Fallback: Check for token in GET parameters
    if (isset($_GET['token'])) {
        return $_GET['token'];
    }

    // Fallback: Check for HTTP_AUTHORIZATION header (some servers use this)
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $httpAuth = $_SERVER['HTTP_AUTHORIZATION'];
        if (preg_match('/Bearer\s(\S+)/', $httpAuth, $matches)) {
            return $matches[1];
        }
    }

    // Fallback: Check for REDIRECT_HTTP_AUTHORIZATION (for some server configurations)
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $redirectAuth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        if (preg_match('/Bearer\s(\S+)/', $redirectAuth, $matches)) {
            return $matches[1];
        }
    }

    http_response_code(400);
    echo 'Invalid or missing Authorization token.\n';
    echo 'Please provide the token via Authorization header, POST parameter "token", or GET parameter "token".\n';
    echo 'Headers received: \n\n';
    echo json_encode($headers, JSON_PRETTY_PRINT);
    echo '\n\nServer variables (AUTH related): \n\n';
    $authVars = array_filter($_SERVER, function($key) {
        return stripos($key, 'auth') !== false || stripos($key, 'http_') === 0;
    }, ARRAY_FILTER_USE_KEY);
    echo json_encode($authVars, JSON_PRETTY_PRINT);
    exit;
}

function sendDebugEmail($messageContent) {
    include __DIR__ . '/admin/base_config.php';
    $to = $cuckooPostBaseConfig['from'];
    $debugMessage = "";
    foreach ($messageContent as $key => $value) {
        $debugMessage .= "$key:\t$value\r\n";
    }
    mail($to, "CuckooPost ERROR", $debugMessage);
    exit;
}

function validateAttachments($attachments) {
    if (empty($attachments)) {
        return;
    }

    include __DIR__ . '/admin/base_config.php';

    $maxAttachments = isset($cuckooPostBaseConfig['maxAttachments']) ? $cuckooPostBaseConfig['maxAttachments'] : 3;
    $maxAttachmentSizeMB = isset($cuckooPostBaseConfig['maxAttachmentSizeMB']) ? $cuckooPostBaseConfig['maxAttachmentSizeMB'] : 10;
    $maxAttachmentSizeBytes = $maxAttachmentSizeMB * 1024 * 1024;

    if (count($attachments) > $maxAttachments) {
        http_response_code(400);
        exit("Too many attachments. Max allowed: $maxAttachments");
    }

    $totalSize = 0;
    foreach ($attachments as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
             http_response_code(400);
             exit("Error uploading file: " . $file['name'] . " (Code: " . $file['error'] . ")");
        }
        $totalSize += $file['size'];
    }

    if ($totalSize > $maxAttachmentSizeBytes) {
         http_response_code(400);
         exit("Total attachment size exceeds limit of {$maxAttachmentSizeMB}MB");
    }
}

function validateInputs($token, $email, $subject, $message) {
    if (!$token || !$email || !$subject || !$message) {
        http_response_code(400);
        $errorMessage = 'Invalid input.';
        echo $errorMessage;
        sendDebugEmail([
            'Error' => $errorMessage,
            'Token' => $token,
            'Email' => $email,
            'Subject' => $subject,
            'Message' => $message
        ]);
        exit;
    }
}

function isTokenValid($tokenData) {
    return $tokenData && strtotime($tokenData['expiration_date'] . ' 00:00:01') > time() && ($tokenData['limit'] == 0 || $tokenData['counter'] < $tokenData['limit']);
}

function sendEmailBySMTP($email, $subject, $message, $baseConfig, $smtpConfig, $attachments = [], $tokenData = []) {
    $smtpHost = $smtpConfig['host'];
    $from = $baseConfig['from'];
    $fromName = !empty($tokenData['sender_name']) ? $tokenData['sender_name'] : $baseConfig['fromName'];
    $smtpUsername = $smtpConfig['username'];
    $smtpPassword = $smtpConfig['password'];
    $smtpPort = $smtpConfig['port'];
    $smtpAuthType = isset($smtpConfig['auth_type']) ? $smtpConfig['auth_type'] : ''; // CRAM-MD5, LOGIN, PLAIN, XOAUTH2

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;
        $mail->AuthType = $smtpAuthType;

        // Recipients
        $mail->setFrom($from, $fromName);
        $mail->addAddress($email);

        // Content
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $message;

        foreach ($attachments as $file) {
            if (isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
                $mail->addAttachment($file['tmp_name'], $file['name']);
            }
        }

        $mail->send();
        echo 'Email sent successfully.';
        return true;
    } catch (Exception $e) {
        http_response_code(500);
        echo "Failed to send email via SMTP: {$mail->ErrorInfo}";
        return false;
    }
}

function sendEmail($email, $subject, $message, $attachments = [], $tokenData = []) {
    include __DIR__ . '/admin/base_config.php';
    $smtpConfigFile = __DIR__ . '/admin/smtp_config.php';

    if (file_exists($smtpConfigFile)) {
        include $smtpConfigFile;
        return sendEmailBySMTP($email, $subject, $message, $cuckooPostBaseConfig, $cuckooPostSmtpConfig, $attachments, $tokenData);
    }

    // Fallback to PHP mail() function (using PHPMailer)
    include __DIR__ . '/admin/base_config.php';
    $fromName = !empty($tokenData['sender_name']) ? $tokenData['sender_name'] : (!empty($cuckooPostBaseConfig['fromName']) ? $cuckooPostBaseConfig['fromName'] : $cuckooPostBaseConfig['from']);
    $from = $cuckooPostBaseConfig['from'];
    
    $mail = new PHPMailer(true);

    try {
        $mail->setFrom($from, $fromName);
        $mail->addAddress($email);
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $message;

        foreach ($attachments as $file) {
            if (isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
                $mail->addAttachment($file['tmp_name'], $file['name']);
            }
        }

        $mail->send();
        echo 'Email sent successfully.';
        return true;
    } catch (Exception $e) {
        http_response_code(500);
        $errorMessag = "Failed to send email.";
        $detailedErrorMessage = $mail->ErrorInfo;
        echo $errorMessag;
        sendDebugEmail([
            'Error' => $errorMessag,
            'Detailed Error' => $detailedErrorMessage,
            'Email' => $email,
            'Subject' => $subject,
            'Message' => $message
        ]);
        return false;
    }
}

function fetchTokenData($token) {
    try {
        $db = new SQLite3(__DIR__ . '/admin/CuckooPost.db');
        $stmt = $db->prepare('SELECT uuid, `description`, sender_name, expiration_date, `limit`, `counter`, recipient_whitelist FROM tokens WHERE uuid = :token');
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result;
    } catch (Exception $e) {
        http_response_code(500);
        $errorMessage = 'Database error: ' . $e->getMessage();
        echo $errorMessage;
        sendDebugEmail([
            'Error' => $errorMessage,
            'Token' => $token
        ]);
        exit;
    }
}

function increaseMessageCounter($token) {
    try {
        $db = new SQLite3(__DIR__ . '/admin/CuckooPost.db');
        $stmt = $db->prepare('UPDATE tokens SET `counter` = `counter` + 1 WHERE uuid = :token');
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $stmt->execute();
    } catch (Exception $e) {
        http_response_code(500);
        $errorMessage = 'Database error after message was send successfully: ' . $e->getMessage();
        echo $errorMessage;
        sendDebugEmail([
            'Error' => $errorMessage,
            'Token' => $token
        ]);
        exit;
    }
}

function validateSetup() {
    $errorOccurred = false;
    $errorMessages = [];
    if(!is_dir(__DIR__ . '/vendor')) {
        $errorOccurred = true;
        $errorMessages[] = "Vendor folder missing, please execute 'composer install' in the document root of this site.";
    }

    if (!file_exists(__DIR__ . '/admin/CuckooPost.db')) {
        $errorOccurred = true;
        $errorMessages[] = 'Database file not found.';
    }

    if (!file_exists(__DIR__ . '/admin/base_config.php')) {
        $errorOccurred = true;
        $errorMessages[] = 'Base config file not found.';
    }

    include __DIR__ . '/admin/base_config.php';
    if (!isset($cuckooPostBaseConfig['from']) || !isset($cuckooPostBaseConfig['fromName'])) {
        $errorOccurred = true;
        $errorMessages[] = 'Base config file is missing required values.';
    }

    if ($errorOccurred) {
        http_response_code(500);
        echo 'CuckooPost was net properly set up. Please check your php error log.';
        // write error messages to php log
        error_log('CuckooPost was net properly set up. Error Messages: ' . json_encode($errorMessages));

        // if $cuckooPostBaseConfig['from'] is a valid email addresse, then send debug mail
        if (filter_var($cuckooPostBaseConfig['from'], FILTER_VALIDATE_EMAIL)) {
            sendDebugEmail([
                'Error' => 'CuckooPost was net properly set up.',
                'Error Messages' => json_encode($errorMessages)
            ]);
        }
        exit;
    }
}

function isRecipientAllowed($recipientWhitelistString, $email) {
    if(!$recipientWhitelistString) {
        return true;
    }
    $recipientWhitelist = explode(',', $recipientWhitelistString);

    // Direct match check
    if (in_array($email, $recipientWhitelist)) {
        return true;
    }

    // Check for plus addressing
    if (strpos($email, '+') !== false) {
        // Extract the base email (part before the +)
        list($emailUsername, $emailDomain) = explode('@', $email);
        list($baseUsername) = explode('+', $emailUsername);
        $baseEmail = $baseUsername . '@' . $emailDomain;

        // Check if the base email is in the whitelist
        if (in_array($baseEmail, $recipientWhitelist)) {
            return true;
        }
    }

    return false;
}

function logMessage($tokenData, $email, $subject, $message, $attachments = []) {
    include __DIR__ . '/admin/base_config.php';
    if (!$cuckooPostBaseConfig['mailLog']) {
        return;
    }

    $attachmentNames = [];
    foreach ($attachments as $file) {
        if (isset($file['name'])) {
            $attachmentNames[] = $file['name'];
        }
    }
    $attachmentsString = implode(', ', $attachmentNames);

    try {
        $db = new SQLite3(__DIR__ . '/admin/CuckooPost.db');
        $stmt = $db->prepare('INSERT INTO mail_logs (token_uuid, token_description, recipient, subject, message, attachments) VALUES (:token_uuid, :token_description, :recipient, :subject, :message, :attachments)');
        $stmt->bindValue(':token_uuid', $tokenData['uuid'], SQLITE3_TEXT);
        $stmt->bindValue(':token_description', $tokenData['description'], SQLITE3_TEXT);
        $stmt->bindValue(':recipient', $email, SQLITE3_TEXT);
        $stmt->bindValue(':subject', $subject, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
        $stmt->bindValue(':attachments', $attachmentsString, SQLITE3_TEXT);
        $stmt->execute();
    } catch (Exception $e) {
        http_response_code(500);
        $errorMessage = 'Database error after message was send successfully: ' . $e->getMessage();
        echo $errorMessage;
        sendDebugEmail([
            'Error' => $errorMessage,
            'Token' => $token,
            'Email' => $email,
            'Subject' => $subject,
            'Message' => $message
        ]);
        exit;
    }
}

// Main script execution
validateSetup();
list($email, $subject, $message, $attachments) = getPostVariables();
$token = getAuthorizationToken();
validateInputs($token, $email, $subject, $message);
validateAttachments($attachments);

$message = strip_tags($message);

$tokenData = fetchTokenData($token);
if (!$tokenData) {
    http_response_code(401);
    $errorMessage = 'Invalid token or token expired [2].';
    echo $errorMessage;
    sendDebugEmail([
        'Error' => $errorMessage,
        'Token' => $token,
        'Email' => $email,
        'Subject' => $subject,
        'Message' => $message
    ]);
    exit;
}

if (!isTokenValid($tokenData)) {
    http_response_code(401);
    $errorMessage = 'Invalid token or token expired [1].';
    echo $errorMessage;
    sendDebugEmail([
        'Error' => $errorMessage,
        'Token' => $token,
        'TokenData' => json_encode($tokenData),
        'Email' => $email,
        'Subject' => $subject,
        'Message' => $message
    ]);
    exit;
}

if (!isRecipientAllowed($tokenData['recipient_whitelist'], $email)) {
    http_response_code(403);
    $errorMessage = 'Recipient not allowed.';
    echo $errorMessage;
    sendDebugEmail([
        'Error' => $errorMessage,
        'Token' => $token,
        'TokenData' => json_encode($tokenData),
        'Email' => $email,
        'Subject' => $subject,
        'Message' => $message
    ]);
    exit;
}

$mailWasSend = sendEmail($email, $subject, $message, $attachments, $tokenData);
if ($mailWasSend) {
    increaseMessageCounter($tokenData['uuid']);
    logMessage($tokenData, $email, $subject, $message, $attachments);
}

exit;
?>
