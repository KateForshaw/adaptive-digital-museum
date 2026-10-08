<?php
// =====================================================================
// Digital Museum Research Project
// Phase 2 - Behaviour Logging Engine
// tests/phase2/test_event_logging.php
//
// Test cases BL1-BL4 (Testing Plans.docx > Phase 2 Test Cases) - all
// four hit the same endpoint (api/log_event.php), so they're tested
// together here:
//   BL1  Test click event logging       - Click test button                 -> event_log stores click event
//   BL2  Test dwell_start + dwell_end    - Open modal -> close modal         -> two events logged with correct dwell duration
//   BL3  Test navigation depth           - Open 3 artefacts sequentially     -> navigation_depth increments correctly
//   BL4  Test revisit detection          - Open same artefact twice          -> revisit flag logged
//
// Like the other Phase 2 tests, this exercises the real HTTP endpoint
// with cURL rather than querying the database directly.
//
// Creates a throwaway participant/session directly in the database,
// uses 3 real (already-seeded) artefact_ids for the event calls, then
// deletes the throwaway data afterwards. The artefacts themselves are
// never touched.
// =====================================================================

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain');

$baseUrl  = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$endpoint = $baseUrl . '/digitalmuseum/api/log_event.php';

$errors = [];
$testParticipantCode = 'TEST_EV_' . time(); // participant_code is VARCHAR(20) - keep prefix short
$participantId = null;
$sessionId     = null;

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function postJson(string $url, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$httpCode, json_decode($body, true)];
}

function getRequest(string $url): int
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode;
}

