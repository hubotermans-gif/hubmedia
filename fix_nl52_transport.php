<?php
// Direct database helper - remove transport 2 from NL52
session_start();

// Connect to database
$dbconn = mysqli_connect('localhost', 'hubmed01', 'A3RliMu3BeWVQspBNZDVvIWtF', 'hubmed01_boekhouding');
if (!$dbconn) {
    die('Database connection failed: ' . mysqli_connect_error());
}

// Get current transport bits for NL52
$query = "SELECT transport FROM magazijn_rayon_transport WHERE rayon='NL52' AND seizoen='VJ' AND jaar=2026";
$result = mysqli_query($dbconn, $query);
$row = mysqli_fetch_assoc($result);

if ($row) {
    $currentBits = intval($row['transport']);
    echo "Huidige transport bits: " . $currentBits . " (binary: " . decbin($currentBits) . ")\n";
    
    // Remove bit 2 (transport #2 checkbox) - using bitwise AND with inverted mask
    $newBits = $currentBits & ~(1 << 1);  // Bit 1 is transport 2
    echo "Nieuwe transport bits: " . $newBits . " (binary: " . decbin($newBits) . ")\n";
    
    // Update the database
    $updateQuery = "UPDATE magazijn_rayon_transport SET transport=$newBits WHERE rayon='NL52' AND seizoen='VJ' AND jaar=2026";
    if (mysqli_query($dbconn, $updateQuery)) {
        echo "✅ Transport 2 verwijderd van NL52!\n";
    } else {
        echo "❌ Update mislukt: " . mysqli_error($dbconn) . "\n";
    }
} else {
    echo "❌ Geen data gevonden voor NL52\n";
}

mysqli_close($dbconn);
?>
