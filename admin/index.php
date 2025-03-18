<?php
$db = new SQLite3('CuckooPost.db');
$db->exec('CREATE TABLE IF NOT EXISTS tokens (uuid TEXT PRIMARY KEY, `description` TEXT, expiration_date TEXT, `limit` NUMBER DEFAULT 0, `counter` NUMBER DEFAULT 0)');

// Check if UUID already exists
function doesUUIDAlreadyExist($uuid) {
    global $db;
    $stmt = $db->prepare('SELECT 1 FROM tokens WHERE uuid = :uuid');
    $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
    $result = $stmt->execute();
    return $result->fetchArray() !== false;
}

// Function to generate UUID
function generateUUID() {
    global $db;
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    if (doesUUIDAlreadyExist($uuid)) {
        return generateUUID();
    }

    return $uuid;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $uuid = generateUUID();

    $description = $_POST['description'];
    $expiration_date = $_POST['expiration_date'];
    $limit = $_POST['limit'];

    $stmt = $db->prepare('INSERT INTO tokens (uuid, `description`, expiration_date, `limit` ) VALUES (:uuid, :description, :expiration_date, :limit)');
    $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
    $stmt->bindValue(':description', $description, SQLITE3_TEXT);
    $stmt->bindValue(':expiration_date', $expiration_date, SQLITE3_TEXT);
    $stmt->bindValue(':limit', $limit, SQLITE3_TEXT);
    $stmt->execute();
}

// Handle token deletion
if (isset($_GET['delete'])) {
    $uuid = $_GET['delete'];
    $stmt = $db->prepare('DELETE FROM tokens WHERE uuid = :uuid');
    $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
    $stmt->execute();
}

// Fetch all tokens
$result = $db->query('SELECT uuid, `description`, expiration_date, `limit`, `counter` FROM tokens');
$tokens = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $tokens[] = $row;
}
$db->close();

// Check if base config is valid
$isBaseConfigValid = file_exists(__DIR__ . '/base_config.php');
if ($isBaseConfigValid) {
    include __DIR__ . '/base_config.php';

    if (!isset($cuckooPostBaseConfig['from']) || !isset($cuckooPostBaseConfig['fromName'])) {
        $isBaseConfigValid = false;
    }

    if ($isBaseConfigValid && !filter_var($cuckooPostBaseConfig['from'], FILTER_VALIDATE_EMAIL)) {
        $isBaseConfigValid = false;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>CuckooPost Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/beercss@3.9.7/dist/cdn/beer.min.css" rel="stylesheet">
    <script type="module" src="https://cdn.jsdelivr.net/npm/beercss@3.9.7/dist/cdn/beer.min.js"></script>
    <script type="module" src="https://cdn.jsdelivr.net/npm/material-dynamic-colors@1.1.2/dist/cdn/material-dynamic-colors.min.js"></script>
    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                return;
            }, function(err) {
                alert('Failed to copy token');
            });
        }
    </script>
