<?php
/** File: includes/Serving_Health_Adapter_Factory.php */
declare(strict_types=1);
namespace ArgentVideo;
use RuntimeException;
final class Serving_Health_Adapter_Factory
{
    /** @var array<string,Serving_Health_Adapter> */
    private array $adapters=array();
    public function __construct(Serving_Health_Adapter ...$adapters){foreach($adapters as $adapter){$this->register($adapter);}}
    public function register(Serving_Health_Adapter $adapter):void
    {
        $type=Backend_Identity::sanitize($adapter->type());
        if(''===$type||$type!==$adapter->type()||isset($this->adapters[$type]))throw new RuntimeException('Invalid or duplicate serving-health adapter type.');
        $this->adapters[$type]=$adapter;
    }
    public function resolve(string $type):?Serving_Health_Adapter{$type=Backend_Identity::sanitize($type);return ''!==$type?($this->adapters[$type]??null):null;}
}
// EOF
