<?php

declare(strict_types=1);

namespace ChapterScript;

const MAX_BODY_BYTES = 32768;
const FIELDS = [
    'name' => ['Name', 150],
    'email' => ['Email', 254],
    'organization' => ['Studio or organization', 200],
    'website' => ['Website or portfolio', 500],
    'story' => ['Tell us your story', 3000],
    'heard_about' => ['How did you hear about ChapterScript?', 300],
];
const CHOICES = [
    'role' => ['Director or filmmaker', 'Producer', 'Artist or designer', 'Developer', 'Educator', 'Museum or cultural institution', 'Brand or agency', 'Something else'],
    'vision_pro' => ['Yes', 'Planning to get one', 'Not yet'],
    'mac' => ['Apple silicon', 'Intel', 'No Mac yet'],
    'shipped' => ["Yes, it's out", 'In progress', 'Not yet'],
    'tools' => ['Reality Composer Pro', 'Unity', 'Unreal Engine', 'Blender', 'Final Cut Pro', 'DaVinci Resolve', 'Premiere Pro or After Effects', 'Something else'],
    'making' => ['Narrative story', 'Immersive film', 'Documentary', 'Education or training', 'Museum or exhibition', 'Live event or performance', 'Brand experience', 'Something else'],
    'timeline' => ['Right away', 'In the next few months', 'Just exploring'],
];
const CHOICE_LABELS = [
    'role' => 'What best describes you?', 'vision_pro' => 'Do you have an Apple Vision Pro?',
    'mac' => 'Which Mac do you work on?', 'shipped' => 'Have you released anything for Apple Vision Pro?',
    'tools' => 'What do you create with today?', 'making' => 'What would you make with ChapterScript?',
    'timeline' => 'When would you like to start?',
];

