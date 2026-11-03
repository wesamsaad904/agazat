<?php
header("Content-Type: application/json; charset=utf-8");
require_once "config.php";

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents("php://input"), true) ?: [];

function today() { return date("Y-m-d"); }

// إخراج JSON يدعم النصوص العربية دون تهريب
function jout($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function fetch_employee($mysqli, $code) {
    $stmt = $mysqli->prepare("SELECT * FROM employees WHERE code = ?");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res->fetch_assoc();
}

function do_register($mysqli, $code, $name, $number, $workplace) {
    if ($code === '' || $name === '') {
        return ["ok" => false, "message" => "رمز البطاقة والاسم مطلوبان."];
    }
    $stmt = $mysqli->prepare(
        "INSERT INTO employees (code, name, number, workplace, status)
         VALUES (?, ?, ?, ?, 'in')
         ON DUPLICATE KEY UPDATE name = VALUES(name), number = VALUES(number), workplace = VALUES(workplace)"
    );
    $stmt->bind_param("ssss", $code, $name, $number, $workplace);
    $stmt->execute();
    return ["ok" => true, "employee" => fetch_employee($mysqli, $code)];
}

// $date must be "YYYY-MM-DD". Used both for live scans (date = today) and
// for backdated rows coming from an Excel import.
function do_scan($mysqli, $code, $mode, $date) {
    $employee = fetch_employee($mysqli, $code);
    if (!$employee) {
        return ["ok" => false, "reason" => "not_registered", "code" => $code];
    }

    if ($mode === "out") {
        if ($employee['status'] === 'out') {
            return ["ok" => false, "reason" => "already_out", "employee" => $employee];
        }
        $stmt = $mysqli->prepare("UPDATE employees SET status = 'out', last_out = ? WHERE code = ?");
        $stmt->bind_param("ss", $date, $code);
        $stmt->execute();
        return ["ok" => true, "employee" => fetch_employee($mysqli, $code)];
    }

    if ($mode === "in") {
        if ($employee['status'] !== 'out') {
            return ["ok" => false, "reason" => "not_out", "employee" => $employee];
        }
        $out = $employee['last_out'];
        $days = (new DateTime($out))->diff(new DateTime($date))->days;

        $stmt = $mysqli->prepare(
            "INSERT INTO trips (employee_code, date_out, date_in, days_away) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("sssi", $code, $out, $date, $days);
        $stmt->execute();

        $stmt = $mysqli->prepare("UPDATE employees SET status = 'in', last_in = ? WHERE code = ?");
        $stmt->bind_param("ss", $date, $code);
        $stmt->execute();

        return ["ok" => true, "employee" => fetch_employee($mysqli, $code), "days" => $days];
    }

    return ["ok" => false, "message" => "وضع غير معروف."];
}

switch ($action) {

    case "list": {
        $result = $mysqli->query(
            "SELECT * FROM employees ORDER BY (status = 'out') DESC, name ASC"
        );
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        jout($rows);
        break;
    }

    case "history": {
        $code = $_GET['code'] ?? '';
        $stmt = $mysqli->prepare(
            "SELECT date_out, date_in, days_away FROM trips WHERE employee_code = ? ORDER BY date_in DESC"
        );
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        jout($rows);
        break;
    }

    case "register":
    case "add_manual": {
        $code = trim($input['code'] ?? '');
        $name = trim($input['name'] ?? '');
        $number = trim($input['number'] ?? '');
        $workplace = trim($input['workplace'] ?? '');

        if ($action === "add_manual" && fetch_employee($mysqli, $code)) {
            jout(["ok" => false, "message" => "رمز البطاقة مسجل بالفعل."]);
            break;
        }

        jout(do_register($mysqli, $code, $name, $number, $workplace));
        break;
    }

    case "scan": {
        $code = trim($input['code'] ?? '');
        $mode = $input['mode'] ?? '';
        $date = $input['date'] ?? today();
        jout(do_scan($mysqli, $code, $mode, $date));
        break;
    }

    case "import_excel": {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            jout(["ok" => false, "message" => "لم يتم رفع أي ملف."]);
            break;
        }
        require_once "xlsx_reader.php";
        $fallbackDate = $_POST['date'] ?? today();

        try {
            $sheets = read_xlsx($_FILES['file']['tmp_name']);
        } catch (Exception $e) {
            jout(["ok" => false, "message" => "تعذر قراءة الملف: " . $e->getMessage()]);
            break;
        }

        if (count($sheets) < 2) {
            jout(["ok" => false, "message" => "يجب أن يحتوي الملف على ورقتين على الأقل: واحدة لتسجيلات الخروج وأخرى لتسجيلات العودة."]);
            break;
        }

        $result = ["departures_processed" => 0, "arrivals_processed" => 0, "errors" => []];

        // Sheet 1 = departures, sheet 2 = arrivals. Columns: Code, Name, Number, Workplace, Date (optional).
        // Row 0 of each sheet is assumed to be a header row and is skipped.
        $departureRows = array_slice($sheets[0]['rows'], 1);
        foreach ($departureRows as $i => $row) {
            $code = trim($row[0] ?? '');
            if ($code === '') continue;
            $name = trim($row[1] ?? '');
            $number = trim($row[2] ?? '');
            $workplace = trim($row[3] ?? '');
            $date = trim($row[4] ?? '') ?: $fallbackDate;

            if ($name !== '') do_register($mysqli, $code, $name, $number, $workplace);
            $r = do_scan($mysqli, $code, "out", $date);
            if ($r['ok']) {
                $result['departures_processed']++;
            } else {
                $result['errors'][] = "صف الخروج رقم " . ($i + 2) . " ($code): " . ($r['message'] ?? $r['reason']);
            }
        }

        $arrivalRows = array_slice($sheets[1]['rows'], 1);
        foreach ($arrivalRows as $i => $row) {
            $code = trim($row[0] ?? '');
            if ($code === '') continue;
            $name = trim($row[1] ?? '');
            $number = trim($row[2] ?? '');
            $workplace = trim($row[3] ?? '');
            $date = trim($row[4] ?? '') ?: $fallbackDate;

            if ($name !== '') do_register($mysqli, $code, $name, $number, $workplace);
            $r = do_scan($mysqli, $code, "in", $date);
            if ($r['ok']) {
                $result['arrivals_processed']++;
            } else {
                $result['errors'][] = "صف العودة رقم " . ($i + 2) . " ($code): " . ($r['message'] ?? $r['reason']);
            }
        }

        $result['ok'] = true;
        jout($result);
        break;
    }

    case "search": {
        $type = $_GET['type'] ?? '';
        $value = trim($_GET['value'] ?? '');
        if ($value === '') {
            jout(["ok" => false, "message" => "يرجى إدخال اسم أو تاريخ للبحث."]);
            break;
        }

        if ($type === "name") {
            $like = '%' . $value . '%';
            $stmt = $mysqli->prepare("SELECT * FROM employees WHERE name LIKE ? OR code LIKE ? OR number LIKE ? ORDER BY name");
            $stmt->bind_param("sss", $like, $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            $matches = [];
            while ($emp = $res->fetch_assoc()) {
                $hstmt = $mysqli->prepare("SELECT date_out, date_in, days_away FROM trips WHERE employee_code = ? ORDER BY date_in DESC");
                $hstmt->bind_param("s", $emp['code']);
                $hstmt->execute();
                $hres = $hstmt->get_result();
                $history = [];
                while ($h = $hres->fetch_assoc()) $history[] = $h;
                $matches[] = ["employee" => $emp, "history" => $history];
            }
            jout(["ok" => true, "mode" => "name", "results" => $matches]);

        } elseif ($type === "date") {
            // Departures on this date: closed trips whose date_out matches,
            // plus anyone still currently out whose (open) departure was this date.
            // Arrivals on this date: closed trips whose date_in matches.
            $stmt = $mysqli->prepare(
                "SELECT t.employee_code AS code, e.name, e.number, e.workplace, t.date_out AS date, 'out' AS event
                 FROM trips t JOIN employees e ON e.code = t.employee_code WHERE t.date_out = ?
                 UNION ALL
                 SELECT e.code, e.name, e.number, e.workplace, e.last_out AS date, 'out' AS event
                 FROM employees e WHERE e.status = 'out' AND e.last_out = ?
                 UNION ALL
                 SELECT t.employee_code AS code, e.name, e.number, e.workplace, t.date_in AS date, 'in' AS event
                 FROM trips t JOIN employees e ON e.code = t.employee_code WHERE t.date_in = ?
                 ORDER BY name"
            );
            $stmt->bind_param("sss", $value, $value, $value);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($r = $res->fetch_assoc()) $rows[] = $r;
            jout(["ok" => true, "mode" => "date", "date" => $value, "results" => $rows]);

        } else {
            jout(["ok" => false, "message" => "نوع بحث غير معروف."]);
        }
        break;
    }

    case "export": {
        $employees = [];
        $result = $mysqli->query("SELECT * FROM employees");
        while ($row = $result->fetch_assoc()) $employees[] = $row;

        $trips = [];
        $result = $mysqli->query("SELECT * FROM trips");
        while ($row = $result->fetch_assoc()) $trips[] = $row;

        jout(["employees" => $employees, "trips" => $trips]);
        break;
    }

    case "clear_all": {
        $mysqli->query("DELETE FROM trips");
        $mysqli->query("DELETE FROM employees");
        jout(["ok" => true]);
        break;
    }

    default:
        http_response_code(400);
        jout(["ok" => false, "message" => "إجراء غير معروف: $action"]);
}
