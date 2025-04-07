<?php
$db = new SQLite3('CuckooPost.db');
$db->exec('CREATE TABLE IF NOT EXISTS tokens (uuid TEXT PRIMARY KEY, `description` TEXT, expiration_date TEXT, `limit` NUMBER DEFAULT 0, `counter` NUMBER DEFAULT 0, recipient_whitelist TEXT)');
$db->exec("CREATE TABLE IF NOT EXISTS mail_logs (token_uuid TEXT, token_description TEXT, recipient TEXT, subject TEXT, message TEXT, sent_at TEXT DEFAULT (datetime('now')), FOREIGN KEY(token_uuid) REFERENCES tokens(uuid))");

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

    $recipientWhitelistRaw = $_POST['recipein-whitelist'];
    $recipientWhitelist = explode(',', $recipientWhitelistRaw);
    $tempRecipientWhitelist = [];
    for ($i = 0; $i < count($recipientWhitelist); $i++) {
        $recipientWhitelist[$i] = trim($recipientWhitelist[$i]);
        $recipient = filter_var($recipientWhitelist[$i], FILTER_VALIDATE_EMAIL);
        if ($recipient) {
            $tempRecipientWhitelist[$i] = $recipient;
        }
    }

    $newRecipientWhitelist = implode(',', $tempRecipientWhitelist);

    $stmt = $db->prepare('INSERT INTO tokens (uuid, `description`, expiration_date, `limit`, recipient_whitelist) VALUES (:uuid, :description, :expiration_date, :limit, :recipient_whitelist)');
    $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
    $stmt->bindValue(':description', $description, SQLITE3_TEXT);
    $stmt->bindValue(':expiration_date', $expiration_date, SQLITE3_TEXT);
    $stmt->bindValue(':limit', $limit, SQLITE3_TEXT);
    $stmt->bindValue(':recipient_whitelist', $newRecipientWhitelist, SQLITE3_TEXT);
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
$result = $db->query('SELECT t.uuid, t.`description`, t.expiration_date, t.`limit`, t.`counter`, t.recipient_whitelist,
                      CASE WHEN EXISTS (SELECT 1 FROM mail_logs ml WHERE ml.token_uuid = t.uuid) THEN 1 ELSE 0 END AS has_logs
                      FROM tokens t');
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
    <link href="https://unpkg.com/tabulator-tables@6.3.1/dist/css/tabulator.min.css" rel="stylesheet">
    <link href="https://unpkg.com/tabulator-tables@6.3.1/dist/css/tabulator_bulma.min.css" rel="stylesheet">
    <script type="text/javascript" src="https://unpkg.com/tabulator-tables@6.3.1/dist/js/tabulator.min.js"></script>
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

            <details>
                <summary>
                    <article class="round border no-elevate slow-ripple">
                    <nav>
                        <i>add</i>
                        <div class="max bold">Add Token</div>
                    </nav>
                    </article>
                </summary>
                <article class="round border">
                    <?php
                        $formState = ($isBaseConfigValid) ? "": "inert";
                    ?>
                    <form method="post" action="" <?php echo $formState ?>>
                        <div class="form-group">
                            <div class="field border label small round">
                                <input type="text" id="description" name="description" required>
                                <label>Description</label>
                            </div>
                            <div class="field border label small round">
                                <input type="date" id="expiration_date" name="expiration_date" required value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                                <label>Expiration date</label>
                                <span class="helper">Entering 2024-02-13 will restrict Access at 2024-02-13 00:00:01</span>
                            </div>
                            <div class="field label border small round">
                                <input type="number" id="limit" name="limit">
                                <label>Limit</label>
                                <i>numbers</i>
                                <span class="helper">Setting this value to '0' will allow an unlimited number of mails to be send.</span>
                            </div>
                            <div class="field border label small round">
                                <input type="text" id="recipein-whitelist" name="recipein-whitelist"
                                       pattern="^([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})(,\s*[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})*$"
                                       title="Enter comma-separated email addresses (e.g., user@example.com, another@example.com)"
                                       oninvalid="this.setCustomValidity('Please enter valid comma-separated email addresses')"
                                       oninput="this.setCustomValidity('')">
                                <label>Recipient whitelist</label>
                                <span class="helper">Comma separated list of allowed email recipient addresses. Leave empty for no filtering.</span>
                                <i>alternate_email</i>
                            </div>
                        </div>
                        <button type="submit" class="button primary slow-ripple"><i>add</i>Generate Token</button>
                    </form>
                </article>
            </details>

            <hr class="large">

            <h3>Existing Tokens </h3>

            <table class="table stripes medium-space">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Limit</th>
                        <th>Expiration Date</th>
                        <th>Token</th>
                        <th>Whitelist</th>
                        <th>Logs</th>
                        <th>Delete</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $token): ?>
                    <tr>
                        <!----------- Description ------------------->
                        <td><?php echo htmlspecialchars($token['description']); ?></td>
                        <!----------- Limit ------------------------->
                        <td class="<?php echo ((int)$token['limit'] !== 0 && (int)$token['counter'] >= (int)$token['limit']) ? 'error-text' : ''; ?>">
                            <?php
                                $maxLimit = (int)$token['limit'] === 0 ? '<i class="small">all_inclusive</i>' : $token['limit'];
                                $displayLimits = (int)$token['counter'] . ' / ' . $maxLimit;
                                echo $displayLimits;
                                ?>
                        </td>
                        <!----------- Expiration date --------------->
                        <td class="<?php echo (new DateTime($token['expiration_date']) < new DateTime()) ? 'error-text' : ''; ?>">
                            <?php echo htmlspecialchars($token['expiration_date']); ?>
                        </td>
                        <td>
                            <code><?php echo htmlspecialchars($token['uuid']); ?></code>
                            <button class="circle medium-elevate small slow-ripple" onclick="copyToClipboard('<?php echo htmlspecialchars($token['uuid']); ?>')">
                                <i>content_copy</i>
                                <div class="tooltip">Copy token to clipboard</div>
                            </button>

                        </td>
                        <!----------- Recipient Whitelist ----------->
                        <td class="center-align">
                            <?php if(empty($token['recipient_whitelist'])): ?>
                                <i>all_inclusive</i>
                            <?php else: ?>
                                <button data-ui="#whitelist-dialog-<?php echo htmlspecialchars($token['uuid']); ?>" class="circle medium-elevate small slow-ripple" onclick="copyToClipboard('<?php echo htmlspecialchars($token['uuid']); ?>')">
                                    <i>playlist_add_check</i>
                                    <div class="tooltip">View recipient whitelist</div>
                                </button>
                                <div class="overlay blur"></div>
                                <dialog id="whitelist-dialog-<?php echo htmlspecialchars($token['uuid']); ?>">
                                    <h5>Recipient Whitelist for <?php echo htmlspecialchars($token['description']); ?></h5>
                                    <div>
                                        <ul class="list border no-space">
                                            <?php
                                                $recipientWhitelist = explode(',', $token['recipient_whitelist']);
                                                foreach ($recipientWhitelist as $recipient) {
                                                    echo '<li>' . htmlspecialchars($recipient) . '</li>';
                                                }
                                            ?>
                                        </ul>
                                    </div>
                                    <nav class="right-align no-space">
                                        <button data-ui="#whitelist-dialog-<?php echo htmlspecialchars($token['uuid']); ?>" class="button"><i>close</i>Close</button>
                                    </nav>
                                </dialog>
                            <?php endif; ?>
                        </td>
                        <!----------- Logs ------------------------->
                        <td>
                            <?php if(!empty($token['has_logs'])): ?>
                                <button data-ui="#mail-logs-dialog-<?php echo htmlspecialchars($token['uuid']); ?>" class="circle medium-elevate small slow-ripple" onclick="fetchMailLogs('<?php echo htmlspecialchars($token['uuid']); ?>')">
                                    <i>overview</i>
                                    <div class="tooltip">View token mail logs</div>
                                </button>

                                <dialog id="mail-logs-dialog-<?php echo htmlspecialchars($token['uuid']); ?>" class="max">
                                    <h5>Mail logs for <?php echo htmlspecialchars($token['description']); ?></h5>
                                    <div id="mail-logs-table-<?php echo htmlspecialchars($token['uuid']); ?>"></div>
                                    <nav class="right-align no-space">
                                        <button data-ui="#mail-logs-dialog-<?php echo htmlspecialchars($token['uuid']); ?>" class="button"><i>close</i>Close</button>
                                    </nav>
                                </dialog>
                            <?php endif; ?>
                        </td>
                        <!----------- Delete ----------------------->
                        <td>
                            <a href="?delete=<?php echo urlencode($token['uuid']); ?>" class="button circle danger small slow-ripple"><i>delete</i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <hr class="large">

            <details>
                <summary>
                    <article class="round border no-elevate slow-ripple">
                    <nav>
                        <i>expand_more</i>
                        <div class="max bold">Help</div>

                    </nav>
                    </article>
                </summary>
                <article class="round border">
                    <h4>Config</h4>
                    <h5>Base Config</h5>
                    <p><bold>It is important</bold> that you adjust the <code>base_config.php</code> to fit your needs.
                    The application will not work if not filled out correct.</p>
                    <p><bold>The email address in the <code>from</code> field must be a valid email address.</bold>
                    It is not only used as 'From:" address, it functions also as mail recipient address for error messages.</p>

                    <h5>SMTP</h5>
                    <p>Set up your mail server in <code>smtp_config.php</code> to send emails via SMTP.
                    This is the recomended method because the fall back is the use of the php <code>mail()</code>
                    function which will most likely use sendmail. When sending mails by sendmail they will most likely markt as spam.</p>

                    <h5>Example config files</h5>
                    For both config files there are example files in the <code>admin</code> folder. You can copy them and adjust them to your needs.
                </article>
            </details>

            <details>
                <summary>
                    <article class="round border no-elevate slow-ripple">
                    <nav>
                        <i>expand_more</i>
                        <div class="max bold">Usage</div>
                    </nav>
                    </article>
                </summary>
                <article class="round border">
                    <h5>PowerShell</h5>
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
                    <h5>cURL</h5>
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
                    <h5>Python</h5>
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
                    <h5>REST</h5>
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

            <footer class="fixed center-align">
                <a href="https://github.com/soulflyman/CuckooPost" target="_blank">
                    <i class="extra">
                        <svg viewBox="0 0 24 24">
                            <path d="M12,2A10,10 0 0,0 2,12C2,16.42 4.87,20.17 8.84,21.5C9.34,21.58 9.5,21.27 9.5,21C9.5,20.77 9.5,20.14 9.5,19.31C6.73,19.91 6.14,17.97 6.14,17.97C5.68,16.81 5.03,16.5 5.03,16.5C4.12,15.88 5.1,15.9 5.1,15.9C6.1,15.97 6.63,16.93 6.63,16.93C7.5,18.45 8.97,18 9.54,17.76C9.63,17.11 9.89,16.67 10.17,16.42C7.95,16.17 5.62,15.31 5.62,11.5C5.62,10.39 6,9.5 6.65,8.79C6.55,8.54 6.2,7.5 6.75,6.15C6.75,6.15 7.59,5.88 9.5,7.17C10.29,6.95 11.15,6.84 12,6.84C12.85,6.84 13.71,6.95 14.5,7.17C16.41,5.88 17.25,6.15 17.25,6.15C17.8,7.5 17.45,8.54 17.35,8.79C18,9.5 18.38,10.39 18.38,11.5C18.38,15.32 16.04,16.16 13.81,16.41C14.17,16.72 14.5,17.33 14.5,18.26C14.5,19.6 14.5,20.68 14.5,21C14.5,21.27 14.66,21.59 15.17,21.5C19.14,20.16 22,16.42 22,12A10,10 0 0,0 12,2Z">
                            </path>
                        </svg>
                    </i>
                </a>
            </footer>
        </div>
    </main>
    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                return;
            }, function(err) {
                alert('Failed to copy token');
            });
        }

        function fetchMailLogs(uuid) {
            const tableId = '#mail-logs-table-' + uuid;
            if (!document.querySelector(tableId).classList.contains('tabulator')) {
                new Tabulator(tableId, {
                    ajaxURL: 'mail_logs.php?token_uuid=' + uuid,
                    layout: "fitColumns",
                    columns: [
                        { title: "Recipient", field: "recipient" },
                        { title: "Subject", field: "subject" },
                        { title: "Message", field: "message" },
                        { title: "Sent At", field: "sent_at" }
                    ]
                });
            } else {
                var table = Tabulator.findTable("#mail-logs-table-" + uuid)[0]; // find table object for table with id of example-table
                table.replaceData();
            }
        }
    </script>
</body>
</html>
