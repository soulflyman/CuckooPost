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
    return [$email, $subject, $message];
}

function getAuthorizationToken() {
    $headers = apache_request_headers();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : null;
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        return $matches[1];
    } else {
        http_response_code(400);
        echo 'Invalid or missing Authorization header.';
        exit;
    }
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

function sendEmailBySMTP($email, $subject, $message, $baseConfig, $smtpConfig) {
    $smtpHost = $smtpConfig['host'];
    $from = $baseConfig['from'];
    $fromName = $baseConfig['fromName'];
    $smtpUsername = $smtpConfig['username'];
    $smtpPassword = $smtpConfig['password'];
    $smtpPort = $smtpConfig['port'];

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

        // Recipients
        $mail->setFrom($from, $fromName);
        $mail->addAddress($email);

        // Content
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $message;

        $mail->send();
        echo 'Email sent successfully.';
        return true;
    } catch (Exception $e) {
        http_response_code(500);
        echo "Failed to send email via SMTP: {$mail->ErrorInfo}";
        return false;
    }
}

function sendEmail($email, $subject, $message) {
    include __DIR__ . '/admin/base_config.php';
    $smtpConfigFile = __DIR__ . '/admin/smtp_config.php';

    if (file_exists($smtpConfigFile)) {
        include $smtpConfigFile;
        return sendEmailBySMTP($email, $subject, $message, $cuckooPostBaseConfig, $cuckooPostSmtpConfig);
    }

    // Fallback to PHP mail() function
    include __DIR__ . '/admin/base_config.php';
    $fromName = !empty($cuckooPostBaseConfig['fromName']) ? $cuckooPostBaseConfig['fromName'] : $cuckooPostBaseConfig['from'];
    $fromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $from = $cuckooPostBaseConfig['from'];
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    //$message = wordwrap($message, 70, "\r\n");

    $headers = 'From: '.$fromName.' <'.$cuckooPostBaseConfig['from'].'>' . "\r\n" .
                'X-Mailer: PHP/' . phpversion() . "\r\n" .
                'Content-Type: text/plain; charset=UTF-8';
    if (mail($email, $subject, $message, $headers)) {
        echo 'Email sent successfully.';
        return true;
    } else {
        http_response_code(500);
        $errorMessag = "Failed to send email.";
        $detailedErrorMessage = error_get_last()['message'];
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
        $stmt = $db->prepare('SELECT uuid, `description`, expiration_date, `limit`, `counter`, recipient_whitelist FROM tokens WHERE uuid = :token');
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result;
    } catch (Exception $e) {
        http_response_code(500);
        $errorMessage = 'Database error: ' . $e->getMessage();
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
            'Token' => $token,
            'Email' => $email,
            'Subject' => $subject,
            'Message' => $message
        ]);
        exit;
    }
}

function validateSetup() {
    $errorOccurred = false;
    $errorMessages = [];
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

function logMessage($tokenData, $email, $subject, $message) {
    include __DIR__ . '/admin/base_config.php';
    if (!$cuckooPostBaseConfig['mailLog']) {
        return;
    }
    try {
        $db = new SQLite3(__DIR__ . '/admin/CuckooPost.db');
        $stmt = $db->prepare('INSERT INTO mail_logs (token_uuid, token_description, recipient, subject, message) VALUES (:token_uuid, :token_description, :recipient, :subject, :message)');
        $stmt->bindValue(':token_uuid', $tokenData['uuid'], SQLITE3_TEXT);
        $stmt->bindValue(':token_description', $tokenData['description'], SQLITE3_TEXT);
        $stmt->bindValue(':recipient', $email, SQLITE3_TEXT);
        $stmt->bindValue(':subject', $subject, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
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
list($email, $subject, $message) = getPostVariables();
$token = getAuthorizationToken();
validateInputs($token, $email, $subject, $message);

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

$mailWasSend = sendEmail($email, $subject, $message);
if ($mailWasSend) {
    increaseMessageCounter($tokenData['uuid']);
    logMessage($tokenData, $email, $subject, $message);
}

exit;
?>
