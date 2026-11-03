<?php

define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-lew-personalizations.php';

$tenant = '9e8f3289-46bc-46cd-8e89-340640053a87';
$snippet = <<<'HTML'
<script type="text/javascript">
    ((a,b,c,d,e,f)=>{
        a._lecloud=a._lecloud||{event:[],personalization:[]};
        e=b.getElementsByTagName(c)[0];
        f=b.createElement(c);
        f.type="module";
        f.async=!0;
        f.src='https://le-cloud.app/f/t/'+d+".js";
        e.parentNode.insertBefore(f,e);
    })(window,document,"script","9e8f3289-46bc-46cd-8e89-340640053a87");
</script>
HTML;

foreach ([$tenant, 'https://le-cloud.app/f/t/' . $tenant . '.js', $snippet] as $input) {
    if (LEW_Personalizations::extract_tenant_id($input) !== $tenant) {
        throw new RuntimeException('Tenant ID was not extracted from valid input.');
    }
}

foreach (['https://other.example/f/t/' . $tenant . '.js', '<script>alert(1)</script>', 'not-a-uuid'] as $input) {
    if (LEW_Personalizations::extract_tenant_id($input) !== '') {
        throw new RuntimeException('Invalid tenant input was accepted.');
    }
}

$tag = LEW_Personalizations::module_script_tag('<script type="text/javascript" src="example.js"></script>', 'lew-personalization-base');
if (!str_contains($tag, 'type="module" async') || str_contains($tag, 'text/javascript')) {
    throw new RuntimeException('Base script was not rendered as an async module.');
}

echo "Personalization tenant tests passed.\n";
