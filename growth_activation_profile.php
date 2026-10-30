<?php
declare(strict_types=1);
/** Owner-authorized Growth/Enterprise profile; core runtime defaults stay closed. */
function tamasyaGrowthActivationProfile(): array {
    return [
        'TAMASYA_GROWTH_SUITE_ENABLED'=>'1',
        'TAMASYA_GROWTH_KPI_ENABLED'=>'1',
        'TAMASYA_GROWTH_RATE_MANAGER_ENABLED'=>'1',
        'TAMASYA_GROWTH_GROUP_CORPORATE_ENABLED'=>'1',
        'TAMASYA_GROWTH_ADVANCED_FOLIO_ENABLED'=>'1',
        'TAMASYA_GROWTH_PROCUREMENT_ENABLED'=>'1',
        'TAMASYA_GROWTH_CHANNEL_FOUNDATION_ENABLED'=>'1',
        'TAMASYA_GROWTH_PAYMENT_FOUNDATION_ENABLED'=>'1',
        'TAMASYA_ENTERPRISE_COMPLETION_ENABLED'=>'1',
        'TAMASYA_ENTERPRISE_FOLIO_WORKFLOW_ENABLED'=>'1',
        'TAMASYA_ENTERPRISE_PROCUREMENT_AP_ENABLED'=>'1',
        'TAMASYA_ENTERPRISE_CRM_LOYALTY_ENABLED'=>'1',
        'TAMASYA_ENTERPRISE_HEALTH_MONITORING_ENABLED'=>'1',
        'TAMASYA_ENTERPRISE_PROVIDER_ADAPTERS_ENABLED'=>'0',
        'TAMASYA_ENTERPRISE_CRM_CAMPAIGN_SEND_ENABLED'=>'0',
        'TAMASYA_PROMOTION_CODES_ENABLED'=>'0',
    ];
}

function tamasyaGrowthProfileEnvironment(string $original): string {
    $profile=tamasyaGrowthActivationProfile();
    $lines=preg_split('/\r?\n/',$original)?:[];
    $out=[];
    foreach($lines as $line){
        if(preg_match('/^\s*(?:export\s+)?([A-Z_][A-Z0-9_]*)\s*=/',$line,$m)&&array_key_exists($m[1],$profile))continue;
        $out[]=$line;
    }
    $text=rtrim(implode("\n",$out))."\n\n# Growth/Enterprise activation 20261021-r21\n";
    foreach($profile as $key=>$value)$text.=$key.'='.$value."\n";
    return $text;
}