try {
    // -------------------------------------------------------------
    // Setup: throwaway participant + open session, and 3 real
    // artefact_ids to use across the test cases below
    // -------------------------------------------------------------
    $stmt = $pdo->prepare('INSERT INTO participant (participant_code) VALUES (:code)');
    $stmt->execute([':code' => $testParticipantCode]);
    $participantId = (int)$pdo->lastInsertId();

    $conditionId = $pdo->query('SELECT condition_id FROM study_condition ORDER BY condition_id LIMIT 1')
        ->fetchColumn();
    if ($conditionId === false) {
        throw new RuntimeException('study_condition table is empty - run insert_conditions.sql first.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO session_log
            (participant_id, condition_id, session_start,
             timeout_triggered_static, timeout_triggered_adaptive, error_flag)
        VALUES
            (:participant_id, :condition_id, :session_start, FALSE, FALSE, FALSE)
    ");
    $stmt->execute([
        ':participant_id' => $participantId,
        ':condition_id'   => $conditionId,
        ':session_start'  => date('Y-m-d H:i:s'),
    ]);
    $sessionId = (int)$pdo->lastInsertId();

    $artefactIds = $pdo->query('SELECT artefact_id FROM artefact ORDER BY artefact_id LIMIT 3')
        ->fetchAll(PDO::FETCH_COLUMN);
    if (count($artefactIds) < 3) {
        throw new RuntimeException('Need at least 3 seeded artefacts - run insert_metadata.sql first.');
    }
    [$artefactA, $artefactB, $artefactC] = $artefactIds;

    // -------------------------------------------------------------
    // BL1: click event
    // -------------------------------------------------------------
    [$httpCode, $data] = postJson($endpoint, [
        'session_id'  => $sessionId,
        'artefact_id' => $artefactA,
        'event_type'  => 'click',
    ]);

    if ($httpCode !== 201 || ($data['success'] ?? false) !== true) {
        $errors[] = "BL1: expected HTTP 201 + success=true for a click event, got {$httpCode}.";
    } else {
        $row = fetchEvent($pdo, (int)$data['event_id']);
        if (!$row || $row['event_type'] !== 'click' || (int)$row['artefact_id'] !== $artefactA) {
            $errors[] = 'BL1: click event was not stored correctly in event_log.';
        }
    }

    // -------------------------------------------------------------
    // BL2: dwell_start then dwell_end (with dwell_duration)
    // -------------------------------------------------------------
    [$httpCodeStart, $dataStart] = postJson($endpoint, [
        'session_id'  => $sessionId,
        'artefact_id' => $artefactA,
        'event_type'  => 'dwell_start',
    ]);
    if ($httpCodeStart !== 201 || ($dataStart['success'] ?? false) !== true) {
        $errors[] = "BL2: expected HTTP 201 + success=true for dwell_start, got {$httpCodeStart}.";
    }

    [$httpCodeEnd, $dataEnd] = postJson($endpoint, [
        'session_id'     => $sessionId,
        'artefact_id'    => $artefactA,
        'event_type'     => 'dwell_end',
        'dwell_duration' => 15,
    ]);
    if ($httpCodeEnd !== 201 || ($dataEnd['success'] ?? false) !== true) {
        $errors[] = "BL2: expected HTTP 201 + success=true for dwell_end, got {$httpCodeEnd}.";
    } else {
        $row = fetchEvent($pdo, (int)$dataEnd['event_id']);
        if (!$row || $row['event_type'] !== 'dwell_end' || (int)$row['dwell_duration'] !== 15) {
            $errors[] = 'BL2: dwell_end event was not stored with the correct dwell_duration.';
        }
    }

    // dwell_end without dwell_duration should be rejected
    [$httpCodeBadDwell, $dataBadDwell] = postJson($endpoint, [
        'session_id'  => $sessionId,
        'artefact_id' => $artefactA,
        'event_type'  => 'dwell_end',
    ]);
    if ($httpCodeBadDwell !== 400 || ($dataBadDwell['success'] ?? true) !== false) {
        $errors[] = "BL2: dwell_end with no dwell_duration should return 400, got {$httpCodeBadDwell}.";
    }

    // -------------------------------------------------------------
    // BL3: navigation_depth increments across 3 sequential opens
    // -------------------------------------------------------------
    $navArtefacts = [$artefactA, $artefactB, $artefactC];
    $navEventIds  = [];
    foreach ($navArtefacts as $depth => $artefactId) {
        [$httpCodeNav, $dataNav] = postJson($endpoint, [
            'session_id'       => $sessionId,
            'artefact_id'      => $artefactId,
            'event_type'       => 'navigation_depth',
            'navigation_depth' => $depth + 1,
        ]);
        if ($httpCodeNav !== 201 || ($dataNav['success'] ?? false) !== true) {
            $errors[] = "BL3: expected HTTP 201 + success=true for navigation_depth " . ($depth + 1) . ", got {$httpCodeNav}.";
        } else {
            $navEventIds[] = (int)$dataNav['event_id'];
        }
    }
    if (count($navEventIds) === 3) {
        foreach ($navEventIds as $i => $eventId) {
            $row = fetchEvent($pdo, $eventId);
            if (!$row || (int)$row['navigation_depth'] !== $i + 1) {
                $errors[] = 'BL3: navigation_depth event ' . ($i + 1) . ' was not stored with the expected value.';
            }
        }
    }

    // navigation_depth without a navigation_depth value should be rejected
    [$httpCodeBadNav, $dataBadNav] = postJson($endpoint, [
        'session_id'  => $sessionId,
        'artefact_id' => $artefactA,
        'event_type'  => 'navigation_depth',
    ]);
    if ($httpCodeBadNav !== 400 || ($dataBadNav['success'] ?? true) !== false) {
        $errors[] = "BL3: navigation_depth with no navigation_depth value should return 400, got {$httpCodeBadNav}.";
    }

    // -------------------------------------------------------------
    // BL4: revisit - reopening artefact A is logged as a revisit
    // (revisit detection itself is client-side logic in logging.js;
    // this confirms the endpoint accepts and stores the event type)
    // -------------------------------------------------------------
    [$httpCodeRevisit, $dataRevisit] = postJson($endpoint, [
        'session_id'  => $sessionId,
        'artefact_id' => $artefactA,
        'event_type'  => 'revisit',
    ]);
    if ($httpCodeRevisit !== 201 || ($dataRevisit['success'] ?? false) !== true) {
        $errors[] = "BL4: expected HTTP 201 + success=true for a revisit event, got {$httpCodeRevisit}.";
    } else {
        $row = fetchEvent($pdo, (int)$dataRevisit['event_id']);
        if (!$row || $row['event_type'] !== 'revisit' || (int)$row['artefact_id'] !== $artefactA) {
            $errors[] = 'BL4: revisit event was not stored correctly in event_log.';
        }
    }

    // -------------------------------------------------------------
    // General validation + integrity checks
    // -------------------------------------------------------------
    [$httpCodeType, $dataType] = postJson($endpoint, [
        'session_id'  => $sessionId,
        'artefact_id' => $artefactA,
        'event_type'  => 'not_a_real_event_type',
    ]);
    if ($httpCodeType !== 400 || ($dataType['success'] ?? true) !== false) {
        $errors[] = "Validation: invalid event_type should return 400, got {$httpCodeType}.";
    }

    [$httpCodeArt, $dataArt] = postJson($endpoint, [
        'session_id'  => $sessionId,
        'artefact_id' => 999999999,
        'event_type'  => 'click',
    ]);
    if ($httpCodeArt !== 500 || ($dataArt['success'] ?? true) !== false) {
        $errors[] = "Validation: non-existent artefact_id should return 500, got {$httpCodeArt}.";
    }

    [$httpCodeSess, $dataSess] = postJson($endpoint, [
        'session_id'  => 999999999,
        'artefact_id' => $artefactA,
        'event_type'  => 'click',
    ]);
    if ($httpCodeSess !== 500 || ($dataSess['success'] ?? true) !== false) {
        $errors[] = "Validation: non-existent session_id should return 500, got {$httpCodeSess}.";
    }

    $httpCodeMethod = getRequest($endpoint);
    if ($httpCodeMethod !== 405) {
        $errors[] = "Validation: expected HTTP 405 for a GET request, got {$httpCodeMethod}.";
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    // Always clean up, even if an assertion above failed. Deleting the
    // participant cascades to session_log (ON DELETE CASCADE) and from
    // there to event_log (ON DELETE CASCADE) - see create_tables.sql.
    // The 3 artefacts used are pre-existing seed data and are never
    // modified, so nothing to clean up there.
    if ($participantId) {
        $pdo->prepare('DELETE FROM participant WHERE participant_id = :id')->execute([':id' => $participantId]);
    }
}

function fetchEvent(PDO $pdo, int $eventId)
{
    $stmt = $pdo->prepare('SELECT * FROM event_log WHERE event_id = :id');
    $stmt->execute([':id' => $eventId]);
    return $stmt->fetch();
}

echo "BL1-BL4 - Test click, dwell_start/dwell_end, navigation_depth, revisit\n";
echo str_repeat('-', 50) . "\n";
echo "Endpoint tested: {$endpoint}\n";
echo 'Session used for test: ' . ($sessionId ?? 'n/a') . "\n";
echo "(Test data cleaned up after verification.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "click, dwell_start/dwell_end (with correct dwell_duration), navigation_depth (incrementing ";
    echo "correctly), and revisit events are all logged correctly, and invalid requests are rejected.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
