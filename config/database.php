<?php
/**
 * Google Sheets "Database" 
 * MySQL ki jagah Google Sheets Sheets API v4 use karta hai.
 * 
 * Setup:
 * 1. Google Cloud Console mein ek Service Account banao
 * 2. Sheets API enable karo
 * 3. JSON key download karo aur config/google-credentials.json mein rakh do
 * 4. Sheet ko is service account email se share karo (editor permissions)
 * 
 * Sheet tabs (worksheets) table ki tarah kaam karte hain:
 *   - staff
 *   - doctors
 *   - patients
 *   - products
 *   - diagnoses
 *   - visits
 *   - password_resets
 *   - settings
 */

class SheetsDB
{
    private $spreadsheetId;
    private $accessToken;
    private static $instance = null;

    // Sheet names -> tab names in Google Sheets
    private $sheets = [
        'staff'           => 'staff',
        'doctors'         => 'doctors',
        'patients'        => 'patients',
        'products'        => 'products',
        'diagnoses'       => 'diagnoses',
        'visits'          => 'visits',
        'password_resets' => 'password_resets',
        'settings'        => 'settings',
    ];

    public function __construct()
    {
        $this->spreadsheetId = GOOGLE_SHEET_ID;
        $this->accessToken   = $this->getAccessToken();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─── OAuth2 Service Account ───────────────────────────────────────────────

    private function getAccessToken(): string
    {
        $credFile = GOOGLE_SERVICE_ACCOUNT_JSON;
        if (!file_exists($credFile)) {
            throw new RuntimeException(
                "Google credentials file not found: $credFile\n" .
                "Please download your Service Account JSON key and place it at config/google-credentials.json"
            );
        }
        $creds = json_decode(file_get_contents($credFile), true);

        $now = time();
        $payload = [
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ];

        $jwt = $this->createJWT($payload, $creds['private_key']);

        $response = $this->httpPost('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        if (empty($response['access_token'])) {
            throw new RuntimeException('Could not get Google access token: ' . json_encode($response));
        }
        return $response['access_token'];
    }

    private function createJWT(array $payload, string $privateKey): string
    {
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $body   = base64_encode(json_encode($payload));
        $header = rtrim(strtr($header, '+/', '-_'), '=');
        $body   = rtrim(strtr($body,   '+/', '-_'), '=');
        $data   = "$header.$body";
        openssl_sign($data, $sig, $privateKey, 'SHA256');
        $sig = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
        return "$data.$sig";
    }

    // ─── HTTP helpers ─────────────────────────────────────────────────────────

    private function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true) ?? [];
    }

    private function apiRequest(string $method, string $url, array $body = []): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
        ];
        if (!empty($body)) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) throw new RuntimeException("cURL error: $err");
        return json_decode($res, true) ?? [];
    }

    // ─── Core Sheet Read / Write ─────────────────────────────────────────────

    /**
     * Read all rows from a sheet tab.
     * Row 1 is treated as column headers.
     * Returns array of associative arrays.
     */
    public function readSheet(string $sheetName): array
    {
        $range = urlencode("{$sheetName}");
        $url   = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$range}";
        $data  = $this->apiRequest('GET', $url);

        $values = $data['values'] ?? [];
        if (count($values) < 1) return [];

        $headers = array_shift($values);
        $rows    = [];
        foreach ($values as $row) {
            // Pad row to header length in case trailing empty cells are omitted
            while (count($row) < count($headers)) $row[] = '';
            $rows[] = array_combine($headers, $row);
        }
        return $rows;
    }

    /**
     * Append a new row to a sheet tab.
     * $data: associative array matching column headers.
     * Returns the new row's 1-based index (row number in sheet).
     */
    public function appendRow(string $sheetName, array $data): int
    {
        // Get headers from row 1
        $headers = $this->getHeaders($sheetName);
        $row = [];
        foreach ($headers as $h) {
            $row[] = $data[$h] ?? '';
        }

        $range = urlencode($sheetName);
        $url   = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$range}:append?valueInputOption=RAW&insertDataOption=INSERT_ROWS";
        $res   = $this->apiRequest('POST', $url, ['values' => [$row]]);

        // Parse updated range to get row number e.g. "staff!A5:K5" -> 5
        $updatedRange = $res['updates']['updatedRange'] ?? '';
        preg_match('/(\d+)$/', $updatedRange, $m);
        return (int)($m[1] ?? 0);
    }

    /**
     * Update a specific row in a sheet tab.
     * $rowIndex: 1-based row number (NOT including header).
     * The actual sheet row = $rowIndex + 1 (because row 1 is header).
     */
    public function updateRow(string $sheetName, int $rowIndex, array $data): void
    {
        $headers   = $this->getHeaders($sheetName);
        $sheetRow  = $rowIndex + 1; // +1 for header row
        $colLetter = $this->colLetter(count($headers));
        $range     = urlencode("{$sheetName}!A{$sheetRow}:{$colLetter}{$sheetRow}");
        $url       = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$range}?valueInputOption=RAW";

        $row = [];
        foreach ($headers as $h) {
            $row[] = $data[$h] ?? '';
        }
        $this->apiRequest('PUT', $url, ['values' => [$row]]);
    }

    /**
     * Get header row of a sheet.
     */
    public function getHeaders(string $sheetName): array
    {
        $range = urlencode("{$sheetName}!1:1");
        $url   = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$range}";
        $data  = $this->apiRequest('GET', $url);
        return $data['values'][0] ?? [];
    }

    /**
     * Convert column count to letter (1->A, 27->AA, etc.)
     */
    private function colLetter(int $n): string
    {
        $result = '';
        while ($n > 0) {
            $n--;
            $result = chr(65 + ($n % 26)) . $result;
            $n = (int)($n / 26);
        }
        return $result;
    }

    // ─── Higher-level helpers (mimic PDO-style) ───────────────────────────────

    /**
     * Find all rows where $column = $value (case-insensitive string match).
     */
    public function where(string $sheet, string $column, $value): array
    {
        $rows   = $this->readSheet($sheet);
        $result = [];
        foreach ($rows as $i => $row) {
            if (isset($row[$column]) && strtolower((string)$row[$column]) === strtolower((string)$value)) {
                $row['_row'] = $i + 2; // 1-indexed sheet row (1 header + 0-indexed array)
                $result[] = $row;
            }
        }
        return $result;
    }

    /**
     * Find first row where $column = $value.
     */
    public function findOne(string $sheet, string $column, $value): ?array
    {
        $rows = $this->where($sheet, $column, $value);
        return $rows[0] ?? null;
    }

    /**
     * Find row by id column.
     */
    public function findById(string $sheet, $id): ?array
    {
        return $this->findOne($sheet, 'id', $id);
    }

    /**
     * Insert a row and auto-assign a numeric id.
     */
    public function insert(string $sheet, array $data): array
    {
        $rows   = $this->readSheet($sheet);
        $maxId  = 0;
        foreach ($rows as $r) {
            if (isset($r['id']) && is_numeric($r['id']) && (int)$r['id'] > $maxId) {
                $maxId = (int)$r['id'];
            }
        }
        $data['id']         = $maxId + 1;
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        $this->appendRow($sheet, $data);
        return $data;
    }

    /**
     * Update row by id. Merges $data into existing row.
     */
    public function update(string $sheet, $id, array $data): bool
    {
        $rows = $this->readSheet($sheet);
        foreach ($rows as $i => $row) {
            if (isset($row['id']) && (string)$row['id'] === (string)$id) {
                $merged = array_merge($row, $data);
                $merged['updated_at'] = date('Y-m-d H:i:s');
                $this->updateRow($sheet, $i + 1, $merged); // +1 because readSheet is 0-indexed
                return true;
            }
        }
        return false;
    }

    /**
     * Soft-delete: sets soft_delete = 1.
     */
    public function softDelete(string $sheet, $id): bool
    {
        return $this->update($sheet, $id, ['soft_delete' => '1']);
    }

    /**
     * Get all rows (excluding soft-deleted).
     */
    public function all(string $sheet, bool $includeSoftDeleted = false): array
    {
        $rows = $this->readSheet($sheet);
        if ($includeSoftDeleted) return $rows;
        return array_values(array_filter($rows, fn($r) => empty($r['soft_delete']) || $r['soft_delete'] === '0'));
    }

    /**
     * Search rows by multiple fields (LIKE simulation).
     */
    public function search(string $sheet, array $fields, string $query): array
    {
        $all    = $this->all($sheet);
        $query  = strtolower($query);
        $result = [];
        foreach ($all as $row) {
            foreach ($fields as $f) {
                if (isset($row[$f]) && str_contains(strtolower((string)$row[$f]), $query)) {
                    $result[] = $row;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * Generate unique patient ID.
     */
    public function generateUniqueId(string $emailPrefix): string
    {
        return strtoupper(substr($emailPrefix, 0, 5)) . '-' . date('ymd-His') . '-' . rand(100, 999);
    }
}

// Global getter
function getDB(): SheetsDB
{
    return SheetsDB::getInstance();
}
