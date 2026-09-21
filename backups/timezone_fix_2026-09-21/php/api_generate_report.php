<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$user = require_login($pdo);

require_once __DIR__ . '/../services/PermissionService.php';
require_once __DIR__ . '/../services/ReportService.php';
require_once __DIR__ . '/../services/ReportPeriodHelper.php';
require_once __DIR__ . '/../services/PdfReportService.php';
require_once __DIR__ . '/../services/ExcelReportService.php';

PermissionService::requirePermission($user['role'], 'reports.generate');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}
if ($method === 'POST') {
    require_csrf();
}

// Merge query string, form fields and JSON body into a single input map.
$input = array_merge($_GET, $_POST, read_json_body());

$format = strtolower(trim((string)($input['format'] ?? '')));
if (!in_array($format, ['pdf', 'xlsx'], true)) {
    respond(['success' => false, 'message' => 'Invalid format. Allowed: pdf, xlsx.'], 422);
}

$period = strtolower(trim((string)($input['period'] ?? '')));
if (!ReportPeriodHelper::isValidPeriod($period)) {
    respond(['success' => false, 'message' => 'Invalid period. Allowed: today, week, month, year, custom.'], 422);
}

$startDate = trim((string)($input['start_date'] ?? ''));
$endDate = trim((string)($input['end_date'] ?? ''));

if ($period === 'custom') {
    if ($startDate === '' || $endDate === '') {
        respond(['success' => false, 'message' => 'Custom period requires start_date and end_date.'], 422);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        respond(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD.'], 422);
    }
    if ($startDate > $endDate) {
        respond(['success' => false, 'message' => 'start_date must not be after end_date.'], 422);
    }
} elseif ($startDate !== '' || $endDate !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        respond(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD.'], 422);
    }
    if ($startDate > $endDate) {
        respond(['success' => false, 'message' => 'start_date must not be after end_date.'], 422);
    }
}

$type = strtolower(trim((string)($input['type'] ?? 'general')));
if (!in_array($type, ['general', 'custom'], true)) {
    respond(['success' => false, 'message' => 'Invalid report type. Allowed: general, custom.'], 422);
}

$categories = [];
if (isset($input['categories'])) {
    $raw = $input['categories'];
    $categories = is_array($raw) ? array_map('strval', $raw) : explode(',', (string)$raw);
}
$categories = array_values(array_filter(array_map('strtolower', array_map('trim', $categories)), fn ($c) => $c !== ''));

$options = [
    'period' => $period,
    'start_date' => $startDate !== '' ? $startDate : null,
    'end_date' => $endDate !== '' ? $endDate : null,
    'type' => $type,
    'categories' => $categories,
];

try {
    $report = (new ReportService())->generateReport($options, $user);
} catch (InvalidArgumentException $e) {
    respond(['success' => false, 'message' => $e->getMessage()], 422);
}

// Wide tables read better in landscape orientation. Pick landscape when a
// section needs six or more columns, or when the columns' minimum widths
// would exceed the A4 portrait printable width.
$landscape = false;
$portraitContentW = 515.0; // A4 portrait minus the 40pt page margins
foreach (($report['sections'] ?? []) as $section) {
    foreach ([$section['columns'] ?? [], ...($section['subsections'] ?? [])] as $cols) {
        if (count($cols) >= 6 || PdfReportService::estimateMinWidth($cols) > $portraitContentW) {
            $landscape = true;
            break 2;
        }
    }
}

$safeTitle = preg_replace('/[^A-Za-z0-9 _\-]/', '', (string)($report['meta']['title'] ?? 'Report')) ?? '';
$safeTitle = trim(preg_replace('/\s+/', ' ', $safeTitle) ?? '');
$filename = 'MpeliOutFitStore Report - ' . ($safeTitle !== '' ? $safeTitle : 'Report') . '.' . $format;

audit_log(
    (int)$user['id'],
    'report_generated',
    "Format: {$format}, Period: {$period}, Type: {$type}, Categories: " . implode(', ', $report['meta']['categories'] ?? []) . ", Range: " . (($report['meta']['period_start'] ?? '') . ' - ' . ($report['meta']['period_end'] ?? '')),
    'success',
    [
        'module' => 'reports',
        'description' => "Report generated and downloaded ({$format}, {$period}, {$type})",
        'entity_type' => 'report',
        'new_values' => [
            'format' => $format,
            'period' => $period,
            'type' => $type,
            'categories' => $report['meta']['categories'] ?? [],
            'period_start' => $report['meta']['period_start'] ?? null,
            'period_end' => $report['meta']['period_end'] ?? null,
        ],
    ]
);

if ($format === 'pdf') {
    $binary = (new PdfReportService())->render($report, $landscape);
    $contentType = 'application/pdf';
} else {
    $binary = (new ExcelReportService())->render($report);
    $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $contentType . '; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($binary));
header('Cache-Control: no-cache, no-store, must-revalidate');
echo $binary;
exit;
