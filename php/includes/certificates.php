<?php
/**
 * Certificate issuing + PDF rendering + verification.
 * PDFs are lazy-cached in certificates.pdf_bytes so we render once.
 */

declare(strict_types=1);

/** A human-ish unique serial like QF-7F3A-2K9D. */
function cert_make_serial(): string
{
    $part = fn() => strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    for ($i = 0; $i < 20; $i++) {
        $serial = 'QF-' . $part() . '-' . $part();
        if (!DB::scalar("SELECT 1 FROM certificates WHERE serial=?", [$serial])) return $serial;
    }
    return 'QF-' . strtoupper(bin2hex(random_bytes(4)));
}

/**
 * Issue a certificate for a passed attempt if one doesn't exist yet.
 * Returns the serial (existing or new), or null if not eligible.
 */
function issue_certificate_if_passed(array $quiz, array $attempt): ?string
{
    if (!feature_enabled('feature_certificates')) return null;
    if ($quiz['kind'] !== 'exam') return null;
    if (empty($quiz['pass_mark'])) return null;
    if ($attempt['needs_grading']) return null;
    if ((float)$attempt['percentage'] < (float)$quiz['pass_mark']) return null;

    $existing = DB::one("SELECT serial FROM certificates WHERE attempt_id=?", [$attempt['id']]);
    if ($existing) return $existing['serial'];

    $serial = cert_make_serial();
    DB::insert(
        "INSERT INTO certificates(attempt_id, quiz_id, serial, recipient_name, score, max_score, percentage, issued_at)
         VALUES(?,?,?,?,?,?,?,?)",
        [$attempt['id'], $quiz['id'], $serial, $attempt['student_name'] ?: 'Recipient',
         (float)$attempt['score'], (float)$attempt['max_score'], (float)$attempt['percentage'], now_ts()]
    );
    return $serial;
}

/** Render a certificate PDF (bytes) for a certificate row. */
function render_certificate_pdf(array $cert, string $quizTitle, string $verifyUrl): string
{
    $pdf = new MiniPDF(); // A4 landscape
    $W = $pdf->width();

    // Outer + inner decorative border
    $pdf->setColor(79, 70, 229);         // brand indigo
    $pdf->rect(28, 28, $W - 56, $pdf->height() - 56, 3);
    $pdf->setColor(199, 210, 254);
    $pdf->rect(38, 38, $W - 76, $pdf->height() - 76, 1);

    $pdf->setColor(15, 23, 42);
    $pdf->setFont(true, 34);
    $pdf->textCenter(120, 'Certificate of Achievement');

    $pdf->setColor(100, 116, 139);
    $pdf->setFont(false, 13);
    $pdf->textCenter(170, 'This certifies that');

    $pdf->setColor(79, 70, 229);
    $pdf->setFont(true, 30);
    $pdf->textCenter(215, $cert['recipient_name'] ?: 'Recipient');

    $pdf->setColor(100, 116, 139);
    $pdf->setFont(false, 13);
    $pdf->textCenter(258, 'has successfully completed');

    $pdf->setColor(15, 23, 42);
    $pdf->setFont(true, 18);
    $pdf->textCenter(292, $quizTitle);

    $pct = round((float)$cert['percentage']);
    $pdf->setColor(5, 150, 105);
    $pdf->setFont(true, 15);
    $pdf->textCenter(330, "Score: {$pct}%");

    // Footer line: date (left) + serial (right)
    $issued = date('F j, Y', (int)$cert['issued_at']);
    $pdf->setColor(100, 116, 139);
    $pdf->setFont(false, 10);
    $pdf->text(70, 470, 'Issued: ' . $issued);
    $serialText = 'Serial: ' . $cert['serial'];
    $pdf->text($W - 70 - $pdf->textWidth($serialText, 10), 470, $serialText);

    $pdf->setColor(148, 163, 184);
    $pdf->setFont(false, 8);
    $pdf->textCenter(500, 'Verify at ' . $verifyUrl);

    return $pdf->output();
}

/** Get PDF bytes for a serial, rendering + caching on first request. */
function certificate_pdf_bytes(array $cert): string
{
    $cached = $cert['pdf_bytes'] ?? null;
    if (is_resource($cached)) $cached = stream_get_contents($cached);
    if (is_string($cached) && $cached !== '') {
        $decoded = base64_decode($cached, true);
        if ($decoded !== false && strncmp($decoded, '%PDF', 4) === 0) return $decoded;
        if (strncmp($cached, '%PDF', 4) === 0) return $cached; // legacy raw
    }

    $quiz = DB::one("SELECT title FROM quizzes WHERE id=?", [$cert['quiz_id']]);
    $verifyUrl = abs_url('/verify/' . $cert['serial']);
    $pdf = render_certificate_pdf($cert, $quiz['title'] ?? 'Quiz', $verifyUrl);
    try {
        // Store as base64 to stay portable across MySQL LONGBLOB + SQLite BLOB
        // (we base64 on write, decode on read) — avoids binary-escaping issues.
        DB::run("UPDATE certificates SET pdf_bytes=? WHERE id=?", [base64_encode($pdf), $cert['id']]);
    } catch (Throwable $e) { /* non-fatal: still serve the rendered PDF */ }
    return $pdf;
}
