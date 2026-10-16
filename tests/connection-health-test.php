<?php
const ABSPATH=__DIR__; const AQM_GHL_OPTION_KEY='settings';
$GLOBALS['http']=[]; $GLOBALS['calls']=[];
function aqm_ghl_get_settings(){return ['form_ids'=>[19], 'create_opportunity'=>1];}
function aqm_ghl_get_active_auth(){return ['mode'=>'oauth','location_id'=>'test-location','token'=>'secret'];}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error { function __construct(...$args){} }
function wp_remote_get($url,$args){$GLOBALS['calls'][]=$url; return array_shift($GLOBALS['http']);}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'] ?? '';}
require __DIR__.'/../includes/helpers.php';
function check($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
check(function_exists('aqm_ghl_check_connection'),'read-only connection check exists');
$GLOBALS['http']=[['code'=>200,'body'=>'{"customFields":[]}'],['code'=>200,'body'=>'{"contacts":[]}'],['code'=>401]];
$r=aqm_ghl_check_connection(); check(!$r['ok'],'pipeline permission failure must fail enabled opportunity check');
check($r['checks']['pipelines']===401,'report the failed permission');
check(strpos(json_encode($r),'secret')===false,'no token in results');
$GLOBALS['http']=[['code'=>200,'body'=>'{"customFields":[]}'],['code'=>200,'body'=>'{"contacts":[]}'],['code'=>200,'body'=>'{"pipelines":[]}']];
$r=aqm_ghl_check_connection(); check(!$r['ok'],'empty pipelines cannot create opportunities');
$GLOBALS['http']=[['code'=>200,'body'=>'{"customFields":[]}'],['code'=>200,'body'=>'{"contacts":[]}'],['code'=>200,'body'=>'{"pipelines":[{"id":"p","stages":[{"id":"s"}]}]}']];
$r=aqm_ghl_check_connection(); check($r['ok'],'valid contact, fields, pipeline and stage must pass');
$GLOBALS['http']=[new WP_Error(),['code'=>200,'body'=>'{"contacts":[]}'],['code'=>200,'body'=>'{"pipelines":[{"id":"p","stages":[{"id":"s"}]}]}']];
$r=aqm_ghl_check_connection(); check(!$r['ok'],'network failure must fail closed');
foreach($GLOBALS['calls'] as $url){check(strpos($url,'https://services.leadconnectorhq.com/')===0,'fixed API destination only');}
echo "PASS: permission failures, missing pipelines, healthy reads, network failure, secret-safe output\n";
