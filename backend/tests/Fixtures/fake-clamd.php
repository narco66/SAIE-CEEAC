<?php

/*
 * Faux démon clamd pour les tests : protocole zINSTREAM réel (blocs préfixés par leur longueur sur 4 octets, bloc nul final),
 * détection de la signature EICAR. Usage : php fake-clamd.php <port> <connexions>
 */
$port = (int) ($argv[1] ?? 3311);
$connections = (int) ($argv[2] ?? 1);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $error);
if (! $server) {
    fwrite(STDERR, $error);
    exit(1);
}
for ($i = 0; $i < $connections; $i++) {
    $client = stream_socket_accept($server, 30);
    if (! $client) {
        break;
    }
    $command = '';
    while (! str_ends_with($command, "\0")) {
        $command .= fread($client, 1);
    }
    $data = '';
    while (true) {
        $header = fread($client, 4);
        $length = unpack('N', $header)[1];
        if ($length === 0) {
            break;
        }
        $chunk = '';
        while (strlen($chunk) < $length) {
            $chunk .= fread($client, $length - strlen($chunk));
        }
        $data .= $chunk;
    }
    $infected = str_contains($data, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE');
    fwrite($client, $command === "zINSTREAM\0" ? ($infected ? "stream: Eicar-Test-Signature FOUND\0" : "stream: OK\0") : "UNKNOWN COMMAND\0");
    fclose($client);
}
