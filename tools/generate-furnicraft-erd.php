<?php

declare(strict_types=1);

/**
 * Generate a focused ERD for FurniCraft's core customer workflows.
 * Run from the repository root: php tools/generate-furnicraft-erd.php
 */

$tables = [
    'users' => [
        'id|PK', 'name', 'email', 'email_verified_at', 'password', 'role', 'phone', 'is_active',
    ],
    'customer_preferences' => [
        'id|PK', 'user_id|FK:users.id (unique)', 'furniture_type', 'budget_min', 'budget_max', 'style', 'wood_type', 'varnish_finish', 'room_width_m', 'room_depth_m', 'notes',
    ],
    'room_images' => [
        'id|PK', 'user_id|FK:users.id', 'image_path', 'room_width_m', 'room_depth_m', 'analysis', 'analysis_status', 'analyzed_at',
    ],
    'room_preview_analyses' => [
        'id|PK', 'image_sha256', 'pipeline_version', 'status', 'attempts', 'analysis', 'failure_code', 'analyzed_at', 'created_at', 'updated_at',
    ],
    'furniture_products' => [
        'id|PK', 'name', 'category', 'description', 'wood_type', 'varnish_finish', 'style', 'price', 'width_cm', 'depth_cm', 'height_cm', 'image_path', 'status',
    ],
    'recommendations' => [
        'id|PK', 'user_id|FK:users.id', 'customer_preference_id|FK:customer_preferences.id', 'room_image_id|FK:room_images.id (nullable)', 'furniture_type', 'summary', 'created_at',
    ],
    'recommendation_items' => [
        'id|PK', 'recommendation_id|FK:recommendations.id', 'furniture_product_id|FK:furniture_products.id', 'total_score', 'position', 'explanation',
    ],
    'favorites' => [
        'id|PK', 'user_id|FK:users.id', 'furniture_product_id|FK:furniture_products.id', 'created_at',
    ],
    'furniture_view_events' => [
        'id|PK', 'user_id|FK:users.id', 'furniture_product_id|FK:furniture_products.id', 'session_key', 'created_at', 'updated_at',
    ],
    'reservations' => [
        'id|PK', 'reference', 'user_id|FK:users.id', 'furniture_product_id|FK:furniture_products.id', 'recommendation_id|FK:recommendations.id (nullable)', 'branch', 'preferred_visit_at', 'status', 'notes', 'confirmed_at', 'confirmed_by|FK:users.id (nullable)', 'created_at',
    ],
    'reservation_transactions' => [
        'id|PK', 'reservation_id|FK:reservations.id', 'actor_id|FK:users.id (nullable)', 'action', 'notes', 'metadata', 'created_at', 'updated_at',
    ],
    'chat_messages' => [
        'id|PK', 'user_id|FK:users.id', 'recommendation_id|FK:recommendations.id', 'role', 'message', 'created_at',
    ],
    'password_reset_codes' => [
        'id|PK', 'email', 'code_hash', 'expires_at', 'used_at', 'requested_ip', 'created_at', 'updated_at',
    ],
    'notification_logs' => [
        'id|PK', 'user_id|FK:users.id (nullable)', 'type', 'channel', 'recipient', 'subject', 'status', 'payload', 'sent_at', 'created_at', 'updated_at',
    ],
    'system_usability_responses' => [
        'id|PK', 'user_id|FK:users.id (unique)', 'answers', 'sus_score', 'created_at', 'updated_at',
    ],
    'room_placement_evaluations' => [
        'id|PK', 'room_image_id|FK:room_images.id', 'evaluator_id|FK:users.id', 'floor_detection_correct', 'zone_appropriate', 'clearance_respected', 'scale_plausible', 'notes', 'created_at', 'updated_at',
    ],
    'privacy_audit_events' => [
        'id|PK', 'user_id|FK:users.id (nullable)', 'action', 'subject_type', 'subject_id', 'metadata', 'created_at', 'updated_at',
    ],
    'ai_service_events' => [
        'id|PK', 'feature', 'operation', 'status', 'model', 'http_status', 'latency_ms', 'fallback_used', 'failure_code', 'created_at', 'updated_at',
    ],
    'room_preview_generations' => [
        'id|PK', 'room_image_id|FK:room_images.id', 'furniture_product_id|FK:furniture_products.id', 'status', 'placement_json', 'completed_at',
    ],
    'registration_verification_codes' => [
        'id|PK', 'user_id|FK:users.id', 'code_hash', 'expires_at', 'used_at', 'attempts', 'requested_ip', 'created_at', 'updated_at',
    ],
    'cache' => ['key|PK', 'value', 'expiration'],
    'cache_locks' => ['key|PK', 'owner', 'expiration'],
    'failed_jobs' => ['id|PK', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'],
    'job_batches' => ['id|PK', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'failed_job_ids', 'options', 'cancelled_at', 'created_at', 'finished_at'],
    'jobs' => ['id|PK', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'],
    'migrations' => ['id|PK', 'migration', 'batch'],
    'password_reset_tokens' => ['email|PK', 'token', 'created_at'],
    'sessions' => ['id|PK', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'],
];

$foreignKeys = [
    ['users', 'customer_preferences', 'user_id'],
    ['users', 'room_images', 'user_id'],
    ['users', 'furniture_products', 'created_by'],
    ['users', 'recommendations', 'user_id'],
    ['customer_preferences', 'recommendations', 'customer_preference_id'],
    ['room_images', 'recommendations', 'room_image_id'],
    ['recommendations', 'recommendation_items', 'recommendation_id'],
    ['furniture_products', 'recommendation_items', 'furniture_product_id'],
    ['users', 'favorites', 'user_id'],
    ['furniture_products', 'favorites', 'furniture_product_id'],
    ['users', 'furniture_view_events', 'user_id'],
    ['furniture_products', 'furniture_view_events', 'furniture_product_id'],
    ['users', 'reservations', 'user_id'],
    ['furniture_products', 'reservations', 'furniture_product_id'],
    ['recommendations', 'reservations', 'recommendation_id'],
    ['users', 'reservations', 'confirmed_by'],
    ['reservations', 'reservation_transactions', 'reservation_id'],
    ['users', 'reservation_transactions', 'actor_id'],
    ['users', 'chat_messages', 'user_id'],
    ['recommendations', 'chat_messages', 'recommendation_id'],
    ['users', 'notification_logs', 'user_id'],
    ['users', 'system_usability_responses', 'user_id'],
    ['room_images', 'room_placement_evaluations', 'room_image_id'],
    ['users', 'room_placement_evaluations', 'evaluator_id'],
    ['users', 'privacy_audit_events', 'user_id'],
    ['room_images', 'room_preview_generations', 'room_image_id'],
    ['furniture_products', 'room_preview_generations', 'furniture_product_id'],
    ['users', 'registration_verification_codes', 'user_id'],
];

$coreTableNames = [
    'users', 'customer_preferences', 'room_images', 'furniture_products', 'recommendations',
    'recommendation_items', 'favorites', 'reservations', 'chat_messages', 'room_preview_generations',
];
$tables = array_intersect_key($tables, array_flip($coreTableNames));
$foreignKeys = array_values(array_filter($foreignKeys, static fn (array $relationship): bool =>
    isset($tables[$relationship[0]], $tables[$relationship[1]])
    && count(array_filter($tables[$relationship[1]], static fn (string $field): bool =>
        str_starts_with($field, $relationship[2].'|FK:')
    )) > 0
));

$positions = [
    'users' => [0, 0], 'customer_preferences' => [0, 1], 'room_images' => [0, 2],
    'furniture_products' => [1, 0], 'recommendations' => [1, 1], 'recommendation_items' => [1, 2],
    'favorites' => [2, 0], 'reservations' => [2, 1],
    'chat_messages' => [3, 0], 'room_preview_generations' => [3, 1],
];
$columnX = [70, 800, 1530, 2260];
$cardWidth = 620;
$slotHeight = 450;
$top = 200;
$rowHeight = 20;
$nodes = [];
$parts = [];
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
$fmt = static fn (float $n): string => rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');

$parts[] = '<svg xmlns="http://www.w3.org/2000/svg" width="3000" height="1550" viewBox="0 0 3000 1550">';
$parts[] = '<rect width="3000" height="1550" fill="#fbf7f3"/>';
$parts[] = '<style>text{font-family:Arial,sans-serif;fill:#33241d}.title{font-size:35px;font-weight:bold}.subtitle{font-size:17px;fill:#715d52}.table{stroke:#573424;stroke-width:2}.tablehead{fill:#573424}.tablename{font-size:16px;font-weight:bold;fill:#fff}.row{fill:#fffaf5;stroke:#cdb8a8;stroke-width:1}.row-alt{fill:#f4e9de;stroke:#cdb8a8;stroke-width:1}.keycell{fill:#ead6c4;stroke:#c5a88f;stroke-width:1}.keytext{font-size:11px;font-weight:bold;fill:#573424}.field{font-size:12px;fill:#33241d}.edge{stroke:#573424;stroke-width:3.5;opacity:.85;fill:none}.many-mark,.one-mark{stroke:#573424;stroke-width:3;fill:none}.cardinality{font-size:15px;font-weight:bold;fill:#573424;stroke:#fbf7f3;stroke-width:4px;paint-order:stroke fill}.legend{font-size:14px;fill:#715d52}.m2m{font-size:14px;font-weight:bold;fill:#573424}</style>';
$parts[] = '<defs><marker id="one" markerWidth="10" markerHeight="16" refX="0" refY="8" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><path d="M 2 1 L 2 15" class="one-mark"/></marker><marker id="many" markerWidth="18" markerHeight="18" refX="0" refY="9" orient="auto" markerUnits="userSpaceOnUse"><path d="M 0 9 L 15 9 M 0 9 L 11 1 M 0 9 L 11 17" class="many-mark"/></marker></defs>';
$parts[] = '<text x="70" y="55" class="title">FurniCraft Core Database Entity Diagram</text>';
$parts[] = '<text x="70" y="83" class="subtitle">Key fields and relationships for account, room planning, recommendations, reservations, chat, and previews</text>';
$parts[] = '<text x="70" y="112" class="legend">M:N relationships use junction tables: users ↔ favorites ↔ furniture_products; recommendations ↔ recommendation_items ↔ furniture_products.</text>';
$parts[] = '<text x="70" y="140" class="legend">Crowfoot = many · bar = one · PK = primary key · FK = foreign key · other technical tables are omitted for clarity.</text>';

// Draw relationship lines before the table entities so each arrow sits behind its endpoint tables.
$parallel = [];
foreach ($foreignKeys as [$parent, $child, $fk]) {
    $pair = $parent.'>'.$child;
    $parallel[$pair] = ($parallel[$pair] ?? 0) + 1;
}
$seen = [];
foreach ($foreignKeys as [$parent, $child, $fk]) {
    if (!isset($positions[$parent], $positions[$child])) {
        throw new RuntimeException("Missing table position for {$parent} or {$child}.");
    }
    [$pc, $pr] = $positions[$parent];
    [$cc, $cr] = $positions[$child];
    $px = $columnX[$pc]; $py = $top + $pr * $slotHeight;
    $cx = $columnX[$cc]; $cy = $top + $cr * $slotHeight;
    $ph = 48 + count($tables[$parent]) * $rowHeight;
    $ch = 48 + count($tables[$child]) * $rowHeight;
    $dx = (float) $cx - $px;
    $dy = ($cy + $ch / 2) - ($py + $ph / 2);
    $pMidX = $px + $cardWidth / 2;
    $pMidY = $py + $ph / 2;
    $cMidX = $cx + $cardWidth / 2;
    $cMidY = $cy + $ch / 2;
    $dx = $cMidX - $pMidX;
    $dy = $cMidY - $pMidY;
    $norm = max(abs($dx) / ($cardWidth / 2), abs($dy) / ($ph / 2));
    $pScale = 1 / $norm;
    $cScale = 1 / max(abs($dx) / ($cardWidth / 2), abs($dy) / ($ch / 2));
    $x1 = $pMidX + $dx * $pScale;
    $y1 = $pMidY + $dy * $pScale;
    $x2 = $cMidX - $dx * $cScale;
    $y2 = $cMidY - $dy * $cScale;
    $key = $parent.'>'.$child;
    $index = $seen[$key] ?? 0;
    $seen[$key] = $index + 1;
    $offset = $parallel[$key] > 1 ? ($index - ($parallel[$key] - 1) / 2) * 12 : 0;
    if ($offset !== 0) {
        $length = max(1, hypot($dx, $dy));
        $nx = -$dy / $length * $offset;
        $ny = $dx / $length * $offset;
        $x1 += $nx; $y1 += $ny; $x2 += $nx; $y2 += $ny;
    }
    $cardinality = ($fk === 'user_id' && in_array($child, ['customer_preferences', 'system_usability_responses'], true)) ? '1' : 'M';
    $parts[] = '<path d="M '.$fmt($x1).' '.$fmt($y1).' L '.$fmt($x2).' '.$fmt($y2).'" class="edge" marker-start="url(#one)" marker-end="url(#many)"/>';
    $parts[] = '<text x="'.$fmt($x1 + ($x2 - $x1) * .09).'" y="'.$fmt($y1 + ($y2 - $y1) * .09 - 7).'" class="cardinality">1</text>';
    $parts[] = '<text x="'.$fmt($x1 + ($x2 - $x1) * .91).'" y="'.$fmt($y1 + ($y2 - $y1) * .91 - 7).'" class="cardinality">'.$cardinality.'</text>';
}

foreach ($tables as $tableName => $fields) {
    [$column, $row] = $positions[$tableName];
    $x = $columnX[$column];
    $y = $top + $row * $slotHeight;
    $height = 48 + count($fields) * $rowHeight;
    if ($height > $slotHeight - 25) {
        throw new RuntimeException("Table {$tableName} exceeds its diagram slot; expand slotHeight.");
    }
    $parts[] = '<rect x="'.$x.'" y="'.$y.'" width="'.$cardWidth.'" height="'.$height.'" rx="3" class="table" fill="#fffaf5"/>';
    $parts[] = '<path d="M '.$x.' '.($y + 3).' q 0 -3 3 -3 h '.($cardWidth - 6).' q 3 0 3 3 v 34 h -'.$cardWidth.' z" class="tablehead"/>';
    $parts[] = '<text x="'.($x + 12).'" y="'.($y + 24).'" class="tablename">'.$escape($tableName).'</text>';
    foreach ($fields as $index => $field) {
        [$fieldName, $tag] = array_pad(explode('|', $field, 2), 2, '');
        $lineY = $y + 48 + $index * $rowHeight;
        $rowClass = $index % 2 === 0 ? 'row' : 'row-alt';
        $parts[] = '<rect x="'.$x.'" y="'.$lineY.'" width="'.$cardWidth.'" height="'.$rowHeight.'" class="'.$rowClass.'"/>';
        $parts[] = '<rect x="'.$x.'" y="'.$lineY.'" width="52" height="'.$rowHeight.'" class="keycell"/>';
        $keyLabel = $tag === 'PK' ? 'PK' : (str_starts_with($tag, 'FK:') ? 'FK' : '');
        if ($keyLabel !== '') {
            $parts[] = '<text x="'.($x + 13).'" y="'.($lineY + 14).'" class="keytext">'.$keyLabel.'</text>';
        }
        $displayName = $fieldName;
        if (str_contains($tag, '(nullable)')) {
            $displayName .= ' (nullable)';
        } elseif (str_contains($tag, '(unique)')) {
            $displayName .= ' (unique)';
        }
        $parts[] = '<text x="'.($x + 62).'" y="'.($lineY + 14).'" class="field">'.$escape($displayName).'</text>';
    }
}

$parts[] = '<text x="70" y="1518" class="legend">Shows 10 core entities and their key columns; full schema details remain in the Laravel migrations.</text>';
$parts[] = '</svg>';

$outputPath = dirname(__DIR__).'/output/furnicraft-erd-alpha.svg';
if (file_put_contents($outputPath, implode("\n", $parts)."\n") === false) {
    throw new RuntimeException("Could not write {$outputPath}");
}

echo 'Wrote '.$outputPath.' with '.count($tables).' core tables, '.array_sum(array_map('count', $tables)).' key columns, and '.count($foreignKeys).' foreign keys.'.PHP_EOL;
