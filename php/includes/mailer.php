<?php
/**
 * Email sending. Tries SMTP (if configured in config), else PHP mail().
 * Returns true on apparent success. Callers should show an on-screen link
 * fallback when this returns false (common on shared hosts without mail()).
 *
 * The SMTP client is a minimal AUTH-LOGIN implementation over fsockopen with
 * optional TLS — enough for Gmail/SendGrid/Mailgun style relays.
 */

declare(strict_types=1);

function mail_configured(): bool
{
    return trim((string)cfg('smtp_host','')) !== '';
}

function send_mail(string $to, string $subject, string $bodyText): bool
{
    if (mail_configured()) {
        try { return _smtp_send($to, $subject, $bodyText); }
        catch (Throwable $e) { error_log('SMTP send failed: '.$e->getMessage()); /* fall through */ }
    }
    // Fallback: PHP mail()
    $from = (string) (cfg('smtp_from') ?: ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $headers = "From: " . app_name() . " <{$from}>\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, $subject, $bodyText, $headers);
}

function _smtp_send(string $to, string $subject, string $body): bool
{
    $host = (string) cfg('smtp_host');
    $port = (int) (cfg('smtp_port') ?: 587);
    $user = (string) cfg('smtp_user');
    $pass = (string) cfg('smtp_pass');
    $secure = (string) cfg('smtp_secure', 'tls');
    $from = (string) (cfg('smtp_from') ?: $user);

    $transport = ($secure === 'ssl') ? "ssl://{$host}" : $host;
    $fp = @fsockopen($transport, $port, $errno, $errstr, 15);
    if (!$fp) throw new RuntimeException("connect failed: $errstr");

    $read = function() use ($fp) { $d=''; while($line=fgets($fp,515)){ $d.=$line; if(isset($line[3])&&$line[3]===' ')break; } return $d; };
    $cmd = function($c) use ($fp, $read) { fwrite($fp, $c."\r\n"); return $read(); };

    $read();
    $host_name = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $cmd("EHLO {$host_name}");
    if ($secure === 'tls') {
        $cmd("STARTTLS");
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp); throw new RuntimeException('STARTTLS failed');
        }
        $cmd("EHLO {$host_name}");
    }
    if ($user !== '') {
        $cmd("AUTH LOGIN");
        $cmd(base64_encode($user));
        $r = $cmd(base64_encode($pass));
        if (strpos($r, '235') !== 0) { fclose($fp); throw new RuntimeException('auth failed'); }
    }
    $cmd("MAIL FROM:<{$from}>");
    $cmd("RCPT TO:<{$to}>");
    $r = $cmd("DATA");
    if (strpos($r, '354') !== 0) { fclose($fp); throw new RuntimeException('DATA rejected'); }
    $data = "From: " . app_name() . " <{$from}>\r\n"
          . "To: <{$to}>\r\n"
          . "Subject: {$subject}\r\n"
          . "MIME-Version: 1.0\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
          . str_replace("\n.", "\n..", $body) . "\r\n.";
    $r = $cmd($data);
    $cmd("QUIT");
    fclose($fp);
    return strpos($r, '250') === 0;
}
