<?php
// =====================================================================
// Digital Museum Research Project
// Phase 7 - Error/Fallback System
// tests/phase7/test_missing_session.php
//
// Test case FB1 (Testing Plans.docx > Phase 7 Test Cases)
//   Description:     Test missing session variable
//   Steps:            Load adaptive.php directly
//   Expected Result:  Fallback screen appears
//
// No participant/session is created for this test at all - the point
// of FB1 is what happens with NO active PHP session in the browser, so
// a fresh (empty) cookie jar is used throughout. Nothing is written to
// the database, so there is nothing to clean up afterwards.
//
// TC1 checks the literal FB1 step (adaptive.php) redirects to the
// correct fallback.php?reason=missing_session URL, not just "somewhere
// else". TC2 follows that redirect and confirms the fallback screen
// itself renders correctly (FB1's expected result). TC3 extends the
// same check to the other four participant-facing pages that share the
// identical guard (static.php, transition1.php, transition2.php,
// questionnaire.php), since Phase 7's Testing Plan scopes this test to
// "fallback.php" as a component, not to adaptive.php alone.
// =====================================================================

header('Content-Type: text/plain');

$baseUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/digitalmuseum';

$errors = [];
$cookieJarPath = tempnam(sys_get_temp_dir(), 'museum_test_fb1_cookies_');

/**
 * GET a URL with an empty/shared cookie jar (no session cookie set at
 * any point in this test). $followLocation = false returns the raw
 * response (status line + headers + body) so the redirect target
 * itself can be checked, not just where it eventually lands.
 */
function httpGet(string $url, string $cookieJarPath, bool $followLocation): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieJarPath,
        CURLOPT_COOKIEFILE     => $cookieJarPath,
        CURLOPT_FOLLOWLOCATION => $followLocation,
        CURLOPT_HEADER         => !$followLocation,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$httpCode, $body];
}

try {
    // -------------------------------------------------------------
    // TC1: FB1's literal step - load adaptive.php directly with no
    // session cookie, and confirm the redirect targets fallback.php
    // with reason=missing_session specifically.
    // -------------------------------------------------------------
    [$httpCode1, $raw1] = httpGet($baseUrl . '/adaptive.php', $cookieJarPath, false);

    if ($httpCode1 < 300 || $httpCode1 >= 400) {
        $errors[] = "TC1: expected a redirect loading adaptive.php with no session, got HTTP {$httpCode1}.";
    }
    if (stripos($raw1, 'Location: fallback.php?reason=missing_session') === false) {
        $errors[] = 'TC1: adaptive.php did not redirect to fallback.php?reason=missing_session with no active session.';
    }

    // -------------------------------------------------------------
    // TC2: following that redirect actually shows the fallback screen
    // (FB1's expected result), with the missing_session-specific
    // message and the "Return to Home" recovery button.
    // -------------------------------------------------------------
    [$httpCode2, $body2] = httpGet($baseUrl . '/adaptive.php', $cookieJarPath, true);

    if ($httpCode2 !== 200) {
        $errors[] = "TC2: expected HTTP 200 once redirected to fallback.php, got {$httpCode2}.";
    }
    if (strpos($body2, '<body class="fallback-page">') === false) {
        $errors[] = 'TC2: fallback screen did not render after loading adaptive.php with no active session.';
    }
    if (strpos($body2, 'find an active session') === false) {
        $errors[] = 'TC2: fallback screen did not show the missing_session-specific message.';
    }
    if (strpos($body2, 'Return to Home') === false) {
        $errors[] = 'TC2: fallback screen is missing its "Return to Home" button.';
    }

    // -------------------------------------------------------------
    // TC3: the same missing-session guard exists on static.php,
    // transition1.php, transition2.php, and questionnaire.php - all
    // must show the fallback screen too, with no active session.
    // -------------------------------------------------------------
    $otherPages = ['static.php', 'transition1.php', 'transition2.php', 'questionnaire.php'];
    foreach ($otherPages as $page) {
        [$httpCodeP, $bodyP] = httpGet($baseUrl . '/' . $page, $cookieJarPath, true);
        if ($httpCodeP !== 200 || strpos($bodyP, '<body class="fallback-page">') === false) {
            $errors[] = "TC3: {$page} did not show the fallback screen when loaded with no active session.";
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Unexpected error: ' . $e->getMessage();
} finally {
    @unlink($cookieJarPath);
}

echo "FB1 - Test missing session variable\n";
echo str_repeat('-', 50) . "\n";
echo "Pages tested: adaptive.php (per FB1's steps), plus static.php, transition1.php,\n";
echo "transition2.php, questionnaire.php (same guard, same page, extra coverage).\n";
echo "(No database records were created for this test.)\n\n";

if (empty($errors)) {
    echo "RESULT: PASS\n";
    echo "Every participant-facing page redirects to fallback.php?reason=missing_session when\n";
    echo "there is no active session, and fallback.php renders the correct calm screen with a\n";
    echo "working Return to Home button.\n";
    exit(0);
}

echo "RESULT: FAIL\n";
echo count($errors) . " issue(s) found:\n";
foreach ($errors as $e) {
    echo " - {$e}\n";
}
exit(1);