</head>
<body>
    <main class="responsive">
        <div class="container">
            <?php if (!file_exists(__DIR__ . '/smtp_config.php')): ?>
                <article class="round error-text">
                    <h3>Warning</h3>
                    <p>SMTP configuration is not set up. Emails will be sent using PHP's <code>mail()</code> function.
                    For better deliverability, it is recommended to set up SMTP configuration.
                    Mails send by PHP's <code>mail()</code> funtion are send by sendmail and most likely gettting tagged as spam.</p>
                </article>
            <?php endif; ?>
            <?php if (!$isBaseConfigValid): ?>
                $baseConfigNotValid = true;
                <article class="round error-text">
                    <h3>Error</h3>
                    <p>SMTP configuration is not set up. Emails will be sent using PHP's <code>mail()</code> function.
                    For better deliverability, it is recommended to set up SMTP configuration.
                    Mails send by PHP's <code>mail()</code> funtion are send by sendmail and most likely gettting tagged as spam.</p>
                </article>
            <?php endif; ?>
            <h2>Create Access Token</h2>
            <?php
                $formState = ($isBaseConfigValid) ? "": "inert";
            ?>
            <form method="post" action="" <?php echo $formState ?>>
                <div class="form-group">
                    <div class="field border label large round">
                        <input type="text" id="description" name="description" required>
                        <label>Description</label>
                    </div>
                    <div class="field border label large round">
                        <input type="date" id="expiration_date" name="expiration_date" required>
                        <label>Expiration date</label>
                        <span class="helper">Entering 2024-02-13 will restrict Access at 2024-02-13 00:00:01</span>
                    </div>
                    <div class="field label border large round">
                        <input type="number" id="limit" name="limit" required>
                        <label>Limit</label>
                        <i>numbers</i>
                        <span class="helper">Setting this value to '0' will allow an unlimited number of mails to be send.</span>
                    </div>

                </div>
                <button type="submit" class="button primary">Generate Token</button>
            </form>

            <hr class="large">

            <details>
                <summary>
                    <article class="round primary no-elevate">
                    <nav>
                        <div class="max bold">Help</div>
                        <i>expand_more</i>
                    </nav>
                    </article>
                </summary>
                <article class="round border">
                    <h3>Config</h3>
                    <h4>Base Config</h4>
                    <p><bold>It is important</bold> that you adjust the <code>base_config.php</code> to fit your needs.
                    The application will not work if not filled out correct.</p>
                    <p><bold>The email address in the <code>from</code> field must be a valid email address.</bold>
                    It is not only used as 'From:" address, it functions also as mail recipient address for error messages.</p>

                    <h4>SMTP</h4>
                    <p>Set up your mail server in <code>smtp_config.php</code> to send emails via SMTP.
                    This is the recomended method because the fall back is the use of the php <code>mail()</code>
                    function which will most likely use sendmail. When sending mails by sendmail they will most likely markt as spam.</p>

                    <h4>Example config files</h4>
                    For both config files there are example files in the <code>admin</code> folder. You can copy them and adjust them to your needs.
                </article>
            </details>

            <hr class="large">

            <details>
                <summary>
                    <article class="round primary no-elevate">
                    <nav>
                        <div class="max bold">Usage</div>
                        <i>expand_more</i>
                    </nav>
                    </article>
                </summary>
                <article class="round border">
                    <h3>PowerShell</h3>
                    <pre>
                        <code>
Invoke-RestMethod -Uri "https://example.com/CuckooPost" `
  -Method Post `
  -Headers @{ "Authorization" = "Bearer YOUR_TOKEN" } `
  -Body @{
    mailto = "someone@example.com"
    subject = "Hello"
    message = "Test message"
  } `
  -ContentType "application/x-www-form-urlencoded"</code>
                    </pre>
                </article>
                <article class="round border">
                    <h3>cURL</h3>
                    <pre>
                        <code>
curl -X POST "https://example.com/CuckooPost" \
    -H "Authorization: Bearer YOUR_TOKEN" \
    -d "mailto=someone@example.com" \
    -d "subject=Hello" \
    -d "message=Test%20message"
                        </code>
                    </pre>
                </article>
                <article class="round border">
                    <h3>Python</h3>
                    <pre>
                        <code>
import requests

url = "https://example.com/CuckooPost"
headers = {
    "Authorization": "Bearer YOUR_TOKEN",
    "Content-Type": "application/x-www-form-urlencoded"
}
data = {
    "mailto": "someone@example.com",
    "subject": "Hello",
    "message": "Test message"
}

response = requests.post(url, headers=headers, data=data)
if response.ok:
    print("Success:", response.text)
else:
    print("Error:", response.status_code, response.text)</code>
                    </pre>
                </article>
                <article class="round border">
                    <h3>REST</h3>
                    <pre>
                        <code>
POST https://example.com/CuckooPost  HTTP/1.1
Authorization: Bearer YOUR_TOKEN
Content-Type: application/x-www-form-urlencoded

mailto=someone@example.com&subject=Hello&message=Test%20message</code>
                    </pre>
                </article>
            </details>

            <hr class="large">

            <h1>Manage Tokens</h1>
            <table class="table stripes medium-space">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Limit</th>
                        <th>Expiration Date</th>
                        <th>UUID</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $token): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($token['description']); ?></td>
                        <td class="<?php echo ((int)$token['limit'] !== 0 && (int)$token['counter'] >= (int)$token['limit']) ? 'error-text' : ''; ?>">
                            <?php
                                $maxLimit = (int)$token['limit'] === 0 ? '<i class="small">all_inclusive</i>' : $token['limit'];
                                $displayLimits = (int)$token['counter'] . ' / ' . $maxLimit;
                                echo $displayLimits;
                                ?>
                        </td>
                        <td class="<?php echo (new DateTime($token['expiration_date']) < new DateTime()) ? 'error-text' : ''; ?>">
                            <?php echo htmlspecialchars($token['expiration_date']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($token['uuid']); ?></td>
                        <td>
                            <button class="button" onclick="copyToClipboard('<?php echo htmlspecialchars($token['uuid']); ?>')">Copy</button>
                            <a href="?delete=<?php echo urlencode($token['uuid']); ?>" class="button danger">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
