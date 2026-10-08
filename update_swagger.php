<?php
$map = [
    'App\\Http\\Controllers\\Auth\\SigninController' => ['Auth', ''],
    'App\\Http\\Controllers\\Auth\\SignupController' => ['Auth', ''],
    'App\\Http\\Controllers\\Profile\\ProfileController' => ['Profile', ''],
    'App\\Http\\Controllers\\Profile\\AvatarController' => ['Profile', ''],
    'App\\Http\\Controllers\\Profile\\HandleCheckController' => ['Profile', ''],
    'App\\Http\\Controllers\\Profile\\EmailController' => ['Profile', ''],
    'App\\Http\\Controllers\\Profile\\PasswordController' => ['Profile', ''],
    'App\\Http\\Controllers\\Profile\\SecurityController' => ['Profile', ''],
    'App\\Http\\Controllers\\Profile\\AccountController' => ['Profile', ''],
    'App\\Http\\Controllers\\Otp\\OtpController' => ['Auth', ''], // or maybe OTP
    'App\\Http\\Controllers\\Housing\\HousingSearchController' => ['Housing', ''],
    'App\\Http\\Controllers\\Admin\\RoleController' => ['Admin - Roles', ''],
    'App\\Http\\Controllers\\Geo\\CountryController' => ['Geo - Countries', 'Admin - Geo'],
    'App\\Http\\Controllers\\Geo\\CurrencyController' => ['Geo - Currencies', 'Admin - Geo'],
    'App\\Http\\Controllers\\Geo\\CityController' => ['Geo - Cities', 'Admin - Geo'],
    'App\\Http\\Controllers\\Geo\\NeighbourhoodController' => ['Geo - Neighbourhoods', 'Admin - Geo'],
    'App\\Http\\Controllers\\Geo\\OperatorController' => ['Geo - Operators', 'Admin - Geo'],
];

$dir = __DIR__ . '/app/Http/Controllers';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        
        $classNameMatch = [];
        preg_match('/namespace (App.*?);.*?class (.*?)\s/s', $content, $classNameMatch);
        if (!$classNameMatch) continue;
        
        $fullClass = $classNameMatch[1] . '\\' . $classNameMatch[2];
        
        if (isset($map[$fullClass])) {
            $publicGroup = $map[$fullClass][0];
            $adminGroup = $map[$fullClass][1] ?: $publicGroup;
            
            // On replace the class level @group
            $content = preg_replace('/@group\s+.*?\n/', "@group $publicGroup\n", $content);
            $content = preg_replace('/@tags\s+.*?\n/', "@tags $publicGroup\n", $content);
            
            // For Geo, index and show are public, others are admin. 
            if (strpos($fullClass, 'Geo\\') !== false) {
                // Remove existing method-level groups just in case
                // Insert specific @group for store, update, destroy
                $content = preg_replace('/\/\*\*\s*(?:\n|\r\n)?(?:\s*\*\s*(?!@group|@tags).*?(?:\n|\r\n)?)*\s*\*\s*@param.*?(?:\n|\r\n)?.*?\s*public function (store|update|destroy)/s', "/**\n     * @group $adminGroup\n     * @authenticated\n$0", $content);
                // Also index and show
                $content = preg_replace('/\/\*\*\s*(?:\n|\r\n)?(?:\s*\*\s*(?!@group|@tags).*?(?:\n|\r\n)?)*\s*\*\s*@param.*?(?:\n|\r\n)?.*?\s*public function (index|show)/s', "/**\n     * @group $publicGroup\n$0", $content);
            } else if (strpos($fullClass, 'Admin\\') !== false) {
                $content = preg_replace('/(\/\*\*)/', "$1\n     * @authenticated", $content, 1);
            } else if (strpos($fullClass, 'Auth\\') === false) {
                $content = preg_replace('/(\/\*\*)/', "$1\n     * @authenticated", $content, 1);
            }
            
            // Save
            file_put_contents($file->getPathname(), $content);
        }
    }
}
echo "Done.";
