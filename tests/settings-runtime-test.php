<?php
// Run with: php tests/settings-runtime-test.php
const ABSPATH = __DIR__;
const AQM_GHL_OPTION_KEY = 'settings';
$GLOBALS['stored'] = ['form_ids'=>[19], 'mapping'=>[19=>['email'=>101]], 'tags'=>'website-lead', 'create_opportunity'=>1, 'oauth_location_id'=>'gerrior-location', 'oauth_access_token'=>'old', 'oauth_company_id'=>'company'];
$GLOBALS['notices']=[]; $GLOBALS['detect_calls']=0;
function add_action(...$args) {}
function get_option($key,$default=[]) { return $GLOBALS['stored']; }
function aqm_ghl_get_settings() { return $GLOBALS['stored']; }
function sanitize_text_field($v) { return trim(strip_tags((string)$v)); }
function wp_strip_all_tags($v) { return strip_tags($v); }
function wp_unslash($v) { return $v; }
function absint($v) { return abs((int)$v); }
function __($v,...$args) { return $v; }
function current_user_can($cap) { return true; }
function add_settings_error(...$args) { $GLOBALS['notices'][]=$args; }
function aqm_ghl_autodetect_mapping_for_form($id) { return []; }
function aqm_ghl_autodetect_custom_fields_for_form($id) {
    $GLOBALS['detect_calls']++;
    // A token/location write while mapping is running must not enter mapping again.
    if ($GLOBALS['detect_calls']===1 && !empty($GLOBALS['simulate_nested'])) {
        $GLOBALS['stored']=(new AQM_GHL_Admin())->sanitize_settings(array_merge($GLOBALS['stored'], ['oauth_access_token'=>'nested-token']));
    }
    return [];
}
require __DIR__.'/../includes/class-aqm-ghl-admin.php';
function expect($condition,$message) { if(!$condition) {fwrite(STDERR,"FAIL: $message\n"); exit(1);} }
$admin=new AQM_GHL_Admin();
$runtime=array_merge($GLOBALS['stored'],['oauth_access_token'=>'fresh','oauth_location_id'=>'selected-location','oauth_company_id'=>'new-company']);
$result=$admin->sanitize_settings($runtime);
expect($GLOBALS['detect_calls']===0,'runtime token writes must not call field mapping');
expect($result===$runtime,'runtime writes must preserve the exact complete settings including new metadata');
expect(count($GLOBALS['notices'])===0,'runtime writes must not create settings-saved notices');
expect($admin->sanitize_settings(null)===$GLOBALS['stored'],'invalid runtime data must preserve existing settings');
$GLOBALS['pagenow']='options.php'; $_SERVER['REQUEST_METHOD']='POST'; $_POST=['option_page'=>'aqm_ghl_connector','action'=>'update'];
$GLOBALS['simulate_nested']=true; $GLOBALS['detect_calls']=0;
$result=$admin->sanitize_settings(['form_ids'=>[19], 'tags'=>'new-tag', 'create_opportunity'=>1]);
expect($GLOBALS['detect_calls']===1,'form save must map once even with a nested runtime write');
expect($result['tags']==='new-tag' && $result['form_ids']===[19],'form edits must still save');
expect($result['oauth_access_token']==='nested-token','form save must preserve tokens refreshed during mapping');
expect(count($GLOBALS['notices'])===0,'WordPress supplies the single form-save confirmation');
echo "PASS: runtime settings preservation, no recursive mapping, form save, and no repeated notices\n";
