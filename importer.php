<?php

require "vendor/autoload.php";

use Uro\TeltonikaFmParser\FmParser;

# Instantiate dotenv to read configuration from environment variables or .env file
$dotenv = Dotenv\Dotenv::create(__DIR__);
$dotenv->load();

// Configuration variables
$listenPort = getenv('LISTEN_PORT');
$allowedDevices = getenv('ALLOWED_DEVICES');
$dbscheme = getenv('DB_SCHEME');
$dbhost = getenv('DB_HOST');
$dbport = getenv('DB_PORT');
$dbname = getenv('DB_NAME');
$dbuser = getenv('DB_USER');
$dbpass = getenv('DB_PASS');
$timezone = getenv('TIMEZONE');

// Make a database connection
$database = InfluxDB\Client::fromDSN(sprintf('%s://%s:%s@%s:%s/%s', $dbscheme, $dbuser, $dbpass, $dbhost, $dbport, $dbname));

// Set timezone as requested
date_default_timezone_set($timezone);

// Make an array containing the allowed device IMEI numbers
$allowedDevices = explode( ' ', $allowedDevices );

$parser = new FmParser('tcp');
$socket = stream_socket_server("tcp://0.0.0.0:$listenPort", $errno, $errstr);

if (!$socket) {
  throw new \Exception("$errstr ($errno)");
} else {

  echo "Starting Teltonika FM-listener...\n";

  while ( $conn = stream_socket_accept( $socket, 600 ) ) {
    echo "New connection arriving...\n";

    // Read IMEI
    $payload = fread($conn, 10240);
    $imei = $parser->decodeImei($payload);
    echo "New message received from IMEI: $imei\n";

    // Check if device is allowed
    if ( !in_array( $imei, $allowedDevices ) ) {
      echo "Device is not allowed!\n";
      // Decline packet
      fwrite($conn, Uro\TeltonikaFmParser\Protocol\Tcp\Reply::reject());
      continue;
    } else {
      // Accept packet
      fwrite($conn, Uro\TeltonikaFmParser\Protocol\Tcp\Reply::accept());
    }

    // Read Data
    $payload = fread($conn, 10240);
    $packet = $parser->decodeData($payload);

    // Print packet
    // print_r( $packet->getAvlDataCollection() );

    // Check how many avlData objects was received
    $num = $packet->getAvlDataCollection()->getNumberOfData();
    print( "Number of data objects received: $num\n" );

    $points = [];

    // Loop through the avlData objects
    for ( $i = 0; $i < $num; $i++ ) {
      print( "Processing object number: $i\n" );
      $gps = $packet->getAvlDataCollection()->getAvlData()[$i]->getGpsElement();
      // print_r( $packet->getAvlDataCollection()->getAvlData()[$i] );
      // print_r( $packet->getAvlDataCollection()->getAvlData()[$i]->getGpsElement() );
      $timestamp = round( $packet->getAvlDataCollection()->getAvlData()[$i]->getTimestamp() / 1000 );
      print( "Timestamp: " . $timestamp . "\n" );
      print( "Time: " . date( DATE_RFC2822, $timestamp ) . "\n" );
      print( "Lat: " . $gps->getLatitude() . "\n" );
      print( "Lot: " . $gps->getLongitude() . "\n" );
      print( "Alt: " . $gps->getAltitude() . "\n" );
      print( "Spd: " . $gps->getSpeed() . "\n" );
      print( "----------------------------------------------------------------------------------------------\n" );
      
      $points[] = new InfluxDB\Point(
        'gps_points',
        null,
        [ 'source' => $imei ],
        [ 'lat' => $gps->getLatitude(), 'lon' => $gps->getLongitude(), 'speed' => $gps->getSpeed() ],
        $timestamp
      );

    }

    echo "About to add to InfluxDB:\n";
    print_r( $points );

    $result = $database->writePoints($points, InfluxDB\Database::PRECISION_SECONDS);

    // Send acknowledge
    fwrite($conn, $parser->encodeAcknowledge($packet));

    // Close connection
    fclose($conn);
    echo "Connection closed\n";
  }

  fclose($socket);
  echo "Socket closed\n";
}

echo "Program terminating...\n";

?>
