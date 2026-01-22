<?php
if (!isset($_GET['token_uuid'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Token UUID is required.']));
}

$token_uuid = $_GET['token_uuid'];

try {
    $db = new SQLite3(__DIR__ . '/CuckooPost.db');
    $stmt = $db->prepare('SELECT sent_at, recipient, subject, message, attachments FROM mail_logs WHERE token_uuid = :token_uuid');
    $stmt->bindValue(':token_uuid', $token_uuid, SQLITE3_TEXT);
    $result = $stmt->execute();

    $logs = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $logs[] = $row;
    }

    header('Content-Type: application/json');
    echo json_encode($logs);
} catch (Exception $e) {
    http_response_code(500);
    exit(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
}
?>
