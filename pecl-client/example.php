<?php
/**
 * Run the reverse function.
 *
 * @link http://de2.php.net/manual/en/gearman.examples-reverse.php
 */
$gmclient= new GearmanClient();

# Add default server (localhost).
$gmclient->addServer();

$function = 'reverse';
$data     = 'Hello!';

do {

    $result = $gmclient->do($function, $data);

    switch($gmclient->returnCode()) {
    case GEARMAN_WORK_DATA:
        echo "Data: $result\n";
        break;
    case GEARMAN_WORK_STATUS:
        list($numerator, $denominator)= $gmclient->doStatus();
        echo "Status: $numerator/$denominator complete\n";
        break;
    case GEARMAN_WORK_FAIL:
        echo "Failed\n";
        exit;
    case GEARMAN_SUCCESS:
        break;
    default:
        echo "RET: " . $gmclient->returnCode() . "\n";
        exit;
    }
}
while($gmclient->returnCode() != GEARMAN_SUCCESS);
