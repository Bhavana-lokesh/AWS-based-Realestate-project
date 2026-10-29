<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this cleanup from the command line.\n");
}

require_once dirname(__DIR__) . '/config.php';

$demoPrefix = 'DEMO - Seed30 - %';
$lockName = 'cleanup_marked_demo_properties';
$lockStatement = $con->prepare('SELECT GET_LOCK(?, 10)');
$lockStatement->bind_param('s', $lockName);
$lockStatement->execute();
$lockAcquired = (int)$lockStatement->get_result()->fetch_row()[0];
$lockStatement->close();

if ($lockAcquired !== 1) {
    throw new RuntimeException('Could not acquire the demo cleanup lock; no listings were changed.');
}

$hasActivity = "
    NOT EXISTS (SELECT 1 FROM favorite f WHERE f.property_id = p.property_id)
    AND NOT EXISTS (SELECT 1 FROM appointment a WHERE a.property_id = p.property_id)
    AND NOT EXISTS (SELECT 1 FROM offer o WHERE o.property_id = p.property_id)
    AND NOT EXISTS (SELECT 1 FROM rental_interest ri WHERE ri.property_id = p.property_id)
    AND NOT EXISTS (SELECT 1 FROM rental_contracts rc WHERE rc.property_id = p.property_id)";

try {
    $con->begin_transaction();

    $deleteImages = $con->prepare(
        'DELETE FROM property_image
         WHERE property_id IN (
             SELECT p.property_id
             FROM property_listings p
             WHERE p.title LIKE ? AND ' . $hasActivity . '
         )'
    );
    $deleteImages->bind_param('s', $demoPrefix);
    $deleteImages->execute();
    $deletedImages = $deleteImages->affected_rows;
    $deleteImages->close();

    $deleteListings = $con->prepare(
        'DELETE p FROM property_listings p WHERE p.title LIKE ? AND ' . $hasActivity
    );
    $deleteListings->bind_param('s', $demoPrefix);
    $deleteListings->execute();
    $deletedListings = $deleteListings->affected_rows;
    $deleteListings->close();

    $retireListings = $con->prepare(
        "UPDATE property_listings SET status = 'hold'
         WHERE title LIKE ?"
    );
    $retireListings->bind_param('s', $demoPrefix);
    $retireListings->execute();
    $retiredListings = $retireListings->affected_rows;
    $retireListings->close();

    $remainingStatement = $con->prepare(
        'SELECT COUNT(*) FROM property_listings WHERE title LIKE ?'
    );
    $remainingStatement->bind_param('s', $demoPrefix);
    $remainingStatement->execute();
    $remainingListings = (int)$remainingStatement->get_result()->fetch_row()[0];
    $remainingStatement->close();

    $con->commit();
    printf(
        "Cleanup complete. Deleted %d listings and %d sample images; hid %d activity-linked listings. %d marked demo listings remain for history.\n",
        $deletedListings,
        $deletedImages,
        $retiredListings,
        $remainingListings
    );
} catch (Throwable $exception) {
    $con->rollback();
    error_log('Marked demo property cleanup failed: ' . $exception->getMessage());
    throw $exception;
} finally {
    $releaseStatement = $con->prepare('SELECT RELEASE_LOCK(?)');
    $releaseStatement->bind_param('s', $lockName);
    $releaseStatement->execute();
    $releaseStatement->close();
}
