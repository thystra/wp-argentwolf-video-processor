<?php
/** Structural regression for plain-language operator event/status copy. */
declare(strict_types=1);

$assert=static function(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}};
$worker=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Task_Worker.php');
$coord=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Publication_Task_Coordinator.php');

foreach(array(
    "'WordPress publication authority and source preparation reached a durable boundary.'",
    "'PeerTube publication finalization reached a durable boundary.'",
    "'AWVP committed this durable processing boundary.'",
    "'Publication finalization authority is invalid.'",
    "'Frozen publication manifest no longer matches current reviewed provider authority.'",
) as $old){
    $assert(!str_contains($worker.$coord,$old),'Old implementation-facing operator copy remains: '.$old);
}
foreach(array(
    'This task was superseded by a newer publication state',
    'PeerTube is ready at the reviewed pre-publication visibility',
    'AWVP saved the current task result.',
    'The reviewed PeerTube publication settings no longer match the current server choices.',
) as $required){
    $assert(str_contains($worker.$coord,$required),'Expected plain-language operator copy is missing: '.$required);
}

echo "Operator event language structural tests passed.\n";