function connect(array $config): \PDO
{
    $db = $config['database'] ?? [];
    foreach (['host', 'name', 'username', 'password'] as $key) {
        if (!isset($db[$key]) || !is_string($db[$key]) || $db[$key] === '') {
            throw new \RuntimeException('Missing database configuration');
        }
    }
    // Configuration is private, but disallow DSN delimiters in interpolated values.
    if (preg_match('/[;\x00-\x20]/', $db['host'] . $db['name'])) {
        throw new \RuntimeException('Invalid database configuration');
    }
    $pdo = new \PDO("mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4", $db['username'], $db['password'], [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_EMULATE_PREPARES => false,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function originAllowed(array $server, array $allowed): bool
{
    if (($server['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
        return false;
    }
    if (isset($server['HTTP_ORIGIN'])) {
        return in_array($server['HTTP_ORIGIN'], $allowed, true);
    }
    // Older browsers may omit Origin on a regular form POST. A same-origin
    // Referer is the fallback; a request with neither is rejected.
    $parts = parse_url($server['HTTP_REFERER'] ?? '');
    if (!$parts || !isset($parts['scheme'], $parts['host'])) {
        return false;
    }
    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }
    return in_array($origin, $allowed, true);
}

function ipKey(string $ip, string $secret): string
{
    $packed = @inet_pton($ip);
    if ($packed === false || !preg_match('/\A[0-9a-f]{64}\z/i', $secret)) {
        throw new \RuntimeException('Invalid rate-limit configuration or client address');
    }
    // Never read user-supplied X-Forwarded-For / CF-Connecting-IP headers.
    return hash_hmac('sha256', $packed, $secret);
}

/** Returns seconds until retry, or zero. Called only after origin validation; invalid answers count. */
function rateLimit(\PDO $pdo, string $key, array $limits, int $now): int
{
    if (!$limits) {
        throw new \RuntimeException('Missing rate limits');
    }
    ksort($limits, SORT_NUMERIC); // Consistent locking order across concurrent requests.
    foreach ($limits as $seconds => $limit) {
        if (!is_int($seconds) || $seconds < 1 || $seconds > 604800 || !is_int($limit) || $limit < 1 || $limit > 10000) {
            throw new \RuntimeException('Invalid rate limits');
        }
    }
    // Indexed, bounded cleanup avoids a cron dependency and unbounded stale rows.
    $cleanup = $pdo->prepare('DELETE FROM early_access_rate_limits WHERE expires_at <= ? LIMIT 100');
    $cleanup->execute([$now]);
    $pdo->beginTransaction();
    try {
        $increment = $pdo->prepare('INSERT INTO early_access_rate_limits (ip_key, window_seconds, attempts, expires_at)
            VALUES (?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE
                attempts = IF(expires_at <= ?, 1, LEAST(attempts + 1, ?)),
                expires_at = IF(expires_at <= ?, VALUES(expires_at), expires_at)');
        $read = $pdo->prepare('SELECT attempts, expires_at FROM early_access_rate_limits WHERE ip_key = ? AND window_seconds = ? FOR UPDATE');
        $retry = 0;
        foreach ($limits as $seconds => $limit) {
            $increment->execute([$key, $seconds, $now + $seconds, $now, $limit + 1, $now]);
            $read->execute([$key, $seconds]);
            $row = $read->fetch();
            if ((int) $row['attempts'] > $limit) {
                $retry = max($retry, (int) $row['expires_at'] - $now);
            }
        }
        $pdo->commit();
        return $retry;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Validate without silently truncating user answers. */
function validate(array $input): array
{
    $data = [];
    $errors = [];
    foreach (FIELDS as $key => [$label, $max]) {
        $value = $input[$key] ?? '';
        if (!is_string($value) || !preg_match('//u', $value)) {
            $errors[$key] = "$label must be text.";
            continue;
        }
        $value = trim($value);
        // Count Unicode characters without requiring mbstring on the host.
        $length = preg_match_all('/./us', $value);
        if ($length > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            $errors[$key] = "$label must be at most $max characters and contain no control characters.";
        }
        $data[$key] = $value;
    }
    if (isset($data['name']) && $data['name'] === '') {
        $errors['name'] = 'Tell us what to call you.';
    }
    if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (isset($data['story']) && preg_match_all('/./us', $data['story']) < 10) {
        $errors['story'] = 'Tell us a little more about your story (at least 10 characters).';
    }
    foreach (CHOICES as $key => $options) {
        if (in_array($key, ['tools', 'making'], true)) {
            $value = $input[$key] ?? [];
            if (!is_array($value) || !array_is_list($value) || count($value) > count($options)) {
                $errors[$key] = 'Choose from the listed options for ' . CHOICE_LABELS[$key];
                continue;
            }
            foreach ($value as $choice) {
                if (!is_string($choice) || !in_array($choice, $options, true)) {
                    $errors[$key] = 'Choose from the listed options for ' . CHOICE_LABELS[$key];
                }
            }
            $data[$key] = isset($errors[$key]) ? [] : array_values(array_unique($value));
        } else {
            $value = $input[$key] ?? '';
            if (!is_string($value) || ($value !== '' && !in_array($value, $options, true))) {
                $errors[$key] = 'Choose from the listed options for ' . CHOICE_LABELS[$key];
            } else {
                $data[$key] = $value;
            }
        }
    }
    $updates = $input['updates'] ?? '';
    if (!in_array($updates, ['', 'Yes'], true)) {
        $errors['updates'] = 'Use the checkbox to choose whether to receive updates.';
    }
    $data['updates'] = $updates === 'Yes' ? 1 : 0;
    if (array_diff(array_keys($input), array_merge(array_keys(FIELDS), array_keys(CHOICES), ['updates', '_honey']))) {
        $errors['form'] = 'Unexpected form fields. Reload the page and try again.';
    }
    if (isset($input['_honey']) && !is_string($input['_honey'])) {
        $errors['form'] = 'Invalid form data. Reload the page and try again.';
    }
    if (isset($data['email'])) {
        $data['email'] = strtolower($data['email']);
    }
    return [$data, $errors];
}

function saveApplication(\PDO $pdo, array $data): void
{
    $data['tools'] = json_encode($data['tools'], JSON_THROW_ON_ERROR);
    $data['making'] = json_encode($data['making'], JSON_THROW_ON_ERROR);
    $columns = array_merge(array_keys(FIELDS), array_keys(CHOICES), ['updates']);
    $sql = 'INSERT INTO early_access_applications (' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', array_fill(0, count($columns), '?')) . ')';
    try {
        $pdo->prepare($sql)->execute(array_map(fn ($key) => $data[$key], $columns));
    } catch (\PDOException $e) {
        // The only user-controlled unique column is email. A repeat submission
        // must never change the original answers or marketing consent.
        if (($e->errorInfo[1] ?? null) !== 1062) {
            throw $e;
        }
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Normal form POSTs receive an accessible response with editable answers on failure. */
function respond(int $status, string $message, bool $json, array $errors = [], array $input = []): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $status === 200, 'message' => $message, 'errors' => (object) $errors], JSON_THROW_ON_ERROR);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $ok = $status === 200;
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Get in touch — ChapterScript</title><link rel="stylesheet" href="/assets/css/site.css">'
        . '<main class="access section"><div class="access-inner"><h1>' . ($ok ? 'Thank you.' : 'Your request was not saved.') . '</h1><p>' . escape($message) . '</p>';
    if (!$ok && $input) {
        echo '<form class="form" method="post" action="/api/early-access.php">';
        foreach (FIELDS as $key => [$label, $max]) {
            $value = is_string($input[$key] ?? null) ? $input[$key] : '';
            echo '<div class="field"><label for="' . $key . '">' . escape($label) . '</label>';
            if ($key === 'story') {
                echo '<textarea id="story" name="story" rows="5" maxlength="3000" required>' . escape($value) . '</textarea>';
            } else {
                echo '<input id="' . $key . '" name="' . $key . '" type="' . ($key === 'email' ? 'email' : 'text')
                    . '" maxlength="' . $max . '" value="' . escape($value) . '"' . (in_array($key, ['name', 'email'], true) ? ' required' : '') . '>';
            }
            if (isset($errors[$key])) {
                echo '<p class="err">' . escape($errors[$key]) . '</p>';
            }
            echo '</div>';
        }
        foreach (CHOICES as $key => $options) {
            $multi = in_array($key, ['tools', 'making'], true);
            echo '<fieldset class="field"><legend>' . escape(CHOICE_LABELS[$key]) . '</legend><div class="chips">';
            foreach ($options as $option) {
                $selected = $multi ? (is_array($input[$key] ?? null) && in_array($option, $input[$key], true)) : ($input[$key] ?? '') === $option;
                echo '<label class="chip"><input type="' . ($multi ? 'checkbox' : 'radio') . '" name="' . $key . ($multi ? '[]' : '')
                    . '" value="' . escape($option) . '"' . ($selected ? ' checked' : '') . '><span>' . escape($option) . '</span></label>';
            }
            echo '</div></fieldset>';
        }
        echo '<label><input type="checkbox" name="updates" value="Yes"' . (($input['updates'] ?? '') === 'Yes' ? ' checked' : '')
            . '> Also send me the occasional note about ChapterScript</label>'
            . '<div class="hp" aria-hidden="true"><label>Leave this empty <input name="_honey" tabindex="-1" autocomplete="off"></label></div>'
            . '<div class="submit-row"><button class="btn btn-primary" type="submit">Try again</button></div></form>';
    }
    echo '<p><a href="/#early-access">Back to ChapterScript</a></p></div></main></html>';
    exit;
}
