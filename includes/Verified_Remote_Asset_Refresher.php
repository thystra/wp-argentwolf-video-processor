<?php
/** File: includes/Verified_Remote_Asset_Refresher.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Refresh exact provider-backed remote-asset facts before operator serving adoption. */
interface Verified_Remote_Asset_Refresher
{
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';

    /** @return array{status:string,reason_code:string,message:string} */
    public function refresh(int $video_id, int $remote_asset_id, int $now): array;
}
// EOF
