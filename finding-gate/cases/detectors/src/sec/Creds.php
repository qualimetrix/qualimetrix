<?php
namespace App;
final class Creds
{
    public function values(): array
    {
        $apiSecret1 = 'SG.abcdefghijklmnop.qrstuvwxyzABCDEFGH';         // SendGrid-style, identifier segments
        $password2 = 'Admin.Pass123';                                   // dotted, identifier segments
        $password3 = 'correct-horse.battery-staple';                    // lowercase hyphen passphrase
        $password4 = 'Summer-2024.x';                                   // hyphen with digit -> reported
        $password5 = 'auth.password.reset';                             // translation key (legit silence)
        $password6 = 'auth.password-reset';                             // key with hyphen (legit silence)
        $apiKey7 = 'sk_live_abcdefghijklmnop';                          // plain secret -> reported
        $token8 = 'xoxb.T012AB.B34CD';                                  // dotted identifier-ish token
        $secret9 = 'App.Config.DatabasePassword';                       // PascalCase config key
        return [$apiSecret1, $password2, $password3, $password4, $password5, $password6, $apiKey7, $token8, $secret9];
    }
}
